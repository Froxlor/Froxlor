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

namespace Froxlor\Cron;

use ReflectionClass;

final class TaskId
{
	/**
	 * TYPE=1 MEANS TO REBUILD APACHE VHOSTS.CONF
	 */
	const REBUILD_VHOST = 1;

	/**
	 * TYPE=2 MEANS TO CREATE A NEW HOME AND CHOWN
	 */
	const CREATE_HOME = 2;

	/**
	 * TYPE=4 MEANS THAT SOMETHING IN THE DNS CONFIG HAS CHANGED.
	 * REBUILD froxlor_bind.conf IF BIND IS ENABLED, UPDATE DKIM KEYS
	 */
	const REBUILD_DNS = 4;

	/**
	 * TYPE=5 MEANS THAT A NEW FTP-ACCOUNT HAS BEEN CREATED, CREATE THE DIRECTORY
	 */
	const CREATE_FTP = 5;

	/**
	 * TYPE=6 MEANS THAT A CUSTOMER HAS BEEN DELETED AND THAT WE HAVE TO REMOVE ITS FILES
	 */
	const DELETE_CUSTOMER_FILES = 6;

	/**
	 * TYPE=7 Customer deleted an email account and wants the data to be deleted on the filesystem
	 */
	const DELETE_EMAIL_DATA = 7;

	/**
	 * TYPE=8 Customer deleted a ftp account and wants the homedir to be deleted on the filesystem
	 * refs #293
	 */
	const DELETE_FTP_DATA = 8;

	/**
	 * TYPE=9 MEANS THAT SOMETHING ANTISPAM RELATED HAS CHANGED.
	 * REBUILD froxlor_settings.conf IF ANTISPAM IS ENABLED
	 */
	const REBUILD_RSPAMD = 9;

	/**
	 * TYPE=10 Set the filesystem - quota
	 */
	const CREATE_QUOTA = 10;

	/**
	 * TYPE=11 domain has been deleted, remove from pdns database if used
	 */
	const DELETE_DOMAIN_PDNS = 11;

	/**
	 * TYPE=12 domain has been deleted, remove from acme.sh/let's encrypt directory if used
	 */
	const DELETE_DOMAIN_SSL = 12;

	/**
	 * TYPE=13 rebuild mail config
	 */
	const REBUILD_MAIL_CONF = 13;

	/**
	 * TYPE=20 CUSTUMER DATA DUMP
	 */
	const CREATE_CUSTOMER_DATADUMP = 20;

	/**
	 * TYPE=99 REGENERATE CRON
	 */
	const REBUILD_CRON = 99;

	/**
	 * Return if a cron task id is valid
	 *
	 * @param int|string $id cron task id (legacy string support)
	 * @return boolean
	 */
	public static function isValid($id)
	{
		static $reflContants;
		if (!is_numeric($id)) {
			return false;
		}
		$numericid = (int)$id;
		if (!is_array($reflContants)) {
			$reflClass = new ReflectionClass(get_called_class());
			$reflContants = $reflClass->getConstants();
		}
		return in_array($numericid, $reflContants, true);
	}

	/**
	 * Get constant name by id
	 *
	 * @param int|string $id cron task id (legacy string support)
	 * @return string|false constant name or false if not found
	 */
	public static function convertToConstant($id)
	{
		static $reflContants;
		if (!is_numeric($id)) {
			return false;
		}
		$numericid = (int)$id;
		if (!is_array($reflContants)) {
			$reflClass = new ReflectionClass(get_called_class());
			$reflContants = $reflClass->getConstants();
		}
		return array_search($numericid, $reflContants, true);
	}
}
