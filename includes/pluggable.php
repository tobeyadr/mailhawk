<?php

use MailHawk\Hawk_Mailer;
use function MailHawk\get_admin_mailhawk_uri;
use function MailHawk\is_valid_email;

if ( ! function_exists( 'wp_mail' ) && get_option( 'mailhawk_is_connected' ) === 'yes' ):

	function wp_mail( $to, $subject, $message, $headers = '', $attachments = array() ) {
		return mailhawk_mail( $to, $subject, $message, $headers, $attachments );
	}
elseif ( function_exists( 'wp_mail' ) && get_option( 'mailhawk_is_connected' ) === 'yes' ) :
	add_action( 'admin_notices', 'mailhawk_wp_mail_already_defined' );
endif;

function mailhawk_wp_mail_already_defined() {
	?>
	<div class="notice notice-warning is-dismissible">
		<img class="alignleft" height="70" style="margin: 3px 10px 0 0"
		     src="<?php echo esc_url( MAILHAWK_ASSETS_URL . 'images/hawk-head.png' ); ?>" alt="Hawk">
		<p>
			<?php _e( '<b>Attention:</b> It looks like another plugin is overwriting the <code>wp_mail</code> function. Please disable it to allow MailHawk to work properly.', 'mailhawk' ); ?>
		</p>
	</div>
	<?php
}

/**
 * Sends an email, similar to PHP's mail function.
 *
 * A true return value does not automatically mean that the user received the
 * email successfully. It just only means that the method used was able to
 * process the request without any errors.
 *
 * The default content type is `text/plain` which does not allow using HTML.
 * However, you can set the content type of the email by using the
 * {@see 'wp_mail_content_type'} filter.
 *
 * The default charset is based on the charset used on the blog. The charset can
 * be set using the {@see 'wp_mail_charset'} filter.
 *
 * @param string|array $to Array or comma-separated list of email addresses to send message.
 * @param string $subject Email subject
 * @param string $message Message contents
 * @param string|array $headers Optional. Additional headers.
 * @param string|array $attachments Optional. Files to attach.
 *
 * @return bool Whether the email contents were sent successfully.
 * @since 1.2.1
 *
 * @global Hawk_Mailer $hawkmailer
 *
 */
