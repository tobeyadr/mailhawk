( function ($, connect ) {

    $(function () {
        var $connectButton = $( '#connect' );
        $connectButton.on( 'click', function () {
            $connectButton.html( connect.connecting_text );
            $connectButton.addClass( 'spin' );
        } );
    });

})(jQuery, MailHawkConnect );