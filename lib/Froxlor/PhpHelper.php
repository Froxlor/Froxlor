<?php

namespace Froxlor;

class PhpHelper
{

	private static $sort_key = 'id';

	private static $sort_type = SORT_STRING;

	/**
	 * sort an array by either natural or string sort and a given index where the value for comparison is found
	 *
	 * @param array $list
	 * @param string $key
	 *
	 * @return boolean
	 */
	public static function sortListBy(&$list, $key = 'id')
	{
		self::$sort_type = Settings::Get('panel.natsorting') == 1 ? SORT_NATURAL : SORT_STRING;
		self::$sort_key = $key;
		return usort($list, array(
			'self',
			'sortListByGivenKey'
		));
	}

	private static function sortListByGivenKey($a, $b)
	{
		if (self::$sort_type == SORT_NATURAL) {
			return strnatcasecmp($a[self::$sort_key], $b[self::$sort_key]);
		}
		return strcasecmp($a[self::$sort_key], $b[self::$sort_key]);
	}

	/**
	 * Wrapper around htmlentities to handle arrays, with the advantage that you
	 * can select which fields should be handled by htmlentities
	 *
	 * @param array|string $subject
	 *        	The subject array
	 * @param string $fields
	 *        	The fields which should be checked for, separated by spaces
	 * @param int $quote_style
	 *        	See php documentation about this
	 * @param string $charset
	 *        	See php documentation about this
	 *
	 * @return array|string The string or an array with htmlentities converted strings
	 * @author Florian Lippert <flo@syscp.org>
	 */
	public static function htmlentitiesArray($subject, $fields = '', $quote_style = ENT_QUOTES, $charset = 'UTF-8')
	{
		if (is_array($subject)) {
			if (!is_array($fields)) {
				$fields = self::arrayTrim(explode(' ', $fields));
			}

			foreach ($subject as $field => $value) {
				if ((!is_array($fields) || empty($fields)) || (is_array($fields) && !empty($fields) && in_array($field, $fields))) {
					// Just call ourselve to manage multi-dimensional arrays
					$subject[$field] = self::htmlentitiesArray($value, $fields, $quote_style, $charset);
				}
			}
		} else {
			$subject = empty($subject) ? "" : htmlentities($subject, $quote_style, $charset);
		}

		return $subject;
	}

	/**
	 * Replaces Strings in an array, with the advantage that you
	 * can select which fields should be str_replace'd
	 *
	 * @param 	string|array $search
	 * 				String or array of strings to search for
	 * @param string|array $reaplce
	 *        	 String or array to replace with
	 * @param string|array $subject
	 *        	String or array The subject array
	 * @param string $fields
	 *        	string The fields which should be checked for, separated by spaces
	 * @return string|array The str_replace'd array
	 * @author Florian Lippert <flo@syscp.org>
	 */
	public static function strReplaceArray($search, $replace, $subject, $fields = '')
	{
		if (is_array($subject)) {
			$fields = self::arrayTrim(explode(' ', $fields));
			foreach ($subject as $field => $value) {
				if ((!is_array($fields) || empty($fields)) || (is_array($fields) && !empty($fields) && in_array($field, $fields))) {
					$subject[$field] = str_replace($search, $replace, $value);
				}
			}
		} else {
			$subject = str_replace($search, $replace, $subject);
		}

		return $subject;
	}

	/**
	 * froxlor php error handler
	 *
	 * @param int $errno
	 * @param string $errstr
	 * @param string $errfile
	 * @param int $errline
	 *
	 * @return void|boolean
	 */
	public static function phpErrHandler($errno, $errstr, $errfile, $errline)
	{
		if (!(error_reporting() & $errno)) {
			// This error code is not included in error_reporting
			return;
		}

		if (!isset($_SERVER['SHELL']) || (isset($_SERVER['SHELL']) && $_SERVER['SHELL'] == '')) {
			// prevent possible file-path-disclosure
			$errfile = str_replace(\Froxlor\Froxlor::getInstallDir(), "", $errfile);
			// build alert
			$type = 'danger';
			if ($errno == E_NOTICE || $errno == E_DEPRECATED || $errno == E_STRICT) {
				$type = 'info';
			} elseif ($errno = E_WARNING) {
				$type = 'warning';
			}
			$err_display = '<div class="alert alert-' . $type . ' my-1" role="alert">';
			$err_display .= '<strong>#' . $errno . ' ' . $errstr . '</strong><br>';
			$err_display .= $errfile . ':' . $errline;
			// later depended on whether to show or now
			$err_display .= '<br><p><pre>';
			$debug = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
			foreach ($debug as $dline) {
				$err_display .= $dline['function'] . '() called at [' . str_replace(\Froxlor\Froxlor::getInstallDir(), '', ($dline['file'] ?? 'unknown')) . ':' . ($dline['line'] ?? 0) . ']<br>';
			}
			$err_display .= '</pre></p>';
			// end later
			$err_display .= '</div>';
			// check for more existing errors
			$errors = isset(\Froxlor\UI\Panel\UI::twig()->getGlobals()['global_errors']) ? \Froxlor\UI\Panel\UI::twig()->getGlobals()['global_errors'] : "";
			\Froxlor\UI\Panel\UI::twig()->addGlobal('global_errors', $errors . $err_display);
			// return true to ignore php standard error-handler
			return true;
		}

		// of on shell, use the php standard error-handler
		return false;
	}

