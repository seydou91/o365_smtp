# Office 365 SMTP OAuth2

[![License](https://img.shields.io/badge/License-GPL--2.0--or--later-blue.svg)](https://www.gnu.org/licenses/old-licenses/gpl-2.0.txt)
[![Drupal compatibility](https://img.shields.io/badge/Drupal-10%20%7C%2011-black.svg)](https://www.drupal.org/project/o365_smtp)
[![GitHub release](https://img.shields.io/github/v/release/seydou91/o365_smtp)](https://github.com/seydou91/o365_smtp/releases)

Sends emails via Office 365 / Microsoft 365 using OAuth2 authentication. This module provides a complete solution for sending emails from your Drupal site through Office 365's SMTP servers without needing to store credentials in plain text.

## Features

- **OAuth2 authentication**: delegated authorization code flow with the
  `SMTP.Send` permission; no mailbox password is stored
- **Automatic token refresh**, serialized between concurrent requests
- **Verified TLS** connection to `smtp.office365.com:587` (STARTTLS)
- **Plain text and HTML emails**, multiple recipients, Cc, Bcc and Reply-To
- **File attachments** from Drupal stream wrappers, with a size limit
- **Status report** entries and a **test form** showing the raw SMTP response

## Requirements

- Drupal 10.3 or later, or Drupal 11
- PHP 8.1+ with the OpenSSL extension
- A Microsoft 365 mailbox with **SMTP AUTH** enabled
- A Microsoft Entra ID application registration with a client secret and the
  delegated **SMTP.Send** permission (see below)

## Installation

### Using Composer (Recommended)

```bash
composer require drupal/o365_smtp
```

### Enable the Module

```bash
drush en o365_smtp
```

Or via the admin UI:
1. Go to **Extend** (`/admin/modules`)
2. Find "Office 365 SMTP OAuth2" and enable it

## Azure AD / Microsoft Entra ID Setup

### Step 1: Register an Application

1. Go to [Microsoft Azure Portal](https://portal.azure.com/) > **Microsoft Entra ID** (formerly Azure Active Directory)
2. Go to **App registrations** > **New registration**
3. Enter a name (e.g., "Drupal SMTP")
4. Set a **Web** redirect URI:
   `https://your-domain.com/admin/config/system/o365_smtp/oauth/callback`
   (the exact value is displayed on the module settings page)
5. Click **Register**
6. Copy the **Application (client) ID** and **Directory (tenant) ID**

### Step 2: Create Client Secret

1. In your app registration, go to **Certificates & secrets**
2. Click **New client secret**
3. Add a description and select expiration
4. Click **Add**
5. **Copy the secret value immediately** (it won't be shown again)

### Step 3: Configure API Permissions

The module uses the **delegated** authorization code flow: an administrator
signs in once with the sending mailbox, and the module sends as that mailbox
over SMTP. Microsoft Graph `Mail.Send` and *application* permissions are
**not** used.

1. Go to **API permissions** > **Add a permission**
2. Select **APIs my organization uses** and search for
   **Office 365 Exchange Online**
3. Select **Delegated permissions**, check **SMTP.Send**, then
   **Add permissions**
4. Check that **Microsoft Graph** > **offline_access** (delegated) is listed
   too, and add it the same way if needed: it allows refresh tokens
5. Click **Grant admin consent for &lt;your organization&gt;**

### Step 4: Enable SMTP AUTH on the Mailbox

SMTP AUTH is disabled by default in many tenants. Enable it for the mailbox
used as **From Email Address**, either:

- in the Microsoft 365 admin center: **Users** > **Active users** > the user >
  **Mail** > **Manage email apps** > check **Authenticated SMTP**; or
- with Exchange Online PowerShell:

  ```powershell
  Set-CASMailbox -Identity mailbox@example.com -SmtpClientAuthenticationDisabled $false
  ```

The mailbox setting takes precedence over the tenant-wide setting.

### Step 5: Note Your Credentials

You will need:
- **Application (client) ID** (e.g., `xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx`)
- **Client secret value** (the secret you just created)
- **Directory (tenant) ID** (e.g., `xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx`)

## Configuration

### Step 1: Configure the Module

1. Go to **Configuration** > **System** > **Office 365 SMTP Settings** (`/admin/config/system/o365_smtp`)
2. Fill in the form:
   - **Application (Client) ID**: Your Azure AD client ID
   - **Client Secret Value**: Your Azure AD client secret
   - **Directory (Tenant) ID**: Your Azure AD tenant ID
   - **From Email Address**: The email address to send from (must match an authorized user)
   - **From Name** (optional): Display name for the sender
   - **Maximum attachment size (MB)**: 10 by default
   - **EHLO host name** (optional): see *Troubleshooting*
3. Click **Save configuration**

The client secret field is a password field: leave it empty to keep the
current value.

#### Keeping the client secret out of exported configuration

By default the client secret is stored in the `o365_smtp.settings`
configuration object, which means it ends up in `config/sync` (and usually in
git) after a `drush config:export`. On production sites, define it in
`settings.php` instead:

```php
$settings['o365_smtp.client_secret'] = getenv('O365_SMTP_CLIENT_SECRET');
```

When this setting is present it always takes precedence over the configuration
value and the field is disabled in the form. You can then remove the secret
from configuration:

```bash
drush config:set o365_smtp.settings client_secret ''
```

### Step 2: Authorize with Microsoft

1. Check that the **Redirect URI** shown on the settings page is registered in
   the app registration
2. Click **Authorize with Office 365**
3. Sign in with the account of the **From Email Address** and accept the
   requested permissions

The status report (`/admin/reports/status`) then shows the module as
authorized.

### Step 3: Set as Default Mail System (Optional)

To use this module for all site emails:

1. Go to **Configuration** > **System** > **Mail system** (`/admin/config/system/mail`)
2. Set the "Default system" to "Office 365 SMTP"

## Usage

### Sending Test Emails

Use the built-in test form at `/admin/config/system/o365_smtp/test`. It sends a
plain text message directly through the module, even when it is not the default
mail system. When sending fails, the form displays the raw last response of the
SMTP server: include it when asking for support.

### Sending Emails with Attachments

You can send emails with attachments programmatically:

```php
use Drupal\Core\Mail\MailManagerInterface;

$mail_manager = \Drupal::service('plugin.manager.mail');

// Prepare attachments.
$params['attachments'] = [
  [
    // A public://, private:// or temporary:// URI.
    'filepath' => 'private://invoices/invoice-42.pdf',
    'filename' => 'invoice-42.pdf',
    'filemime' => 'application/pdf',
  ],
  [
    // Or content generated in memory.
    'filecontent' => $csv,
    'filename' => 'export.csv',
    'filemime' => 'text/csv',
  ],
];

$module = 'o365_smtp';
$key = 'custom_email';
$to = 'recipient@example.com';
$langcode = \Drupal::languageManager()->getDefaultLanguage()->getId();

$result = $mail_manager->mail($module, $key, $to, $langcode, $params);
```

Attachment restrictions:

- `filepath` must be a `public://`, `private://` or `temporary://` URI. Absolute
  paths and other stream wrappers are refused, as is any path resolving outside
  the stream wrapper directory.
- Each attachment is limited to the size set in the module settings (10 MB by
  default).
- A refused attachment is logged and the whole email is not sent.

### Sender (From) behavior

Office 365 only accepts messages whose sender is the authenticated mailbox (or
a mailbox it has *Send As* rights on). The module therefore always uses the
configured **From Email Address**:

- as the address authenticated with XOAUTH2;
- as the envelope sender (`MAIL FROM`);
- as the address of the `From:` header.

The display name of the `From:` header is the configured **From Name**. When it
is empty, the display name of the From header provided by Drupal (usually the
site name) is used. The *address* provided by Drupal (`$message['from']`, the
site email by default) is not used, because Office 365 would reject it with
`SendAsDenied` unless it is the same mailbox.

### Message format

- The `Content-Type` header set by Drupal is honored: `text/plain` messages
  (password reset, account notifications…) are converted and wrapped like the
  core PHP mailer does; `text/html` messages are sent as HTML.
- Bodies are encoded in quoted-printable (base64 if the message asks for it),
  so long HTML lines never exceed the SMTP line length limit.
- Non-ASCII subjects and display names are encoded (RFC 2047).
- `$message['to']` may contain several comma-separated recipients. `Cc`,
  `Bcc` (sent to, never shown in headers) and `Reply-To` headers are supported.

## Troubleshooting

### Status report

`/admin/reports/status` shows whether the module is configured and authorized,
and reports an error when Microsoft rejected the refresh token (expired or
revoked). In that case authorize the module again from the settings page.

### "500 5.3.3 Unrecognized command"

Earlier versions announced the site with `EHLO` followed by
`$_SERVER['SERVER_NAME']`. That variable does not exist under Drush or cron, so
a bare `EHLO` was sent and rejected by Office 365. This was neither an OpenSSL
nor an Azure permission problem. The module now uses, in this order: the
**EHLO host name** setting, the host of the current request, then the machine
host name.

### "SMTP authentication failed"

The error returned by Microsoft is logged and shown, for example
`{"status":"401","schemes":"bearer","scope":"https://outlook.office.com/SMTP.Send"}`.
Check that SMTP AUTH is enabled on the mailbox and that the From Email Address
is the account that authorized the module.

### "535 5.7.139 Authentication unsuccessful"

SMTP AUTH is disabled for the mailbox or the tenant: see
*Step 4: Enable SMTP AUTH on the Mailbox*.

### Authorization errors (AADSTS…)

- `AADSTS50011`: the redirect URI does not match. Register the exact URI shown
  on the settings page.
- `AADSTS65001`: consent is missing. Grant admin consent for the delegated
  permissions.
- `AADSTS7000215`: invalid client secret. Use the secret *value* (not its ID)
  and check its expiry date.

### Connection Refused

- Check that your server can connect to `smtp.office365.com:587`
- Verify firewall rules allow outbound SMTP connections

## Frequently Asked Questions

### Does this work with Gmail or other providers?

This module is specifically designed for Microsoft Office 365 / Microsoft 365. It uses Microsoft's OAuth2 endpoints and SMTP servers.

### Can I use this with Drupal 9?

For Drupal 9 support, use the `8.x-1.x` branch. Note that official support may be dropped in future versions.

### How do I upgrade the module?

```bash
composer update drupal/o365_smtp
drush updatedb
drush cr
```

When upgrading from a version using the `/phpmailer_oauth2/aad-callback`
redirect URI, replace it in Microsoft Entra ID with
`https://your-domain.com/admin/config/system/o365_smtp/oauth/callback`. Sending
keeps working without it; the new URI is only needed the next time you
authorize. See `CHANGELOG.md` for all upgrade steps.

## Security Considerations

- **No passwords stored**: OAuth2 tokens are used instead of passwords
- **Tokens outside configuration**: Tokens are stored in Drupal's State API,
  never in exported configuration
- **Client secret**: can be defined in `settings.php` so it is never exported
- **CSRF-protected authorization**: the OAuth2 `state` parameter is random,
  single use and bound to the administrator's session
- **Verified TLS**: the certificate and host name of `smtp.office365.com` are
  verified after STARTTLS
- **Header injection**: CR, LF and NUL characters are stripped from every value
  used in a header or an SMTP command, and addresses are validated
- **Attachments**: only read from Drupal stream wrappers, with a size limit

## License

This project is licensed under the [GNU General Public License, version 2 or later](https://www.gnu.org/licenses/old-licenses/gpl-2.0.txt).

## Support

- Report issues at: https://www.drupal.org/project/issues/o365_smtp
- GitHub issue tracker: https://github.com/seydou91/o365_smtp/issues

## Maintainers

- [seydou91](https://www.drupal.org/u/seydou91) - Original author