<?php
/**
 * Class WordPress\Plugin_Check\Admin\Admin_AJAX
 *
 * @package plugin-check
 */

namespace WordPress\Plugin_Check\Admin;

use Exception;
use WordPress\Plugin_Check\Checker\AJAX_Runner;
use WordPress\Plugin_Check\Checker\Runtime_Check;
use WordPress\Plugin_Check\Checker\Runtime_Environment_Setup;
use WP_Error;

/**
 * Class to handle the Admin AJAX requests.
 *
 * @since n.e.x.t
 */
class Admin_AJAX {

	/**
	 * Nonce key.
	 *
	 * @since n.e.x.t
	 * @var string
	 */
	const NONCE_KEY = 'plugin-check-run-checks';

	/**
	 * Clean up Runtime Environment action name.
	 *
	 * @since n.e.x.t
	 * @var string
	 */
	const ACTION_CLEAN_UP_ENVIRONMENT = 'plugin_check_clean_up_environment';

	/**
	 * Set up Runtime Environment action name.
	 *
	 * @since n.e.x.t
	 * @var string
	 */
	const ACTION_SET_UP_ENVIRONMENT = 'plugin_check_set_up_environment';

	/**
	 * Get Checks to run action name.
	 *
	 * @since n.e.x.t
	 * @var string
	 */
	const ACTION_GET_CHECKS_TO_RUN = 'plugin_check_get_checks_to_run';

	/**
	 * Run Checks action name.
	 *
	 * @since n.e.x.t
	 * @var string
	 */
	const ACTION_RUN_CHECKS = 'plugin_check_run_checks';

	/**
	 * Runner context.
	 *
	 * @since n.e.x.t
	 * @var AJAX_Runner
	 */
	protected $runner;

	/**
	 * Constructor.
	 *
	 * @since n.e.x.t
	 *
	 * @param AJAX_Runner $runner AJAX Runner.
	 */
	public function __construct( AJAX_Runner $runner ) {
		$this->runner = $runner;
	}

	/**
	 * Registers WordPress hooks for the Admin AJAX.
	 *
	 * @since n.e.x.t
	 */
	public function add_hooks() {
		add_action( 'wp_ajax_' . self::ACTION_CLEAN_UP_ENVIRONMENT, array( $this, 'clean_up_environment' ) );
		add_action( 'wp_ajax_' . self::ACTION_SET_UP_ENVIRONMENT, array( $this, 'set_up_environment' ) );
		add_action( 'wp_ajax_' . self::ACTION_GET_CHECKS_TO_RUN, array( $this, 'get_checks_to_run' ) );
		add_action( 'wp_ajax_' . self::ACTION_RUN_CHECKS, array( $this, 'run_checks' ) );
	}

	/**
	 * Creates and returns the nonce.
	 *
	 * @since n.e.x.t
	 */
	public function get_nonce() {
		return wp_create_nonce( self::NONCE_KEY );
	}

	/**
	 * Handles the AJAX request to setup the runtime environment if needed.
	 *
	 * @since n.e.x.t
	 */
	public function set_up_environment() {
		// Verify the nonce before continuing.
		$valid_request = $this->verify_request( filter_input( INPUT_POST, 'nonce', FILTER_SANITIZE_FULL_SPECIAL_CHARS ) );

		if ( is_wp_error( $valid_request ) ) {
			wp_send_json_error( $valid_request, 403 );
		}

		$checks = filter_input( INPUT_POST, 'checks', FILTER_DEFAULT, FILTER_FORCE_ARRAY );
		$plugin = filter_input( INPUT_POST, 'plugin', FILTER_SANITIZE_FULL_SPECIAL_CHARS );

		try {
			$this->runner->set_check_slugs( $checks );
			$this->runner->set_plugin( $plugin );
			$checks_to_run = $this->runner->get_checks_to_run();
		} catch ( Exception $error ) {
			wp_send_json_error(
				new WP_Error( 'invalid-request', $error->getMessage() ),
				400
			);
		}

		$message = __( 'No runtime checks, runtime environment was not setup.', 'plugin-check' );

		if ( $this->has_runtime_check( $checks_to_run ) ) {
			$runtime = new Runtime_Environment_Setup();
			$runtime->setup();
			$message = __( 'Runtime environment setup successful.', 'plugin-check' );
		}

		wp_send_json_success(
			array(
				'message' => $message,
				'plugin'  => $plugin,
				'checks'  => $checks,
			)
		);
	}

