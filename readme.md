# WordPress SMTP & Email Delivery service

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

## How can I report security bugs?

You can report security bugs through the Patchstack Vulnerability Disclosure Program. The Patchstack team helps validate, triage and handle any security vulnerabilities. [Report a security vulnerability.]( https://patchstack.com/database/vdp/19bd47ae-c921-41f4-b595-2ce635777438 )
