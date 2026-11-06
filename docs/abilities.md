# MailHawk abilities

MailHawk registers 20 abilities with the [WordPress Abilities API](https://developer.wordpress.org/apis/abilities/) (WordPress 6.9+). They are what an AI assistant or automation can do with MailHawk, in the same way it can with Groundhogg. Every ability checks that the current user has the `manage_options` capability, validates its input against a JSON schema, and validates what it returns.

They are marked public, so the [MCP adapter](https://github.com/WordPress/mcp-adapter) lists them with the other abilities of a site. Connect an assistant to your site as an administrator and it can discover them with `mcp-adapter-discover-abilities`, and run one with `mcp-adapter-execute-ability`.

## What they never do

- Read or change the API key, the license token, the credentials of the MTA, or the delete-everything-on-uninstall switch.
- Send an email with text or a subject of their choosing. The test email is fixed, only its address can be chosen.
- Delete a domain without the domain being typed a second time, or the domain of the default From address.
- Trust or forget an address that reported email from the site as spam.
- Send an email from the log again to anyone who would be held for review now, like someone who reported spam or an address that keeps bouncing. Sending again skips that review, so it is refused, and trusting the address is the way to say it is fine.
- Accept a From address or a domain on a free email provider, like gmail.com, which could never be authenticated.

## Reference

R is read only, D is destructive (it deletes or sends something, or changes what is held back).

| Ability | | Input | What it does |
|---|---|---|---|
| `mailhawk/get-status` | R | `refresh` | Connection, suspension and setup state, the webhook, usage against the monthly allowance, domains, the last 30 days of sending, held emails, and a list of what needs attention with a code for each. Start here. |
| `mailhawk/get-reports` | R | `range`: `today`, `7`, `14`, `30` | The Overview numbers for a range next to the range before it, with a series to chart and the usage. |
| `mailhawk/list-domains` | R | | Sending domains and whether SPF, DKIM and the return path check out. |
| `mailhawk/get-domain-dns` | R | `domain` | The DNS records to add for a domain, as the DNS screen shows them. If the domain already has an SPF record, the one to use has MailHawk merged into it. |
| `mailhawk/add-domain` | | `domain` | Register a domain and return its DNS records. Adding one that is registered already is fine. |
| `mailhawk/check-domain-dns` | | `domain` | Ask the service to look at the DNS records again. |
| `mailhawk/delete-domain` | D | `domain`, `confirm` | Remove a domain. `confirm` has to be the same domain. |
| `mailhawk/list-emails` | R | `status`, `recipient`, `subject`, `after`, `before`, `order`, `limit` (max 100), `offset` | Search the email log, newest first, without what the emails said. |
| `mailhawk/get-email` | R | `id`, `include_body` | One email with its headers, and its body when asked, cut off at 50,000 characters. |
| `mailhawk/send-test-email` | D | `to` | Send the fixed test email, to the current user when `to` is left out. It is held like any other if the address is risky. |
| `mailhawk/retry-email` | D | `id` | Send a failed email again. |
| `mailhawk/get-quarantine-report` | R | `expiring_days`, `limit` | The held emails closest to being deleted first, how many expire soon, and how many addresses are trusted and blocked. |
| `mailhawk/release-email` | D | `id`, `trust_recipients` | Send a held email, and optionally trust the people it went to. |
| `mailhawk/reject-email` | D | `id`, `unsubscribe_contacts` | Delete a held email so it is never sent, and optionally unsubscribe the Groundhogg contacts it was for. |
| `mailhawk/list-recipients` | R | `status`: `trusted`, `watching`, `blocked`, `search`, `limit` (max 200), `offset` | The addresses MailHawk keeps track of. |
| `mailhawk/assess-recipient-risk` | R | `email` | The risk score of an address, whether email to it would be held, and the factors it is made of. |
| `mailhawk/trust-recipient` | | `email` | Never hold email to this address. |
| `mailhawk/remove-recipient` | D | `email` | Forget what is known about an address. |
| `mailhawk/get-settings` | R | | The default From name and address, logging, retention and retries. |
| `mailhawk/update-settings` | | any of `from_name`, `from_email`, `email_logging_enabled`, `log_retention_days`, `retry_failed_emails`, `failed_email_retries` | Change only what is given, and say what changed. |

Errors come back with a code beginning `mailhawk_`, like `mailhawk_not_connected`, or the code the MailHawk service gave when it was the one that refused.

## Hooks

- `mailhawk/abilities/enabled` (filter, default `true`): return `false` to register none of them.
- `mailhawk/abilities/register_categories` and `mailhawk/abilities/register_abilities` (actions): register your own next to these. Extend `MailHawk\Abilities\Ability` to get the permission check, the annotations and the error helpers.
- `mailhawk/abilities/max_body_length` (filter, default `50000`): how much of an email `get-email` returns.
