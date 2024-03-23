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

namespace Froxlor\Cron\Http\LetsEncrypt;

use Froxlor\Cron\FroxlorCron;
use Froxlor\Cron\TaskId;
use Froxlor\Database\Database;
use Froxlor\Domain\Domain;
use Froxlor\FileDir;
use Froxlor\Froxlor;
use Froxlor\FroxlorLogger;
use Froxlor\PhpHelper;
use Froxlor\Settings;
use Froxlor\System\Cronjob;
use Froxlor\Validate\Validate;
use PDO;
use PDOStatement;

class AcmeSh extends FroxlorCron
{

	const ACME_PROVIDER = [
		'letsencrypt' => "https://acme-v02.api.letsencrypt.org/directory",
		'letsencrypt_test' => "https://acme-staging-v02.api.letsencrypt.org/directory",
		'buypass' => "https://api.buypass.com/acme/directory",
		'buypass_test' => "https://api.test4.buypass.no/acme/directory",
		'zerossl' => "https://acme.zerossl.com/v2/DV90",
		'google' => "https://dv.acme-v02.api.pki.goog/directory",
		'google_test' => "https://dv.acme-v02.test-api.pki.goog/directory",
	];
	public static $no_inserttask = false;
	private static $apiserver = "";
	private static $acmesh = "/root/.acme.sh/acme.sh";
	/**
	 *
	 * @var PDOStatement
	 */
	private static $updcert_stmt = null;
	/**
	 *
	 * @var PDOStatement
	 */
	private static $upddom_stmt = null;

