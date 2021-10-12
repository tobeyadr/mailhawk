=== WordPress SMTP Service, Email Delivery Solved! — MailHawk ===
Contributors: Adrian Tobey, Marc Goldman, trainingbusinesspros
Donate link: https://mailhawk.io/pricing/
Tags: email, smtp, wordpress smtp, smtp plugin, wp mail smtp
Requires at least: 5.0
Tested up to: 5.8
Stable tag: 1.1
Requires PHP: 7.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

An easier WordPress SMTP service. Improve your WordPress email deliverability!

== Description ==

WordPress SMTP & Email Delivery service

👉 [Official Site](https://mailhawk.io/) | 👉 [Pricing](https://mailhawk.io/pricing/) | 👉 [WaaS](https://mailhawk.io/waas/)

Is your WordPress email not reaching your customer's inbox? **We can help!**

MailHawk is the **FIRST** email solution specializing in WordPress email delivery.

With MailHawk you can rest assured that your...

* Account registration emails
* WooCommerce order confirmation email
* Password reset emails
* LifterLMS notifications
* LearnDash emails
* Easy Digital Downloads renwal reminder emails
* Groundhogg broadcast emails
* MailPoet Newsletter emails
* BuddyBoss emails

Will reach the recipients inbox!

## 📧 How does it work?

MailHawk is an SMTP plugin & SMTP service *all-in-one!* Which means we're your one stop shop for sending emails from WordPress.

To get started all you need to do is follow these steps:

1. Install MailHawk on your site!
2. Connect your WordPress site the the MailHawk service.
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

👨  **Marc Goldman**, _CEO of Send13 & Klean13_

> I'm super excited to bring my many years of email deliverability experience to the WordPress community so we can help more small business have their WordPress emails reach the inbox!

## FEATURES

MailHawk is making sending email from WordPress easier and more transparent with these innovative features.

## 📈 ANALYTICS & DASHBOARD REPORTING

Wondering if your emails are reaching the inbox? You can see your deliverability rate right within the MailHawk dashboard on your WordPress site! Use this information to make adjustments to your emails to improve your deliverability!

## 🌎 DOMAIN AUTHENTICATION

MailHawk provides SPF and DKIM authentication methods. This will drastically improve your email deliverability and provide you with a greater chance of skipping the spam folder.

## 🛡️ BLACKLIST/WHITELIST MANAGEMENT

Stop spam from ruining your sender reputation by maintaining a blacklist of emails and domains which are causing you issues. Bounced emails can be automatically added to the blacklist so don’t have to worry about invalid email attacks.

## 📫 EMAIL LOGGING

Keep track of your emails and debug email sending failures! You can resend emails, retry failed emails and even see which emails bounced! If you ever need to prove an email was sent you can check the log for up to 14 days after the email was sent! View content, headers and even the raw MIME message if you need to.

## 🌐 MULTISITE COMPATIBLE

Are you using Multisite? MailHawk can be enabled across your entire multisite so you only have to configure it once!

## 🌐 WAAS COMPATIBLE

Building a WaaS an not sure how to handle WordPress SMTP? [Choose one of our WaaS plans](https://mailhawk.io/waas/) and use MailHawk for all your customers!

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/mailhawk` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Use the Tools->MailHawk screen to configure the plugin

== Frequently Asked Questions ==

= Is MailHawk a free service? =

No, MailHawk requires a paid monthly subscription which starts at $14.97/mo

= Are there limits to how many emails I can send? =

We have different plans for different volume senders, starting at 40,000 emails/mo

= Can I send email from multiple sites with 1 account? =

Yes, you can connect multiple sites to one account without paying extra!

= Can I use MailHawk for my WaaS? =

Yes, we have special [WaaS account plans](https://mailhawk.io/waas/) to accommodate WaaS sites.

= My emails are going to spam, will MailHawk help? =

Yes, MailHawk is specifically designed to prevent your emails from ending up in the spam folder.

== Screenshots ==

1. Pick the plan the best suits your needs!
2. See your delivery reports right inside your WordPress dashboard.
3. Keep track of your emails and view issues with our email log.
4. Authenticate your domain and vastly improve the deliverability of your emails.
5. Prevent abusers from tarnishing your sender reputation by blacklisting offenders.

== Changelog ==

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
* FIXED Call to `pluggable.php` to early.

= 1.0.12 (2020-11-24) =
* UPDATED Readme.
* FIXED Search bar not working in the email log.
* FIXED WooCommerce email address format causing false negatives when validating email addresses.

= 1.0.11 (2020-09-23) =
* ADDED Better notice with mor context when there is a `wp_mail` conflict.

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
* TWEAKED Use our own version oh PHPMailer to avoid potential conflicts with other plugins

= 1.0.2 (2020-05-25) =
* ADDED uninstall file
* ADDED DNS instructions
* TWEAKED Some CSS

= 1.0.1 =
* Initial release
