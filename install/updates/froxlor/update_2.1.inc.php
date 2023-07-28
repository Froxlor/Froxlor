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

use Froxlor\Database\Database;
use Froxlor\FileDir;
use Froxlor\Froxlor;
use Froxlor\Install\Update;
use Froxlor\Settings;

if (!defined('_CRON_UPDATE')) {
	if (!defined('AREA') || (defined('AREA') && AREA != 'admin') || !isset($userinfo['loginname']) || (isset($userinfo['loginname']) && $userinfo['loginname'] == '')) {
		header('Location: ../../../../index.php');
		exit();
	}
}

if (Froxlor::isDatabaseVersion('202304260')) {
	Update::showUpdateStep("Cleaning domains table");
	Database::query("ALTER TABLE `" . TABLE_PANEL_DOMAINS . "` DROP COLUMN `ismainbutsubto`;");
	Update::lastStepStatus(0);

	Update::showUpdateStep("Creating new tables and fields");
	Database::query("DROP TABLE IF EXISTS `panel_loginlinks`;");
	$sql = "CREATE TABLE `panel_loginlinks` (
	  `hash` varchar(500) NOT NULL,
	  `loginname` varchar(50) NOT NULL,
	  `valid_until` int(15) NOT NULL,
	  `allowed_from` text NOT NULL,
	  UNIQUE KEY `loginname` (`loginname`)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;";
	Database::query($sql);
	Update::lastStepStatus(0);

	Update::showUpdateStep("Adjusting setting for deactivated webroot");
	$current_deactivated_webroot = Settings::Get('system.deactivateddocroot');
	if (empty($current_deactivated_webroot)) {
		Settings::Set('system.deactivateddocroot', FileDir::makeCorrectDir(Froxlor::getInstallDir() . '/templates/misc/deactivated/'));
		Update::lastStepStatus(0);
	} else {
		Update::lastStepStatus(1, 'Customized setting, not changing');
	}

	Update::showUpdateStep("Creating new tables and fields for backups");
	Database::query("DROP TABLE IF EXISTS `". TABLE_PANEL_BACKUP_STORAGES ."`;");
	$sql = "CREATE TABLE `". TABLE_PANEL_BACKUP_STORAGES ."` (
	  `id` int(11) NOT NULL AUTO_INCREMENT,
	  `description` varchar(255) NOT NULL,
	  `type` varchar(255) NOT NULL DEFAULT 'local',
	  `region` varchar(255) NULL,
	  `bucket` varchar(255) NULL,
	  `destination_path` varchar(255) NOT NULL,
	  `hostname` varchar(255) NULL,
	  `username` varchar(255) NULL,
	  `password` text,
	  `pgp_public_key` text,
	  `retention` int(3) NOT NULL DEFAULT 3,
	  PRIMARY KEY (`id`)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;";
	Database::query($sql);
	Database::query("
		INSERT INTO `panel_backup_storages` (`id`, `description`, `destination_path`) VALUES
		(1, 'Local backup storage', '/var/customers/backups');
	");
	Database::query("DROP TABLE IF EXISTS `". TABLE_PANEL_BACKUPS ."`;");
	$sql = "CREATE TABLE `". TABLE_PANEL_BACKUPS ."` (
	  `id` int(11) NOT NULL AUTO_INCREMENT,
	  `adminid` int(11) NOT NULL,
	  `customerid` int(11) NOT NULL,
	  `loginname` varchar(255) NOT NULL,
	  `size` bigint(20) NOT NULL,
	  `storage_id` int(11) NOT NULL,
	  `filename` varchar(255) NOT NULL,
	  `created_at` int(15) NOT NULL,
	  PRIMARY KEY (`id`)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;";
	Database::query($sql);
	// add customer backup-target-storage
	Database::query("ALTER TABLE `" . TABLE_PANEL_CUSTOMERS . "` ADD `backup` int(11) NOT NULL default '1' AFTER `allowed_mysqlserver`;");
	Database::query("ALTER TABLE `" . TABLE_PANEL_CUSTOMERS . "` ADD `access_backups` tinyint(1) NOT NULL default '1' AFTER `backup`;");
	Update::lastStepStatus(0);

	Update::showUpdateStep("Adding new backup settings");
	Settings::AddNew('backup.enabled', 0);
	Settings::AddNew('backup.default_storage', 1);
	Settings::AddNew('backup.default_customer_access', 1);
	Settings::AddNew('backup.default_pgp_public_key', '');
	Settings::AddNew('backup.default_retention', 3);
	Update::lastStepStatus(0);

	Update::showUpdateStep("Adjusting cronjobs");
	Database::query("
        UPDATE `" . TABLE_PANEL_CRONRUNS . "` SET
        `module`= 'froxlor/export',
        `cronfile` = 'export',
        `cronclass` = '\\Froxlor\\Cron\\System\\ExportCron',
        `interval` = '1 HOUR',
        `desc_lng_key` = 'cron_export'
        WHERE `module` = 'froxlor/backup'
    ");
	Database::query("
        INSERT INTO `" . TABLE_PANEL_CRONRUNS . "` SET
        `module`= 'froxlor/backup',
        `cronfile` = 'backup',
        `cronclass` = '\\Froxlor\\Cron\\Backup\\BackupCron',
        `interval` = '1 DAY',
        `isactive` = '0',
        `desc_lng_key` = 'cron_backup'
    ");
	Update::lastStepStatus(0);

	Update::showUpdateStep("Adjusting system for data-export function");
	Database::query("UPDATE `" . TABLE_PANEL_SETTINGS . "`SET `varname` = 'exportenabled' WHERE `settinggroup`= 'system' AND `varname`= 'backupenabled");
	Database::query("UPDATE `" . TABLE_PANEL_SETTINGS . "`SET `value` = REPLACE(`value`, 'extras.backup', 'extras.export') WHERE `settinggroup` = 'panel' AND `varname` = 'customer_hide_options'");
	Database::query("DELETE FROM `" . TABLE_PANEL_USERCOLUMNS . "` WHERE `section` = 'backup_list'");
	Database::query("DELETE FROM `" . TABLE_PANEL_TASKS . "` WHERE `type` = '20'");
	Update::lastStepStatus(0);

	Froxlor::updateToDbVersion('202305240');
}
