<?php

namespace Froxlor\System;

use Froxlor\Settings;

class Crypt
{

	/**
	 * Generates a random password
	 */
	public static function generatePassword()
	{
		$alpha_lower = 'abcdefghijklmnopqrstuvwxyz';
		$alpha_upper = strtoupper($alpha_lower);
		$numeric = '0123456789';
		$special = Settings::Get('panel.password_special_char');
		$length = Settings::Get('panel.password_min_length') > 3 ? Settings::Get('panel.password_min_length') : 10;

		$pw = self::specialShuffle($alpha_lower);
		$n = floor(($length) / 4);

		if (Settings::Get('panel.password_alpha_upper')) {
			$pw .= mb_substr(self::specialShuffle($alpha_upper), 0, $n);
		}

		if (Settings::Get('panel.password_numeric')) {
			$pw .= mb_substr(self::specialShuffle($numeric), 0, $n);
		}

		if (Settings::Get('panel.password_special_char_required')) {
			$pw .= mb_substr(self::specialShuffle($special), 0, $n);
		}

		$pw = mb_substr($pw, -$length);

		return self::specialShuffle($pw);
	}

	/**
	 * multibyte-character safe shuffle function
	 *
	 * @param string $str
	 *
	 * @return string
	 */
	private static function specialShuffle($str = null)
	{
		$len = mb_strlen($str);
		$sploded = array();
		while ($len-- > 0) {
			$sploded[] = mb_substr($str, $len, 1);
		}
		shuffle($sploded);
		return join('', $sploded);
	}

	/**
	 * Make encrypted password from clear text password
	 *
	 * @author Michal Wojcik <m.wojcik@sonet3.pl>
	 * @author Michael Kaufmann <mkaufmann@nutime.de>
	 * @author Froxlor team <team@froxlor.org> (2010-)
	 *        
	 *         0 - default crypt (depends on system configuration)
	 *         1 - MD5 $1$
	 *         2 - BLOWFISH $2y$07$
	 *         3 - SHA-256 $5$ (default)
	 *         4 - SHA-512 $6$
	 *        
	 * @param string $password
	 *        	Password to be encrypted
	 * @param bool $htpasswd
	 *        	optional whether to generate a SHA1 password for directory protection
	 *        	
	 * @return string encrypted password
	 */
	public static function makeCryptPassword($password, $htpasswd = false)
	{
		if ($htpasswd) {
			return '{SHA}' . base64_encode(sha1($password, true));
		}
		$algo = Settings::Get('system.passwordcryptfunc') !== null ? Settings::Get('system.passwordcryptfunc') : PASSWORD_DEFAULT;
		return password_hash($password, $algo);
	}

	/**
	 * return an array of available hashes
	 *
	 * @return array
	 */
	public static function getAvailablePasswordHashes()
	{
		global $lng;

		// get available pwd-hases
		$available_pwdhashes = array(
			PASSWORD_DEFAULT => $lng['serversettings']['systemdefault']
		);
		if (defined('PASSWORD_BCRYPT')) {
			$available_pwdhashes[PASSWORD_BCRYPT] = 'Bcrypt/Blowfish';
		}
		if (defined('PASSWORD_ARGON2I')) {
			$available_pwdhashes[PASSWORD_ARGON2I] = 'Argon2i';
		}
		if (defined('PASSWORD_ARGON2ID')) {
			$available_pwdhashes[PASSWORD_ARGON2ID] = 'Argon2id';
		}

		return $available_pwdhashes;
	}

