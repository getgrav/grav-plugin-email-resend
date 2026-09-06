# Email Resend Plugin

The **Email Resend** plugin is an extension for [Grav CMS](https://github.com/getgrav/grav). It sends your site's mail through [Resend](https://resend.com), over their API or over SMTP.

It needs **PHP 8.1 or newer**, the [Email](https://github.com/getgrav/grav-plugin-email) plugin, and a Resend account.

## Installation

    bin/gpm install email-resend

## Configuration

```yaml
enabled: true
transport: api
api_key:
```

The **transport** is `api` (recommended) or `smtp`. The **api_key** is your Resend API key and is what sends the mail either way: Resend's SMTP user is the word `resend` for every account and the password is that same key, so there is no separate SMTP account to create.

Everything else about how mail is sent is configured in the main Email plugin. Point its engine at Resend:

```yaml
mailer:
  engine: resend
```

A default `from:` address is also required, on a domain you have verified in Resend.

## Delivery reports

Resend can tell your site what happened to every message it sent, and this plugin knows how to read those reports and how to set them up. You need an add-on that wants them, such as the KahunaCart Newsletter; on its own this plugin only makes the answers available.

**The one button.** Once an add-on has given you a webhook address, press **Set up** and this plugin creates the webhook in Resend for the seven events worth acting on and saves the signing secret Resend hands back — which it hands back once, in that one answer, and never again. Pressing it twice leaves one webhook rather than two, and pressing it after the address has changed — a new secret, or a store that lost its settings — moves the webhook Resend already holds to the new address rather than adding a second one with a signing secret you would never see. The key needs **Full access** in Resend under API Keys: it is the `POST /webhooks` call that creates the webhook, `GET /webhooks` that reads back what is already registered, and `PATCH /webhooks/{id}` that moves one. A key with Sending access sends mail perfectly well and can do none of the three.

## License

MIT — see [LICENSE](LICENSE).
