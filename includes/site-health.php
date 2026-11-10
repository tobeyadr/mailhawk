<?php

namespace MailHawk;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What MailHawk tells Site Health: tests on the Status tab, and a MailHawk section on the Info tab for support.
 *
 * Nothing here reaches the MTA unless a person opens Site Health, and then only for the usage test, which is the one test
 * that runs after the page has loaded. The API key and the signature secrets are never part of the information.
 */
class Site_Health {

	/**
	 * The share of the monthly limit that is worth a warning
	 */
	const USAGE_WARNING = 0.8;

	/**
	 * The test that runs after the page has loaded, and the AJAX action Site Health calls for it
	 */
	const USAGE_TEST = 'mailhawk-usage';

	public function __construct() {
		add_filter( 'site_status_tests', [ $this, 'register_tests' ] );
		add_filter( 'debug_information', [ $this, 'debug_information' ] );
		add_action( 'wp_ajax_health-check-' . self::USAGE_TEST, [ $this, 'ajax_usage_test' ] );
	}

	/**
	 * @param array $tests
	 *
	 * @return array
	 */
	public function register_tests( $tests ) {

		$tests['direct']['mailhawk_connection'] = [
			'label' => __( 'MailHawk is connected', 'mailhawk' ),
			'test'  => [ $this, 'test_connection' ],
		];

		// What is left is about sending through MailHawk, which a site that is not connected does not do
		if ( ! mailhawk_is_connected() ) {
			return $tests;
		}

		$tests['direct']['mailhawk_throttled'] = [
			'label' => __( 'MailHawk is sending email', 'mailhawk' ),
			'test'  => [ $this, 'test_throttled' ],
		];

		$tests['direct']['mailhawk_delivery_sync'] = [
			'label' => __( 'MailHawk keeps the email log up to date', 'mailhawk' ),
			'test'  => [ $this, 'test_delivery_sync' ],
		];

		// Asks the MTA, so not while the page loads
		$tests['async']['mailhawk_usage'] = [
			'label'    => __( 'MailHawk usage', 'mailhawk' ),
			'test'     => self::USAGE_TEST,
			'has_rest' => false,
		];

		return $tests;
	}

	/**
	 * A result in the shape Site Health shows
	 *
	 * @param string $test        the key of the test
	 * @param string $status      good, recommended or critical
	 * @param string $label       the headline
	 * @param string $description what it means, one or more paragraphs
	 * @param string $actions     what to do about it, as links
	 *
	 * @return array
	 */
	protected function result( $test, $status, $label, $description, $actions = '' ) {
		return [
			'label'       => $label,
			'status'      => $status,
			'badge'       => [
				'label' => __( 'MailHawk', 'mailhawk' ),
				'color' => 'blue',
			],
			'description' => '<p>' . esc_html( $description ) . '</p>',
			'actions'     => $actions,
			'test'        => $test,
		];
	}

	/**
	 * @param string $url
	 * @param string $text
	 * @param bool   $external
	 *
	 * @return string
	 */
	protected function link( $url, $text, $external = false ) {
		return sprintf(
			'<p><a href="%1$s"%2$s>%3$s</a></p>',
			esc_url( $url ),
			$external ? ' target="_blank" rel="noopener"' : '',
			esc_html( $text )
		);
	}

	/**
	 * Where the plan can be upgraded
	 *
	 * @return string
	 */
	protected function upgrade_link() {
		return $this->link( 'https://mailhawk.io/pricing/', __( 'Upgrade your plan', 'mailhawk' ), true );
	}

	/**
	 * Whether email goes out through MailHawk, and whether the MTA accepts the site
	 *
	 * @return array
	 */
	public function test_connection() {

		$test = 'mailhawk_connection';

		if ( ! mailhawk_is_connected() ) {
			return $this->result(
				$test,
				'recommended',
				__( 'MailHawk is not connected', 'mailhawk' ),
				__( 'Email from this site is not going out through MailHawk, so it is sent the way WordPress does it by default, and it may not reach the inbox.', 'mailhawk' ),
				$this->link( mailhawk_admin_page(), __( 'Connect MailHawk', 'mailhawk' ) )
			);
		}

		if ( mailhawk_is_suspended() ) {

			$suspension  = get_mailhawk_suspension();
			$since       = mailhawk_suspended_since();
			$description = [ __( 'The MailHawk service is not accepting email from this site, so none of it is going out.', 'mailhawk' ) ];

			// Older MTA builds do not say why
			$description[] = $suspension['reason']
				? sprintf( __( 'The reason given: %s', 'mailhawk' ), $suspension['reason'] )
				: __( 'This is usually a problem with the account or the subscription.', 'mailhawk' );

			if ( $since ) {
				$description[] = sprintf( __( 'Suspended since %s.', 'mailhawk' ), $since );
			}

			if ( 'organization' === $suspension['scope'] ) {
				$description[] = __( 'The whole MailHawk account is suspended, so none of its sites can send email.', 'mailhawk' );
			}

			return $this->result(
				$test,
				'critical',
				__( 'MailHawk has suspended sending for this site', 'mailhawk' ),
				implode( ' ', $description ),
				$this->link( mailhawk_account_url(), __( 'See what to do next on your MailHawk account', 'mailhawk' ), true )
				. $this->link( 'https://wordpress.org/support/plugin/mailhawk/', __( 'Ask for help', 'mailhawk' ), true )
			);
		}

		if ( is_mailhawk_setup_incomplete() ) {
			return $this->result(
				$test,
				'recommended',
				__( 'MailHawk setup is not finished', 'mailhawk' ),
				__( 'MailHawk is connected, but the setup was not finished. Email may be sent from a domain that is not verified, which hurts delivery.', 'mailhawk' ),
				$this->link( mailhawk_admin_page(), __( 'Finish the setup', 'mailhawk' ) )
			);
		}

		return $this->result(
			$test,
			'good',
			__( 'MailHawk is connected', 'mailhawk' ),
			__( 'Email from this site goes out through MailHawk.', 'mailhawk' )
		);
	}

