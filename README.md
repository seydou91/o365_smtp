# Office 365 SMTP OAuth2

[![License](https://img.shields.io/badge/License-GPL--2.0--or--later-blue.svg)](https://www.gnu.org/licenses/old-licenses/gpl-2.0.txt)
[![Drupal compatibility](https://img.shields.io/badge/Drupal-10%20%7C%2011-black.svg)](https://www.drupal.org/project/o365_smtp)
[![GitHub release](https://img.shields.io/github/v/release/seydou91/o365_smtp)](https://github.com/seydou91/o365_smtp/releases)

Sends emails via Office 365 / Microsoft 365 using OAuth2 authentication. This module provides a complete solution for sending emails from your Drupal site through Office 365's SMTP servers without needing to store credentials in plain text.

## Features

- **OAuth2 Authentication**: Secure authentication using Microsoft OAuth2 (no plain text passwords)
- **Automatic Token Refresh**: Tokens are automatically refreshed before expiration
- **File Attachments**: Full support for sending emails with file attachments (MIME multipart)
- **Entity Configuration**: Settings managed via Drupal's configuration system
- **Test Form**: Built-in form to test email sending

## Requirements

- A Microsoft Entra ID (Azure AD) application registered in the Azure portal
- The application must have **Mail.Send** permission for Microsoft Graph API
- A client secret or X.509 certificate configured in Azure AD
- PHP 8.1+ with OpenSSL extension
- Drupal 10 or 11

## Installation

### Using Composer (Recommended)

```bash
composer require seydou91/o365_smtp
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
4. Set redirect URI: `https://your-domain.com/phpmailer_oauth2/aad-callback`
5. Click **Register**
6. Copy the **Application (client) ID** and **Directory (tenant) ID**

### Step 2: Create Client Secret

1. In your app registration, go to **Certificates & secrets**
2. Click **New client secret**
3. Add a description and select expiration
4. Click **Add**
5. **Copy the secret value immediately** (it won't be shown again)

### Step 3: Configure API Permissions

1. Go to **API permissions**
2. Click **Add a permission**
3. Select **Microsoft Graph** > **Application permissions**
4. Check **Mail.Send**
5. Click **Add permissions**
6. Click **Grant admin consent** for your organization

### Step 4: Note Your Credentials

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
3. Click **Save configuration**

### Step 2: Authorize with Microsoft

Click the **Authorize with Office 365** button to complete the OAuth2 flow.

### Step 3: Set as Default Mail System (Optional)

To use this module for all site emails:

1. Go to **Configuration** > **System** > **Mail system** (`/admin/config/system/mail`)
2. Set the "Default system" to "Office 365 SMTP"

## Usage

### Sending Test Emails

Use the built-in test form at `/admin/config/system/o365_smtp/test`

### Sending Emails with Attachments

You can send emails with attachments programmatically:

```php
use Drupal\Core\Mail\MailManagerInterface;

$mail_manager = \Drupal::service('plugin.manager.mail');

// Prepare attachments.
$params['attachments'] = [
  [
    'filepath' => '/path/to/document.pdf',
    'filename' => 'document.pdf',
    'filemime' => 'application/pdf',
  ],
];

$module = 'o365_smtp';
$key = 'custom_email';
$to = 'recipient@example.com';
$langcode = \Drupal::languageManager()->getDefaultLanguage()->getId();

$result = $mail_manager->mail($module, $key, $to, $langcode, $params);
```

## Troubleshooting

### "SMTP Error: 500 5.3.3 Unrecognized command"

This usually indicates a protocol error. Make sure:
- Your PHP has the OpenSSL extension enabled
- Your Azure AD application has **Mail.Send** permissions
- Admin consent was granted for the permissions

### Authentication Failed

- Verify your Client ID and Client Secret are correct
- Make sure the "From Email" matches an account authorized to send emails
- Re-authorize if needed by clicking the authorization link again

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
composer update seydou91/o365_smtp
```

Then clear Drupal's cache:
```bash
drush cr
```

## Security Considerations

- **No passwords stored**: OAuth2 tokens are used instead of passwords
- **Tokens stored securely**: Tokens are stored in Drupal's State API
- **Automatic refresh**: Tokens are refreshed automatically before expiration

## License

This project is licensed under the [GNU General Public License, version 2 or later](https://www.gnu.org/licenses/old-licenses/gpl-2.0.txt).

## Support

- Report issues at: https://www.drupal.org/project/issues/o365_smtp
- GitHub issue tracker: https://github.com/seydou91/o365_smtp/issues

## Maintainers

- [seydou91](https://www.drupal.org/u/seydou91) - Original author