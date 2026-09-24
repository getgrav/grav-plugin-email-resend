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
signing_secret:
inbound_signing_secret:
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

## Receiving mail

Resend can also receive mail at your domain, and this plugin knows how to read it for an add-on that wants it, such as a helpdesk turning replies into tickets. It needs an Email plugin with inbound mail (`Email::supportsFeature('inbound')`); with an older one this plugin sends and reports deliveries exactly as before.

1. In Resend, open **Domains**, open your domain and turn on **Receiving**, then add the MX record it shows to your DNS. If the domain already receives mail elsewhere, receive on a subdomain such as `support.example.com`, because mail only goes to the MX record with the lowest priority value. Every account can also receive at its own `<id>.resend.app` address.
2. Open **Webhooks**, press **Add Webhook**, paste the address the add-on gives you and tick `email.received` only.
3. Open that webhook, copy its signing secret (`whsec_…`) and paste it into **Inbound signing secret** here (`inbound_signing_secret`). Resend makes a separate secret for every webhook, so it is not the delivery-report secret.

Resend's `email.received` webhook carries the message's details but not the message, so the add-on stores a reference, answers at once, and downloads the message later from its own worker: `GET /emails/receiving/{id}` with the API key above, then the original message from the signed `raw.download_url` it returns (or, for a message with no raw file, the text, HTML, headers and each attachment from `GET /emails/receiving/{id}/attachments`). That needs a key with **Full access**. Resend's own SPF, DKIM and DMARC results are passed on with the message, and inbound mail counts towards your Resend email quota like sent mail does.

## License

MIT — see [LICENSE](LICENSE).