	/**
	 * Whether the MTA turns email away because the plan is used up
	 *
	 * @return array
	 */
	public function test_throttled() {

		$test = 'mailhawk_throttled';

		// Only while the wait MailHawk asked for is still running. After it, the flag stays until an email goes through,
		// which says nothing about whether sending works again.
		if ( time() < mailhawk_throttled_until() ) {
			return $this->result(
				$test,
				'critical',
				__( 'MailHawk is turning email away', 'mailhawk' ),
				__( "This site has used all of its emails for the month, so MailHawk is not sending what it is asked to. The emails it turned away are marked as failed in the log. Sending starts again when the limit resets, or as soon as the plan is upgraded.", 'mailhawk' ),
				$this->upgrade_link() . $this->link( mailhawk_admin_page( [ 'view' => 'log', 'status' => 'failed' ] ), __( 'See the failed emails', 'mailhawk' ) )
			);
		}

		return $this->result(
			$test,
			'good',
			__( 'MailHawk is sending email', 'mailhawk' ),
			__( 'MailHawk has not turned any email away lately.', 'mailhawk' )
		);
	}

	/**
	 * Whether what is still waiting at "sent" in the log is looked up. It is a cron event, and a site where WP-Cron does
	 * not run does not do it, which only costs the log some detail.
	 *
	 * @return array
	 */
	public function test_delivery_sync() {

		$test = 'mailhawk_delivery_sync';

		// Nothing to bring up to date when the log is off
		if ( get_option( 'mailhawk_disable_email_logging' ) ) {
			return $this->result(
				$test,
				'good',
				__( 'MailHawk email logging is turned off', 'mailhawk' ),
				__( 'The email log is turned off, so there is nothing to update.', 'mailhawk' )
			);
		}

		$next = wp_next_scheduled( Delivery_Sync::HOOK );

		// The event runs every 15 minutes and is scheduled again on every load, so it always exists. Overdue by more than
		// 30 minutes means nothing is running WP-Cron.
		if ( ! $next || $next < time() - 30 * MINUTE_IN_SECONDS ) {
			return $this->result(
				$test,
				'recommended',
				__( 'MailHawk cannot update the email log by itself', 'mailhawk' ),
				__( 'The scheduled task that updates the email log has not run on time. This usually means WP-Cron is turned off and nothing else runs it. Emails are still sent, but the log may still show them as Sent after they were delivered or bounced.', 'mailhawk' )
			);
		}

		// The MTA could not be asked lately, and the sync is leaving it alone for a while
		$paused = get_transient( Delivery_Sync::PAUSE );

		if ( false !== $paused ) {
			return $this->result(
				$test,
				'recommended',
				__( 'MailHawk could not update the email log lately', 'mailhawk' ),
				sprintf(
					/* translators: %s: reason the update of the email log is paused */
					__( 'The update of the email log is paused for a while. Emails are still sent, but the log may still show them as Sent after they were delivered or bounced. Reason: %s', 'mailhawk' ),
					esc_html( (string) $paused )
				)
			);
		}

		return $this->result(
			$test,
			'good',
			__( 'MailHawk keeps the email log up to date', 'mailhawk' ),
			__( 'The scheduled task that brings the email log up to date is scheduled.', 'mailhawk' )
		);
	}

	/**
	 * How much of the month's emails are used, for Site Health to ask after the page has loaded
	 */
	public function ajax_usage_test() {

		check_ajax_referer( 'health-check-site-status' );

		if ( ! current_user_can( 'view_site_health_checks' ) ) {
			wp_send_json_error();
		}

		wp_send_json_success( $this->test_usage() );
	}

