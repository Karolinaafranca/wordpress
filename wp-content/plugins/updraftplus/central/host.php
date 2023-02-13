<?php

if (!defined('ABSPATH')) die('No direct access.');

/**
 * The template definition for UpdraftCentral host
 */
abstract class UpdraftCentral_Host {

	public $plugin_name;

	public $translations;

	public $error_reporting_stop_when_logged = false;

	public $no_deprecation_warnings = false;

	private $jobdata;

	abstract protected function load_updraftcentral();
	abstract protected function is_host_dir_set();
	abstract protected function get_host_dir();
	abstract protected function get_version();
	abstract public function log($line, $level = 'notice', $uniq_id = false);

	/**
	 * Class constructor
	 */
	public function __construct() {
		add_action('wp_ajax_updraft_central_ajax', array($this, 'updraft_central_ajax_handler'));
	}

	/**
	 * Returns the plugin name associated with this host class
	 *
	 * @return string
	 */
	public function get_plugin_name() {
		return $this->plugin_name;
	}

	/**
	 * Retrieves or shows a message from the translations collection based on its identifier key
	 *
	 * @param string $key  The ID of the the message
	 * @param bool   $echo Indicate whether the message is to be shown directly (echoed) or just for retrieval
	 *
	 * @return string/void
	 */
	public function retrieve_show_message($key, $echo = false) {
		if (empty($key) || !isset($this->translations[$key])) return '';

		if ($echo) {
			echo $this->translations[$key];
			return;
		}

		return $this->translations[$key];
	}

	/**
	 * Adds a section to a designated area primarily used for generating UpdraftCentral keys
	 *
	 * @return void
	 */
	public function debugtools_dashboard() {
		global $updraftcentral_main;

		if (!class_exists('UpdraftCentral_Main')) {
			if (defined('UPDRAFTCENTRAL_CLIENT_DIR') && file_exists(UPDRAFTCENTRAL_CLIENT_DIR.'/bootstrap.php')) {
				include_once(UPDRAFTCENTRAL_CLIENT_DIR.'/bootstrap.php');
				$updraftcentral_main = new UpdraftCentral_Main();
			}
		}

		if ($updraftcentral_main) {
			$updraftcentral_main->debugtools_dashboard();
		}
	}

	/**
	 * Handles ajax requests coming from the section or area generated by the
	 * "debugtools_dashboard" method (below)
	 *
	 * @return void
	 */
	public function updraft_central_ajax_handler() {
		global $updraftcentral_main;

		$nonce = empty($_REQUEST['nonce']) ? '' : $_REQUEST['nonce'];
		if (!wp_verify_nonce($nonce, 'updraftcentral-credentialtest-nonce') || empty($_REQUEST['subaction'])) die('Security check');

		if (is_a($updraftcentral_main, 'UpdraftCentral_Main')) {

			$subaction = $_REQUEST['subaction'];
			if (is_callable(array($updraftcentral_main, $subaction))) {

				// Undo WP's slashing of POST data
				$data = $this->wp_unslash($_POST);

				// TODO: Once all commands come through here and through updraft_send_command(), the data should always come from this attribute (once updraft_send_command() is modified appropriately).
				if (isset($data['action_data'])) $data = $data['action_data'];
				try {
					$results = call_user_func(array($updraftcentral_main, $subaction), $data);
				} catch (Exception $e) {
					$log_message = 'PHP Fatal Exception error ('.get_class($e).') has occurred during '.$subaction.' subaction. Error Message: '.$e->getMessage().' (Code: '.$e->getCode().', line '.$e->getLine().' in '.$e->getFile().')';
					error_log($log_message);
					echo json_encode(array(
						'fatal_error' => true,
						'fatal_error_message' => $log_message
					));
					die;
				// @codingStandardsIgnoreLine
				} catch (Error $e) {
					$log_message = 'PHP Fatal error ('.get_class($e).') has occurred during '.$subaction.' subaction. Error Message: '.$e->getMessage().' (Code: '.$e->getCode().', line '.$e->getLine().' in '.$e->getFile().')';
					error_log($log_message);
					echo json_encode(array(
						'fatal_error' => true,
						'fatal_error_message' => $log_message
					));
					die;
				}
				if (is_wp_error($results)) {
					$results = array(
						'result' => false,
						'error_code' => $results->get_error_code(),
						'error_message' => $results->get_error_message(),
						'error_data' => $results->get_error_data(),
					);
				}

				if (is_string($results)) {
					// A handful of legacy methods, and some which are directly the source for iframes, for which JSON is not appropriate.
					echo $results;
				} else {
					echo json_encode($results);
				}
				die;
			}
		}
		die;
	}