	/**
	 * Handles the AJAX request to cleanup the runtime environment.
	 *
	 * @since n.e.x.t
	 */
	public function clean_up_environment() {
		global $wpdb, $table_prefix;

		// Verify the nonce before continuing.
		$valid_request = $this->verify_request( filter_input( INPUT_POST, 'nonce', FILTER_SANITIZE_FULL_SPECIAL_CHARS ) );

		if ( is_wp_error( $valid_request ) ) {
			wp_send_json_error( $valid_request, 403 );
		}

		// Set the new prefix.
		$old_prefix = $wpdb->set_prefix( $table_prefix . 'pc_' );

		$message = __( 'Runtime environment was not prepared, cleanup was not run.', 'plugin-check' );

		// Test if the runtime environment tables exist.
		if ( $wpdb->posts === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->posts ) ) || defined( 'WP_PLUGIN_CHECK_OBJECT_CACHE_DROPIN_VERSION' ) ) {
			$runtime = new Runtime_Environment_Setup();
			$runtime->cleanup();
			$message = __( 'Runtime environment cleanup successful.', 'plugin-check' );
		}

		// Restore the old prefix.
		$wpdb->set_prefix( $old_prefix );

		wp_send_json_success(
			array(
				'message' => $message,
			)
		);
	}

	/**
	 * Handles the AJAX request that returns the checks to run.
	 *
	 * @since n.e.x.t
	 */
	public function get_checks_to_run() {
		// Verify the nonce before continuing.
		$valid_request = $this->verify_request( filter_input( INPUT_POST, 'nonce', FILTER_SANITIZE_FULL_SPECIAL_CHARS ) );

		if ( is_wp_error( $valid_request ) ) {
			wp_send_json_error( $valid_request, 403 );
		}

		$checks = filter_input( INPUT_POST, 'checks', FILTER_DEFAULT, FILTER_FORCE_ARRAY );
		$checks = is_null( $checks ) ? array() : $checks;
		$plugin = filter_input( INPUT_POST, 'plugin', FILTER_SANITIZE_FULL_SPECIAL_CHARS );

		try {
			$this->runner->set_check_slugs( $checks );
			$this->runner->set_plugin( $plugin );

			$plugin_basename = $this->runner->get_plugin_basename();
			$checks_to_run   = $this->runner->get_checks_to_run();
		} catch ( Exception $error ) {
			wp_send_json_error(
				new WP_Error( 'invalid-checks', $error->getMessage() ),
				403
			);
		}

		wp_send_json_success(
			array(
				'plugin' => $plugin_basename,
				'checks' => array_keys( $checks_to_run ),
			)
		);
	}

	/**
	 * Run checks.
	 *
	 * @since n.e.x.t
	 */
	public function run_checks() {
		// Verify the nonce before continuing.
		$valid_request = $this->verify_request( filter_input( INPUT_POST, 'nonce', FILTER_SANITIZE_FULL_SPECIAL_CHARS ) );

		if ( is_wp_error( $valid_request ) ) {
			wp_send_json_error( $valid_request, 403 );
		}

		$checks = filter_input( INPUT_POST, 'checks', FILTER_DEFAULT, FILTER_FORCE_ARRAY );
		$checks = is_null( $checks ) ? array() : $checks;
		$plugin = filter_input( INPUT_POST, 'plugin', FILTER_SANITIZE_FULL_SPECIAL_CHARS );

		try {
			$this->runner->set_check_slugs( $checks );
			$this->runner->set_plugin( $plugin );
			$results = $this->runner->run();
		} catch ( Exception $error ) {
			wp_send_json_error(
				new WP_Error( 'invalid-request', $error->getMessage() ),
				400
			);
		}

		wp_send_json_success(
			array(
				'message'  => __( 'Checks run successfully', 'plugin-check' ),
				'errors'   => $results->get_errors(),
				'warnings' => $results->get_warnings(),
			)
		);
	}

	/**
	 * Verify the request.
	 *
	 * @since n.e.x.t
	 *
	 * @param string $nonce The request nonce passed.
	 * @return bool|WP_Error True if the nonce is valid. WP_Error if invalid.
	 */
	protected function verify_request( $nonce ) {
		if ( ! wp_verify_nonce( $nonce, self::NONCE_KEY ) ) {
			return new WP_Error( 'invalid-nonce', __( 'Invalid nonce', 'plugin-check' ) );
		}

		if ( ! current_user_can( 'activate_plugins' ) ) {
			return new WP_Error( 'invalid-permissions', __( 'Invalid user permissions, you are not allowed to perform this request.', 'plugin-check' ) );
		}

		return true;
	}

	/**
	 * Check for a Runtime_Check in a list of checks.
	 *
	 * @since n.e.x.t
	 *
	 * @param array $checks An array of Check instances.
	 * @return bool True if a Runtime_Check exists in the array, false if not.
	 */
	protected function has_runtime_check( array $checks ) {
		foreach ( $checks as $check ) {
			if ( $check instanceof Runtime_Check ) {
				return true;
			}
		}

		return false;
	}
}