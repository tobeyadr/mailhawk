<?php

/**
 * Email template to send to inform the admin of quarantined emails
 */

?>
<!doctype html>
<html>
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="x-apple-disable-message-reformatting"/>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
    <title><?php esc_html_e( $subject ); ?></title>
    <base target="_blank">
    <style id="global-styles">
        img {
            max-width: 100% !important;
        }

        body {
            font-size: 14px;
            font-family: Arial, "Helvetica Neue", Helvetica, sans-serif;
            font-weight: 400;
        }

        .footer p {
            font-size: 13px;
            color: #999999;
            margin: .5em 0;
        }

        .footer p a {
            text-decoration: none;
        }
    </style>
    <style id="responsive">

        @media only screen and (max-width: 480px) {
            table.responsive.email-columns,
            table.responsive.email-columns > tbody,
            table.responsive.email-columns tr.email-columns-row,
            table.responsive.email-columns tr.email-columns-row > td.email-columns-cell {
                display: block !important;
                width: auto !important;
            }

        }
    </style>
    <style id="block-styles">

        #b-89083668-4345-487d-9758-430d164c8965 {
            padding: 30px 30px 30px 30px;
        }

        #b-04cd15ed-ff22-4d67-8d54-897be6ba9dc2 p, #b-04cd15ed-ff22-4d67-8d54-897be6ba9dc2 li {
            line-height: 1.4;
            font-family: system-ui, sans-serif;
            font-weight: normal;
            font-size: 16px;
            font-style: normal;
            text-transform: none;
        }

        #b-04cd15ed-ff22-4d67-8d54-897be6ba9dc2 a {
            line-height: 1.4;
            font-family: system-ui, sans-serif;
            font-weight: normal;
            font-size: 16px;
            font-style: normal;
            text-transform: none;
            color: #488aff;
        }

        #b-04cd15ed-ff22-4d67-8d54-897be6ba9dc2 b, #b-04cd15ed-ff22-4d67-8d54-897be6ba9dc2 strong {
            font-weight: bold;
        }

        #b-04cd15ed-ff22-4d67-8d54-897be6ba9dc2 ul {
            list-style: disc;
            padding-left: 30px;
        }

        #b-04cd15ed-ff22-4d67-8d54-897be6ba9dc2 ol {
            padding-left: 30px;
        }

        #b-04cd15ed-ff22-4d67-8d54-897be6ba9dc2 h1 {
            line-height: 1.4;
            font-family: system-ui, sans-serif;
            font-weight: 500;
            font-size: 42px;
            font-style: normal;
            text-transform: none;
        }

        #b-04cd15ed-ff22-4d67-8d54-897be6ba9dc2 h2 {
            line-height: 1.4;
            font-family: system-ui, sans-serif;
            font-weight: 500;
            font-size: 24px;
            font-style: normal;
            text-transform: none;
        }

        #b-04cd15ed-ff22-4d67-8d54-897be6ba9dc2 h3 {
            line-height: 1.4;
            font-family: system-ui, sans-serif;
            font-weight: 500;
            font-size: 20px;
            font-style: normal;
            text-transform: none;
        }

        #b-04cd15ed-ff22-4d67-8d54-897be6ba9dc2 {
            padding: 30px 30px 30px 30px;
            border-radius: 10px 10px 10px 10px;
            background-color: #ffffff;
        }

    </style>
</head>
<body class="email responsive template-boxed" style="background-color: #d9eef4">
<table class="alignment-container" style="width: 100%;border-collapse: collapse;" cellpadding="0" cellspacing="0"
       role="presentation">
    <tr>
        <td align="center" bgcolor="#d9eef4"
            background="" style="background-color:#d9eef4;">
            <table class="content-container" cellpadding="0" cellspacing="0" style="border-collapse: collapse"
                   role="presentation">
                <tr>
                    <td width="500" style="width: 500px">
                        <div class="body-content" style="text-align: left;">
                            <table cellpadding="0" cellspacing="0" width="100%" role="presentation">
                                <tr>
                                    <td id="b-89083668-4345-487d-9758-430d164c8965" bgcolor="" background=""
                                        valign="top" style="padding: 30px; overflow: hidden;">
                                        <div class="img-container" style="text-align: center;">
                                            <img src="<?php echo MAILHAWK_ASSETS_URL . 'images/mailhawk-logo-x350.png'; ?>"
                                                 alt="" width="300" height="auto"
                                                 style="box-sizing: border-box; vertical-align: bottom; height: auto;">
                                        </div>
                                    </td>
                                </tr>
                                <tr>
                                    <td id="b-04cd15ed-ff22-4d67-8d54-897be6ba9dc2" bgcolor="#ffffff" background="" valign="top"
                                        style="padding: 30px; border-radius: 10px; background-color: rgb(255, 255, 255); overflow: hidden;">
                                        <p>Hello admin!</p>
                                        <p>To prevent damage to your sender reputation MailHawk has quarantined <b><?php _e( number_format_i18n( $count_quarantined ) ); ?></b> emails
                                            on <b><a href="<?php echo esc_url( home_url() ) ?>"><?php _e( \MailHawk\home_url_no_scheme() ) ?></a></b>.</p>
                                        <p>MailHawk <a href="https://mailhawk.io/email-quarantine/">predicts and quarantines emails</a> that <i>if sent</i> may bounce, cause complaints, or otherwise harm your sender reputation.</p>
                                        <p>These emails can be released and sent manually in just a few clicks from the MailHawk dashboard.</p>
                                        <p>Please take the following steps to either release or reject the quarantined emails...</p>
                                        <ol>
                                            <li>Go to the <a href="<?php echo esc_url( \MailHawk\mailhawk_admin_page( [ 'view' => 'log', 'status' => 'quarantine' ] ) ) ?>">MailHawk dashboard</a>.</li>
                                            <li>Review each quarantined email, its content, and its intended recipient.</li>
                                            <li><b style="color: #2ba123">Release</b> emails that you are confident will be delivered.</li>
                                            <li><b style="color: #aa0000;">Reject</b> any emails that seem suspicious or have unverified recipients.</li>
                                        </ol>
                                        <p><a href="<?php echo esc_url( \MailHawk\mailhawk_admin_page( [ 'view' => 'log', 'status' => 'quarantine' ] ) ) ?>">Review quarantined emails now!</a></p>
                                        <p>Unreleased emails will automatically be deleted within <?php _e( \MailHawk\get_log_retention_days() ) ?> days of being quarantined.</p>
                                        <p>If you have any questions or feedback about the quarantine system, please <a href="https://mailhawk.io/contact/">let us know.</a></p>
                                        <p><i>~ The MailHawk Team</i></p>
                                    </td>
                                </tr>
                                <tr>
                                    <td id="b-0388802d-ca98-4668-aaf1-f7e9e29fb5b4" class="" bgcolor="" background=""
                                        valign="top" style="padding: 5px; overflow: hidden;">
                                        <table cellspacing="0" cellpadding="0" role="presentation">
                                            <tr>
                                                <td height="20" style="height: 20px;"></td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                        </div>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>

