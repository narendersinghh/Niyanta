# Email setup

Niyanta queues every email (invitations, password resets, notifications, payslips, reminders). The scheduler sends the queue every minute, so **cron must be running** (Settings › Cron & scheduler).

Configure it under **Settings › Email**:

1. Pick your provider. Niyanta fills in the host, port and encryption, and shows where to find the credentials.
2. Enter the username and password or SMTP key. Secrets are stored encrypted and never shown again.
3. Set a **From address** on a domain you have verified with the provider.
4. Save, then click **Send test email**. The test is sent straight away (not queued), and any SMTP error is shown word for word.

The chosen preset is stored as `email.provider`. Every preset except *PHP mail()* uses the SMTP driver (`email.driver = smtp`).

## Presets

| Provider | Host | Port | Encryption | Username | Password |
|---|---|---|---|---|---|
| Brevo (Sendinblue) | `smtp-relay.brevo.com` | 587 | STARTTLS | SMTP login from *SMTP & API* | SMTP key (not the account password) |
| Mailjet | `in-v3.mailjet.com` | 587 | STARTTLS | API key | Secret key |
| SendGrid | `smtp.sendgrid.net` | 587 | STARTTLS | `apikey` (literally) | API key with Mail Send permission |
| Mailgun | `smtp.mailgun.org` (EU: `smtp.eu.mailgun.org`) | 587 | STARTTLS | Domain SMTP login, e.g. `postmaster@mg.example.com` | That login's SMTP password |
| Amazon SES | `email-smtp.<region>.amazonaws.com` | 587 | STARTTLS | SES SMTP user name | SES SMTP password (not the IAM secret key) |
| Postmark | `smtp.postmarkapp.com` | 587 | STARTTLS | Server API token | Server API token |
| Zoho Mail | `smtp.zoho.com` (India: `smtp.zoho.in`, EU: `smtp.zoho.eu`) | 587 (or 465 SSL) | STARTTLS | Full mailbox address | Password or app-specific password |
| Google Workspace / Gmail | `smtp.gmail.com` | 587 | STARTTLS | Full Gmail/Workspace address | 16-character app password |
| Microsoft 365 / Outlook | `smtp.office365.com` | 587 | STARTTLS | Full mailbox address | Mailbox password (SMTP AUTH must be enabled) |
| Hostinger | `smtp.hostinger.com` | 465 | SSL/TLS | Full mailbox address | Mailbox password |
| cPanel / hosting mail | `mail.yourdomain.com` (see *Connect Devices*) | 465 | SSL/TLS | Full mailbox address | Mailbox password |
| Custom SMTP | from your provider | 587 / 465 | STARTTLS / SSL | as provided | as provided |
| PHP mail() | — | — | — | — | — |

Port 587 always pairs with **STARTTLS** and port 465 with **SSL/TLS**. Mixing them up is the most common cause of timeouts.

## Provider steps

**Brevo.** Open your name (top right) › *SMTP & API* › *SMTP*. Copy the login, then click *Generate a new SMTP key*; it is shown only once. Authenticate your domain under *Senders, domains & dedicated IPs*. Docs: https://developers.brevo.com/docs/smtp-integration

**Mailjet.** Go to *Account settings › REST API › API Key Management* and copy the API key (username) and Secret key (password). Add your domain with SPF and DKIM under *Senders & Domains*. Docs: https://dev.mailjet.com/smtp-relay/configuration/

**SendGrid.** Go to *Settings › API Keys › Create API Key* and give it Restricted Access with *Mail Send*. The username is `apikey`. Authenticate your domain under *Sender Authentication*. Docs: https://www.twilio.com/docs/sendgrid/for-developers/sending-email/integrating-with-the-smtp-api

**Mailgun.** Go to *Send › Sending › Domain settings › SMTP credentials*. Reset the `postmaster@` password or add a new SMTP user. EU-region domains use `smtp.eu.mailgun.org`. Docs: https://documentation.mailgun.com/docs/mailgun/user-manual/sending-messages/send-smtp

