<?php

namespace MailHawk\Abilities;

use WP_Error;
use function MailHawk\mailhawk_is_connected;
use function MailHawk\mailhawk_is_suspended;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * An ability MailHawk registers with the WordPress Abilities API, so AI tools can use MailHawk the way they use
 * Groundhogg. Follows the shape of Groundhogg's own base class, MailHawk does not need Groundhogg for it.
 *
 * Every ability needs the same capability as the MailHawk screens, manage_options.
 */
abstract class Ability {

	protected const NAME       = '';
	protected const CATEGORY   = '';
	protected const CAPABILITY = 'manage_options';

	protected const PUBLIC      = true;
	protected const READONLY    = false;
	protected const DESTRUCTIVE = false;
	protected const IDEMPOTENT  = false;

	/**
	 * Register the ability.
	 *
	 * @return void
	 */
	public function __construct() {

		$args = $this->get_args();

		$args['category']         = static::CATEGORY;
		$args['execute_callback'] = $this;

		$args['permission_callback'] ??= [ $this, 'can_execute' ];

		$args['meta'] = array_merge(
			[
				'public' => static::PUBLIC,
			],
			$args['meta'] ?? []
		);

		$args['meta']['annotations'] = array_merge(
			[
				'readonly'    => static::READONLY,
				'destructive' => static::DESTRUCTIVE,
				'idempotent'  => static::IDEMPOTENT,
			],
			$args['meta']['annotations'] ?? []
		);

		wp_register_ability( static::NAME, $args );
	}

	/**
	 * The label, description, input schema and output schema of the ability
	 *
	 * @return array
	 */
	abstract protected function get_args(): array;

	/**
	 * Run the ability
	 *
	 * @param mixed $input
	 *
	 * @return mixed array on success, WP_Error otherwise
	 */
	abstract public function __invoke( $input );

	/**
	 * Whether the current user can run the ability
	 *
	 * @param mixed $input
	 *
	 * @return bool
	 */
	public function can_execute( $input = null ) {
		return current_user_can( static::CAPABILITY );
	}

	/**
	 * Input is null when the caller sent nothing, which is fine when every field is optional
	 *
	 * @param mixed $input
	 *
	 * @return array
	 */
	protected function input( $input ): array {
		return is_array( $input ) ? $input : [];
	}

	/**
	 * @param string $code    without the mailhawk_ prefix
	 * @param string $message
	 * @param int    $status
	 *
	 * @return WP_Error
	 */
	protected function error( string $code, string $message, int $status = 400 ): WP_Error {
		return new WP_Error( 'mailhawk_' . $code, $message, [ 'status' => $status ] );
	}

	/**
	 * An error from MailHawk's service, as it was reported
	 *
	 * @param WP_Error $error
	 *
	 * @return WP_Error
	 */
	protected function service_error( WP_Error $error ): WP_Error {
		return new WP_Error( $error->get_error_code(), $error->get_error_message(), [ 'status' => 502 ] );
	}

	/**
	 * Most abilities talk to MailHawk's service, which they can not do before the site is connected or while the account
	 * is suspended.
	 *
	 * @return WP_Error|null null when the service can be used
	 */
	protected function check_connection(): ?WP_Error {

		if ( ! mailhawk_is_connected() ) {
			return $this->error( 'not_connected', __( 'MailHawk is not connected to an account. Connect it from Tools, MailHawk in wp-admin, this needs a person to sign in.', 'mailhawk' ), 409 );
		}

		if ( mailhawk_is_suspended() ) {
			return $this->error( 'suspended', __( 'The MailHawk account is suspended. Use mailhawk/get-status to see why and what to do next.', 'mailhawk' ), 409 );
		}

		return null;
	}
}
