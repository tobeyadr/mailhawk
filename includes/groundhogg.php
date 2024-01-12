<?php

namespace MailHawk;

use function Groundhogg\get_contactdata;
use function Groundhogg\get_db;
use function Groundhogg\is_a_contact;
use function Groundhogg\the_email;

defined( 'ABSPATH' ) || exit;

class Groundhogg {

	/**
	 * Constructor.
	 *
	 * @since 3.36.1
	 *
	 * @return void
	 */
	public function __construct() {
		add_action( 'wp_ajax_mailhawk_groundhogg_remote_install', array( $this, 'ajax_callback_remote_install' ) );
	}

	/**
	 * Ajax callback for installing MailHawk Plugin.
	 *
	 * @since 3.36.1
	 *
	 * @hook  wp_ajax_mailhawk_groundhogg_remote_install
	 *
	 * @return void
	 */
	public function ajax_callback_remote_install() {

		$ret = $this->do_remote_install();
		ob_clean();
		wp_send_json( $ret, ! empty( $ret['status'] ) ? $ret['status'] : 200 );

	}

	/**
	 * Remote installation method.
	 *
	 * @since 3.36.1
	 *
	 * @return array
	 */
	public function do_remote_install() {

		if ( ! current_user_can( 'install_plugins' ) || ! wp_verify_nonce( get_request_var( 'nonce' ), 'install_groundhogg' ) ) {
			return array(
				'code'    => 'groundhogg_install_unauthorized',
				'message' => __( 'You do not have permission to perform this action.', 'groundhogg' ),
				'status'  => 403,
			);
		}

		$install = $this->install();

		if ( is_wp_error( $install ) ) {
			return array(
				'code'    => $install->get_error_code(),
				'message' => $install->get_error_message(),
				'status'  => 400,
			);
		}

		if ( ! defined( 'GROUNDHOGG_VERSION' ) ) {
			return array(
				'code'    => 'groundhogg_missing',
				'message' => 'MailHawk not installed.',
				'status'  => 400,
			);
		}

		return array(
			'redirect_uri' => admin_url( 'admin.php?page=gh_guided_setup' ),
		);

	}

	/**
	 * Install / Activate MailHawk plugin.
	 *
	 * @since 3.36.1
	 *
	 * @return \WP_Error|true
	 */
	private function install() {

		$is_groundhogg_installed = false;

		foreach ( get_plugins() as $path => $details ) {
			if ( false === strpos( $path, '/groundhogg.php' ) ) {
				continue;
			}
			$is_groundhogg_installed = true;
			$activate                = activate_plugin( $path );
			if ( is_wp_error( $activate ) ) {
				return $activate;
			}
			break;
		}

		$install = null;
		if ( ! $is_groundhogg_installed ) {

			include_once ABSPATH . 'wp-admin/includes/plugin-install.php';
			include_once ABSPATH . 'wp-admin/includes/file.php';
			include_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

			// Use the WordPress Plugins API to get the plugin download link.
			$api = plugins_api(
				'plugin_information',
				array(
					'slug' => 'groundhogg',
				)
			);
			if ( is_wp_error( $api ) ) {
				return $api;
			}

			// Use the AJAX upgrader skin to quietly install the plugin.
			$upgrader = new \Plugin_Upgrader( new \WP_Ajax_Upgrader_Skin() );
			$install  = $upgrader->install( $api->download_link );
			if ( is_wp_error( $install ) ) {
				return $install;
			}

			$activate = activate_plugin( $upgrader->plugin_info() );
			if ( is_wp_error( $activate ) ) {
				return $activate;
			}
		}

		// Final check to see if Groundhogg is available.
		if ( ! defined( 'GROUNDHOGG_VERSION' ) ) {
			return new \WP_Error( 'groundhogg_not_found', __( 'Groundhogg plugin not found. Please try again.', 'groundhogg' ), $install );
		}

		return true;

	}

	/**
	 * Output some quick and dirty inline JS.
	 *
	 * @since 3.36.1
	 *
	 * @return void
	 */
	public function output_js() {
		?>
        <script>
          var btn = document.getElementById('groundhogg-connect')
          btn.addEventListener('click', function (e) {
            e.preventDefault()
            mailhawk_groundhogg_remote_install()
          })

          /**
           * Perform AJAX request to install MailHawk plugin.
           *
           * @since 3.36.1
           *
           * @return void
           */
          function mailhawk_groundhogg_remote_install () {

            var data = {
              'action': 'mailhawk_groundhogg_remote_install',
              'nonce': '<?php echo wp_create_nonce( 'install_groundhogg' ); ?>',
            }

            jQuery.post(ajaxurl, data, function (res) {
              // Redirect to the Groundhogg guided setup
              window.location = res.redirect_uri
            }).fail(function (jqxhr) {
              if (jqxhr.responseJSON && jqxhr.responseJSON.message) {
                alert('Error: ' + jqxhr.responseJSON.message)
                console.log(jqxhr)
              }
            })
          }
        </script>
		<?php

	}

	/**
	 * @var Groundhogg;
	 */
	public static $instance;

	/**
	 * Instance.
	 *
	 * Ensures only one instance of the plugin class is loaded or can be loaded.
	 *
	 * @since  1.0.0
	 * @access public
	 * @static
	 *
	 * @return Groundhogg An instance of the class.
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Clone.
	 *
	 * Disable class cloning and throw an error on object clone.
	 *
	 * The whole idea of the singleton design pattern is that there is a single
	 * object. Therefore, we don't want the object to be cloned.
	 *
	 * @access public
	 * @since  1.0.0
	 */
	public function __clone() {
		// Cloning instances of the class is forbidden.
		_doing_it_wrong( __FUNCTION__, esc_html__( 'Something went wrong.', 'groundhogg' ), '2.0.0' );
	}

	/**
	 * Wakeup.
	 *
	 * Disable unserializing of the class.
	 *
	 * @access public
	 * @since  1.0.0
	 */
	public function __wakeup() {
		// Unserializing instances of the class is forbidden.
		_doing_it_wrong( __FUNCTION__, esc_html__( 'Something went wrong.', 'groundhogg' ), '2.0.0' );
	}

}
