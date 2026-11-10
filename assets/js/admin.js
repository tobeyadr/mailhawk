(function ($, connect, review, groundhogg, dns) {

    // Copy text, with a fallback for wp-admin served without HTTPS, where the clipboard API is not there
    function copyText(text) {
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text);
        }

        var $field = $('<textarea readonly>').val(text).css({position: 'fixed', top: 0, left: 0, opacity: 0}).appendTo('body');
        $field[0].select();

        var copied = document.execCommand('copy');
        $field.remove();

        return copied ? $.Deferred().resolve().promise() : $.Deferred().reject().promise();
    }

    // The names on the DNS screen: the host inside copies just the host, the rest of the pill the full hostname. The
    // TTL and value pills copy what they show.
    $(document).on('click', '.mailhawk-dns-name, .mailhawk-dns-host', function (e) {
        e.stopPropagation();

        var $target = $(this);
        var $name = $target.closest('.mailhawk-dns-name');
        var isHost = $target.is('.mailhawk-dns-host');

        $.when(copyText($target.attr('data-copy'))).done(function () {
            // A value says it in its own words, a name says which part
            $name.find('.mailhawk-dns-copied').text($name.attr('data-copied') || (isHost ? dns.host_copied : dns.hostname_copied));
            $name.toggleClass('copied-host', isHost).toggleClass('copied-hostname', !isHost);

            clearTimeout($name.data('copiedTimer'));
            $name.data('copiedTimer', setTimeout(function () {
                $name.removeClass('copied-host copied-hostname').find('.mailhawk-dns-copied').text('');
            }, 1500));
        });
    });

    // A button that copies a block of text, like the DNS instructions, and says so on itself for a moment
    $(document).on('click', '.mailhawk-copy-button', function () {
        var $button = $(this);
        var $label = $button.find('.mailhawk-copy-button-label');

        if (!$button.data('label')) {
            $button.data('label', $label.text());
        }

        $.when(copyText($button.attr('data-copy'))).done(function () {
            $label.text($button.attr('data-copied'));
            $button.addClass('copied');

            clearTimeout($button.data('copiedTimer'));
            $button.data('copiedTimer', setTimeout(function () {
                $label.text($button.data('label'));
                $button.removeClass('copied');
            }, 2000));
        });
    });

    // The test email: say so when the address it is sent from is on a domain that is not verified, or not MailHawk's
    $(function () {
        var $from = $('#mailhawk-test-from');
        var $warning = $('.mailhawk-test-from-warning');

        if (!$from.length || !$warning.length) {
            return;
        }

        var domains = $from.data('domains') || {};
        var $link = $warning.find('.mailhawk-test-from-warning-link');
        var $select = $('#mailhawk-test-from-select');

        // The select picks a suggested address, its last choice opens the field for any other
        function pick() {
            var value = $select.val();

            $from.prop('hidden', value !== '');

            if (value !== '') {
                $from.val(value);
            } else if ($select.find('option[value="' + $from.val() + '"]').length) {
                // Coming from a suggestion, start the other address empty
                $from.val('').trigger('focus');
            }

            check();
        }

        $select.prop('hidden', false).on('change', pick);

        function check() {
            var email = $.trim($from.val()).toLowerCase();
            var at = email.lastIndexOf('@');
            var domain = at > 0 ? email.slice(at + 1) : '';

            // Nothing to say until there is an address to say it about, or when its domain is verified
            if (!domain || domain.indexOf('.') === -1 || domains[domain] === true) {
                $warning.prop('hidden', true);
                return;
            }

            var known = Object.prototype.hasOwnProperty.call(domains, domain);
            var kind = known ? 'unverified' : 'unknown';

            $warning.find('.mailhawk-test-from-warning-text').text($warning.attr('data-' + kind).replace('%s', domain));
            $link.text($link.attr('data-' + kind)).attr('href', known
                ? $warning.attr('data-dns-url').replace('%s', encodeURIComponent(domain))
                : $warning.attr('data-domains-url'));

            $warning.prop('hidden', false);
        }

        $from.on('input change', check);
        pick();
    });

    // The pill is not a button element, it holds one, so Enter and Space are handled here
    $(document).on('keydown', '.mailhawk-dns-name', function (e) {
        if (e.target === this && (e.key === 'Enter' || e.key === ' ')) {
            e.preventDefault();
            $(this).trigger('click');
        }
    });

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


})(jQuery, MailHawkConnect, MailHawkReview, MailHawkGroundhogg, MailHawkDns);
