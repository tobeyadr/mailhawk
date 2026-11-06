=== MailHawk — Simple SMTP, Email Delivery, and Email Logging ===
Contributors: trainingbusinesspros
Donate link: https://mailhawk.io/pricing/
Tags: email, smtp, wordpress smtp, smtp plugin, wp mail smtp
Requires at least: 6.9
Tested up to: 7.1
Stable tag: 1.4.0
Requires PHP: 7.1
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

An easier SMTP service for WordPress. Improve your WordPress email deliverability!

== Description ==

SMTP & Email Delivery service for WordPress.

👉 [Official Site](https://mailhawk.io/) | 👉 [Pricing](https://mailhawk.io/pricing/) | 👉 [WaaS](https://mailhawk.io/waas/)

Is your WordPress email not reaching your customer's inbox? **We can help!**

MailHawk is SMTP and email delivery for WordPress in one plugin. Connect your site, authenticate your domain, and your WordPress email goes out through a service built for it, with the logs, reports, and quarantine protection to see and safeguard everything you send.

With MailHawk you can rest assured that your...

* Account registration emails
* WooCommerce order confirmation email
* Password reset emails
* LifterLMS notifications
* LearnDash emails
* Easy Digital Downloads renewal reminder emails
* Groundhogg broadcast emails
* MailPoet Newsletter emails
* BuddyBoss emails

Will reach the recipients inbox!

## 📧 How does it work?

MailHawk is an SMTP plugin & SMTP service *all-in-one!* Which means we're your one stop shop for sending emails from WordPress.

To get started all you need to do is follow these steps:

1. Install MailHawk on your site!
2. Connect your WordPress site to the MailHawk service.
3. [Pick a plan](https://mailhawk.io/pricing/) that fits your needs.
4. Configure your DNS records.
5. Start sending email!

## 🤷‍♂️ Who will benefit from MailHawk?

Any WordPress website that needs to send emails needs a WordPress SMTP service like MailHawk!

It will especially help send emails from plugins like:

* [Groundhogg](https://groundhogg.io)
* WooCommerce
* LearnDash
* LifterLMS
* BuddyBoss & BuddyPress
* Easy Digital Downloads
* And more...

## 🧑‍🤝‍🧑 Who is behind MailHawk?

Our team is made up of people who've spent a long time in the email industry!

👨  **Adrian Tobey**, _CEO & Creator of [Groundhogg](https://www.groundhogg.io/)_

> There are many SMTP plugins and SMTP services, but you always have to get BOTH to work. With MailHawk you just sign up and start sending email right away. No extra services, zero complications. No one is as focused on WordPress email deliverability as we are.

## FEATURES

MailHawk is making sending email from WordPress easier and more transparent with these innovative features.

## 📈 ANALYTICS & DASHBOARD REPORTING

Wondering if your emails are reaching the inbox? You can see your deliverability rate right within the MailHawk dashboard on your WordPress site! Use this information to make adjustments to your emails to improve your deliverability! Switch the report between today, the last 7, 14, or 30 days.

## 🚨 BOUNCE & COMPLAINT REPORTING

See how many of your emails bounced or were reported as spam, right from the MailHawk dashboard and the email log. Compare each period with the one before it to spot problems early, and get a digest by email when recipients report your messages as spam. If you use Groundhogg, contacts who complain are automatically marked so you stop mailing them.

## 🌎 DOMAIN AUTHENTICATION

MailHawk provides SPF and DKIM authentication methods. This will drastically improve your email deliverability and provide you with a greater chance of skipping the spam folder.

## 🛡️ EMAIL QUARANTINE

Protect your sender reputation before it gets damaged. MailHawk assesses each recipient before an email is sent, and holds emails that are likely to bounce or be reported as spam in quarantine instead of sending them. You are emailed when something is quarantined, then you can release the email to send it, or reject it so it never sends. [Learn more about email quarantine](https://mailhawk.io/email-quarantine/).

## 📫 EMAIL LOGGING

Keep track of your emails and debug email sending failures! You can resend emails, retry failed emails and even see which emails bounced! If you ever need to prove an email was sent you can check the log for up to 14 days after the email was sent! View content, headers and even the raw MIME message if you need to.

## 🌐 MULTISITE COMPATIBLE

Are you using Multisite? MailHawk can be enabled across your entire multisite so you only have to configure it once!

## 🌐 WAAS COMPATIBLE

Building a WaaS and not sure how to handle WordPress SMTP? [Choose one of our WaaS plans](https://mailhawk.io/waas/) and use MailHawk for all your customers!

## 🔌 EXTERNAL SERVICES

MailHawk is a hosted email delivery service, so this plugin cannot work without connecting to the MailHawk services below, both operated by MailHawk. Nothing is sent until you click the Connect button. The terms and privacy policy for both services are here: [Terms & Conditions](https://mailhawk.io/terms-and-conditions/) | [Privacy Policy](https://mailhawk.io/privacy-policy/).

**MailHawk account service (https://mailhawk.io)**

Used to connect your site to your MailHawk account, and to check your account and plan.

* When you click Connect, your browser is sent to mailhawk.io along with a one-time state key and the address of your MailHawk admin page to return to.
* Afterwards the plugin exchanges the code it receives for an API token, and uses that token to fetch your credentials and account details, and to tell MailHawk when your site is activated or disconnected.

**MailHawk email delivery service (https://mta01.mailhawk.io)**

The mail server that delivers your email, and reports back on it.

* Every email sent from your site is sent to it to be delivered. This includes the sender, recipients, subject, headers, message body, and any attachments.
* The domain names you add to MailHawk are sent to it to be authenticated.
* Your site asks it for delivery, bounce and complaint reports, and for your plan's sending limits.
* The plugin registers your site's webhook address with it. It then calls that address to report when an email is delivered, delayed, fails, bounces, or is reported as spam. These calls are signed and verified by the plugin.

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/mailhawk` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Use the Tools->MailHawk screen to configure the plugin

== Frequently Asked Questions ==

= Is MailHawk a free service? =

No, MailHawk requires a paid subscription which starts at $1/mo. See the [current plans](https://mailhawk.io/pricing/).

= Are there limits to how many emails I can send? =

Yes, each plan has a monthly sending limit. Plans start at 1,000 emails/mo, and go up to 375,000 emails/mo on the Agency plan.

= Can I send email from multiple sites with 1 account? =

Yes, each plan includes a number of sites, from 1 on the Basic plan up to 25 on the Agency plan.

= Can I use MailHawk for my WaaS? =

Yes, we have special [WaaS account plans](https://mailhawk.io/waas/) to accommodate WaaS sites.

= My emails are going to spam, will MailHawk help? =

Yes, MailHawk is specifically designed to prevent your emails from ending up in the spam folder.

= How can I report security bugs? =

You can report security bugs through the Patchstack Vulnerability Disclosure Program. The Patchstack team helps validate, triage and handle any security vulnerabilities. [Report a security vulnerability.]( https://patchstack.com/database/vdp/19bd47ae-c921-41f4-b595-2ce635777438 )

== Screenshots ==

1. Pick the plan that best suits your needs!
2. See your delivery reports right inside your WordPress dashboard.
3. Keep track of your emails and view issues with our email log.
4. Authenticate your domain and vastly improve the deliverability of your emails.
5. Protect your sender reputation by quarantining risky emails before they are sent.

== Changelog ==

= 1.4.0 (2026-11-06) =
* ADDED Spam complaints are now tracked. Complaints are shown on the overview and marked as "Reported as spam" in the email log.
* ADDED When Groundhogg is active, contacts who report an email as spam are marked as complained, and the site owner is emailed a digest of new complaints.
* ADDED Choose the date range of the overview graph and cards from a new "Show data for" dropdown with Today, 7, 14, and 30 day options.
* ADDED The overview now shows when the monthly usage resets.
* IMPROVED Redesigned the MailHawk admin with a cleaner navigation, cards, tables, status labels, and forms. Destructive buttons are now red.
* IMPROVED The Delivery Rate on the overview no longer counts bounced emails twice.
* TWEAKED Webhooks can now be received in batches, which reduces the number of requests the MailHawk service makes to your site. Existing sites are switched over by the database update, so run it when prompted.
* TWEAKED The MailHawk connection is now confirmed with the MailHawk service before the site is counted, and an empty response is treated as a failed connection.
* SECURITY Fixed an SQL injection in the email log sort order.
* SECURITY The email preview is now sandboxed so message content can no longer run scripts in the admin.
* SECURITY Added missing output escaping across the admin views and notices.
* SECURITY Webhook requests that arrive without a signature are now rejected.
* FIXED The check that triggers database updates compared the wrong value and now validates the requested action correctly.
* DEV New `mailhawk/spam_complaint` action, fired with the recipient address, message ID, and complaint details when a recipient reports an email as spam.
* UPDATED MailHawk now requires WordPress 6.9 and PHP 7.1, and has been tested up to WordPress 7.1.
* UPDATED Removed the unused PHPMailer dependency and moved PHPUnit to development dependencies.

= 1.3.5 (2025-09-15) =
* TWEAKED New webhook verification uses SHA256 since SHA1 is now deprecated on many systems.

= 1.3.4.1 (2025-08-07) =
* UPDATED Repository plugin name.

= 1.3.4 (2025-07-01) =
* ADDED Patchstack MVDP program disclaimer.
* FIXED JSON error if connection fails or times out when trying to initially connect MailHawk.

= 1.3.3 (2025-05-14) =
* TWEAKED Use user meta to store state key rather than transients to avoid connection issues when transients aren't working.

= 1.3.2 (2025-03-24) =
* FIXED Vulnerability responsibly disclosed by Patchstack.

= 1.3.1 (2024-12-04) =
* FIXED Retrying and releasing quarantined emails sending with incorrect content-type.
* FIXED Quarantine release link in email details modal not working.

= 1.3 (2024-01-12) =
* ADDED [New email quarantine system](https://mailhawk.io/email-quarantine) to prevent potentially damaging emails from being sent.
* FIXED Overview number formatting.

= 1.2.4 (2023-12-20) =
* FIXED Google and some other inboxes switched to sending HardFail for inboxes that don't exist instead of a proper bounce.

= 1.2.3 (2023-12-06) =
* ADDED Option to disable email logging outright.

= 1.2.2 (2023-10-23) =
* ADDED Return-Path DNS record setup instruction.

= 1.2.1 (2023-05-19) =
* ADDED Which account the MailHawk plugin is currently connected to in the Danger Zone settings.

= 1.2 (2022-11-07) =
* ADDED Telemetry for notifying admins when they are nearing their send limit
* FIXED Resend/Retry bulk actions not working in the email log

= 1.1.2 (2022-05-04) =
* TWEAKED Changes to how the whitelist & blacklist function.

= 1.1.1 (2021-12-29) =
* TWEAKED Change webhook settings to only send bounce notifications instead of ALL email events.

= 1.1 (2021-10-12) =
* ADDED Settings for API KEY so can be changed by support.
* ADDED Better error messages for adding domains so when the limit is reached it says why.
* TWEAKED bumped minimum version support for WordPress and PHP to 5.0 and 7.0 respectively.

= 1.0.15 (2021-01-04) =
* TWEAKED use URL params instead of POST VAR for connect process.
* TWEAKED use updated PHPMailer Lib from WP 5.5 instead of included one.

= 1.0.14 (2020-12-16) =
* TWEAKED DNS record now always show in lower case.
* FIXED jQuery deprecation warnings.

= 1.0.13 (2020-11-25) =
* FIXED Call to `pluggable.php` too early.

= 1.0.12 (2020-11-24) =
* UPDATED Readme.
* FIXED Search bar not working in the email log.
* FIXED WooCommerce email address format causing false negatives when validating email addresses.

= 1.0.11 (2020-09-23) =
* ADDED Better notice with more context when there is a `wp_mail` conflict.

= 1.0.9 (2020-09-21) =
* FIXED Plugins sending and empty string as an attachment causing emails to fail.

= 1.0.8 (2020-09-11) =
* FIXED Preview/Details modal not working after first click caused by updated jQuery Lib.

= 1.0.6 (2020-06-01) =
* FIXED bug causing MailHawk not to appear on non-multisite installations.

= 1.0.5 (2020-05-29) =
* ADDED Additional notice on domains page explaining DNS.

= 1.0.4 (2020-05-29) =
* ADDED Support for WaaS and Multisite.
* ADDED Promotion for Groundhogg during guided setup.
* TWEAKED improved the setup process and UI.
* TWEAKED improved the DNS screen.
* FIXED use our own version of the PHPMailer/Exception

= 1.0.3 (2020-05-27) =
* ADDED Automatically generate plain-text version of email if `content-type` is `text/html`
* TWEAKED Use our own version of PHPMailer to avoid potential conflicts with other plugins

= 1.0.2 (2020-05-25) =
* ADDED uninstall file
* ADDED DNS instructions
* TWEAKED Some CSS

= 1.0.1 (2020-05-21) =
* Initial release
