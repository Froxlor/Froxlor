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

namespace Froxlor\System;

use Exception;
use Froxlor\Cron\TaskId;
use Froxlor\Database\Database;
use Froxlor\FroxlorLogger;
use Froxlor\Settings;
use PDO;

class Cronjob
{

	/**
	 * Function checkLastGuid
	 *
	 * Checks if the system's last guid is not higher than the one saved
	 * in froxlor's database. If it's higher, froxlor needs to
	 * set its last guid to this one to avoid conflicts with libnss-users
	 *
	 * @return null
	 */
	public static function checkLastGuid()
	{
		$mylog = FroxlorLogger::getInstanceOf();

		$group_lines = [];
		$group_guids = [];
		$update_to_guid = 0;

		$froxlor_guid = 0;
		$result_stmt = Database::query("SELECT MAX(`guid`) as `fguid` FROM `" . TABLE_PANEL_CUSTOMERS . "`");
		$result = $result_stmt->fetch(PDO::FETCH_ASSOC);
		$froxlor_guid = $result['fguid'];

		// possibly no customers yet or f*cked up lastguid settings
		if ($froxlor_guid < Settings::Get('system.lastguid')) {
			$froxlor_guid = Settings::Get('system.lastguid');
		}

		$g_file = '/etc/group';

		if (file_exists($g_file)) {
			if (is_readable($g_file)) {
				if (true == ($groups = file_get_contents($g_file))) {
					$group_lines = explode("\n", $groups);

					foreach ($group_lines as $group) {
						$group_guids[] = explode(":", $group);
					}

					foreach ($group_guids as $group) {
						/**
						 * nogroup | nobody have very high guids
						 * ignore them
						 */
						if ($group[0] == 'nogroup' || $group[0] == 'nobody') {
							continue;
						}

						$guid = isset($group[2]) ? (int)$group[2] : 0;

						if ($guid > $update_to_guid) {
							$update_to_guid = $guid;
						}
					}

					// if it's lower, then froxlor's highest guid is the last
					if ($update_to_guid < $froxlor_guid) {
						$update_to_guid = $froxlor_guid;
					} elseif ($update_to_guid == $froxlor_guid) {
						// if it's equal, that means we already have a collision
						// to ensure it won't happen again, increase the guid by one
						$update_to_guid = (int)$update_to_guid++;
					}

					// now check if it differs from our settings
					if ($update_to_guid != Settings::Get('system.lastguid')) {
						$mylog->logAction(FroxlorLogger::CRON_ACTION, LOG_NOTICE,
							'Updating froxlor last guid to ' . $update_to_guid);
						Settings::Set('system.lastguid', $update_to_guid);
					}
				} else {
					$mylog->logAction(FroxlorLogger::CRON_ACTION, LOG_NOTICE,
						'File /etc/group not readable; cannot check for latest guid');
				}
			} else {
				$mylog->logAction(FroxlorLogger::CRON_ACTION, LOG_NOTICE,
					'File /etc/group not readable; cannot check for latest guid');
			}
		} else {
			$mylog->logAction(FroxlorLogger::CRON_ACTION, LOG_NOTICE,
				'File /etc/group does not exist; cannot check for latest guid');
		}
	}

