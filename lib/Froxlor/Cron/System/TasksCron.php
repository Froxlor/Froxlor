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

namespace Froxlor\Cron\System;

use Exception;
use Froxlor\Cron\FroxlorCron;
use Froxlor\Cron\Http\ConfigIO;
use Froxlor\Cron\Http\HttpConfigBase;
use Froxlor\Cron\Mail\Dovecot;
use Froxlor\Cron\Mail\Postfix;
use Froxlor\Cron\Mail\Rspamd;
use Froxlor\Cron\TaskId;
use Froxlor\Database\Database;
use Froxlor\Dns\PowerDNS;
use Froxlor\Domain\Domain;
use Froxlor\FileDir;
use Froxlor\FroxlorLogger;
use Froxlor\Settings;
use PDO;

class TasksCron extends FroxlorCron
{

	/**
	 * @throws Exception
	 */
	public static function run()
	{
		/**
		 * LOOK INTO TASKS TABLE TO SEE IF THERE ARE ANY UNDONE JOBS
		 */
		self::$cronlog->logAction(FroxlorLogger::CRON_ACTION, LOG_INFO, "TasksCron: Searching for tasks to do");
		// no type 99 (regenerate cron.d-file) and no type 20 (customer data export)
		// order by type descending to re-create bind and then webserver at the end
		$result_tasks_stmt = Database::query("
			SELECT `id`, `type`, `data` FROM `" . TABLE_PANEL_TASKS . "` WHERE `type` <> '99' AND `type` <> '20' ORDER BY `type` DESC, `id` ASC
		");
		$num_results = Database::num_rows();
		$resultIDs = [];

		while ($row = $result_tasks_stmt->fetch(PDO::FETCH_ASSOC)) {
			$resultIDs[] = $row['id'];

			if ($row['data'] != '') {
				$row['data'] = json_decode($row['data'], true);
			}

			if ($row['type'] == TaskId::REBUILD_VHOST) {
				/**
				 * TYPE=1 MEANS TO REBUILD APACHE VHOSTS.CONF
				 */
				self::rebuildWebserverConfigs();
			} elseif ($row['type'] == TaskId::CREATE_HOME) {
				/**
				 * TYPE=2 MEANS TO CREATE A NEW HOME AND CHOWN
				 */
				self::createNewHome($row);
			} elseif ($row['type'] == TaskId::REBUILD_DNS && (int)Settings::Get('system.bind_enable') != 0) {
				/**
				 * TYPE=4 MEANS THAT SOMETHING IN THE BIND CONFIG HAS CHANGED.
				 * REBUILD froxlor_bind.conf IF BIND IS ENABLED
				 */
				self::rebuildDnsConfigs();
			} elseif ($row['type'] == TaskId::CREATE_FTP) {
				/**
				 * TYPE=5 MEANS THAT A NEW FTP-ACCOUNT HAS BEEN CREATED, CREATE THE DIRECTORY
				 */
				self::createNewFtpHome($row);
			} elseif ($row['type'] == TaskId::DELETE_CUSTOMER_FILES) {
				/**
				 * TYPE=6 MEANS THAT A CUSTOMER HAS BEEN DELETED AND THAT WE HAVE TO REMOVE ITS FILES
				 */
				self::deleteCustomerData($row);
			} elseif ($row['type'] == TaskId::DELETE_EMAIL_DATA) {
				/**
				 * TYPE=7 Customer deleted an email account and wants the data to be deleted on the filesystem
				 */
				self::deleteEmailData($row);
			} elseif ($row['type'] == TaskId::DELETE_FTP_DATA) {
				/**
				 * TYPE=8 Customer deleted a ftp account and wants the homedir to be deleted on the filesystem
				 * refs #293
				 */
				self::deleteFtpData($row);
			} elseif ($row['type'] == TaskId::REBUILD_RSPAMD && (int)Settings::Get('antispam.activated') != 0) {
				/**
				 * TYPE=9 Rebuild antispam config
				 */
				self::rebuildAntiSpamConfigs();
			} elseif ($row['type'] == TaskId::CREATE_QUOTA && (int)Settings::Get('system.diskquota_enabled') != 0) {
				/**
				 * TYPE=10 Set the filesystem - quota
				 */
				self::setFilesystemQuota();
			} elseif ($row['type'] == TaskId::DELETE_DOMAIN_PDNS && Settings::Get('system.dns_server') == 'PowerDNS') {
				/**
				 * TYPE=11 domain has been deleted, remove from pdns database if used
				 */
				FroxlorLogger::getInstanceOf()->logAction(FroxlorLogger::CRON_ACTION, LOG_NOTICE, "Removing PowerDNS entries for domain " . $row['data']['domain']);
				PowerDNS::cleanDomainZone($row['data']['domain']);
			} elseif ($row['type'] == TaskId::DELETE_DOMAIN_SSL) {
				/**
				 * TYPE=12 domain has been deleted, remove from acme.sh/let's encrypt directory if used
				 */
				FroxlorLogger::getInstanceOf()->logAction(FroxlorLogger::CRON_ACTION, LOG_NOTICE, "Removing Let's Encrypt entries for domain " . $row['data']['domain']);
				Domain::doLetsEncryptCleanUp($row['data']['domain']);
			} elseif ($row['type'] == TaskId::REBUILD_MAIL_CONF) {
				/**
				 * TYPE=13 MEANS TO CREATE REBUILD MAIL CONF
				 */
				self::rebuildMailConfigs();
			}
		}

		if ($num_results != 0) {
			$where = [];
			$where_data = [];
			foreach ($resultIDs as $id) {
				$where[] = "`id` = :id_" . (int)$id;
				$where_data['id_' . $id] = $id;
			}
			$where = implode(' OR ', $where);
			$del_stmt = Database::prepare("DELETE FROM `" . TABLE_PANEL_TASKS . "` WHERE " . $where);
			Database::pexecute($del_stmt, $where_data);
			unset($resultIDs);
			unset($where);
		}

		Database::query("UPDATE `" . TABLE_PANEL_SETTINGS . "` SET `value` = UNIX_TIMESTAMP() WHERE `settinggroup` = 'system' AND `varname` = 'last_tasks_run';");
	}

	private static function rebuildWebserverConfigs()
	{
		if (Settings::Get('system.webserver') == "apache2") {
			$websrv = '\\Froxlor\\Cron\\Http\\Apache';
			if (Settings::Get('system.mod_fcgid') == 1 || Settings::Get('phpfpm.enabled') == 1) {
				$websrv .= 'Fcgi';
			}
		} elseif (Settings::Get('system.webserver') == "lighttpd") {
			$websrv = '\\Froxlor\\Cron\\Http\\Lighttpd';
			if (Settings::Get('system.mod_fcgid') == 1 || Settings::Get('phpfpm.enabled') == 1) {
				$websrv .= 'Fcgi';
			}
		} elseif (Settings::Get('system.webserver') == "nginx") {
			$websrv = '\\Froxlor\\Cron\\Http\\Nginx';
			if (Settings::Get('phpfpm.enabled') == 1) {
				$websrv .= 'Fcgi';
			}
		}

		// get configuration-I/O object
		$configio = new ConfigIO();
		// get webserver object
		$webserver = new $websrv();

		if ($webserver instanceof HttpConfigBase) {
			$webserver->init();
			// clean up old configs
			$configio->cleanUp();
			$webserver->createIpPort();
			$webserver->createVirtualHosts();
			$webserver->createFileDirOptions();
			$webserver->writeConfigs();
			$webserver->createOwnVhostStarter();
			$webserver->reload();
		} else {
			echo "Please check you Webserver settings\n";
		}

		// if we use php-fpm and have a local user for froxlor, we need to
		// add the webserver-user to the local-group in order to allow the webserver
		// to access the fpm-socket
		if (Settings::Get('phpfpm.enabled') == 1 && function_exists("posix_getgrnam")) {
			// get group info about the local-user's group (e.g. froxlorlocal)
			$groupinfo = posix_getgrnam(Settings::Get('phpfpm.vhost_httpgroup'));
			// check group members
			if (isset($groupinfo['members']) && !in_array(Settings::Get('system.httpuser'), $groupinfo['members'])) {
				// webserver has no access, add it
				if (FileDir::isFreeBSD()) {
					FileDir::safe_exec('pw usermod ' . escapeshellarg(Settings::Get('system.httpuser')) . ' -G ' . escapeshellarg(Settings::Get('phpfpm.vhost_httpgroup')));
				} else {
					FileDir::safe_exec('usermod -a -G ' . escapeshellarg(Settings::Get('phpfpm.vhost_httpgroup')) . ' ' . escapeshellarg(Settings::Get('system.httpuser')));
				}
			}
		}

		// Tell the Let's Encrypt cron it's okay to generate the certificate and enable the redirect afterwards
		$upd_stmt = Database::prepare("UPDATE `" . TABLE_PANEL_DOMAINS . "` SET `ssl_redirect` = '3' WHERE `ssl_redirect` = '2'");
		Database::pexecute($upd_stmt);
	}

	private static function createNewHome($row = null)
	{
		FroxlorLogger::getInstanceOf()->logAction(FroxlorLogger::CRON_ACTION, LOG_INFO, 'TasksCron: Task2 started - create new home');

		if (is_array($row['data'])) {
			// define paths
			$userhomedir = FileDir::makeCorrectDir(Settings::Get('system.documentroot_prefix') . '/' . $row['data']['loginname'] . '/');
			$usermaildir = FileDir::makeCorrectDir(Settings::Get('system.vmail_homedir') . '/' . $row['data']['loginname'] . '/');

			// stats directory
			$statsdir = FileDir::makeCorrectDir($userhomedir . '/' . Settings::Get('system.traffictool'));
			FroxlorLogger::getInstanceOf()->logAction(FroxlorLogger::CRON_ACTION, LOG_NOTICE, 'Running: mkdir -p ' . escapeshellarg($statsdir));
			FileDir::safe_exec('mkdir -p ' . escapeshellarg($statsdir));

			foreach (['webalizer', 'awstats', 'goaccess'] as $statstools) {
				$statsdir = FileDir::makeCorrectDir($userhomedir . '/' . $statstools);
				// in case we changed from the other stats -> remove old
				if (Settings::Get('system.traffictool') != $statstools && file_exists($statsdir)) {
					// (yes i know, the stats are lost - that's why you should not change all the time!)
					FileDir::safe_exec('rm -rf ' . escapeshellarg($statsdir));
				}
			}

			// maildir
			FroxlorLogger::getInstanceOf()->logAction(FroxlorLogger::CRON_ACTION, LOG_NOTICE, 'Running: mkdir -p ' . escapeshellarg($usermaildir));
			FileDir::safe_exec('mkdir -p ' . escapeshellarg($usermaildir));

			// check if admin of customer has added template for new customer directories
			if ((int)$row['data']['store_defaultindex'] == 1) {
				FileDir::storeDefaultIndex($row['data']['loginname'], $userhomedir, FroxlorLogger::getInstanceOf(), true);
			}

			// strip of last slash of paths to have correct chown results
			$userhomedir = (substr($userhomedir, 0, -1) == '/') ? substr($userhomedir, 0, -1) : $userhomedir;
			$usermaildir = (substr($usermaildir, 0, -1) == '/') ? substr($usermaildir, 0, -1) : $usermaildir;

			FroxlorLogger::getInstanceOf()->logAction(FroxlorLogger::CRON_ACTION, LOG_NOTICE, 'Running: chown -R ' . (int)$row['data']['uid'] . ':' . (int)$row['data']['gid'] . ' ' . escapeshellarg($userhomedir));
			FileDir::safe_exec('chown -R ' . (int)$row['data']['uid'] . ':' . (int)$row['data']['gid'] . ' ' . escapeshellarg($userhomedir));
			// don't allow others to access the directory (webserver will be the group via libnss-mysql)
			if (Settings::Get('system.mod_fcgid') == 1 || Settings::Get('phpfpm.enabled') == 1) {
				// fcgid or fpm
				FileDir::safe_exec('chmod 0750 ' . escapeshellarg($userhomedir));
			} else {
				// mod_php -> no libnss-mysql -> no webserver-user in group
				FileDir::safe_exec('chmod 0755 ' . escapeshellarg($userhomedir));
			}
			FroxlorLogger::getInstanceOf()->logAction(FroxlorLogger::CRON_ACTION, LOG_NOTICE, 'Running: chown -R ' . (int)Settings::Get('system.vmail_uid') . ':' . (int)Settings::Get('system.vmail_gid') . ' ' . escapeshellarg($usermaildir));
			FileDir::safe_exec('chown -R ' . (int)Settings::Get('system.vmail_uid') . ':' . (int)Settings::Get('system.vmail_gid') . ' ' . escapeshellarg($usermaildir));

			if (Settings::Get('system.nssextrausers') == 1) {
				// explicitly create files after user has been created to avoid unknown user issues for apache/php-fpm when task#1 runs after this
				$extrausers_log = FroxlorLogger::getInstanceOf();
				Extrausers::generateFiles($extrausers_log);
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
			}
		}
	}

	private static function rebuildDnsConfigs()
	{
		$dnssrv = '\\Froxlor\\Cron\\Dns\\' . Settings::Get('system.dns_server');
		$nameserver = new $dnssrv(FroxlorLogger::getInstanceOf());
		$nameserver->writeConfigs();
	}

	private static function createNewFtpHome($row = null)
	{
		FroxlorLogger::getInstanceOf()->logAction(FroxlorLogger::CRON_ACTION, LOG_INFO, 'Creating new FTP-home');
		$result_directories_stmt = Database::query("
			SELECT `f`.`homedir`, `f`.`uid`, `f`.`gid`, `c`.`documentroot` AS `customerroot`
			FROM `" . TABLE_FTP_USERS . "` `f` LEFT JOIN `" . TABLE_PANEL_CUSTOMERS . "` `c` USING (`customerid`)
		");

		while ($directory = $result_directories_stmt->fetch(PDO::FETCH_ASSOC)) {
			FileDir::mkDirWithCorrectOwnership($directory['customerroot'], $directory['homedir'], $directory['uid'], $directory['gid']);
		}
	}

	private static function deleteCustomerData($row = null)
	{
		FroxlorLogger::getInstanceOf()->logAction(FroxlorLogger::CRON_ACTION, LOG_INFO, 'TasksCron: Task6 started - deleting customer data');

		if (is_array($row['data'])) {
			if (isset($row['data']['loginname'])) {
				// remove homedir
				$homedir = FileDir::makeCorrectDir(Settings::Get('system.documentroot_prefix') . '/' . $row['data']['loginname']);

				if (file_exists($homedir) && $homedir != '/' && $homedir != Settings::Get('system.documentroot_prefix') && substr($homedir, 0, strlen(Settings::Get('system.documentroot_prefix'))) == Settings::Get('system.documentroot_prefix')) {
					FroxlorLogger::getInstanceOf()->logAction(FroxlorLogger::CRON_ACTION, LOG_NOTICE, 'Running: rm -rf ' . escapeshellarg($homedir));
					FileDir::safe_exec('rm -rf ' . escapeshellarg($homedir));
				}

				// remove maildir
				$maildir = FileDir::makeCorrectDir(Settings::Get('system.vmail_homedir') . '/' . $row['data']['loginname']);

				if (file_exists($maildir) && $maildir != '/' && $maildir != Settings::Get('system.vmail_homedir') && substr($maildir, 0, strlen(Settings::Get('system.vmail_homedir'))) == Settings::Get('system.vmail_homedir') && is_dir($maildir) && fileowner($maildir) == Settings::Get('system.vmail_uid') && filegroup($maildir) == Settings::Get('system.vmail_gid')) {
					FroxlorLogger::getInstanceOf()->logAction(FroxlorLogger::CRON_ACTION, LOG_NOTICE, 'Running: rm -rf ' . escapeshellarg($maildir));
					// mail-address allows many special characters, see http://en.wikipedia.org/wiki/Email_address#Local_part
					$return = false;
					FileDir::safe_exec('rm -rf ' . escapeshellarg($maildir), $return, [
						'|',
						'&',
						'`',
						'$',
						'?'
					]);
				}

				// remove tmpdir if it exists
				$tmpdir = FileDir::makeCorrectDir(Settings::Get('system.mod_fcgid_tmpdir') . '/' . $row['data']['loginname'] . '/');

				if (file_exists($tmpdir) && is_dir($tmpdir) && $tmpdir != "/" && $tmpdir != Settings::Get('system.mod_fcgid_tmpdir') && substr($tmpdir, 0, strlen(Settings::Get('system.mod_fcgid_tmpdir'))) == Settings::Get('system.mod_fcgid_tmpdir')) {
					FroxlorLogger::getInstanceOf()->logAction(FroxlorLogger::CRON_ACTION, LOG_NOTICE, 'Running: rm -rf ' . escapeshellarg($tmpdir));
					FileDir::safe_exec('rm -rf ' . escapeshellarg($tmpdir));
				}

				// webserver logs
				$logsdir = FileDir::makeCorrectFile(Settings::Get('system.logfiles_directory') . '/' . $row['data']['loginname']);

				if (file_exists($logsdir) && $logsdir != '/' && $logsdir != FileDir::makeCorrectDir(Settings::Get('system.logfiles_directory')) && substr($logsdir, 0, strlen(Settings::Get('system.logfiles_directory'))) == Settings::Get('system.logfiles_directory')) {
					// build up wildcard for webX-{access,error}.log{*}
					$logsdir .= '-*';
					FileDir::safe_exec('rm -f ' . escapeshellarg($logsdir));
				}
			}
		}
	}

	private static function deleteEmailData($row = null)
	{
		FroxlorLogger::getInstanceOf()->logAction(FroxlorLogger::CRON_ACTION, LOG_INFO, 'TasksCron: Task7 started - deleting customer e-mail data');

		if (is_array($row['data'])) {
			if (isset($row['data']['loginname']) && isset($row['data']['email'])) {
				// remove specific maildir
				$email_full = $row['data']['email'];
				if (empty($email_full)) {
					FroxlorLogger::getInstanceOf()->logAction(FroxlorLogger::CRON_ACTION, LOG_ERR, 'FATAL: Task7 asks to delete a email account but email field is empty!');
				}
				$email_user = substr($email_full, 0, strrpos($email_full, "@"));
				$email_domain = substr($email_full, strrpos($email_full, "@") + 1);
				$maildirname = trim(Settings::Get('system.vmail_maildirname'));
				// Add trailing slash to Maildir if needed
				$maildirpath = $maildirname;
				if (!empty($maildirname) and substr($maildirname, -1) != "/") {
					$maildirpath .= "/";
				}

				$maildir = FileDir::makeCorrectDir(Settings::Get('system.vmail_homedir') . '/' . $row['data']['loginname'] . '/' . $email_domain . '/' . $email_user);

				if ($maildir != '/' && !empty($maildir) && !empty($email_full) && $maildir != Settings::Get('system.vmail_homedir') && substr($maildir, 0, strlen(Settings::Get('system.vmail_homedir'))) == Settings::Get('system.vmail_homedir') && is_dir($maildir) && is_dir(FileDir::makeCorrectDir($maildir . '/' . $maildirpath)) && fileowner($maildir) == Settings::Get('system.vmail_uid') && filegroup($maildir) == Settings::Get('system.vmail_gid')) {
					FroxlorLogger::getInstanceOf()->logAction(FroxlorLogger::CRON_ACTION, LOG_NOTICE, 'Running: rm -rf ' . escapeshellarg($maildir));
					// mail-address allows many special characters, see http://en.wikipedia.org/wiki/Email_address#Local_part
					$return = false;
					FileDir::safe_exec('rm -rf ' . escapeshellarg($maildir), $return, [
						'|',
						'&',
						'`',
						'$',
						'~',
						'?'
					]);
				} else {
					// backward-compatibility for old folder-structure
					$maildir_old = FileDir::makeCorrectDir(Settings::Get('system.vmail_homedir') . '/' . $row['data']['loginname'] . '/' . $row['data']['email']);

					if ($maildir_old != '/' && !empty($maildir_old) && $maildir_old != Settings::Get('system.vmail_homedir') && substr($maildir_old, 0, strlen(Settings::Get('system.vmail_homedir'))) == Settings::Get('system.vmail_homedir') && is_dir($maildir_old) && fileowner($maildir_old) == Settings::Get('system.vmail_uid') && filegroup($maildir_old) == Settings::Get('system.vmail_gid')) {
						FroxlorLogger::getInstanceOf()->logAction(FroxlorLogger::CRON_ACTION, LOG_NOTICE, 'Running: rm -rf ' . escapeshellarg($maildir_old));
						// mail-address allows many special characters, see http://en.wikipedia.org/wiki/Email_address#Local_part
						$return = false;
						FileDir::safe_exec('rm -rf ' . escapeshellarg($maildir_old), $return, [
							'|',
							'&',
							'`',
							'$',
							'~',
							'?'
						]);
					}
				}
			}
		}
	}

	private static function deleteFtpData($row = null)
	{
		FroxlorLogger::getInstanceOf()->logAction(FroxlorLogger::CRON_ACTION, LOG_INFO, 'TasksCron: Task8 started - deleting customer ftp homedir');

		if (is_array($row['data'])) {
			if (isset($row['data']['loginname']) && isset($row['data']['homedir'])) {
				// remove specific homedir
				$ftphomedir = FileDir::makeCorrectDir($row['data']['homedir']);
				$customerdocroot = FileDir::makeCorrectDir(Settings::Get('system.documentroot_prefix') . '/' . $row['data']['loginname'] . '/');

				if (file_exists($ftphomedir) && $ftphomedir != '/' && $ftphomedir != Settings::Get('system.documentroot_prefix') && $ftphomedir != $customerdocroot) {
					FroxlorLogger::getInstanceOf()->logAction(FroxlorLogger::CRON_ACTION, LOG_NOTICE, 'Running: rm -rf ' . escapeshellarg($ftphomedir));
					FileDir::safe_exec('rm -rf ' . escapeshellarg($ftphomedir));
				}
			}
		}
	}

	private static function setFilesystemQuota()
	{
		FroxlorLogger::getInstanceOf()->logAction(FroxlorLogger::CRON_ACTION, LOG_INFO, 'TasksCron: Task10 started - setting filesystem quota');

		$usedquota = FileDir::getFilesystemQuota();

		// Check whether we really have entries to check
		if (is_array($usedquota) && count($usedquota) > 0) {
			// Select all customers Froxlor knows about
			$result_stmt = Database::query("SELECT `guid`, `loginname`, `diskspace` FROM `" . TABLE_PANEL_CUSTOMERS . "`;");
			while ($row = $result_stmt->fetch(PDO::FETCH_ASSOC)) {
				// We do not want to set a quota for root by accident
				if ($row['guid'] != 0) {
					$used_quota = isset($usedquota[$row['guid']]) ? $usedquota[$row['guid']]['block']['hard'] : 0;
					// The user has no quota in Froxlor, but on the filesystem
					if (($row['diskspace'] == 0 || $row['diskspace'] == -1024) && $used_quota != 0) {
						FroxlorLogger::getInstanceOf()->logAction(FroxlorLogger::CRON_ACTION, LOG_NOTICE, "Disabling quota for " . $row['loginname']);
						if (FileDir::isFreeBSD()) {
							FileDir::safe_exec(Settings::Get('system.diskquota_quotatool_path') . " -e " . escapeshellarg(Settings::Get('system.diskquota_customer_partition')) . ":0:0 " . $row['guid']);
						} else {
							FileDir::safe_exec(Settings::Get('system.diskquota_quotatool_path') . " -u " . $row['guid'] . " -bl 0 -q 0 " . escapeshellarg(Settings::Get('system.diskquota_customer_partition')));
						}
					} elseif ($row['diskspace'] != $used_quota && $row['diskspace'] != -1024) {
						// The user quota in Froxlor is different than on the filesystem
						FroxlorLogger::getInstanceOf()->logAction(FroxlorLogger::CRON_ACTION, LOG_NOTICE, "Setting quota for " . $row['loginname'] . " from " . $used_quota . " to " . $row['diskspace']);
						if (FileDir::isFreeBSD()) {
							FileDir::safe_exec(Settings::Get('system.diskquota_quotatool_path') . " -e " . escapeshellarg(Settings::Get('system.diskquota_customer_partition')) . ":" . $row['diskspace'] . ":" . $row['diskspace'] . " " . $row['guid']);
						} else {
							FileDir::safe_exec(Settings::Get('system.diskquota_quotatool_path') . " -u " . $row['guid'] . " -bl " . $row['diskspace'] . " -q " . $row['diskspace'] . " " . escapeshellarg(Settings::Get('system.diskquota_customer_partition')));
						}
					}
				}
			}
		}
	}

	/**
	 * @throws Exception
	 */
	private static function rebuildAntiSpamConfigs()
	{
		$antispam = new Rspamd(FroxlorLogger::getInstanceOf());
		$antispam->writeConfigs();
	}

	private static function rebuildMailConfigs()
	{
		if (Settings::Get("system.mail_sni_enabled")) {
			$toBeConfigure = [];
			if (Settings::Get('system.mdaserver') == "dovecot") {
				$toBeConfigure[] = Dovecot::class;
			}
			if (Settings::Get('system.mtaserver') == "postfix") {
				$toBeConfigure[] = Postfix::class;
			}

			foreach ($toBeConfigure as $class_name) {
				$conf = new $class_name();
				$conf->init();
				$conf->createVirtualSSLHost();
				$conf->writeConfigs();
				$conf->reload();
			}
		}
	}
}
