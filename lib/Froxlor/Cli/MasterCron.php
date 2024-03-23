<?php

/**
 * This file is part of the Froxlor project.
 * Copyright (c) 2010 the Froxlor Team (see authors).
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, you can also view it online at
 * https://files.froxlor.org/misc/COPYING.txt
 *
 * @copyright  the authors
 * @author     Froxlor team <team@froxlor.org>
 * @license    https://files.froxlor.org/misc/COPYING.txt GPLv2
 */

namespace Froxlor\Cli;

use Exception;
use Froxlor\Cron\CronConfig;
use Froxlor\Cron\System\Extrausers;
use Froxlor\Cron\TaskId;
use Froxlor\Database\Database;
use Froxlor\FileDir;
use Froxlor\Froxlor;
use Froxlor\FroxlorLogger;
use Froxlor\Settings;
use Froxlor\System\Cronjob;
use PDO;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class MasterCron extends CliCommand
{
	private $lockFile = null;

	private $cronLog = null;

	protected function configure()
	{
		$this->setName('froxlor:cron');
		$this->setDescription('Regulary perform tasks created by froxlor');
		$this->addArgument('job', InputArgument::IS_ARRAY, 'Job(s) to run');
		$this->addOption('run-task', 'r', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Run a specific task [1 = re-generate configs, 4 = re-generate dns zones, 9 = re-generate rspamd configs, 10 = re-set quotas, 99 = re-create cron.d-file]')
			->addOption('force', 'f', InputOption::VALUE_NONE, 'Forces given job or, if none given, forces re-generating of config-files (webserver, nameserver, etc.)')
			->addOption('debug', 'd', InputOption::VALUE_NONE, 'Output debug information about what is going on to STDOUT.')
			->addOption('no-fork', 'N', InputOption::VALUE_NONE, 'Do not fork to background (traffic cron only).');
	}

	/**
	 * @throws Exception
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$result = $this->validateRequirements($output);

		if ($result != self::SUCCESS) {
			// requirements failed, exit
			return $result;
		}

		$jobs = $input->getArgument('job');

		// handle force option
		if ($input->getOption('force')) {
			if (empty($jobs) || in_array('tasks', $jobs)) {
				Cronjob::inserttask(TaskId::REBUILD_VHOST);
				Cronjob::inserttask(TaskId::REBUILD_DNS);
				Cronjob::inserttask(TaskId::REBUILD_RSPAMD);
				Cronjob::inserttask(TaskId::CREATE_QUOTA);
				Cronjob::inserttask(TaskId::REBUILD_CRON);
				$jobs[] = 'tasks';
			}
			define('CRON_IS_FORCED', 1);
		}
		// handle debug option
		if ($input->getOption('debug')) {
			define('CRON_DEBUG_FLAG', 1);
		}
		// handle no-fork option
		if ($input->getOption('no-fork')) {
			define('CRON_NOFORK_FLAG', 1);
		}
		// handle run-task option
		if ($input->getOption('run-task')) {
			$tasks_to_run = $input->getOption('run-task');
			foreach ($tasks_to_run as $ttr) {
				if (in_array($ttr, [TaskId::REBUILD_VHOST, TaskId::REBUILD_DNS, TaskId::REBUILD_RSPAMD, TaskId::CREATE_QUOTA, TaskId::REBUILD_CRON, TaskId::REBUILD_MAIL_CONF])) {
					Cronjob::inserttask($ttr);
					$jobs[] = 'tasks';
				} else {
					$output->writeln('<comment>Unknown task number "' . $ttr . '"</>');
				}
			}
		}

		// unique job-array
		$jobs = array_unique($jobs);

		// check for given job(s) to execute and return if empty
		if (empty($jobs)) {
			$output->writeln('<error>No job given. Nothing to do.</>');
			return self::INVALID;
		}

		$this->validateOwnership($output);

		$this->cronLog = FroxlorLogger::getInstanceOf([
			'loginname' => 'cronjob'
		]);
		$this->cronLog->setCronDebugFlag(defined('CRON_DEBUG_FLAG'));

		// check whether there are actual tasks to perform by 'tasks'-cron, so
		// we don't regenerate files unnecessarily
		$tasks_cnt_stmt = Database::query("SELECT COUNT(*) as jobcnt FROM `panel_tasks`");
		$tasks_cnt = $tasks_cnt_stmt->fetch(PDO::FETCH_ASSOC);

		// iterate through all needed jobs
		foreach ($jobs as $job) {
			// lock the job
			if ($this->lockJob($job, $output)) {
				// get FQDN of cron-class
				$cronfile = $this->getCronModule($job, $output);
				// validate
				if ($cronfile && class_exists($cronfile)) {
					// info
					$output->writeln('<info>Running "' . $job . '" job' . (defined('CRON_IS_FORCED') ? ' (forced)' : '') . (defined('CRON_DEBUG_FLAG') ? ' (debug)' : '') . (defined('CRON_NOFORK_FLAG') ? ' (not forking)' : '') . '</>');
					// update time of last run
					Cronjob::updateLastRunOfCron($job);
					// set logger
					$cronfile::setCronlog($this->cronLog);
					// run the job
					$cronfile::run();
				}
				// free the lockfile
				$this->unlockJob();
			}
		}

		// regenerate nss-extrausers files / invalidate nscd cache (if used)
		$this->refreshUsers((int)$tasks_cnt['jobcnt']);

		// we have to check the system's last guid with every cron run
		// in case the admin installed new software which added a new user
		//so users in the database don't conflict with system users
		$this->cronLog->logAction(FroxlorLogger::CRON_ACTION, LOG_NOTICE, 'Checking system\'s last guid');
		Cronjob::checkLastGuid();

		// check for cron.d-generation task and create it if necessary
		CronConfig::checkCrondConfigurationFile();

		// check for old/compatibility cronjob file
		if (file_exists(Froxlor::getInstallDir() . '/scripts/froxlor_master_cronjob.php')) {
			@unlink(Froxlor::getInstallDir() . '/scripts/froxlor_master_cronjob.php');
			@rmdir(Froxlor::getInstallDir() . '/scripts');
		}

		// reset cronlog-flag if set to "once"
		if ((int)Settings::Get('logger.log_cron') == 1) {
			FroxlorLogger::getInstanceOf()->setCronLog(0);
		}

		// clean up possible old login-links
		Database::query("DELETE FROM `" . TABLE_PANEL_LOGINLINKS . "` WHERE `valid_until` < UNIX_TIMESTAMP()");

		return $result;
	}

	/**
	 * @throws Exception
	 */
	private function validateOwnership(OutputInterface $output)
	{
		// when using fcgid or fpm for froxlor-vhost itself, we have to check
		// whether the permission of the files are still correct
		$output->write('Checking froxlor file permissions...');
		$_mypath = FileDir::makeCorrectDir(Froxlor::getInstallDir());

		if (((int)Settings::Get('system.mod_fcgid') == 1 && (int)Settings::Get('system.mod_fcgid_ownvhost') == 1) || ((int)Settings::Get('phpfpm.enabled') == 1 && (int)Settings::Get('phpfpm.enabled_ownvhost') == 1)) {
			$user = Settings::Get('system.mod_fcgid_httpuser');
			$group = Settings::Get('system.mod_fcgid_httpgroup');

			if (Settings::Get('phpfpm.enabled') == 1) {
				$user = Settings::Get('phpfpm.vhost_httpuser');
				$group = Settings::Get('phpfpm.vhost_httpgroup');
			}
			// all the files and folders have to belong to the local user
			FileDir::safe_exec('chown -R ' . $user . ':' . $group . ' ' . escapeshellarg($_mypath));
		} else {
			// back to webserver permission
			$user = Settings::Get('system.httpuser');
			$group = Settings::Get('system.httpgroup');
			FileDir::safe_exec('chown -R ' . $user . ':' . $group . ' ' . escapeshellarg($_mypath));
		}
		$output->writeln('OK');
	}

	private function lockJob(string $job, OutputInterface $output): bool
	{

		$this->lockFile = '/run/lock/froxlor_' . $job . '.lock';

		if (file_exists($this->lockFile)) {
			$jobinfo = json_decode(file_get_contents($this->lockFile), true);
			$check_pid_return = null;
			// get status of process
			system("kill -CHLD " . (int)$jobinfo['pid'] . " 1> /dev/null 2> /dev/null", $check_pid_return);
			if ($check_pid_return == 1) {
				// Process does not seem to run, most likely it has died
				$this->unlockJob();
			} else {
				// cronjob still running, output info and stop
				$output->writeln([
					'<comment>Job "' . $jobinfo['job'] . '" is currently running.',
					'Started: ' . date('d.m.Y H:i', (int)$jobinfo['startts']),
					'PID: ' . $jobinfo['pid'] . '</>'
				]);
				return false;
			}
		}

		$jobinfo = [
			'job' => $job,
			'startts' => time(),
			'pid' => getmypid()
		];
		file_put_contents($this->lockFile, json_encode($jobinfo));
		return true;
	}

	private function unlockJob(): bool
	{
		return @unlink($this->lockFile);
	}

	private function getCronModule(string $cronname, OutputInterface $output)
	{
		$upd_stmt = Database::prepare("
			SELECT `cronclass` FROM `" . TABLE_PANEL_CRONRUNS . "` WHERE `cronfile` = :cron;
		");
		$cron = Database::pexecute_first($upd_stmt, [
			'cron' => $cronname
		]);
		if ($cron) {
			return $cron['cronclass'];
		}
		$output->writeln("<error>Requested cronjob '" . $cronname . "' could not be found.</>");
		return false;
	}

	private function refreshUsers(int $jobcount = 0)
	{
		if ($jobcount > 0) {
			if (Settings::Get('system.nssextrausers') == 1) {
				Extrausers::generateFiles($this->cronLog);
				// reload crond as shell users might use crontab and the user is only known to crond if reloaded
				FileDir::safe_exec(escapeshellcmd(Settings::Get('system.crondreload')));
				return;
			}

			// clear NSCD cache if using fcgid or fpm, #1570 - not needed for nss-extrausers
			if ((Settings::Get('system.mod_fcgid') == 1 || (int)Settings::Get('phpfpm.enabled') == 1) && Settings::Get('system.nssextrausers') == 0) {
				$false_val = false;
				FileDir::safe_exec('nscd -i passwd 1> /dev/null', $false_val, [
					'>'
				]);
				FileDir::safe_exec('nscd -i group 1> /dev/null', $false_val, [
					'>'
				]);
				// reload crond as shell users might use crontab and the user is only known to crond if reloaded
				FileDir::safe_exec(escapeshellcmd(Settings::Get('system.crondreload')));
			}
		}
	}
}
