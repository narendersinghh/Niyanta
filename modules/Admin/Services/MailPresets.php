<?php
namespace Modules\Admin\Services;

/**
 * SMTP presets for popular providers (see docs/EMAIL.md). Each preset fills host/port/encryption,
 * hints what goes into username/password and shows where to find the credentials.
 * Keys: name, tag, host, port, enc (tls|ssl), user (prefill value or null), user_hint, pass_hint, steps[], docs.
 */
final class MailPresets {
  public static function all(): array {
    return [
      'brevo' => ['name' => 'Brevo', 'tag' => 'formerly Sendinblue', 'host' => 'smtp-relay.brevo.com', 'port' => 587, 'enc' => 'tls', 'user' => null,
        'user_hint' => 'Your SMTP login from the SMTP & API page (e.g. 8a1b2c001@smtp-brevo.com or your account email)', 'pass_hint' => 'An SMTP key (not your account password or an API v3 key)',
        'steps' => ['Sign in to Brevo and open your name (top right) → <b>SMTP & API</b>.', 'Open the <b>SMTP</b> tab and copy the <b>SMTP server</b>, <b>Port</b> and <b>Login</b>.', 'Click <b>Generate a new SMTP key</b>, name it "Niyanta" and copy the key — it is shown only once.', 'Under <b>Senders, domains & dedicated IPs</b>, add and authenticate your sending domain (DKIM/DMARC records).'],
        'docs' => 'https://developers.brevo.com/docs/smtp-integration'],
      'mailjet' => ['name' => 'Mailjet', 'tag' => 'Sinch Mailjet', 'host' => 'in-v3.mailjet.com', 'port' => 587, 'enc' => 'tls', 'user' => null,
        'user_hint' => 'Your Mailjet API key', 'pass_hint' => 'Your Mailjet Secret key',
        'steps' => ['Sign in to Mailjet and open <b>Account settings → REST API → API Key Management (Primary and Sub-account)</b>.', 'Copy the <b>API Key</b> (username) and <b>Secret Key</b> (password).', 'Under <b>Account settings → Senders & Domains</b>, add your domain and set up SPF and DKIM.', 'Use a validated sender address as the From address below.'],
        'docs' => 'https://dev.mailjet.com/smtp-relay/configuration/'],
      'sendgrid' => ['name' => 'SendGrid', 'tag' => 'Twilio', 'host' => 'smtp.sendgrid.net', 'port' => 587, 'enc' => 'tls', 'user' => 'apikey',
        'user_hint' => 'Literally the word apikey', 'pass_hint' => 'A SendGrid API key with Mail Send permission (starts with SG.)',
        'steps' => ['In SendGrid open <b>Settings → API Keys → Create API Key</b>.', 'Choose <b>Restricted Access</b> with <b>Mail Send</b> enabled (or Full Access) and copy the key.', 'Enter <code>apikey</code> as the username and the key as the password.', 'Under <b>Settings → Sender Authentication</b>, authenticate your domain (or verify a single sender).'],
        'docs' => 'https://www.twilio.com/docs/sendgrid/for-developers/sending-email/integrating-with-the-smtp-api'],
      'mailgun' => ['name' => 'Mailgun', 'tag' => 'Sinch', 'host' => 'smtp.mailgun.org', 'port' => 587, 'enc' => 'tls', 'user' => null,
        'user_hint' => 'The SMTP login of your domain, e.g. postmaster@mg.yourdomain.com', 'pass_hint' => 'The SMTP password of that login',
        'steps' => ['In Mailgun open <b>Send → Sending → Domain settings</b> and pick your sending domain.', 'Open the <b>SMTP credentials</b> tab; use the default <code>postmaster@…</code> login or <b>Add new SMTP user</b>.', 'Click <b>Reset password</b> (or create a user) and copy the password — it is shown once.', 'Domains in the EU region use <code>smtp.eu.mailgun.org</code> instead.'],
        'docs' => 'https://documentation.mailgun.com/docs/mailgun/user-manual/sending-messages/send-smtp'],
      'ses' => ['name' => 'Amazon SES', 'tag' => 'AWS', 'host' => 'email-smtp.ap-south-1.amazonaws.com', 'port' => 587, 'enc' => 'tls', 'user' => null,
        'user_hint' => 'SMTP user name generated in the SES console (looks like AKIA…)', 'pass_hint' => 'SMTP password generated with it (not your AWS secret access key)',
        'steps' => ['Open the <b>Amazon SES console</b> in the region you use (the host contains the region, e.g. <code>ap-south-1</code> for Mumbai, <code>us-east-1</code> for N. Virginia).', 'Go to <b>SMTP settings → Create SMTP credentials</b> and download the user name and password.', 'Under <b>Identities</b>, verify your domain (Easy DKIM) or sender address.', 'New accounts are in the <b>sandbox</b> and can only send to verified addresses until you request production access.'],
        'docs' => 'https://docs.aws.amazon.com/ses/latest/dg/send-email-smtp.html'],
      'postmark' => ['name' => 'Postmark', 'tag' => 'transactional', 'host' => 'smtp.postmarkapp.com', 'port' => 587, 'enc' => 'tls', 'user' => null,
        'user_hint' => 'Your Server API token', 'pass_hint' => 'The same Server API token',
        'steps' => ['In Postmark open your <b>Server → API Tokens</b> tab.', 'Copy the <b>Server API token</b> and paste it as both username and password.', 'Under <b>Sender Signatures</b>, verify your domain (DKIM + Return-Path) or a sender address.', 'Use the default transactional message stream.'],
        'docs' => 'https://postmarkapp.com/developer/user-guide/send-email-with-smtp'],
      'zoho' => ['name' => 'Zoho Mail', 'tag' => 'zoho.com · zoho.in', 'host' => 'smtp.zoho.com', 'port' => 587, 'enc' => 'tls', 'user' => null,
        'user_hint' => 'Your full Zoho Mail address', 'pass_hint' => 'Your Zoho password, or an app-specific password if 2FA is on',
        'steps' => ['Use <code>smtp.zoho.in</code> if your account is in the India data centre (you sign in at zoho.in), <code>smtp.zoho.eu</code> for the EU.', 'If two-factor authentication is on, create an <b>app-specific password</b> in Zoho Accounts → <b>Security → App Passwords</b>.', 'The From address must be your mailbox address or one of its aliases.', 'Organisation admins may need to allow SMTP access for the user in the Admin Console.'],
        'docs' => 'https://www.zoho.com/mail/help/zoho-smtp.html'],
      'google' => ['name' => 'Gmail / Workspace', 'tag' => 'app password', 'host' => 'smtp.gmail.com', 'port' => 587, 'enc' => 'tls', 'user' => null,
        'user_hint' => 'Your full Gmail or Google Workspace address', 'pass_hint' => 'A 16-character app password',
        'steps' => ['Turn on <b>2-Step Verification</b> for the Google account.', 'Open <b>myaccount.google.com/apppasswords</b>, create an app password named "Niyanta" and copy it.', 'Use your full address as the username; the From address must be that address or a verified alias.', 'Gmail limits sending (about 500/day for Gmail, 2,000/day for Workspace) — use a transactional provider for higher volumes.'],
        'docs' => 'https://support.google.com/accounts/answer/185833'],
      'microsoft' => ['name' => 'Microsoft 365', 'tag' => 'Outlook · Exchange', 'host' => 'smtp.office365.com', 'port' => 587, 'enc' => 'tls', 'user' => null,
        'user_hint' => 'Your full Microsoft 365 mailbox address', 'pass_hint' => 'The mailbox password (or an app password if MFA requires one)',
        'steps' => ['In the Microsoft 365 admin centre open <b>Users → Active users → (mailbox) → Mail → Manage email apps</b> and tick <b>Authenticated SMTP</b>.', 'If <b>Security defaults</b> are on, SMTP AUTH is blocked — ask your admin to allow it for this mailbox.', 'The From address must be the mailbox (or one it has Send As rights for).', 'Microsoft is phasing out basic SMTP authentication; prefer a transactional provider for long-term use.'],
        'docs' => 'https://learn.microsoft.com/en-us/exchange/mail-flow-best-practices/how-to-set-up-a-multifunction-device-or-application-to-send-email-using-microsoft-365-or-office-365'],
      'hostinger' => ['name' => 'Hostinger', 'tag' => 'Hostinger Email', 'host' => 'smtp.hostinger.com', 'port' => 465, 'enc' => 'ssl', 'user' => null,
        'user_hint' => 'Your full Hostinger mailbox address', 'pass_hint' => 'The mailbox password',
        'steps' => ['In hPanel open <b>Emails → Email accounts</b> and create a mailbox (e.g. hello@yourdomain.com).', 'Use the full address as the username and its password.', 'Open <b>Connect apps & devices</b> to confirm the SMTP host and port for your plan.', 'Check that SPF and DKIM are enabled under <b>Emails → DNS / Email authentication</b>.'],
        'docs' => 'https://support.hostinger.com/en/articles/4305847-set-up-hostinger-email-on-your-applications-and-devices'],
      'cpanel' => ['name' => 'cPanel mail', 'tag' => 'hosting mailbox', 'host' => 'mail.yourdomain.com', 'port' => 465, 'enc' => 'ssl', 'user' => null,
        'user_hint' => 'Your full mailbox address', 'pass_hint' => 'The mailbox password',
        'steps' => ['In cPanel open <b>Email Accounts</b> and create a mailbox.', 'Click <b>Connect Devices</b> next to it and copy the <b>Outgoing Server</b> and <b>SMTP Port</b> from the secure SSL/TLS settings.', 'Replace <code>mail.yourdomain.com</code> with your real host (often your server hostname when the certificate does not cover mail.yourdomain.com).', 'Enable SPF and DKIM in cPanel → <b>Email Deliverability</b>.'],
        'docs' => 'https://docs.cpanel.net/cpanel/email/email-accounts/'],
      'custom' => ['name' => 'Custom SMTP', 'tag' => 'any server', 'host' => '', 'port' => 587, 'enc' => 'tls', 'user' => null,
        'user_hint' => 'Usually the full mailbox address', 'pass_hint' => 'The SMTP password or key',
        'steps' => ['Ask your email provider for the SMTP host, port and encryption.', 'Port 587 normally uses STARTTLS; port 465 uses SSL/TLS.', 'Send a test email after saving to confirm.'], 'docs' => null],
      'mail' => ['name' => 'PHP mail()', 'tag' => 'server default', 'host' => '', 'port' => 0, 'enc' => 'tls', 'user' => null, 'user_hint' => '', 'pass_hint' => '',
        'steps' => ['Uses the server\'s built-in mail function — no credentials needed.', 'Delivery depends on your host and messages often land in spam. Prefer an SMTP provider for production.'], 'docs' => 'https://www.php.net/manual/en/function.mail.php']]; }

  public static function options(): array { return array_map(static fn ($p) => $p['name'], self::all()); }

  public static function get(string $key): ?array { return self::all()[$key] ?? null; }

  /** Data the email tab's JS needs to fill fields (no HTML). */
  public static function forJs(): array { return array_map(static fn ($p) => ['host' => $p['host'], 'port' => $p['port'], 'enc' => $p['enc'], 'user' => $p['user'], 'uh' => $p['user_hint'], 'ph' => $p['pass_hint']], self::all()); } }
