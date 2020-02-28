<?php

namespace MailHawk;

class Connect
{

    static $SEVER_URL = 'https://mailhawk.com';

    public function __construct()
    {
        add_action( 'admin_init', [ $this, 'listen' ] );
    }

    public function listen()
    {

    }

}
