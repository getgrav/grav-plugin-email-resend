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

## License

MIT — see [LICENSE](LICENSE).
