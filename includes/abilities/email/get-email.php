<?php

namespace MailHawk\Abilities\Email;

use MailHawk\Abilities\Ability;
use MailHawk\Abilities\Formatter;
use MailHawk\Classes\Email_Log_Item;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One email from the log, with its headers and, when asked for, what it said
 */
class Get_Email extends Ability {

	protected const NAME     = 'mailhawk/get-email';
	protected const CATEGORY = 'mailhawk-email';

	protected const READONLY   = true;
	protected const IDEMPOTENT = true;

	protected function get_args(): array {

		$schema = Formatter::email_schema();

		$schema['properties']['content_type'] = [ 'type' => 'string' ];

		$schema['properties']['headers'] = [
			'type'  => 'array',
			'items' => [
				'type'       => 'object',
				'properties' => [
					'name'  => [ 'type' => 'string' ],
					'value' => [ 'type' => 'string' ],
				],
				'required'   => [ 'name', 'value' ],
			],
		];

		$schema['properties']['body'] = [
			'type'        => [ 'string', 'null' ],
			'description' => __( 'What the email said, the HTML or the plain text. Null unless include_body is true.', 'mailhawk' ),
		];

		$schema['properties']['body_truncated'] = [
			'type'        => 'boolean',
			'description' => __( 'True when the body was cut short because it is very long.', 'mailhawk' ),
		];

		array_push( $schema['required'], 'content_type', 'headers', 'body', 'body_truncated' );

		return [
			'label'       => __( 'Get MailHawk Email', 'mailhawk' ),
			'description' => __( 'One email from the log: its status, recipients, error, headers and, only when include_body is true, what it said. An email can have personal data in it, so ask for the body only when it is needed.', 'mailhawk' ),

			'input_schema' => [
				'type'                 => 'object',
				'additionalProperties' => false,
				'required'             => [ 'id' ],
				'properties'           => [
					'id'           => [
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The ID of the email in the log, see mailhawk/list-emails.', 'mailhawk' ),
					],
					'include_body' => [
						'type'    => 'boolean',
						'default' => false,
					],
				],
			],

			'output_schema' => $schema,
		];
	}

	public function __invoke( $input ) {

		$input = $this->input( $input );
		$item  = new Email_Log_Item( absint( $input['id'] ?? 0 ) );

		if ( ! $item->exists() ) {
			return $this->error( 'email_not_found', __( 'There is no email with that ID in the log. It may have been deleted when the log was cleaned up.', 'mailhawk' ), 404 );
		}

		$headers = [];

		foreach ( (array) $item->headers as $header ) {
			if ( is_array( $header ) && isset( $header[0], $header[1] ) ) {
				$headers[] = [ 'name' => (string) $header[0], 'value' => (string) $header[1] ];
			}
		}

		$body      = null;
		$truncated = false;

		if ( ! empty( $input['include_body'] ) ) {

			/**
			 * The longest body to return, in characters
			 *
			 * @param int $length
			 */
			$max       = absint( apply_filters( 'mailhawk/abilities/max_body_length', 50000 ) );
			$body      = (string) $item->content;
			$truncated = mb_strlen( $body ) > $max;
			$body      = $truncated ? mb_substr( $body, 0, $max ) : $body;
		}

		return array_merge( Formatter::email( $item ), [
			'content_type'   => (string) $item->get_content_type(),
			'headers'        => $headers,
			'body'           => $body,
			'body_truncated' => $truncated,
		] );
	}
}
