(function ($, connect) {

    $(function () {
        var $connectButton = $('#connect');
        $connectButton.on('click', function () {
            $connectButton.html(connect.connecting_text);
            $connectButton.addClass('spin');
        });
    });

    var $doc = $(document);

    var $modal = $('#mailhawk-modal');
    var $overlay = $('#mailhawk-overlay');

    function  close_modal() {
        $modal.hide();
        $overlay.hide();
    }

    $doc.on('click', '.mailhawk-log-preview .close', function (e) {
        close_modal();
    });

    $doc.on('keydown', function(event) {
        if (event.key === "Escape") {
            close_modal();
        }
    });

    $doc.on('click', '.mailhawk-log-wrap .mpreview a', function (e) {

        e.preventDefault();

        var $e = $(e.target);

        var ajaxCall = $.ajax({
            type: "post",
            url: ajaxurl,
            dataType: 'json',
            data: {action: 'mailhawk_preview_email', preview: $e.attr( 'data-log-id' ) },
            success: function (response) {

                $modal.html( response.data.content );

                $.fullFrame();

                $modal.css( 'top', $(window).scrollTop() + 'px' );
                $modal.show();
                $overlay.show();
            },
        });

    });


})(jQuery, MailHawkConnect);