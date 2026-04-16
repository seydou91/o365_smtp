# Office 365 SMTP OAuth2

Sends emails via Office 365 SMTP using OAuth2 authentication.

## Requirements

- A Microsoft Entra ID (Azure AD) application registered in your Azure portal.
- The application must have the **SMTP.Send** permission for Microsoft Graph.
- A configured client secret or certificate.

## Installation

1. Install the module:
   ```bash
   composer require drupal/o365_smtp
   ```
2. Enable the module via Drush or the admin UI.
3. Navigate to **Configuration > System > Office 365 SMTP Settings** (`/admin/config/system/o365_smtp`).
4. Fill in your Azure AD credentials (Client ID, Client Secret, Tenant ID, From Email).
5. Click **Authorize with Office 365** to complete the OAuth2 flow.
6. Configure Drupal's mail system to use the **Office 365 SMTP** mail plugin.

## Azure AD Setup

1. Go to [portal.azure.com](https://portal.azure.com) > **Azure Active Directory** > **App registrations**.
2. Click **New registration** and give your app a name (e.g., "Drupal SMTP").
3. Set the redirect URI to: `<your-site-url>/phpmailer_oauth2/aad-callback`
4. Under **Certificates & secrets**, create a new **Client secret**.
5. Under **API permissions**, click **Add a permission** > **Microsoft Graph** > **Application permissions**, and add **Mail.Send**.
6. Click **Grant admin consent** for your organization.
7. Copy your **Application (client) ID**, **Directory (tenant) ID**, and **Client secret value**.

## Configuration

| Setting | Description |
|---------|-------------|
| Application (Client) ID | The client ID from Azure AD |
| Client Secret Value | The secret you created in Azure AD |
| Directory (Tenant) ID | Your Azure AD tenant ID |
| From Email Address | The email address used as the sender |
| From Name | Optional display name for the sender |

## OAuth2 Flow

The module uses the OAuth2 client credentials flow (offline_access scope) to obtain and refresh access tokens automatically. Tokens are stored in Drupal's State API and refreshed before expiration.

## File Attachments

The module supports file attachments in outgoing emails. Attachments are sent as proper MIME multipart messages with base64 encoding. To send attachments, pass them via the `$params['attachments']` array:

```php
$params['attachments'][] = [
  'filepath' => '/path/to/file.pdf',
  'filename' => 'document.pdf',
  'filemime' => 'application/pdf',
];
```

## Tested with

- Office 365 / Microsoft 365
- Drupal 10 and 11
- PHP 8.1+