	/**
	 * Retrieves the filter used by UpdraftCentral to log errors or certain events
	 *
	 * @return string
	 */
	public function get_logline_filter() {
		return 'updraftcentral_logline';
	}

	/**
	 * Gets an RPC object, and sets some defaults on it that we always want
	 *
	 * @param  string $indicator_name indicator name
	 * @return array
	 */
	public function get_udrpc($indicator_name = 'migrator.updraftplus.com') {
		if (!class_exists('UpdraftPlus_Remote_Communications')) include_once($this->get_host_dir().'/vendor/team-updraft/common-libs/src/updraft-rpc/class-udrpc.php');
		$ud_rpc = new UpdraftPlus_Remote_Communications($indicator_name);
		$ud_rpc->set_can_generate(true);
		return $ud_rpc;
	}

	/**
	 * Noop method.
	 * Depending on the host plugin this method may or may not be used.
	 *
	 * N.B. UpdrafPlus plugin is using and overriding this method in its host file.
	 *
	 * @param boolean $register Indicate whether to add or remote filter hooks
	 * @ignore
	 */
	// @codingStandardsIgnoreLine
	public function register_wp_http_option_hooks($register = true) {}

	/**
	 * Remove slashes from a string or array of strings.
	 *
	 * The function wp_unslash() is WP 3.6+, so therefore we have a compatibility method here
	 *
	 * @param String|Array $value String or array of strings to unslash.
	 * @return String|Array Unslashed $value
	 */
	public function wp_unslash($value) {
		return function_exists('wp_unslash') ? wp_unslash($value) : stripslashes_deep($value);
	}

	/**
	 * Generate a log line based from the PHP error information
	 *
	 * @param Integer $errno   Error number
	 * @param String  $errstr  Error string
	 * @param String  $errfile Error file
	 * @param String  $errline Line number where the error occured
	 *
	 * @return string|bool
	 */
	public function php_error_to_logline($errno, $errstr, $errfile, $errline) {
		switch ($errno) {
			case 1:
				$e_type = 'E_ERROR';
				break;
			case 2:
				$e_type = 'E_WARNING';
				break;
			case 4:
				$e_type = 'E_PARSE';
				break;
			case 8:
				$e_type = 'E_NOTICE';
				break;
			case 16:
				$e_type = 'E_CORE_ERROR';
				break;
			case 32:
				$e_type = 'E_CORE_WARNING';
				break;
			case 64:
				$e_type = 'E_COMPILE_ERROR';
				break;
			case 128:
				$e_type = 'E_COMPILE_WARNING';
				break;
			case 256:
				$e_type = 'E_USER_ERROR';
				break;
			case 512:
				$e_type = 'E_USER_WARNING';
				break;
			case 1024:
				$e_type = 'E_USER_NOTICE';
				break;
			case 2048:
				$e_type = 'E_STRICT';
				break;
			case 4096:
				$e_type = 'E_RECOVERABLE_ERROR';
				break;
			case 8192:
				$e_type = 'E_DEPRECATED';
				break;
			case 16384:
				$e_type = 'E_USER_DEPRECATED';
				break;
			case 30719:
				$e_type = 'E_ALL';
				break;
			default:
				$e_type = "E_UNKNOWN ($errno)";
				break;
		}

		if (false !== stripos($errstr, 'table which is not valid in this version of Gravity Forms')) return false;

		if (!is_string($errstr)) $errstr = serialize($errstr);

		if (0 === strpos($errfile, ABSPATH)) $errfile = substr($errfile, strlen(ABSPATH));

		if ('E_DEPRECATED' == $e_type && !empty($this->no_deprecation_warnings)) {
			return false;
		}

		return "PHP event: code $e_type: $errstr (line $errline, $errfile)";
	}

	/**
	 * PHP error handler
	 *
	 * @param Integer $errno   Error number
	 * @param String  $errstr  Error string
	 * @param String  $errfile Error file
	 * @param String  $errline Line number where the error occured
	 *
	 * @return bool
	 */
	public function php_error($errno, $errstr, $errfile, $errline) {
		if (0 == error_reporting()) return true;
		$logline = $this->php_error_to_logline($errno, $errstr, $errfile, $errline);
		if (false !== $logline) $this->log($logline, 'notice', 'php_event');
		// Pass it up the chain
		return $this->error_reporting_stop_when_logged;
	}
}