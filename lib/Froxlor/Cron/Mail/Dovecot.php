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

namespace Froxlor\Cron\Mail;

use Froxlor\Cron\Http\DomainSSL;
use Froxlor\Cron\Http\WebserverBase;
use Froxlor\Database\Database;
use Froxlor\FileDir;
use Froxlor\FroxlorLogger;
use Froxlor\Settings;
use PDO;

class Dovecot
{
	private $content = "";

	public function createVirtualSSLHost()
	{
		$domains = WebserverBase::getVhostsToCreate();
		foreach ($domains as $domain) {
			FroxlorLogger::getInstanceOf()->logAction(FroxlorLogger::CRON_ACTION, LOG_INFO, 'dovecot::createVirtualHosts: creating vhost container for domain ' . $domain['id'] . ', customer ' . $domain['loginname']);
			if ($domain['deactivated'] == '0' && $domain['customer_deactivated'] == '0' && $domain['isemaildomain'] == '1'
				&& $domain['ssl_enabled'] == '1' && $domain['ssl'] == '1') {
				$this->content .= $this->getSSLConf($domain);
			}
		}
	}

	private function getSSLConf($domain)
	{
		$query = "SELECT * FROM `" . TABLE_PANEL_IPSANDPORTS . "` `i`, `" . TABLE_DOMAINTOIP . "` `dip`
			WHERE dip.id_domain = :domainid AND i.id = dip.id_ipandports AND i.ssl = '1' ORDER BY i.ssl_cert_file ASC;";

		$result_stmt = Database::prepare($query);
		Database::pexecute($result_stmt, [
			'domainid' => $domain['id']
		]);
		$content = "";
		while ($ipandport = $result_stmt->fetch(PDO::FETCH_ASSOC)) {
			$domain['ssl_cert_file'] = $ipandport['ssl_cert_file'];
			$domain['ssl_key_file'] = $ipandport['ssl_key_file'];
			$domain['ssl_ca_file'] = $ipandport['ssl_ca_file'];
			$domain['ssl_cert_chainfile'] = $ipandport['ssl_cert_chainfile'];

			// SSL STUFF
			$dssl = new DomainSSL();
			// this sets the ssl-related array-indices in the $domain array
			// if the domain has customer-defined ssl-certificates
			$dssl->setDomainSSLFilesArray($domain);

			if($domain['ssl_cert_file'] != '') {
				$content .= 'local_name ' . $domain['domain'] . " {\n";
				$content .= '  ssl_cert = <' . FileDir::makeCorrectFile($domain['ssl_cert_file']) . "\n";

				if ($domain['ssl_key_file'] != '') {
					$content .= '  ssl_key = <' . FileDir::makeCorrectFile($domain['ssl_key_file']) . "\n";
				}
				$content .="}\n";

			}
		}

		return $content;
	}

	public function writeConfigs()
	{
		if($this->content !== "") {
			$vhosts_filename = FileDir::makeCorrectFile(Settings::Get('system.mda_conf_dir') . '99-froxlor-vhost.ssl.conf');
			$vhosts_file = '# ' . basename($vhosts_filename) . "\n" . '# Created ' . date('d.m.Y H:i') . "\n" . '# Do NOT manually edit this file, all changes will be deleted after the next domain change at the panel.' . "\n" . "\n" . $this->content;
			$vhosts_file_handler = fopen($vhosts_filename, 'w');
			fwrite($vhosts_file_handler, $vhosts_file);
			fclose($vhosts_file_handler);
		}
	}

	public function reload()
	{
		if($this->content !== "") {
			FileDir::safe_exec(escapeshellcmd(Settings::Get('system.mda_reload_command')));
		}
	}

	public function init()
	{

	}
}
