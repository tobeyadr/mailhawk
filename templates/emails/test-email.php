<?php

/**
 * The test email: that email from the site arrives, and what to make of where it landed
 *
 * @var string  $subject
 * @var string  $site    the name of the site
 * @var string  $from    the address it was sent from
 * @var string  $to      the address it was sent to
 * @var string  $sent_at when it was sent
 * @var array[] $faq     where it can land, each with question and answer
 */

$text = 'margin:0 0 16px;font-family:system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif;font-size:16px;line-height:1.5;color:#1d2327;';
$link = 'color:#2271b1;';

?>
<!doctype html>
<html>
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="x-apple-disable-message-reformatting"/>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
    <title><?php echo esc_html( $subject ); ?></title>
    <style>
        img {
            max-width: 100% !important;
        }

        a {
            color: #2271b1;
        }

        @media only screen and (max-width: 540px) {
            .mh-card {
                padding: 24px 20px !important;
            }
        }
    </style>
</head>
<body style="margin:0;padding:0;background-color:#d9eef4;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;background-color:#d9eef4;">
    <tr>
        <td align="center" style="padding:30px 12px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;max-width:540px;">
                <tr>
                    <td align="center" style="padding:0 0 24px;">
                        <img src="<?php echo esc_url( MAILHAWK_ASSETS_URL . 'images/mailhawk-logo-x350.png' ); ?>" alt="MailHawk" width="220"
                             style="display:block;width:220px;height:auto;border:0;">
                    </td>
                </tr>
                <tr>
                    <td class="mh-card" style="padding:32px;border-radius:10px;background-color:#ffffff;">
                        <h1 style="margin:0 0 12px;font-family:system-ui,-apple-system,'Segoe UI',Roboto,Arial,sans-serif;font-size:28px;line-height:1.3;font-weight:600;color:#1d2327;">
							<?php esc_html_e( 'It works!', 'mailhawk' ); ?>
                        </h1>
                        <p style="<?php echo esc_attr( $text ); ?>"><?php printf(
								/* translators: %s: the name of the site */
								esc_html__( 'This test email from %s went out through MailHawk, so the site can send email.', 'mailhawk' ),
								'<b>' . esc_html( $site ) . '</b>'
							); ?></p>

                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
                               style="border-collapse:collapse;margin:0 0 24px;background-color:#f6f7f7;border-radius:8px;">
							<?php foreach ( [
								__( 'From', 'mailhawk' ) => $from,
								__( 'To', 'mailhawk' )   => $to,
								__( 'Sent', 'mailhawk' ) => $sent_at,
							] as $label => $value ) : ?>
                                <tr>
                                    <td style="padding:8px 16px;width:70px;font-family:system-ui,-apple-system,'Segoe UI',Roboto,Arial,sans-serif;font-size:14px;color:#50575e;vertical-align:top;"><?php echo esc_html( $label ); ?></td>
                                    <td style="padding:8px 16px 8px 0;font-family:system-ui,-apple-system,'Segoe UI',Roboto,Arial,sans-serif;font-size:14px;color:#1d2327;word-break:break-all;"><?php echo esc_html( $value ); ?></td>
                                </tr>
							<?php endforeach; ?>
                        </table>

                        <h2 style="margin:0 0 4px;font-family:system-ui,-apple-system,'Segoe UI',Roboto,Arial,sans-serif;font-size:20px;line-height:1.3;font-weight:600;color:#1d2327;">
							<?php esc_html_e( 'Where did it land?', 'mailhawk' ); ?>
                        </h2>
                        <p style="<?php echo esc_attr( $text ); ?>color:#50575e;"><?php esc_html_e( 'Where this email ended up says how mailboxes see email from your site.', 'mailhawk' ); ?></p>

						<?php foreach ( $faq as $item ) : ?>
                            <p style="<?php echo esc_attr( $text ); ?>margin-bottom:4px;"><b><?php echo esc_html( $item['question'] ); ?></b></p>
                            <p style="<?php echo esc_attr( $text ); ?>"><?php echo wp_kses( $item['answer'], [ 'a' => [ 'href' => true ], 'b' => [], 'i' => [] ] ); ?></p>
						<?php endforeach; ?>
                    </td>
                </tr>
                <tr>
                    <td align="center" style="padding:20px 12px 0;font-family:system-ui,-apple-system,'Segoe UI',Roboto,Arial,sans-serif;font-size:13px;line-height:1.5;color:#50575e;">
						<?php printf(
							/* translators: %s: the address of the site, linked */
							esc_html__( 'Sent by the MailHawk plugin on %s. You can ignore this email.', 'mailhawk' ),
							'<a href="' . esc_url( home_url() ) . '" style="' . esc_attr( $link ) . '">' . esc_html( \MailHawk\home_url_no_scheme() ) . '</a>'
						); ?>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
