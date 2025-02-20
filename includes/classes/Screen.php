<?php
/**
 * Determine which ElasticPress screen we are viewing
 *
 * @since  3.0
 * @package elasticpress
 */

namespace ElasticPress;

use ElasticPress\Utils;
use ElasticPress\Installer;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Screen class
 */
class Screen {
	/**
	 * Current screen
	 *
	 * @var string
	 * @since  3.0
	 */
	protected $screen = null;

	/**
	 * Initialize class
	 *
	 * @since 3.0
	 */
	public function setup() {
		add_action( 'admin_init', [ $this, 'determine_screen' ] );
	}

	/**
	 * Determine current ElasticPress screen. null means not EP screen.
	 *
	 * @since 3.0
	 */
	public function determine_screen() {
		// If in network mode, don't output notice in admin and vice-versa.
		if ( defined( 'EP_IS_NETWORK' ) && EP_IS_NETWORK ) {
			if ( ! is_network_admin() ) {
				return false;
			}
		} else {
			if ( is_network_admin() ) {
				return false;
			}
		}

		if ( ! empty( $_GET['page'] ) && false !== strpos( $_GET['page'], 'elasticpress' ) ) {
			$install_status = Installer::factory()->get_install_status();

			$this->screen = 'install';

			if ( 'elasticpress' === $_GET['page'] ) {
				if ( ! isset( $_GET['install_complete'] ) && ( true === $install_status || isset( $_GET['do_sync'] ) ) ) {
					$this->screen = 'dashboard';
				}
			} elseif ( 'elasticpress-settings' === $_GET['page'] ) {
				if ( true === $install_status || 2 === $install_status || isset( $_GET['do_sync'] ) ) {
					$this->screen = 'settings';
				}
			}
		}
	}

	/**
	 * Output template for current screen
	 *
	 * @since 3.0
	 */
	public function output() {
		$install_status = Installer::factory()->get_install_status();

		if ( 'dashboard' === $this->screen ) {
			require_once __DIR__ . '/../partials/dashboard-page.php';
		} elseif ( 'settings' === $this->screen ) {
			require_once __DIR__ . '/../partials/settings-page.php';
		} elseif ( 'install' === $this->screen ) {
			require_once __DIR__ . '/../partials/install-page.php';
		}
	}

	/**
	 * Get current screen
	 *
	 * @since  3.0
	 * @return string
	 */
	public function get_current_screen() {
		return $this->screen;
	}

	/**
	 * Set current screen
	 *
	 * @since  3.0
	 * @param  string $screen Screen to set
	 */
	public function set_current_screen( $screen ) {
		$this->screen = $screen;
	}

	/**
	 * Return singleton instance of class
	 *
	 * @return self
	 * @since 3.0
	 */
	public static function factory() {
		static $instance = false;

		if ( ! $instance ) {
			$instance = new self();
			$instance->setup();
		}

		return $instance;
	}
}
