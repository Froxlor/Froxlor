<?php

/**
 * This file is part of the Froxlor project.
 * Copyright (c) 2010 the Froxlor Team (see authors).
 *
 * For the full copyright and license information, please view the COPYING
 * file that was distributed with this source code. You can also view the
 * COPYING file online at http://files.froxlor.org/misc/COPYING.txt
 *
 * @copyright  (c) the authors
 * @author     Froxlor team <team@froxlor.org> (2010-)
 * @license    GPLv2 http://files.froxlor.org/misc/COPYING.txt
 * @package    Panel
 *
 */
class PhpSettings extends ApiCommand implements ResourceEntity
{

	/**
	 * lists all php-config entries
	 *
	 * @return array count|list
	 */
	public function list()
	{
		if ($this->isAdmin()) {
			$this->logger()->logAction(ADM_ACTION, LOG_NOTICE, "[API] list php-configs");
			
			$result = Database::query("
				SELECT c.*, fd.description as fpmdesc
				FROM `" . TABLE_PANEL_PHPCONFIGS . "` c
				LEFT JOIN `" . TABLE_PANEL_FPMDAEMONS . "` fd ON fd.id = c.fpmsettingid
				ORDER BY c.description ASC
			");
			
			$phpconfigs = array();
			while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
				
				$domainresult = false;
				$query_params = array(
					'id' => $row['id']
				);
				
				$query = "SELECT * FROM `" . TABLE_PANEL_DOMAINS . "`
					WHERE `phpsettingid` = :id
					AND `parentdomainid` = '0'";
				
				if ((int) $this->getUserDetail('domains_see_all') == 0) {
					$query .= " AND `adminid` = :adminid";
					$query_params['adminid'] = $this->getUserDetail('adminid');
				}
				
				if ((int) Settings::Get('panel.phpconfigs_hidestdsubdomain') == 1) {
					$ssdids_res = Database::query("
					SELECT DISTINCT `standardsubdomain` FROM `" . TABLE_PANEL_CUSTOMERS . "`
					WHERE `standardsubdomain` > 0 ORDER BY `standardsubdomain` ASC;");
					$ssdids = array();
					while ($ssd = $ssdids_res->fetch(PDO::FETCH_ASSOC)) {
						$ssdids[] = $ssd['standardsubdomain'];
					}
					if (count($ssdids) > 0) {
						$query .= " AND `id` NOT IN (" . implode(', ', $ssdids) . ")";
					}
				}
				
				$domains = array();
				$domainresult_stmt = Database::prepare($query);
				Database::pexecute($domainresult_stmt, $query_params, true, true);
				
				if (Database::num_rows() > 0) {
					while ($row2 = $domainresult_stmt->fetch(PDO::FETCH_ASSOC)) {
						$domains[] = $row2['domain'];
					}
				}
				
				// check whether we use that config as froxor-vhost config
				if (Settings::Get('system.mod_fcgid_defaultini_ownvhost') == $row['id'] || Settings::Get('phpfpm.vhost_defaultini') == $row['id']) {
					$domains[] = Settings::Get('system.hostname');
				}
				
				if (empty($domains)) {
					$domains[] = $lng['admin']['phpsettings']['notused'];
				}
				
				// check whether this is our default config
				if ((Settings::Get('system.mod_fcgid') == '1' && Settings::Get('system.mod_fcgid_defaultini') == $row['id']) || (Settings::Get('phpfpm.enabled') == '1' && Settings::Get('phpfpm.defaultini') == $row['id'])) {
					$row['is_default'] = true;
				}
				
				$row['domains'] = $domains;
				$phpconfigs[] = $row;
			}
			
			return $this->response(200, "successfull", array(
				'count' => count($phpconfigs),
				'list' => $phpconfigs
			));
		}
		throw new Exception("Not allowed to execute given command.", 403);
	}

	public function get()
	{
		if ($this->isAdmin()) {
			$id = $this->getParam('id');
			
			$result_stmt = Database::prepare("
				SELECT * FROM `" . TABLE_PANEL_PHPCONFIGS . "` WHERE `id` = :id
			");
			$result = Database::pexecute_first($result_stmt, array(
				'id' => $id
			), true, true);
			if ($result) {
				return $this->response(200, "successfull", $result);
			}
			throw new Exception("php-config with id #" . $id . " could not be found", 404);
		}
		throw new Exception("Not allowed to execute given command.", 403);
	}

	public function add()
	{
		if ($this->isAdmin() && $this->getUserDetail('change_serversettings') == 1) {
			
			// required parameter
			$description = $this->getParam('description');
			$phpsettings = $this->getParam('phpsettings');
			
			if (Settings::Get('system.mod_fcgid') == 1) {
				$binary = $this->getParam('binary');
			} elseif (Settings::Get('phpfpm.enabled') == 1) {
				$fpm_config_id = intval($this->getParam('fpmconfig'));
			}
			
			// parameters
			$file_extensions = $this->getParam('file_extensions', true, 'php');
			$mod_fcgid_starter = $this->getParam('mod_fcgid_starter', true, - 1);
			$mod_fcgid_maxrequests = $this->getParam('mod_fcgid_maxrequests', true, - 1);
			$mod_fcgid_umask = $this->getParam('mod_fcgid_umask', true, "022");
			$fpm_enableslowlog = $this->getParam('phpfpm_enable_slowlog', true, 0);
			$fpm_reqtermtimeout = $this->getParam('phpfpm_reqtermtimeout', true, "60s");
			$fpm_reqslowtimeout = $this->getParam('phpfpm_reqslowtimeout', true, "5s");
			$fpm_pass_authorizationheader = $this->getParam('phpfpm_pass_authorizationheader', true, 0);
			
			// validation
			$description = validate($description, 'description', '', '', array(), true);
			$phpsettings = validate(str_replace("\r\n", "\n", $phpsettings), 'phpsettings', '/^[^\0]*$/', '', array(), true);
			if (Settings::Get('system.mod_fcgid') == 1) {
				$binary = makeCorrectFile(validate($binary, 'binary', '', '', array(), true));
				$file_extensions = validate($file_extensions, 'file_extensions', '/^[a-zA-Z0-9\s]*$/', '', array(), true);
				$mod_fcgid_starter = validate($mod_fcgid_starter, 'mod_fcgid_starter', '/^[0-9]*$/', '', array(
					'-1',
					''
				), true);
				$mod_fcgid_maxrequests = validate($mod_fcgid_maxrequests, 'mod_fcgid_maxrequests', '/^[0-9]*$/', '', array(
					'-1',
					''
				), true);
				$mod_fcgid_umask = validate($mod_fcgid_umask, 'mod_fcgid_umask', '/^[0-9]*$/', '', array(), true);
				// disable fpm stuff
				$fpm_config_id = 1;
				$fpm_enableslowlog = 0;
				$fpm_reqtermtimeout = 0;
				$fpm_reqslowtimeout = 0;
				$fpm_pass_authorizationheader = 0;
			} elseif (Settings::Get('phpfpm.enabled') == 1) {
				$fpm_reqtermtimeout = validate($fpm_reqtermtimeout, 'phpfpm_reqtermtimeout', '/^([0-9]+)(|s|m|h|d)$/', '', array(), true);
				$fpm_reqslowtimeout = validate($fpm_reqslowtimeout, 'phpfpm_reqslowtimeout', '/^([0-9]+)(|s|m|h|d)$/', '', array(), true);
				// disable fcgid stuff
				$binary = '/usr/bin/php-cgi';
				$file_extensions = 'php';
				$mod_fcgid_starter = 0;
				$mod_fcgid_maxrequests = 0;
				$mod_fcgid_umask = "022";
			}
			
			if (strlen($description) == 0 || strlen($description) > 50) {
				standard_error('descriptioninvalid', '', true);
			}
			
			$ins_stmt = Database::prepare("
				INSERT INTO `" . TABLE_PANEL_PHPCONFIGS . "` SET
				`description` = :desc,
				`binary` = :binary,
				`file_extensions` = :fext,
				`mod_fcgid_starter` = :starter,
				`mod_fcgid_maxrequests` = :mreq,
				`mod_fcgid_umask` = :umask,
				`fpm_slowlog` = :fpmslow,
				`fpm_reqterm` = :fpmreqterm,
				`fpm_reqslow` = :fpmreqslow,
				`phpsettings` = :phpsettings,
				`fpmsettingid` = :fpmsettingid,
				`pass_authorizationheader` = :fpmpassauth
			");
			$ins_data = array(
				'desc' => $description,
				'binary' => $binary,
				'fext' => $file_extensions,
				'starter' => $mod_fcgid_starter,
				'mreq' => $mod_fcgid_maxrequests,
				'umask' => $mod_fcgid_umask,
				'fpmslow' => $fpm_enableslowlog,
				'fpmreqterm' => $fpm_reqtermtimeout,
				'fpmreqslow' => $fpm_reqslowtimeout,
				'phpsettings' => $phpsettings,
				'fpmsettingid' => $fpm_config_id,
				'fpmpassauth' => $fpm_pass_authorizationheader
			);
			Database::pexecute($ins_stmt, $ins_data, true, true);
			$ins_data['id'] = Database::lastInsertId();
			
			inserttask('1');
			$this->logger()->logAction(ADM_ACTION, LOG_INFO, "[API] php setting with description '" . $description . "' has been created by '" . $this->getUserDetail('loginname') . "'");
			return $this->response(200, "successfull", $ins_data);
		}
		throw new Exception("Not allowed to execute given command.", 403);
	}

	public function update()
	{
		if ($this->isAdmin() && $this->getUserDetail('change_serversettings') == 1) {
			
			// required parameter
			$id = $this->getParam('id');
			
			$json_result = PhpSettings::getLocal($this->getUserData(), array(
				'id' => $id
			))->get();
			$result = json_decode($json_result, true)['data'];
			
			// parameters
			$description = $this->getParam('description', true, $result['description']);
			$phpsettings = $this->getParam('phpsettings', true, $result['phpsettings']);
			$binary = $this->getParam('binary', true, $result['binary']);
			$fpm_config_id = intval($this->getParam('fpmconfig', true, $result['fpmsettingid']));
			$file_extensions = $this->getParam('file_extensions', true, $result['file_extensions']);
			$mod_fcgid_starter = $this->getParam('mod_fcgid_starter', true, $result['mod_fcgid_starter']);
			$mod_fcgid_maxrequests = $this->getParam('mod_fcgid_maxrequests', true, $result['mod_fcgid_maxrequests']);
			$mod_fcgid_umask = $this->getParam('mod_fcgid_umask', true, $result['mod_fcgid_umask']);
			$fpm_enableslowlog = $this->getParam('phpfpm_enable_slowlog', true, $result['fpm_slowlog']);
			$fpm_reqtermtimeout = $this->getParam('phpfpm_reqtermtimeout', true, $result['fpm_reqterm']);
			$fpm_reqslowtimeout = $this->getParam('phpfpm_reqslowtimeout', true, $result['fpm_reqslow']);
			$fpm_pass_authorizationheader = $this->getParam('phpfpm_pass_authorizationheader', true, $result['pass_authorizationheader']);
			
			// validation
			$description = validate($description, 'description', '', '', array(), true);
			$phpsettings = validate(str_replace("\r\n", "\n", $phpsettings), 'phpsettings', '/^[^\0]*$/', '', array(), true);
			if (Settings::Get('system.mod_fcgid') == 1) {
				$binary = makeCorrectFile(validate($binary, 'binary', '', '', array(), true));
				$file_extensions = validate($file_extensions, 'file_extensions', '/^[a-zA-Z0-9\s]*$/', '', array(), true);
				$mod_fcgid_starter = validate($mod_fcgid_starter, 'mod_fcgid_starter', '/^[0-9]*$/', '', array(
					'-1',
					''
				), true);
				$mod_fcgid_maxrequests = validate($mod_fcgid_maxrequests, 'mod_fcgid_maxrequests', '/^[0-9]*$/', '', array(
					'-1',
					''
				), true);
				$mod_fcgid_umask = validate($mod_fcgid_umask, 'mod_fcgid_umask', '/^[0-9]*$/', '', array(), true);
				// disable fpm stuff
				$fpm_config_id = 1;
				$fpm_enableslowlog = 0;
				$fpm_reqtermtimeout = 0;
				$fpm_reqslowtimeout = 0;
				$fpm_pass_authorizationheader = 0;
			} elseif (Settings::Get('phpfpm.enabled') == 1) {
				$fpm_reqtermtimeout = validate($fpm_reqtermtimeout, 'phpfpm_reqtermtimeout', '/^([0-9]+)(|s|m|h|d)$/', '', array(), true);
				$fpm_reqslowtimeout = validate($fpm_reqslowtimeout, 'phpfpm_reqslowtimeout', '/^([0-9]+)(|s|m|h|d)$/', '', array(), true);
				// disable fcgid stuff
				$binary = '/usr/bin/php-cgi';
				$file_extensions = 'php';
				$mod_fcgid_starter = 0;
				$mod_fcgid_maxrequests = 0;
				$mod_fcgid_umask = "022";
			}
			
			if (strlen($description) == 0 || strlen($description) > 50) {
				standard_error('descriptioninvalid', '', true);
			}
			
			$upd_stmt = Database::prepare("
				UPDATE `" . TABLE_PANEL_PHPCONFIGS . "` SET
				`description` = :desc,
				`binary` = :binary,
				`file_extensions` = :fext,
				`mod_fcgid_starter` = :starter,
				`mod_fcgid_maxrequests` = :mreq,
				`mod_fcgid_umask` = :umask,
				`fpm_slowlog` = :fpmslow,
				`fpm_reqterm` = :fpmreqterm,
				`fpm_reqslow` = :fpmreqslow,
				`phpsettings` = :phpsettings,
				`fpmsettingid` = :fpmsettingid,
				`pass_authorizationheader` = :fpmpassauth
				WHERE `id` = :id
			");
			$upd_data = array(
				'desc' => $description,
				'binary' => $binary,
				'fext' => $file_extensions,
				'starter' => $mod_fcgid_starter,
				'mreq' => $mod_fcgid_maxrequests,
				'umask' => $mod_fcgid_umask,
				'fpmslow' => $fpm_enableslowlog,
				'fpmreqterm' => $fpm_reqtermtimeout,
				'fpmreqslow' => $fpm_reqslowtimeout,
				'phpsettings' => $phpsettings,
				'fpmsettingid' => $fpm_config_id,
				'fpmpassauth' => $fpm_pass_authorizationheader,
				'id' => $id
			);
			Database::pexecute($upd_stmt, $upd_data, true, true);
			
			inserttask('1');
			$this->logger()->logAction(ADM_ACTION, LOG_INFO, "[API] php setting with description '" . $description . "' has been updated by '" . $this->getUserDetail('loginname') . "'");
			return $this->response(200, "successfull", $upd_data);
		}
		throw new Exception("Not allowed to execute given command.", 403);
	}

	public function delete()
	{
		if ($this->isAdmin() && $this->getUserDetail('change_serversettings') == 1) {
			$id = $this->getParam('id');
			
			$json_result = PhpSettings::getLocal($this->getUserData(), array(
				'id' => $id
			))->get();
			$result = json_decode($json_result, true)['data'];
			
			if ((Settings::Get('system.mod_fcgid') == '1' && Settings::Get('system.mod_fcgid_defaultini_ownvhost') == $id) || (Settings::Get('phpfpm.enabled') == '1' && Settings::Get('phpfpm.vhost_defaultini') == $id)) {
				standard_error('cannotdeletehostnamephpconfig', '', true);
			}
			
			if ((Settings::Get('system.mod_fcgid') == '1' && Settings::Get('system.mod_fcgid_defaultini') == $id) || (Settings::Get('phpfpm.enabled') == '1' && Settings::Get('phpfpm.defaultini') == $id)) {
				standard_error('cannotdeletedefaultphpconfig', '', true);
			}
			
			// set php-config to default for all domains using the
			// config that is to be deleted
			$upd_stmt = Database::prepare("
				UPDATE `" . TABLE_PANEL_DOMAINS . "` SET
				`phpsettingid` = '1' WHERE `phpsettingid` = :id
			");
			Database::pexecute($upd_stmt, array(
				'id' => $id
			), true, true);
			
			$del_stmt = Database::prepare("
				DELETE FROM `" . TABLE_PANEL_PHPCONFIGS . "` WHERE `id` = :id
			");
			Database::pexecute($del_stmt, array(
				'id' => $id
			), true, true);
			
			inserttask('1');
			$this->logger()->logAction(ADM_ACTION, LOG_INFO, "[API] php setting '" . $result['description'] . "' has been deleted by '" . $this->getUserDetail('loginname') . "'");
			return $this->response(200, "successfull", $result);
		}
		throw new Exception("Not allowed to execute given command.", 403);
	}
}