**Amazon SES.** In the SES console for your region (for example `ap-south-1` for Mumbai), open *SMTP settings › Create SMTP credentials*. Verify your domain under *Identities* (Easy DKIM). New accounts start in the sandbox, which can only send to verified addresses, so request production access before going live. Docs: https://docs.aws.amazon.com/ses/latest/dg/send-email-smtp.html

**Postmark.** Go to *Server › API Tokens* and paste the Server API token as both username and password. Verify a Sender Signature or your domain. Docs: https://postmarkapp.com/developer/user-guide/send-email-with-smtp

**Zoho Mail.** Use the host that matches your data centre (zoho.com / zoho.in / zoho.eu). If two-factor authentication is on, create an app-specific password under *Zoho Accounts › Security › App Passwords*. The From address must be the mailbox or one of its aliases. Docs: https://www.zoho.com/mail/help/zoho-smtp.html

**Google Workspace / Gmail.** Turn on 2-Step Verification, then create an app password at myaccount.google.com/apppasswords. Sending limits are roughly 500 a day for Gmail and 2,000 a day for Workspace. Docs: https://support.google.com/accounts/answer/185833 (Workspace relay options: https://support.google.com/a/answer/176600)

**Microsoft 365.** In the admin centre go to *Users › Active users › (mailbox) › Mail › Manage email apps* and tick *Authenticated SMTP*. Security defaults block SMTP AUTH, and Microsoft is retiring basic auth, so a transactional provider is the better long-term choice. Docs: https://learn.microsoft.com/en-us/exchange/mail-flow-best-practices/how-to-set-up-a-multifunction-device-or-application-to-send-email-using-microsoft-365-or-office-365

**Hostinger.** In hPanel go to *Emails › Email accounts* and create a mailbox. Use the full address and its password. Docs: https://support.hostinger.com/en/articles/4305847-set-up-hostinger-email-on-your-applications-and-devices

**cPanel.** Go to *Email Accounts › Connect Devices* and use the secure SSL/TLS outgoing server and port. If the certificate does not cover `mail.yourdomain.com`, use the server hostname instead. Docs: https://docs.cpanel.net/cpanel/email/email-accounts/

## Deliverability (SPF, DKIM, DMARC, From address)

- Send from an address on **your own domain** (for example `hello@yourcompany.com`) that the provider has verified. Free addresses (gmail.com, yahoo.com) used as the From address through a third-party SMTP server get rejected or marked as spam.
- **SPF**: add the provider's `include:` to a single TXT record, for example `v=spf1 include:spf.brevo.com ~all`. Merge all includes into one record; a domain must have only one SPF record.
- **DKIM**: publish the CNAME/TXT records the provider shows when you authenticate the domain.
- **DMARC**: start with `v=DMARC1; p=none; rua=mailto:dmarc@yourcompany.com`, then tighten it to `quarantine` once reports look clean.
- Keep *From name* recognisable (your company name).

## Troubleshooting

| Error | Likely cause and fix |
|---|---|
| `SMTP connect failed` / timeout | Wrong host or port, or your host blocks outgoing SMTP ports. Ask the host to open 587/465, or use the host's own SMTP. |
| `STARTTLS failed` / certificate errors | Port and encryption are mismatched (587 = STARTTLS, 465 = SSL/TLS), or the host name doesn't match the certificate. Use the provider's exact host name. |
| `535` authentication failed | Wrong username or key. Gmail needs an app password, SendGrid's username is `apikey`, Brevo needs an SMTP key, and SES needs SMTP credentials rather than IAM keys. |
| `550` / sender not allowed | The From address or domain isn't verified with the provider. |
| Test works but notifications don't arrive | Cron isn't running, so the queue isn't processed. Check Settings › Cron & scheduler. Failed queue items are retried 5 times. |
| Mail goes to spam | Set up SPF, DKIM and DMARC as described above, and avoid PHP mail() in production. |

Sent and failed queue entries are deleted after the period set in **Settings › Data retention** (default 30 days).
