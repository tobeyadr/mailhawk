(function ($, connect, review, groundhogg) {

    // The suggestions to look at Groundhogg, on the overview and the recipients screen. Learn more opens WordPress' own
    // plugin details. Saying not to show it again is one answer for both.
    $(document).on('click', '.mailhawk-groundhogg-dismiss', function () {
        $.post(groundhogg.ajax_url, {
            action: 'mailhawk_dismiss_notice',
            notice: 'groundhogg',
            _wpnonce: groundhogg.dismiss_nonce,
        });

        $('.mailhawk-groundhogg-card, .mailhawk-groundhogg-promo').remove();
    });

    // The review modal, opened from the stars in the menu
    $(function () {
        var $modal = $('#mailhawk-review-modal');
        var $button = $('#mailhawk-review-button');

        if (!$modal.length) {
            return;
        }

        function open() {
            $modal.prop('hidden', false);
            $modal.find('.mailhawk-review-now').trigger('focus');
        }

        function close() {
            $modal.prop('hidden', true);
            $button.trigger('focus');
        }

        // Nothing asks again, not even the button
        function stopAsking() {
            $.post(review.ajax_url, {
                action: 'mailhawk_dismiss_notice',
                notice: 'review',
                _wpnonce: review.nonce,
            });
            // Hidden, not removed: a link that is gone from the page doesn't open
            $modal.prop('hidden', true);
            $button.remove();
        }

        $button.on('click', open);
        $modal.on('click', '.mailhawk-modal-close, .mailhawk-review-later', close);
        $modal.on('click', '.mailhawk-review-never', stopAsking);

        // Going to review is as good as an answer, the link opens in a new tab on its own
        $modal.on('click', '.mailhawk-review-now', stopAsking);

        // A click on the dimmed area outside the dialog closes it
        $modal.on('click', function (e) {
            if (e.target === $modal[0]) {
                close();
            }
        });

        $(document).on('keydown', function (e) {
            if (e.key === 'Escape' && !$modal.prop('hidden')) {
                close();
            }
        });
    });

    $(function () {
        var $connectButton = $('#connect');
        var connectLabel = $connectButton.html();
        var connectTimer;

        // Put the button back, the customer may have come back with the browser's back button, or MailHawk may be slow
        function resetConnectButton() {
            clearTimeout(connectTimer);
            $connectButton.html(connectLabel);
            $connectButton.removeClass('spin');
        }

        $connectButton.on('click', function () {
            $connectButton.html(connect.connecting_text);
            $connectButton.addClass('spin');
            connectTimer = setTimeout(resetConnectButton, 15000);
        });

        $(window).on('pageshow', function (e) {
            if (e.originalEvent && e.originalEvent.persisted) {
                resetConnectButton();
            }
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

                $modal.show();
                $overlay.show();
            },
        });

    });


})(jQuery, MailHawkConnect, MailHawkReview, MailHawkGroundhogg);