	/**
	 * Function validatePassword
	 *
	 * if password-min-length is set in settings
	 * we check against the length, if not matched
	 * an error message will be output and 'exit' is called
	 *
	 * @param string $password
	 *        	the password to validate
	 *        	
	 * @return string either the password or an errormessage+exit
	 */
	public static function validatePassword($password = null, $json_response = false)
	{
		if (Settings::Get('panel.password_min_length') > 0) {
			$password = \Froxlor\Validate\Validate::validate($password, Settings::Get('panel.password_min_length'), '/^.{' . (int) Settings::Get('panel.password_min_length') . ',}$/D', 'notrequiredpasswordlength', array(), $json_response);
		}

		if (Settings::Get('panel.password_regex') != '') {
			$password = \Froxlor\Validate\Validate::validate($password, Settings::Get('panel.password_regex'), Settings::Get('panel.password_regex'), 'notrequiredpasswordcomplexity', array(), $json_response);
		} else {
			if (Settings::Get('panel.password_alpha_lower')) {
				$password = \Froxlor\Validate\Validate::validate($password, '/.*[a-z]+.*/', '/.*[a-z]+.*/', 'notrequiredpasswordcomplexity', array(), $json_response);
			}
			if (Settings::Get('panel.password_alpha_upper')) {
				$password = \Froxlor\Validate\Validate::validate($password, '/.*[A-Z]+.*/', '/.*[A-Z]+.*/', 'notrequiredpasswordcomplexity', array(), $json_response);
			}
			if (Settings::Get('panel.password_numeric')) {
				$password = \Froxlor\Validate\Validate::validate($password, '/.*[0-9]+.*/', '/.*[0-9]+.*/', 'notrequiredpasswordcomplexity', array(), $json_response);
			}
			if (Settings::Get('panel.password_special_char_required')) {
				$password = \Froxlor\Validate\Validate::validate($password, '/.*[' . preg_quote(Settings::Get('panel.password_special_char'), '/') . ']+.*/', '/.*[' . preg_quote(Settings::Get('panel.password_special_char'), '/') . ']+.*/', 'notrequiredpasswordcomplexity', array(), $json_response);
			}
		}

		return $password;
	}

	/**
	 * Function validatePasswordLogin
	 *
	 * compare user password-hash with given user-password
	 * and check if they are the same
	 * additionally it updates the hash if the system settings changed
	 * or if the very old md5() sum is used
	 *
	 * @param array $userinfo
	 *        	user-data from table
	 * @param string $password
	 *        	the password to validate
	 * @param string $table
	 *        	either panel_customers or panel_admins
	 * @param string $uid
	 *        	user-id-field in $table
	 *        	
	 * @return boolean
	 */
	public static function validatePasswordLogin($userinfo = null, $password = null, $table = 'panel_customers', $uid = 'customerid')
	{
		$algo = Settings::Get('system.passwordcryptfunc') !== null ? Settings::Get('system.passwordcryptfunc') : PASSWORD_DEFAULT;
		if (is_numeric($algo)) {
			// old setting format
			$algo = PASSWORD_DEFAULT;
			Settings::Set('system.passwordcryptfunc', $algo);
		}
		$pwd_hash = $userinfo['password'];

		$update_hash = false;
		$pwd_check = "";
		// check for good'ole md5
		if (strlen($pwd_hash) == 32 && ctype_xdigit($pwd_hash)) {
			$pwd_check = md5($password);
			$update_hash = true;
		}

		if ($pwd_hash == $pwd_check || password_verify($password, $pwd_hash)) {

			// check for update of hash (only if our database is ready to handle the bigger string)
			$is_ready = (\Froxlor\Froxlor::versionCompare2("0.9.33", \Froxlor\Froxlor::getVersion()) <= 0 ? true : false);
			if ((password_needs_rehash($pwd_hash, $algo) || $update_hash) && $is_ready) {
				$upd_stmt = \Froxlor\Database\Database::prepare("
					UPDATE " . $table . " SET `password` = :newpasswd WHERE `" . $uid . "` = :uid
				");
				$params = array(
					'newpasswd' => self::makeCryptPassword($password),
					'uid' => $userinfo[$uid]
				);
				\Froxlor\Database\Database::pexecute($upd_stmt, $params);
			}
			return true;
		}
		return false;
	}
}