	/**
	 * Inserts a task into the PANEL_TASKS-Table
	 *
	 * @param int $type Type of task
	 * @param string $params Parameter (possible to pass multiple times)
	 *
	 * @throws Exception
	 * @author Froxlor team <team@froxlor.org> (2010-)
	 */
	public static function inserttask(int $type, ...$params)
	{
		// prepare the insert-statement
		$ins_stmt = Database::prepare("
			INSERT INTO `" . TABLE_PANEL_TASKS . "` SET `type` = :type, `data` = :data
		");

		if ($type == TaskId::REBUILD_VHOST || $type == TaskId::REBUILD_DNS || $type == TaskId::CREATE_FTP || $type == TaskId::REBUILD_RSPAMD || $type == TaskId::CREATE_QUOTA || $type == TaskId::REBUILD_CRON) {
			// 4 = bind -> if bind disabled -> no task
			if ($type == TaskId::REBUILD_DNS && Settings::Get('system.bind_enable') == '0') {
				return;
			}
			// 9 = rspamd -> if antispam disabled -> no task
			if ($type == TaskId::REBUILD_RSPAMD && Settings::Get('antispam.activated') == '0') {
				return;
			}
			// 10 = quota -> if quota disabled -> no task
			if ($type == TaskId::CREATE_QUOTA && Settings::Get('system.diskquota_enabled') == '0') {
				return;
			}

			// delete previously inserted tasks if they are the same as we only need ONE
			$del_stmt = Database::prepare("
				DELETE FROM `" . TABLE_PANEL_TASKS . "` WHERE `type` = :type
			");
			Database::pexecute($del_stmt, [
				'type' => $type
			]);

			// insert the new task
			Database::pexecute($ins_stmt, [
				'type' => $type,
				'data' => ''
			]);
		} elseif ($type == TaskId::CREATE_HOME && count($params) == 4 && $params[0] != '' && $params[1] != '' && $params[2] != '' && ($params[3] == 0 || $params[3] == 1)) {
			$data = [];
			$data['loginname'] = $params[0];
			$data['uid'] = $params[1];
			$data['gid'] = $params[2];
			$data['store_defaultindex'] = $params[3];
			$data = json_encode($data);
			Database::pexecute($ins_stmt, [
				'type' => TaskId::CREATE_HOME,
				'data' => $data
			]);
		} elseif ($type == TaskId::DELETE_CUSTOMER_FILES && isset($params[0]) && $params[0] != '') {
			$data = [];
			$data['loginname'] = $params[0];
			$data = json_encode($data);
			Database::pexecute($ins_stmt, [
				'type' => TaskId::DELETE_CUSTOMER_FILES,
				'data' => $data
			]);
		} elseif ($type == TaskId::REBUILD_MAIL_CONF) {
			$data = [];
			$data = json_encode($data);
			Database::pexecute($ins_stmt, [
				'type' => TaskId::REBUILD_MAIL_CONF,
				'data' => $data
			]);
		} elseif ($type == TaskId::DELETE_EMAIL_DATA && count($params) == 2 && $params[0] != '' && $params[1] != '') {
			$data = [];
			$data['loginname'] = $params[0];
			$data['email'] = $params[1];
			$data = json_encode($data);
			Database::pexecute($ins_stmt, [
				'type' => TaskId::DELETE_EMAIL_DATA,
				'data' => $data
			]);
		} elseif ($type == TaskId::DELETE_FTP_DATA && count($params) == 2 && $params[0] != '' && $params[1] != '') {
			$data = [];
			$data['loginname'] = $params[0];
			$data['homedir'] = $params[1];
			$data = json_encode($data);
			Database::pexecute($ins_stmt, [
				'type' => TaskId::DELETE_FTP_DATA,
				'data' => $data
			]);
		} elseif ($type == TaskId::DELETE_DOMAIN_PDNS && isset($params[0]) && $params[0] != '' && Settings::Get('system.bind_enable') == '1' && Settings::Get('system.dns_server') == 'PowerDNS') {
			// -> if bind disabled or dns-server not PowerDNS -> no task
			$data = [];
			$data['domain'] = $params[0];
			$data = json_encode($data);
			Database::pexecute($ins_stmt, [
				'type' => TaskId::DELETE_DOMAIN_PDNS,
				'data' => $data
			]);
		} elseif ($type == TaskId::DELETE_DOMAIN_SSL && isset($params[0]) && $params[0] != '') {
			$data = [];
			$data['domain'] = $params[0];
			$data = json_encode($data);
			Database::pexecute($ins_stmt, [
				'type' => TaskId::DELETE_DOMAIN_SSL,
				'data' => $data
			]);
		} elseif ($type == TaskId::CREATE_CUSTOMER_DATADUMP && isset($params[0]) && is_array($params[0])) {
			$data = json_encode($params[0]);
			Database::pexecute($ins_stmt, [
				'type' => TaskId::CREATE_CUSTOMER_DATADUMP,
				'data' => $data
			]);
		}
	}

	/**
	 * returns an array of all cronjobs and when they last were executed
	 *
	 * @return array
	 */
	public static function getCronjobsLastRun(): array
	{
		$query = "SELECT `lastrun`, `desc_lng_key` FROM `" . TABLE_PANEL_CRONRUNS . "` WHERE `isactive` = '1' ORDER BY `cronfile` ASC";
		$result = Database::query($query);

		$cronjobs_last_run = [];
		while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
			$cronjobs_last_run[] = [
				'title' => lng('crondesc.' . $row['desc_lng_key']),
				'lastrun' => $row['lastrun']
			];
		}
		return $cronjobs_last_run;
	}

	/**
	 * @param string $module
	 * @param int $isactive
	 * @return void
	 * @throws Exception
	 */
	public static function toggleCronStatus(string $module, int $isactive = 0)
	{
		if ($isactive != 1) {
			$isactive = 0;
		}

		$upd_stmt = Database::prepare("
			UPDATE `" . TABLE_PANEL_CRONRUNS . "` SET `isactive` = :active WHERE `module` = :module
		");
		Database::pexecute($upd_stmt, [
			'active' => $isactive,
			'module' => $module
		]);
	}

	/**
	 * returns an array of tasks that are queued to be run by the cronjob
	 *
	 * @return array
	 */
	public static function getOutstandingTasks(): array
	{
		$query = "SELECT * FROM `" . TABLE_PANEL_TASKS . "` ORDER BY `type` ASC";
		$result = Database::query($query);

		$tasks = [];
		while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
			if ($row['data'] != '') {
				$row['data'] = json_decode($row['data'], true);
			}

			$task_id = $row['type'];
			if (TaskId::isValid($task_id)) {
				$task_constname = TaskId::convertToConstant($task_id);
				$lngParams = [];
				if (is_array($row['data'])) {
					// task includes loginname
					if (isset($row['data']['loginname'])) {
						$lngParams = [$row['data']['loginname']];
					}
					// task includes domain data
					if (isset($row['data']['domain'])) {
						$lngParams = [$row['data']['domain']];
					}
				}
				$task = [
					'desc' => lng('tasks.' . $task_constname, $lngParams)
				];
			} else {
				// unknown
				$task = ['desc' => "ERROR: Unknown task type '" . $row['type'] . "'"];
			}

			$tasks[] = $task;
		}

		if (empty($tasks)) {
			$tasks = [['desc' => lng('tasks.noneoutstanding')]];
		}

		return $tasks;
	}

	/**
	 * Send notification to system admin via email
	 *
	 * @param string $message
	 * @param string $subject
	 *
	 * @return void
	 */
	public static function notifyMailToAdmin(string $message, string $subject = "[froxlor] Important notice")
	{
		$mail = new Mailer(true);
		$mailerror = false;
		$mailerr_msg = "";
		try {
			$mail->Subject = $subject;
			$mail->AltBody = $message;
			$mail->MsgHTML(nl2br($message));
			$mail->AddAddress(Settings::Get('panel.adminmail'), Settings::Get('panel.adminmail_defname'));
			$mail->Send();
		} catch (\PHPMailer\PHPMailer\Exception $e) {
			$mailerr_msg = $e->errorMessage();
			$mailerror = true;
		} catch (Exception $e) {
			$mailerr_msg = $e->getMessage();
			$mailerror = true;
		}

		$mail->ClearAddresses();

		if ($mailerror) {
			echo 'Error sending mail: ' . $mailerr_msg . "\n";
		}
	}

	/**
	 * @param string $cronname
	 * @return void
	 * @throws Exception
	 */
	public static function updateLastRunOfCron(string $cronname)
	{
		$upd_stmt = Database::prepare("
			UPDATE `" . TABLE_PANEL_CRONRUNS . "` SET `lastrun` = UNIX_TIMESTAMP() WHERE `cronfile` = :cron;
		");
		Database::pexecute($upd_stmt, [
			'cron' => $cronname
		]);
	}
}