	public static function phpExceptionHandler(\Throwable $exception)
	{
		if (!isset($_SERVER['SHELL']) || (isset($_SERVER['SHELL']) && $_SERVER['SHELL'] == '')) {
			// show
			\Froxlor\UI\Panel\UI::initTwig(true);
			\Froxlor\UI\Panel\UI::twig()->addGlobal('install_mode', '1');
			\Froxlor\UI\Panel\UI::view('misc/alert_nosession.html.twig', [
				'page_title' => 'Uncaught exception',
				'heading' => 'Uncaught exception',
				'type' => 'danger',
				'alert_msg' => $exception->getCode() . ' ' . $exception->getMessage(),
				'alert_info' => $exception->getTraceAsString()
			]);
			die();
		}
	}

	public static function loadConfigArrayDir()
	{
		global $lng, $theme;

		// we now use dynamic function parameters
		// so we can read from more than one directory
		// and still be valid for old calls
		$numargs = func_num_args();
		if ($numargs <= 0) {
			return null;
		}

		// variable that holds all dirs that will
		// be parsed for inclusion
		$configdirs = array();
		// if one of the parameters is an array
		// we assume that this is a list of
		// setting-groups to be selected
		$selection = null;
		for ($x = 0; $x < $numargs; $x++) {
			$arg = func_get_arg($x);
			if (is_array($arg) && isset($arg[0])) {
				$selection = $arg;
			} else {
				$configdirs[] = $arg;
			}
		}

		$data = array();
		$data_files = array();
		$has_data = false;

		foreach ($configdirs as $data_dirname) {
			if (is_dir($data_dirname)) {
				$data_dirhandle = opendir($data_dirname);
				while (false !== ($data_filename = readdir($data_dirhandle))) {
					if ($data_filename != '.' && $data_filename != '..' && $data_filename != '' && substr($data_filename, -4) == '.php') {
						$data_files[] = $data_dirname . $data_filename;
					}
				}
				$has_data = true;
			}
		}

		if ($has_data) {
			sort($data_files);
			foreach ($data_files as $data_filename) {
				$data = array_merge_recursive($data, include $data_filename);
			}
		}

		// if we have specific setting-groups
		// to select, we'll handle this here
		// (this is for multiserver-client settings)
		$_data = array();
		if ($selection != null && is_array($selection) && isset($selection[0])) {
			$_data['groups'] = array();
			foreach ($data['groups'] as $group => $data) {
				if (in_array($group, $selection)) {
					$_data['groups'][$group] = $data;
				}
			}
			$data = $_data;
		}

		return $data;
	}

	/**
	 * ipv6 aware gethostbynamel function
	 *
	 * @param string $host
	 * @param boolean $try_a
	 *        	default true
	 * @return boolean|array
	 */
	public static function gethostbynamel6($host, $try_a = true)
	{
		$dns6 = @dns_get_record($host, DNS_AAAA);
		if (!is_array($dns6)) {
			// no record or failed to check
			$dns6 = [];
		}
		if ($try_a == true) {
			$dns4 = @dns_get_record($host, DNS_A);
			if (!is_array($dns4)) {
				// no record or failed to check
				$dns4 = [];
			}
			$dns = array_merge($dns4, $dns6);
		} else {
			$dns = $dns6;
		}
		$ips = array();
		foreach ($dns as $record) {
			if ($record["type"] == "A") {
				// always use compressed ipv6 format
				$ip = inet_ntop(inet_pton($record["ip"]));
				$ips[] = $ip;
			}
			if ($record["type"] == "AAAA") {
				// always use compressed ipv6 format
				$ip = inet_ntop(inet_pton($record["ipv6"]));
				$ips[] = $ip;
			}
		}
		if (count($ips) < 1) {
			return false;
		} else {
			return $ips;
		}
	}