	/**
	 * @return array
	 */
	public function test_usage() {

		$test  = 'mailhawk_usage';
		$usage = get_email_usage();

		if ( ! $usage ) {
			return $this->result(
				$test,
				'recommended',
				__( 'MailHawk usage could not be checked', 'mailhawk' ),
				__( 'MailHawk did not answer when it was asked how many emails this site used this month.', 'mailhawk' )
			);
		}

		$used  = absint( $usage['usage'] );
		$limit = absint( $usage['limit'] );

		if ( $limit > 0 && $used >= $limit ) {
			return $this->result(
				$test,
				'critical',
				__( 'This site used all of its emails for the month', 'mailhawk' ),
				sprintf( __( 'This site sent %1$s of the %2$s emails in its plan. MailHawk turns email away until the limit resets.', 'mailhawk' ), number_format_i18n( $used ), number_format_i18n( $limit ) ),
				$this->upgrade_link()
			);
		}

		if ( $limit > 0 && $used / $limit > self::USAGE_WARNING ) {
			return $this->result(
				$test,
				'recommended',
				__( 'This site is close to the limit of its plan', 'mailhawk' ),
				sprintf( __( 'This site sent %1$s of the %2$s emails in its plan this month. When they are used up, MailHawk turns email away until the limit resets.', 'mailhawk' ), number_format_i18n( $used ), number_format_i18n( $limit ) ),
				$this->upgrade_link()
			);
		}

		return $this->result(
			$test,
			'good',
			__( 'This site has emails left this month', 'mailhawk' ),
			sprintf( __( 'This site sent %1$s of the %2$s emails in its plan this month.', 'mailhawk' ), number_format_i18n( $used ), number_format_i18n( $limit ) )
		);
	}

	/**
	 * The MailHawk section of the Info tab. Nothing in it is secret: the key is only said to be there or not, and the MTA
	 * is not asked anything.
	 *
	 * @param array $info
	 *
	 * @return array
	 */
	public function debug_information( $info ) {

		$throttled_until = mailhawk_throttled_until();
		$pause           = get_transient( Delivery_Sync::PAUSE );
		$next_sync       = wp_next_scheduled( Delivery_Sync::HOOK );

		// What the MTA said, as one line for support: reason, since when, and whether it is the whole account
		$suspension = __( 'None', 'mailhawk' );

		if ( mailhawk_is_suspended() ) {
			$details    = get_mailhawk_suspension();
			$suspension = implode( ' | ', array_filter( [
				$details['reason'] ? $details['reason'] : __( 'Not given', 'mailhawk' ),
				$details['suspended_at'] ? gmdate( 'Y-m-d H:i:s', $details['suspended_at'] ) . ' UTC' : '',
				'organization' === $details['scope'] ? __( 'Whole account', 'mailhawk' ) : '',
			] ) );
		}

		$yes_no = function ( $value ) {
			return $value ? __( 'Yes', 'mailhawk' ) : __( 'No', 'mailhawk' );
		};

		$when = function ( $timestamp ) {
			return $timestamp ? wp_date( 'Y-m-d H:i:s', $timestamp ) . ' (' . wp_timezone_string() . ')' : __( 'No', 'mailhawk' );
		};

		$info['mailhawk'] = [
			'label'  => __( 'MailHawk', 'mailhawk' ),
			'fields' => [
				'version'         => [
					'label' => __( 'Version', 'mailhawk' ),
					'value' => MAILHAWK_VERSION,
				],
				'connected'       => [
					'label' => __( 'Connected', 'mailhawk' ),
					'value' => $yes_no( mailhawk_is_connected() ),
					'debug' => mailhawk_is_connected() ? 'yes' : 'no',
				],
				'api_key'         => [
					'label' => __( 'API key saved', 'mailhawk' ),
					'value' => $yes_no( (bool) get_mailhawk_api_key() ),
				],
				'suspended'       => [
					'label' => __( 'Suspended by MailHawk', 'mailhawk' ),
					'value' => $yes_no( mailhawk_is_suspended() ),
				],
				'suspension'      => [
					'label' => __( 'Suspension reason', 'mailhawk' ),
					'value' => $suspension,
				],
				'throttled'       => [
					'label' => __( 'Email turned away until', 'mailhawk' ),
					'value' => $when( $throttled_until ),
				],
				'network_active'  => [
					'label' => __( 'Network active', 'mailhawk' ),
					'value' => $yes_no( is_mailhawk_network_active() ),
				],
				'logging'         => [
					'label' => __( 'Email logging', 'mailhawk' ),
					'value' => $yes_no( ! get_option( 'mailhawk_disable_email_logging' ) ),
				],
				'log_entries'     => [
					'label' => __( 'Emails in the log', 'mailhawk' ),
					'value' => number_format_i18n( absint( Plugin::instance()->log->count() ) ),
				],
				'retry_failed'    => [
					'label' => __( 'Failed emails are retried', 'mailhawk' ),
					'value' => $yes_no( get_option( 'mailhawk_retry_failed_emails' ) ),
				],
				'next_sync'       => [
					'label' => __( 'Next update of the email log', 'mailhawk' ),
					'value' => $when( $next_sync ),
				],
				'sync_paused'     => [
					'label' => __( 'Update of the email log paused', 'mailhawk' ),
					'value' => $pause ? sanitize_text_field( (string) $pause ) : __( 'No', 'mailhawk' ),
				],
				'webhook'         => [
					'label' => __( 'Webhook address', 'mailhawk' ),
					'value' => get_rest_api_webhook_listener_uri(),
				],
			],
		];

		return $info;
	}
}
