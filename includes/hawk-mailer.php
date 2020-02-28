<?php

namespace MailHawk;

if ( ! class_exists( '\PHPMailer' ) ){
    require_once ABSPATH . WPINC . '/class-phpmailer.php';
    require_once ABSPATH . WPINC . '/class-smtp.php';
}

class Hawk_Mailer extends \PHPMailer
{
    /**
     * Create a message and send it.
     * Uses the sending method specified by $Mailer.
     * @throws \phpmailerException
     * @return boolean false on error - See the ErrorInfo property for details of the error.
     */
    public function send()
    {
        throw new \phpmailerException( 'Please use a dedicated transactional email service like AWS, SendGrid, Mailgun or SendWP.', self::STOP_CRITICAL );
    }
}