function mailhawk_mail( $to, $subject, $message, $headers = '', $attachments = array() ) {
	// Compact the input, apply the filters, and extract them back out

	/**
	 * Filters the wp_mail() arguments.
	 *
	 * @param array $args A compacted array of wp_mail() arguments, including the "to" email,
	 *                    subject, message, headers, and attachments values.
	 *
	 * @since 2.2.0
	 *
	 */
	$atts = apply_filters( 'wp_mail', compact( 'to', 'subject', 'message', 'headers', 'attachments' ) );

	if ( isset( $atts['to'] ) ) {
		$to = $atts['to'];
	}

	if ( ! is_array( $to ) ) {
		$to = explode( ',', $to );
	}

	if ( isset( $atts['subject'] ) ) {
		$subject = $atts['subject'];
	}

	if ( isset( $atts['message'] ) ) {
		$message = $atts['message'];
	}

	if ( isset( $atts['headers'] ) ) {
		$headers = $atts['headers'];
	}

	if ( isset( $atts['attachments'] ) ) {
		$attachments = $atts['attachments'];
	}

	if ( ! is_array( $attachments ) ) {
		$attachments = explode( "\n", str_replace( "\r\n", "\n", $attachments ) );
	}

	global $hawkmailer;

	// (Re)create it, if it's gone missing
	if ( ! ( $hawkmailer instanceof Hawk_Mailer ) ) {
		$hawkmailer = new Hawk_Mailer( true );
	}

	// Headers
	$cc       = array();
	$bcc      = array();
	$reply_to = array();

	if ( empty( $headers ) ) {
		$headers = array();
	} else {
		if ( ! is_array( $headers ) ) {
			// Explode the headers out, so this function can take both
			// string headers and an array of headers.
			$tempheaders = explode( "\n", str_replace( "\r\n", "\n", $headers ) );
		} else {
			$tempheaders = $headers;
		}
		$headers = array();

		// If it's actually got contents
		if ( ! empty( $tempheaders ) ) {
			// Iterate through the raw headers
			foreach ( (array) $tempheaders as $header ) {
				if ( strpos( $header, ':' ) === false ) {
					if ( false !== stripos( $header, 'boundary=' ) ) {
						$parts    = preg_split( '/boundary=/i', trim( $header ) );
						$boundary = trim( str_replace( array( "'", '"' ), '', $parts[1] ) );
					}
					continue;
				}
				// Explode them out
				list( $name, $content ) = explode( ':', trim( $header ), 2 );

				// Cleanup crew
				$name    = trim( $name );
				$content = trim( $content );

				switch ( strtolower( $name ) ) {
					// Mainly for legacy -- process a From: header if it's there
					case 'from':
						$bracket_pos = strpos( $content, '<' );
						if ( $bracket_pos !== false ) {
							// Text before the bracketed email is the "From" name.
							if ( $bracket_pos > 0 ) {
								$from_name = substr( $content, 0, $bracket_pos - 1 );
								$from_name = str_replace( '"', '', $from_name );
								$from_name = trim( $from_name );
							}

							$from_email = substr( $content, $bracket_pos + 1 );
							$from_email = str_replace( '>', '', $from_email );
							$from_email = trim( $from_email );

							// Avoid setting an empty $from_email.
						} else if ( '' !== trim( $content ) ) {
							$from_email = trim( $content );
						}
						break;
					case 'content-type':
						if ( strpos( $content, ';' ) !== false ) {
							list( $type, $charset_content ) = explode( ';', $content );
							$content_type = trim( $type );
							if ( false !== stripos( $charset_content, 'charset=' ) ) {
								$charset = trim( str_replace( array( 'charset=', '"' ), '', $charset_content ) );
							} else if ( false !== stripos( $charset_content, 'boundary=' ) ) {
								$boundary = trim( str_replace( array(
									'BOUNDARY=',
									'boundary=',
									'"'
								), '', $charset_content ) );
								$charset  = '';
							}

							// Avoid setting an empty $content_type.
						} else if ( '' !== trim( $content ) ) {
							$content_type = trim( $content );
						}
						break;
					case 'cc':
						$cc = array_merge( (array) $cc, explode( ',', $content ) );
						break;
					case 'bcc':
						$bcc = array_merge( (array) $bcc, explode( ',', $content ) );
						break;
					case 'reply-to':
						$reply_to = array_merge( (array) $reply_to, explode( ',', $content ) );
						break;
					default:
						// Add it to our grand headers array
						$headers[ trim( $name ) ] = trim( $content );
						break;
				}
			}
		}
	}

	// Empty out the values that may be set
	$hawkmailer->clearAllRecipients();
	$hawkmailer->clearAttachments();
	$hawkmailer->clearCustomHeaders();
	$hawkmailer->clearReplyTos();
	$hawkmailer->clearAltBody();

	// From email and name
	// If we don't have a name from the input headers
	if ( ! isset( $from_name ) ) {
		$from_name = 'WordPress';
	}

	/* If we don't have an email from the input headers default to wordpress@$sitename
	 * Some hosts will block outgoing mail from this address if it doesn't exist but
	 * there's no easy alternative. Defaulting to admin_email might appear to be another
	 * option but some hosts may refuse to relay mail from an unknown domain. See
	 * https://core.trac.wordpress.org/ticket/5007.
	 */

	if ( ! isset( $from_email ) ) {
		// Get the site domain and get rid of www.
		$sitename = strtolower( $_SERVER['SERVER_NAME'] );
		if ( substr( $sitename, 0, 4 ) == 'www.' ) {
			$sitename = substr( $sitename, 4 );
		}

		$from_email = 'wordpress@' . $sitename;
	}

	/**
	 * Filters the email address to send from.
	 *
	 * @param string $from_email Email address to send from.
	 *
	 * @since 2.2.0
	 *
	 */
	$from_email = apply_filters( 'wp_mail_from', $from_email );

	/**
	 * Filters the name to associate with the "from" email address.
	 *
	 * @param string $from_name Name associated with the "from" email address.
	 *
	 * @since 2.3.0
	 *
	 */
	$from_name = apply_filters( 'wp_mail_from_name', $from_name );

	try {
		$hawkmailer->setFrom( $from_email, $from_name, false );
	} catch ( phpmailerException $e ) {
		$mail_error_data                             = compact( 'to', 'subject', 'message', 'headers', 'attachments' );
		$mail_error_data['phpmailer_exception_code'] = $e->getCode();

		/** This filter is documented in wp-includes/pluggable.php */
		do_action( 'wp_mail_failed', new WP_Error( 'wp_mail_failed', $e->getMessage(), $mail_error_data ) );

		return false;
	}

	// Set mail's subject and body
	$hawkmailer->Subject = $subject;
	$hawkmailer->Body    = $message;

	// Set destination addresses, using appropriate methods for handling addresses
	$address_headers = compact( 'to', 'cc', 'bcc', 'reply_to' );

	foreach ( $address_headers as $address_header => $addresses ) {
		if ( empty( $addresses ) ) {
			continue;
		}

		foreach ( (array) $addresses as $address ) {
			try {
				// Break $recipient into name and address parts if in the format "Foo <bar@baz.com>"
				$recipient_name = '';

				if ( preg_match( '/(.*)<(.+)>/', $address, $matches ) ) {
					if ( count( $matches ) == 3 ) {
						$recipient_name = $matches[1];
						$address        = $matches[2];
					}
				}

				if ( ! is_valid_email( $address ) ) {
					do_action( 'wp_mail_failed', new WP_Error( 'wp_mail_failed', sprintf( 'MailHawk marked %s as an invalid email address. If you wish to allow this recipient to receive email please whitelist their address.', $address ) ) );

					return false;
				}

				switch ( $address_header ) {
					case 'to':
						$hawkmailer->addAddress( $address, $recipient_name );
						break;
					case 'cc':
						$hawkmailer->addCc( $address, $recipient_name );
						break;
					case 'bcc':
						$hawkmailer->addBcc( $address, $recipient_name );
						break;
					case 'reply_to':
						$hawkmailer->addReplyTo( $address, $recipient_name );
						break;
				}
			} catch ( phpmailerException $e ) {
				continue;
			}
		}
	}

	// Set to use PHP's mail()
	$hawkmailer->isMail();

	// Set Content-Type and charset
	// If we don't have a content-type from the input headers
	// Set Content-Type and charset
	// If we don't have a content-type from the input headers
	if ( ! isset( $content_type ) || empty( $content_type ) ) {
		$content_type = contains_html( $message ) ? 'text/html' : 'text/plain';
	}

	/**
	 * Filters the wp_mail() content type.
	 *
	 * @param string $content_type Default wp_mail() content type.
	 *
	 * @since 2.3.0
	 *
	 */
	$content_type = apply_filters( 'wp_mail_content_type', $content_type );

	$hawkmailer->ContentType = $content_type;

	// Set whether it's plaintext, depending on $content_type
	if ( 'text/html' == $content_type ) {
		$hawkmailer->isHTML( true );
	}

	// If we don't have a charset from the input headers
	if ( ! isset( $charset ) ) {
		$charset = get_bloginfo( 'charset' );
	}

	// Set the content-type and charset

	/**
	 * Filters the default wp_mail() charset.
	 *
	 * @param string $charset Default email charset.
	 *
	 * @since 2.3.0
	 *
	 */
	$hawkmailer->CharSet = apply_filters( 'wp_mail_charset', $charset );

	// Set custom headers.
	if ( ! empty( $headers ) ) {
		foreach ( (array) $headers as $name => $content ) {
			// Only add custom headers not added automatically by PHPMailer.
			if ( ! in_array( $name, array( 'MIME-Version', 'X-Mailer' ) ) ) {
				$hawkmailer->addCustomHeader( sprintf( '%1$s: %2$s', $name, $content ) );
			}
		}

		if ( false !== stripos( $content_type, 'multipart' ) && ! empty( $boundary ) ) {
			$hawkmailer->addCustomHeader( sprintf( "Content-Type: %s;\n\t boundary=\"%s\"", $content_type, $boundary ) );
		}
	}

	if ( ! empty( $attachments ) ) {
		foreach ( $attachments as $attachment ) {
			try {
				$hawkmailer->addAttachment( $attachment );
			} catch ( phpmailerException $e ) {
				continue;
			}
		}
	}
	
	/**
	 * Fires after PHPMailer is initialized.
	 *
	 * @param PHPMailer $hawkmailer The PHPMailer instance (passed by reference).
	 *
	 * @since 2.2.0
	 *
	 */
	do_action_ref_array( 'phpmailer_init', array( &$hawkmailer ) );
	
	// Set the AltBody if not set and the email is HTML based...
	if ( $hawkmailer->ContentType === 'text/html' && empty( $hawkmailer->AltBody )){
	    $hawkmailer->AltBody = wp_strip_all_tags( $hawkmailer->Body );
    }

	// Send!
	try {
		return $hawkmailer->send();
	} catch ( phpmailerException $e ) {

		$mail_error_data                             = compact( 'to', 'subject', 'message', 'headers', 'attachments' );
		$mail_error_data['phpmailer_exception_code'] = $e->getCode();

		/**
		 * Fires after a phpmailerException is caught.
		 *
		 * @param WP_Error $error A WP_Error object with the phpmailerException message, and an array
		 *                        containing the mail recipient, subject, message, headers, and attachments.
		 *
		 * @since 4.4.0
		 *
		 */
		do_action( 'wp_mail_failed', new WP_Error( 'wp_mail_failed', $e->getMessage(), $mail_error_data ) );

		return false;
	}
}

/**
 * If the stripped version of the text is different from the passed text
 * then the text contains HTML.
 *
 * @param string $text
 *
 * @return bool
 */
function contains_html( $text='' ){
	return wp_strip_all_tags( $text ) != $text;
}