	/**
	 * run the task
	 *
	 * @param bool $internal
	 * @return number
	 */
	public static function run(bool $internal = false)
	{
		// usually, this is action is called from within the tasks-jobs
		if (!defined('CRON_IS_FORCED') && !defined('CRON_DEBUG_FLAG') && $internal == false) {
			// Let's Encrypt cronjob is combined with regeneration of webserver configuration files.
			// For debugging purposes you can use the --debug switch and the --force switch to run the cron manually.
			// check whether we MIGHT need to run although there is no task to regenerate config-files
			$issue_froxlor = self::issueFroxlorVhost();
			$issue_domains = self::issueDomains();
			$renew_froxlor = self::renewFroxlorVhost();
			$renew_domains = self::renewDomains(true);
			if ($issue_froxlor || !empty($issue_domains) || !empty($renew_froxlor) || $renew_domains) {
				// insert task to generate certificates and vhost-configs
				Cronjob::inserttask(TaskId::REBUILD_VHOST);
				Cronjob::inserttask(TaskId::REBUILD_MAIL_CONF);
			}
			return 0;
		}

		// set server according to settings
		self::$apiserver = self::ACME_PROVIDER[Settings::Get('system.letsencryptca')];

		// validate acme.sh installation
		if (!self::checkInstall()) {
			return -1;
		}

		self::checkUpgrade();

		// flag for re-generation of vhost files
		$changedetected = 0;

		// prepare update sql
		self::$updcert_stmt = Database::prepare("
			REPLACE INTO
				`" . TABLE_PANEL_DOMAIN_SSL_SETTINGS . "`
			SET
				`id` = :id,
				`domainid` = :domainid,
				`ssl_cert_file` = :crt,
				`ssl_key_file` = :key,
				`ssl_ca_file` = :ca,
				`ssl_cert_chainfile` = :chain,
				`ssl_csr_file` = :csr,
				`ssl_fullchain_file` = :fullchain,
				`validfromdate` = :validfromdate,
				`validtodate` = :validtodate,
				`issuer` = :issuer
		");

		// prepare domain update sql
		self::$upddom_stmt = Database::prepare("UPDATE `" . TABLE_PANEL_DOMAINS . "` SET `ssl_redirect` = '1' WHERE `id` = :domainid");

		// check whether there are certificates to issue
		$issue_froxlor = self::issueFroxlorVhost();
		$issue_domains = self::issueDomains();

		// first - generate LE for system-vhost if enabled
		if ($issue_froxlor) {
			// build row
			$certrow = [
				'loginname' => 'froxlor.panel',
				'domain' => Settings::Get('system.hostname'),
				'domainid' => 0,
				'documentroot' => Froxlor::getInstallDir(),
				'leprivatekey' => Settings::Get('system.leprivatekey'),
				'lepublickey' => Settings::Get('system.lepublickey'),
				'leregistered' => Settings::Get('system.leregistered'),
				'ssl_redirect' => Settings::Get('system.le_froxlor_redirect'),
				'validfromdate' => null,
				'validtodate' => null,
				'issuer' => "",
				'ssl_cert_file' => null,
				'ssl_key_file' => null,
				'ssl_ca_file' => null,
				'ssl_csr_file' => null,
				'id' => null,
				'wwwserveralias' => 0
			];

			// add to queue
			$issue_domains[] = $certrow;
		}

		if (count($issue_domains)) {
			FroxlorLogger::getInstanceOf()->logAction(FroxlorLogger::CRON_ACTION, LOG_INFO, "Requesting " . count($issue_domains) . " new Let's Encrypt certificates");
			self::runIssueFor($issue_domains);
			$changedetected = 1;
		}

		// compare file-system certificates with the ones in our database
		// and update if needed
		$renew_froxlor = self::renewFroxlorVhost();
		$renew_domains = self::renewDomains();

		if ($renew_froxlor) {
			// build row
			$certrow = [
				'loginname' => 'froxlor.panel',
				'domain' => Settings::Get('system.hostname'),
				'domainid' => 0,
				'documentroot' => Froxlor::getInstallDir(),
				'leprivatekey' => Settings::Get('system.leprivatekey'),
				'lepublickey' => Settings::Get('system.lepublickey'),
				'leregistered' => Settings::Get('system.leregistered'),
				'ssl_redirect' => Settings::Get('system.le_froxlor_redirect'),
				'validfromdate' => is_array($renew_froxlor) ? $renew_froxlor['validfromdate'] : date('Y-m-d H:i:s', 0),
				'validtodate' => is_array($renew_froxlor) ? $renew_froxlor['validtodate'] : date('Y-m-d H:i:s', 0),
				'issuer' => is_array($renew_froxlor) ? $renew_froxlor['issuer'] : "",
				'ssl_cert_file' => is_array($renew_froxlor) ? $renew_froxlor['ssl_cert_file'] : null,
				'ssl_key_file' => is_array($renew_froxlor) ? $renew_froxlor['ssl_key_file'] : null,
				'ssl_ca_file' => is_array($renew_froxlor) ? $renew_froxlor['ssl_ca_file'] : null,
				'ssl_csr_file' => is_array($renew_froxlor) ? $renew_froxlor['ssl_csr_file'] : null,
				'id' => is_array($renew_froxlor) ? $renew_froxlor['id'] : null,
				'wwwserveralias' => 0
			];
			$renew_domains[] = $certrow;
		}

		foreach ($renew_domains as $domain) {
			$cronlog = FroxlorLogger::getInstanceOf([
				'loginname' => $domain['loginname'],
				'adminsession' => 0
			]);
			if (defined('CRON_IS_FORCED') || self::checkFsFilesAreNewer($domain['domain'], $domain['validtodate'])) {
				self::certToDb($domain, $cronlog, []);
				$changedetected = 1;
			}
		}

		// If we have a change in a certificate, we need to update the webserver - configs
		// This is easiest done by just creating a new task ;)
		if ($changedetected) {
			if (self::$no_inserttask == false) {
				Cronjob::inserttask(TaskId::REBUILD_VHOST);
				Cronjob::inserttask(TaskId::REBUILD_MAIL_CONF);
			}
			FroxlorLogger::getInstanceOf()->logAction(FroxlorLogger::CRON_ACTION, LOG_INFO, "Let's Encrypt certificates have been updated");
		} else {
			FroxlorLogger::getInstanceOf()->logAction(FroxlorLogger::CRON_ACTION, LOG_INFO, "No new certificates or certificate updates found");
		}
		return 0;
	}

	/**
	 * check whether we need to issue a new certificate for froxlor itself
	 *
	 * @return boolean
	 */
	private static function issueFroxlorVhost()
	{
		if (Settings::Get('system.le_froxlor_enabled') == '1') {
			// let's encrypt is enabled, now check whether we have a certificate
			$froxlor_ssl_settings_stmt = Database::prepare("
				SELECT * FROM `" . TABLE_PANEL_DOMAIN_SSL_SETTINGS . "`
				WHERE `domainid` = '0'
			");
			$froxlor_ssl = Database::pexecute_first($froxlor_ssl_settings_stmt);
			// also check for possible existing certificate
			if (($froxlor_ssl && empty($froxlor_ssl['validtodate']))
				|| (!$froxlor_ssl && !self::checkFsFilesAreNewer(Settings::Get('system.hostname'), date('Y-m-d H:i:s')))
			) {
				return true;
			}
		}
		return false;
	}

	private static function checkFsFilesAreNewer($domain, $cert_date = 0): bool
	{
		$certificate_folder = self::getCertificateFolder(strtolower($domain));
		if (empty($certificate_folder)) {
			return false;
		}
		$ssl_file = FileDir::makeCorrectFile($certificate_folder . '/' . strtolower($domain) . '.cer');

		if (is_dir($certificate_folder) && file_exists($ssl_file) && is_readable($ssl_file)) {
			$cert_data = openssl_x509_parse(file_get_contents($ssl_file));
			if ($cert_data && $cert_data['validTo_time_t'] > strtotime($cert_date)) {
				return true;
			}
		}
		return false;
	}

	public static function getWorkingDirFromEnv($domain = "", $forced_ecc = false): string
	{
		// first try without _ecc either if it's enabled currently or not as
		// it might have been at some point so there is a chance we have certificates
		// with and without _ecc - the method getCertificateFolder() will check both
		// possibilities
		if ($forced_ecc) {
			$domain .= "_ecc";
		}
		$env_file = FileDir::makeCorrectFile(dirname(self::getAcmeSh()) . '/acme.sh.env');
		if (file_exists($env_file)) {
			$output = [];
			$cut = <<<EOC
cut -d'"' -f2
EOC;
			exec('grep "LE_WORKING_DIR" ' . escapeshellarg($env_file) . ' | ' . $cut, $output);
			if (is_array($output) && !empty($output) && !empty($output[0])) {
				return FileDir::makeCorrectDir($output[0] . "/" . $domain);
			}
		}
		return FileDir::makeCorrectDir(dirname(self::getAcmeSh()) . "/" . $domain);
	}

	public static function getAcmeSh()
	{
		$from_settings = Settings::Get('system.acmeshpath');
		if (file_exists($from_settings)) {
			return $from_settings;
		}
		return self::$acmesh;
	}

	/**
	 * get a list of domains that require a new certificate (issue)
	 */
	private static function issueDomains()
	{
		$certificates_stmt = Database::query("
			SELECT
				domssl.`id`,
				domssl.`domainid`,
				domssl.`validfromdate`,
				domssl.`validtodate`,
				domssl.`issuer`,
				domssl.`ssl_cert_file`,
				domssl.`ssl_key_file`,
				domssl.`ssl_ca_file`,
				domssl.`ssl_csr_file`,
				dom.`domain`,
				dom.`wwwserveralias`,
				dom.`documentroot`,
				dom.`id` AS 'domainid',
				dom.`ssl_redirect`,
				cust.`leprivatekey`,
				cust.`lepublickey`,
				cust.`leregistered`,
				cust.`customerid`,
				cust.`loginname`
			FROM
				`" . TABLE_PANEL_CUSTOMERS . "` AS cust,
				`" . TABLE_PANEL_DOMAINS . "` AS dom
			LEFT JOIN
				`" . TABLE_PANEL_DOMAIN_SSL_SETTINGS . "` AS domssl ON
					dom.`id` = domssl.`domainid`
			WHERE
				dom.`customerid` = cust.`customerid`
				AND cust.deactivated = 0
				AND dom.`ssl_enabled` = 1
				AND dom.`letsencrypt` = 1
				AND dom.`aliasdomain` IS NULL
				AND dom.`iswildcarddomain` = 0
				AND domssl.`validtodate` IS NULL
		");
		$customer_ssl = $certificates_stmt->fetchAll(PDO::FETCH_ASSOC);
		if ($customer_ssl) {
			return $customer_ssl;
		}
		return [];
	}

	/**
	 * check whether we need to renew-check the certificate for froxlor itself
	 *
	 * @return boolean
	 */
	private static function renewFroxlorVhost()
	{
		if (Settings::Get('system.le_froxlor_enabled') == '1') {
			// let's encrypt is enabled, now check whether we have a certificate
			$froxlor_ssl_settings_stmt = Database::prepare("
				SELECT * FROM `" . TABLE_PANEL_DOMAIN_SSL_SETTINGS . "`
				WHERE `domainid` = '0'
			");
			$froxlor_ssl = Database::pexecute_first($froxlor_ssl_settings_stmt);
			// also check for possible existing certificate
			if ($froxlor_ssl && self::checkFsFilesAreNewer(Settings::Get('system.hostname'), $froxlor_ssl['validtodate'])) {
				return $froxlor_ssl;
			}
		}
		return false;
	}

	/**
	 * get a list of domains that have a lets encrypt certificate (possible renew)
	 */
	private static function renewDomains($check = false)
	{
		$certificates_stmt = Database::query("
			SELECT
				domssl.`id`,
				domssl.`domainid`,
				domssl.`validfromdate`,
				domssl.`validtodate`,
				domssl.`issuer`,
				domssl.`ssl_cert_file`,
				domssl.`ssl_key_file`,
				dom.`domain`,
				dom.`id` AS 'domainid',
				dom.`ssl_redirect`,
				cust.`loginname`
			FROM
				`" . TABLE_PANEL_CUSTOMERS . "` AS cust,
				`" . TABLE_PANEL_DOMAINS . "` AS dom
			LEFT JOIN
				`" . TABLE_PANEL_DOMAIN_SSL_SETTINGS . "` AS domssl ON
					dom.`id` = domssl.`domainid`
			WHERE
				dom.`customerid` = cust.`customerid`
				AND cust.deactivated = 0
				AND dom.`ssl_enabled` = 1
				AND dom.`letsencrypt` = 1
				AND dom.`aliasdomain` IS NULL
				AND dom.`iswildcarddomain` = 0
		");
		$renew_certs = $certificates_stmt->fetchAll(PDO::FETCH_ASSOC);
		if ($renew_certs) {
			if ($check) {
				foreach ($renew_certs as $cert) {
					if (self::checkFsFilesAreNewer($cert['domain'], $cert['validtodate'])) {
						return true;
					}
				}
				return false;
			}
			return $renew_certs;
		}
		return [];
	}

	/**
	 * install acme.sh if not found yet
	 */
	private static function checkInstall($tries = 0)
	{
		if (!file_exists(self::getAcmeSh()) && $tries > 0) {
			FroxlorLogger::getInstanceOf()->logAction(FroxlorLogger::CRON_ACTION, LOG_ERR, "Download/installation of acme.sh seems to have failed. Re-run cronjob to try again or install manually to '" . self::getAcmeSh() . "'");
			echo PHP_EOL . "Download/installation of acme.sh seems to have failed. Re-run cronjob to try again or install manually to '" . self::getAcmeSh() . "'" . PHP_EOL;
			return false;
		} else {
			if (!file_exists(self::getAcmeSh())) {
				FroxlorLogger::getInstanceOf()->logAction(FroxlorLogger::CRON_ACTION, LOG_INFO, "Could not find acme.sh - installing it to /root/.acme.sh/");
				$return = false;
				FileDir::safe_exec("wget -O - https://get.acme.sh | sh -s email=" . Settings::Get('panel.adminmail'), $return, [
					'|'
				]);
				$set_path = self::getAcmeSh();
				// after this, regardless of what the user specified, the acme.sh installation will be in /root/.acme.sh
				if ($set_path != '/root/.acme.sh/acme.sh') {
					Settings::Set('system.acmeshpath', '/root/.acme.sh/acme.sh', true);
					// let the user know
					FroxlorLogger::getInstanceOf()->logAction(FroxlorLogger::CRON_ACTION, LOG_WARNING, "Acme.sh could not be found in '" . $set_path . "' so froxlor installed it to the default location, which is '/root/.acme.sh/'");
					echo PHP_EOL . "Acme.sh could not be found in '" . $set_path . "' so froxlor installed it to the default location, which is '/root/.acme.sh/'" . PHP_EOL;
				}
				// check whether the installation worked
				return self::checkInstall(++$tries);
			}
		}
		return true;
	}

	/**
	 * run upgrade
	 */
	private static function checkUpgrade()
	{
		$acmesh_result = FileDir::safe_exec(self::getAcmeSh() . " --upgrade --auto-upgrade 0");
		// check for activated cron
		$acmesh_result2 = FileDir::safe_exec(self::getAcmeSh() . " --install-cronjob");
		// set default CA
		$acmesh_result3 = FileDir::safe_exec(self::getAcmeSh() . " --set-default-ca --server " . self::$apiserver);
		// log messages
		FroxlorLogger::getInstanceOf()->logAction(FroxlorLogger::CRON_ACTION, LOG_INFO, "Checking for LetsEncrypt client upgrades before renewing certificates:\n" . implode("\n", $acmesh_result) . "\n" . implode("\n", $acmesh_result2) . "\n" . implode("\n", $acmesh_result3));
	}

	/**
	 * issue certificates for a list of domains
	 */
	private static function runIssueFor($certrows = [])
	{
		// prepare aliasdomain-check
		$aliasdomains_stmt = Database::prepare("
			SELECT
				dom.`id` as domainid,
				dom.`domain`,
				dom.`wwwserveralias`
			FROM `" . TABLE_PANEL_DOMAINS . "` AS dom
			WHERE
				dom.`aliasdomain` = :id
				AND dom.`letsencrypt` = 1
				AND dom.`iswildcarddomain` = 0
		");
		// iterate through all domains
		foreach ($certrows as $certrow) {
			// set logger to corresponding loginname for the log to appear in the users system-log
			$cronlog = FroxlorLogger::getInstanceOf([
				'loginname' => $certrow['loginname'],
				'adminsession' => 0
			]);
			// Only issue let's encrypt certificate if no broken ssl_redirect is enabled
			if ($certrow['ssl_redirect'] != 2) {
				$do_force = false;
				if (!empty($certrow['ssl_cert_file']) && empty($certrow['validtodate'])) {
					// domain changed (SAN or similar)
					$do_force = true;
					$cronlog->logAction(FroxlorLogger::CRON_ACTION, LOG_INFO, "Re-creating certificate for " . $certrow['domain']);
				} else {
					$cronlog->logAction(FroxlorLogger::CRON_ACTION, LOG_INFO, "Creating certificate for " . $certrow['domain']);
				}
				$cronlog->logAction(FroxlorLogger::CRON_ACTION, LOG_INFO, "Adding common-name: " . $certrow['domain']);
				$domains = [
					strtolower($certrow['domain'])
				];
				// add www.<domain> to SAN list
				if ($certrow['wwwserveralias'] == 1) {
					$cronlog->logAction(FroxlorLogger::CRON_ACTION, LOG_INFO, "Adding SAN entry: www." . $certrow['domain']);
					$domains[] = strtolower('www.' . $certrow['domain']);
				}
				if ($certrow['domainid'] == 0) {
					$froxlor_aliases = Settings::Get('system.froxloraliases');
					if (!empty($froxlor_aliases)) {
						$froxlor_aliases = explode(",", $froxlor_aliases);
						foreach ($froxlor_aliases as $falias) {
							if (Validate::validateDomain(trim($falias))) {
								$cronlog->logAction(FroxlorLogger::CRON_ACTION, LOG_INFO, "Adding SAN entry: " . strtolower(trim($falias)));
								$domains[] = strtolower(trim($falias));
							}
						}
					}
				} else {
					// add alias domains (and possibly www.<aliasdomain>) to SAN list
					Database::pexecute($aliasdomains_stmt, [
						'id' => $certrow['domainid']
					]);
					$aliasdomains = $aliasdomains_stmt->fetchAll(PDO::FETCH_ASSOC);
					foreach ($aliasdomains as $aliasdomain) {
						$cronlog->logAction(FroxlorLogger::CRON_ACTION, LOG_INFO, "Adding SAN entry: " . $aliasdomain['domain']);
						$domains[] = strtolower($aliasdomain['domain']);
						if ($aliasdomain['wwwserveralias'] == 1) {
							$cronlog->logAction(FroxlorLogger::CRON_ACTION, LOG_INFO, "Adding SAN entry: www." . $aliasdomain['domain']);
							$domains[] = strtolower('www.' . $aliasdomain['domain']);
						}
					}
				}

				self::validateDns($domains, $certrow['domainid'], $cronlog);

				self::runAcmeSh($certrow, $domains, $cronlog, $do_force, $certrow['domainid'] == 0);
			} else {
				$cronlog->logAction(FroxlorLogger::CRON_ACTION, LOG_WARNING, "Skipping Let's Encrypt generation for " . $certrow['domain'] . " due to an enabled ssl_redirect");
				// we need another reconfigure in order to get the certificate
				Cronjob::inserttask(TaskId::REBUILD_VHOST);
			}
		}
	}

	/**
	 * validate dns (A / AAAA record) of domain against known system ips
	 *
	 * @param array $domains
	 * @param int $domain_id
	 * @param FroxlorLogger $cronlog
	 */
	private static function validateDns(array &$domains, $domain_id, &$cronlog)
	{
		if (Settings::Get('system.le_domain_dnscheck') == '1' && !empty($domains)) {
			$loop_domains = $domains;
			// ips according to our system
			$our_ips = Domain::getIpsOfDomain($domain_id);
			foreach ($loop_domains as $idx => $domain) {
				$cronlog->logAction(FroxlorLogger::CRON_ACTION, LOG_INFO, "Validating DNS of " . $domain);
				// ips according to NS
				$domain_ips = PhpHelper::gethostbynamel6($domain, true, Settings::Get('system.le_domain_dnscheck_resolver'));
				if ($domain_ips == false || count(array_intersect($our_ips, $domain_ips)) <= 0) {
					// no common ips...
					$cronlog->logAction(FroxlorLogger::CRON_ACTION, LOG_WARNING, "Skipping Let's Encrypt generation for " . $domain . " due to no system known IP address via DNS check");
					unset($domains[$idx]);
					// in order to avoid a cron-loop that tries to get a certificate every 5 minutes, we disable let's encrypt for this domain
					if ($domain_id > 0) {
						$upd_stmt = Database::prepare("UPDATE `" . TABLE_PANEL_DOMAINS . "` SET `letsencrypt` = '0' WHERE `id` = :did");
						Database::pexecute($upd_stmt, [
							'did' => $domain_id
						]);
					} else {
						// froxlor's hostname
						Settings::Set('system.le_froxlor_enabled', 0);
					}
					$cronlog->logAction(FroxlorLogger::CRON_ACTION, LOG_WARNING, "Let's Encrypt deactivated for domain " . $domain);
					if (!defined('CRON_IS_FORCED') && !defined('CRON_DEBUG_FLAG')) {
						// email info to admin that lets encrypt has been disabled for this domain
						Cronjob::notifyMailToAdmin("Let's Encrypt has been deactivated for domain '" . $domain . "' due to failed dns validation (wrong or no IP address)");
					}
				}
			}
		}
	}

	private static function runAcmeSh(array $certrow, array $domains, &$cronlog = null, bool $force = false, bool $renew_hook = false)
	{
		if (!empty($domains)) {
			$acmesh_cmd = self::getAcmeSh() . " --server " . self::$apiserver . " --issue -d " . implode(" -d ", $domains);
			// challenge path
			$acmesh_cmd .= " -w " . Settings::Get('system.letsencryptchallengepath');
			if (Settings::Get('system.leecc') > 0) {
				// ecc certificate
				$acmesh_cmd .= " --keylength ec-" . Settings::Get('system.leecc');
			} else {
				$acmesh_cmd .= " --keylength " . Settings::Get('system.letsencryptkeysize');
			}
			if (Settings::Get('system.letsencryptreuseold') != '1') {
				$acmesh_cmd .= " --always-force-new-domain-key";
			}
			if (substr(Settings::Get('system.letsencryptca'), -5) == '_test') {
				$acmesh_cmd .= " --staging";
			}
			if ($force) {
				$acmesh_cmd .= " --force";
			}
			if ($renew_hook
				&& !empty(trim(Settings::Get('system.le_renew_services') ?? ""))
				&& !empty(trim(Settings::Get('system.le_renew_hook') ?? ""))
			) {
				$acmesh_cmd .= " --renew-hook '" . Settings::Get('system.le_renew_hook') . "'";
			}
			if (defined('CRON_DEBUG_FLAG')) {
				$acmesh_cmd .= " --debug";
			}

			$exit_code = null;
			$acme_result = FileDir::safe_exec($acmesh_cmd, $exit_code);
			// debug output of acme.sh run
			$cronlog->logAction(FroxlorLogger::CRON_ACTION, LOG_DEBUG, implode("\n", $acme_result));

			if ($exit_code != 0) {
				$cronlog->logAction(FroxlorLogger::CRON_ACTION, LOG_DEBUG, "Non-successful exit-code returned :(");
				if (!defined('CRON_IS_FORCED') && !defined('CRON_DEBUG_FLAG')) {
					Cronjob::notifyMailToAdmin("Let's Encrypt certificate could not be obtained for: " . implode(", ", $domains) . "\n\n" . implode("\n", $acme_result));
				}
			} else {
				$cronlog->logAction(FroxlorLogger::CRON_ACTION, LOG_DEBUG, "Successful exit-code returned - storing certificate");
				$cert_stored = self::certToDb($certrow, $cronlog, $acme_result);

				if ($cert_stored
					&& $renew_hook
					&& !empty(trim(Settings::Get('system.le_renew_services') ?? ""))
					&& !empty(trim(Settings::Get('system.le_renew_hook') ?? ""))
				) {
					$cronlog->logAction(FroxlorLogger::CRON_ACTION, LOG_DEBUG, "Renew-hook is enabled - adjusting configurations");

					$certificate_folder = self::getCertificateFolder(strtolower(Settings::Get('system.hostname')));
					$fullchain = FileDir::makeCorrectFile($certificate_folder . '/fullchain.cer');
					$keyfile = FileDir::makeCorrectFile($certificate_folder . '/' . strtolower(Settings::Get('system.hostname')) . '.key');
					$ca_file = FileDir::makeCorrectFile($certificate_folder . '/ca.cer');

					if (Settings::IsInList('system.le_renew_services', 'postfix')) {
						// "postconf -e" for postfix
						FileDir::safe_exec('postconf -e smtpd_tls_cert_file=' . escapeshellarg($fullchain));
						FileDir::safe_exec('postconf -e smtpd_tls_key_file=' . escapeshellarg($keyfile));
					}
					if (Settings::IsInList('system.le_renew_services', 'dovecot')) {
						// custom config for dovecot
						$dovecot_conf = FileDir::makeCorrectFile(Settings::Get('system.mda_conf_dir') . '99-froxlor.ssl.conf');
						$ssl_content = <<<EOSSL
# Autogenerated configuration by froxlor.
# Do not manually edit this file as it will be overwritten.

ssl = yes
ssl_cert = <{$fullchain}
ssl_key = <{$keyfile}
EOSSL;
						file_put_contents($dovecot_conf, $ssl_content);
					}
					if (Settings::IsInList('system.le_renew_services', 'proftpd')) {
						$proftpd_conf = '/etc/proftpd/tls.conf'; // @fixme setting?
						$rval = false;
						// ECC certificate or not?
						if (strpos($certificate_folder, '_ecc') === false) {
							// comment out ECC related settings
							FileDir::safe_exec("sed -i.bak 's|^TLSECCertificateFile|# TLSECCertificateFile|' " . escapeshellarg($proftpd_conf), $rval, ['|', '?']);
							FileDir::safe_exec("sed -i.bak 's|^TLSECCertificateKeyFile|# TLSECCertificateKeyFile|' " . escapeshellarg($proftpd_conf), $rval, ['|', '?']);
							// add RSA directives
							FileDir::safe_exec("sed -i.bak 's|^#\?\s\?TLSRSACertificateFile.*|TLSRSACertificateFile " . $fullchain . "|' " . escapeshellarg($proftpd_conf), $rval, ['|', '?']);
							FileDir::safe_exec("sed -i.bak 's|^#\?\s\?TLSRSACertificateKeyFile.*|TLSRSACertificateKeyFile " . $keyfile . "|' " . escapeshellarg($proftpd_conf), $rval, ['|', '?']);
						} else {
							// comment out RSA related settings
							FileDir::safe_exec("sed -i.bak 's|^TLSRSACertificateFile|# TLSRSACertificateFile|' " . escapeshellarg($proftpd_conf), $rval, ['|', '?']);
							FileDir::safe_exec("sed -i.bak 's|^TLSRSACertificateKeyFile|# TLSRSACertificateKeyFile|' " . escapeshellarg($proftpd_conf), $rval, ['|', '?']);
							// add ECC directives
							FileDir::safe_exec("sed -i.bak 's|^#\?\s\?TLSECCertificateFile.*|TLSECCertificateFile " . $fullchain . "|' " . escapeshellarg($proftpd_conf), $rval, ['|', '?']);
							FileDir::safe_exec("sed -i.bak 's|^#\?\s\?TLSECCertificateKeyFile.*|TLSECCertificateKeyFile " . $keyfile . "|' " . escapeshellarg($proftpd_conf), $rval, ['|', '?']);
						}
						FileDir::safe_exec("sed -i.bak 's|^#\?\s\?TLSCACertificateFile.*|TLSCACertificateFile " . $ca_file . "|' " . escapeshellarg($proftpd_conf), $rval, ['|', '?']);
					}
					// reload the services
					FileDir::safe_exec(Settings::Get('system.le_renew_hook'));
				}
			}
		}
	}

	private static function certToDb($certrow, &$cronlog, $acme_result): bool
	{
		$return = [];
		self::readCertificateToVar(strtolower($certrow['domain']), $return, $cronlog);

		if (!empty($return['crt'])) {
			$newcert = openssl_x509_parse($return['crt']);

			if ($newcert) {
				// Store the new data
				Database::pexecute(self::$updcert_stmt, [
					'id' => $certrow['id'],
					'domainid' => $certrow['domainid'],
					'crt' => $return['crt'],
					'key' => $return['key'],
					'ca' => $return['chain'],
					'chain' => $return['chain'],
					'csr' => $return['csr'],
					'fullchain' => $return['fullchain'],
					'validfromdate' => date('Y-m-d H:i:s', $newcert['validFrom_time_t']),
					'validtodate' => date('Y-m-d H:i:s', $newcert['validTo_time_t']),
					'issuer' => $newcert['issuer']['O'] ?? ""
				]);

				if ($certrow['ssl_redirect'] == 3) {
					Database::pexecute(self::$upddom_stmt, [
						'domainid' => $certrow['domainid']
					]);
				}

				$cronlog->logAction(FroxlorLogger::CRON_ACTION, LOG_INFO, "Updated Let's Encrypt certificate for " . $certrow['domain']);
				return true;
			} else {
				$cronlog->logAction(FroxlorLogger::CRON_ACTION, LOG_ERR, "Got non-successful Let's Encrypt response for " . $certrow['domain'] . ":\n" . implode("\n", $acme_result));
			}
		} else {
			$cronlog->logAction(FroxlorLogger::CRON_ACTION, LOG_ERR, "Could not get Let's Encrypt certificate for " . $certrow['domain'] . ":\n" . implode("\n", $acme_result));
		}
		return false;
	}

	/**
	 * get certificate files from filesystem and store in $return array
	 *
	 * @param string $domain
	 * @param array $return
	 * @param object $cronlog
	 */
	private static function readCertificateToVar($domain, &$return, &$cronlog)
	{
		$certificate_folder = self::getCertificateFolder($domain);

		if (!empty($certificate_folder)) {
			$certificate_files = [
				'crt' => $domain . '.cer',
				'key' => $domain . '.key',
				'chain' => 'ca.cer',
				'fullchain' => 'fullchain.cer',
				'csr' => $domain . '.csr'
			];
			foreach ($certificate_files as $index => $sslfile) {
				$ssl_file = FileDir::makeCorrectFile($certificate_folder . '/' . $sslfile);
				if (file_exists($ssl_file)) {
					$return[$index] = file_get_contents($ssl_file);
				} else {
					$cronlog->logAction(FroxlorLogger::CRON_ACTION, LOG_ERR, "Could not find file '" . $sslfile . "' in '" . $certificate_folder . "'");
					$return[$index] = null;
				}
			}
		} else {
			$cronlog->logAction(FroxlorLogger::CRON_ACTION, LOG_ERR, "Could not find certificate-folder '" . $certificate_folder . "'");
		}
	}

	private static function getCertificateFolder(string $domain): string
	{
		$certificate_folder = self::getWorkingDirFromEnv(strtolower($domain));
		if (file_exists($certificate_folder)) {
			return $certificate_folder;
		}
		$certificate_folder_ecc = self::getWorkingDirFromEnv($domain, true);
		if (file_exists($certificate_folder_ecc)) {
			return $certificate_folder_ecc;
		}
		FroxlorLogger::getInstanceOf()->logAction(FroxlorLogger::CRON_ACTION, LOG_ERR, "Could not find certificate-folder for domain '" . $domain . "'");
		return "";
	}
}