    /**
     * Function randomStr
     *
     * generate a pseudo-random string of bytes
     *
     * @param int $length
     * @return string
     * @throws \Exception
     */
	public static function randomStr($length)
	{
        if (function_exists('openssl_random_pseudo_bytes')) {
            return openssl_random_pseudo_bytes($length);
        }
        return random_bytes($length);
	}

	/**
	 * Return human readable sizes
	 *
	 * @param int $size
	 *        	size in bytes
	 * @param string $max
	 *        	maximum unit
	 * @param string $system
	 *        	'si' for SI, 'bi' for binary prefixes
	 *
	 * @param string $retstring
	 *        	string
	 */
	public static function sizeReadable($size, $max = null, $system = 'si', $retstring = '%01.2f %s')
	{
		// Pick units
		$systems = array(
			'si' => array(
				'prefix' => array(
					'B',
					'KB',
					'MB',
					'GB',
					'TB',
					'PB'
				),
				'size' => 1000
			),
			'bi' => array(
				'prefix' => array(
					'B',
					'KiB',
					'MiB',
					'GiB',
					'TiB',
					'PiB'
				),
				'size' => 1024
			)
		);
		$sys = isset($systems[$system]) ? $systems[$system] : $systems['si'];

		// Max unit to display
		$depth = count($sys['prefix']) - 1;
		if ($max && false !== $d = array_search($max, $sys['prefix'])) {
			$depth = $d;
		}
		// Loop
		$i = 0;
		while ($size >= $sys['size'] && $i < $depth) {
			$size /= $sys['size'];
			$i++;
		}

		return sprintf($retstring, $size, $sys['prefix'][$i]);
	}

	/**
	 * Replaces all occurrences of variables defined in the second argument
	 * in the first argument with their values.
	 *
	 * @param string $text
	 *        	The string that should be searched for variables
	 * @param array $vars
	 *        	The array containing the variables with their values
	 *
	 * @return string The submitted string with the variables replaced.
	 */
	public static function replaceVariables($text, $vars)
	{
		$pattern = "/\{([a-zA-Z0-9\-_]+)\}/";
		$matches = array();

		if (count($vars) > 0 && preg_match_all($pattern, $text, $matches)) {
			for ($i = 0; $i < count($matches[1]); $i++) {
				$current = $matches[1][$i];

				if (isset($vars[$current])) {
					$var = $vars[$current];
					$text = str_replace("{" . $current . "}", $var, $text);
				}
			}
		}

		$text = str_replace('\n', "\n", $text);
		return $text;
	}

	/**
	 * Returns array with all empty-values removed
	 *
	 * @param array $source
	 *        	The array to trim
	 * @return array The trim'med array
	 */
	public static function arrayTrim($source)
	{
		$returnval = array();
		if (is_array($source)) {
			$source = array_map('trim', $source);
			$returnval = array_filter($source, function ($value) {
				return $value !== '';
			});
		} else {
			$returnval = $source;
		}
		return $returnval;
	}

	public static function recursive_array_search($needle, $haystack, &$keys = array(), $currentKey = '')
	{
		foreach ($haystack as $key => $value) {
			$pathkey = empty($currentKey) ? $key : $currentKey . '.' . $key;
			if (is_array($value)) {
				self::recursive_array_search($needle, $value, $keys, $pathkey);
			} else if (stripos($value, $needle) !== false) {
				$keys[] = $pathkey;
			}
		}
		return true;
	}

	/**
	 * function to check a super-global passed by reference
	 * so it gets automatically updated
	 *
	 * @param array $global
	 * @param \voku\helper\AntiXSS $antiXss
	 */
	public static function cleanGlobal(&$global, &$antiXss)
	{
		$ignored_fields = [
			'system_default_vhostconf',
			'system_default_sslvhostconf',
			'system_apache_globaldiropt',
			'specialsettings',
			'ssl_specialsettings',
			'default_vhostconf_domain',
			'ssl_default_vhostconf_domain',
			'filecontent'
		];
		if (isset($global) && !empty($global)) {
			$tmp = $global;
			foreach ($tmp as $index => $value) {
				if (!in_array($index, $ignored_fields)) {
					$global[$index] = $antiXss->xss_clean($value);
				}
			}
		}
	}
}
