# Changelog

All notable changes to this module are documented in this file.

## [Unreleased]

### Lot 1 — Security

- **OAuth2 `state`**: the authorization request now uses a random 64-character
  state (`random_bytes(32)`) stored in the private tempstore. The callback
  compares it with `hash_equals()`, deletes it (single use) and rejects the
  callback with an explicit error when it is missing or does not match. Errors
  returned by Microsoft (`error`, `error_description`) are shown.
- **TLS verification**: the SMTP connection is opened with
  `stream_socket_client()` and an SSL context verifying the peer certificate and
  the `smtp.office365.com` host name (SNI enabled). STARTTLS uses
  `STREAM_CRYPTO_METHOD_TLS_CLIENT`, which allows TLS 1.3.
- **Client secret**:
  - `$settings['o365_smtp.client_secret']` in `settings.php` takes precedence
    over configuration;
  - the form field is now a password field; leaving it empty keeps the stored
    value; it is disabled when the settings.php override is present.
- **Header/command injection**: CR, LF and NUL are stripped from values used in
  MIME headers and SMTP commands; sender and recipient addresses are validated
  with the email validator.
- **Attachments**: `filepath` must be a `public://`, `private://` or
  `temporary://` URI whose real path stays inside the stream wrapper directory.
  A configurable size limit applies (new `max_attachment_size` setting, in MB,
  default 10). Attachments can also be passed in memory with `filecontent`.
  A refused attachment is logged and the email is not sent.
- Dependency injection in every class of `src/` (no more `\Drupal::` calls),
  Drupal coding standards. The unused mail manager injection and the unused
  `$params`/`$langcode` variables of the test form were removed as part of that
  conversion.

**Breaking changes**

- Attachments given as absolute paths (e.g. `/var/www/files/doc.pdf`) are now
  refused. Use a stream wrapper URI or `filecontent`.
- `O365Client::getAuthorizationUrl()` now requires a second `$state` argument.

### Lot 2 — SMTP protocol and message rendering

- **EHLO under Drush/cron** (the real cause of "500 5.3.3 Unrecognized
  command"): the host name comes from the new `helo_hostname` setting, else the
  current request host, else `php_uname('n')`.
- **XOAUTH2 failures**: `235` is required; on `334` the module sends the empty
  line, decodes Microsoft's base64 JSON error and puts it in the exception and
  the log.
- **Reply codes**: every step expects explicit codes (`220`, `250`, `251`,
  `235`, `354`, `221`) instead of "not 4xx/5xx".
- **MIME** is built with `symfony/mime` (a dependency of Drupal core): RFC 2047
  encoded subject and display names, quoted-printable bodies (base64 when the
  message asks for it), random boundaries, CRLF line endings.
- **Recipients**: comma-separated `To`, `Cc` and `Bcc` each get their own
  `RCPT TO`; `Bcc` is never written in the headers; `Reply-To` is propagated.
- **Drupal headers**: `Content-Type` (`text/plain` / `text/html`),
  `Content-Transfer-Encoding`, `Reply-To`, `Cc`, `Bcc` are honored. Plain text
  messages are formatted like the core PHP mailer instead of being sent as HTML.
- **From**: `"From Name" <from_email>`; without a configured From name, the
  display name of Drupal's From header is used. The configured address remains
  the authenticated user, envelope sender and header address.
- **Socket**: always closed (`try`/`finally`), read/write timeout, response
  reading can no longer loop forever and reports timeouts and closed
  connections; a failed `QUIT` after acceptance no longer fails the send.
- **Token refresh** is serialized with the `lock` service and re-reads the
  State after acquiring it. When Microsoft rejects the refresh token
  (`invalid_grant`), tokens are removed from State and the
  `o365_smtp.reauthorization_required` flag is set.
- **Status report** (`hook_requirements()` in the new `o365_smtp.install`):
  missing configuration, missing authorization, re-authorization required,
  missing OpenSSL extension, client secret still stored in configuration.

**Behavior changes**

- `O365Client::send()` gets an optional sixth `$headers` argument. Without a
  `Content-Type` header the body is still sent as HTML.
- Invalid recipient addresses now throw instead of being passed to the server.

### Lot 3 — Drupal.org contrib compliance

- **Configuration schema** (`config/schema/o365_smtp.schema.yml`) and default
  configuration (`config/install/o365_smtp.settings.yml`).
- **`hook_update_10101()`** adds the missing keys (`helo_hostname`,
  `max_attachment_size`, …) to the configuration of existing sites. Existing
  values and the State keys (`o365_smtp.access_token`,
  `o365_smtp.refresh_token`, `o365_smtp.token_expires`) are kept.
- **`hook_uninstall()`** deletes the tokens and the re-authorization flag from
  State.
- **OAuth routes**: the PHPMailer OAuth2 leftover `/phpmailer_oauth2/aad-callback`
  (single controller branching on `?op=authorize`) is replaced by two routes:
  - `o365_smtp.oauth_authorize`: `/admin/config/system/o365_smtp/oauth/authorize`
    (CSRF token required, so a third-party page cannot force a
    re-authorization);
  - `o365_smtp.oauth_callback`: `/admin/config/system/o365_smtp/oauth/callback`.
  The settings form displays the redirect URI to register.
- **Requirements** moved to an object-oriented
  `#[Hook('runtime_requirements')]` class (`O365SmtpRequirements`); the
  procedural `hook_requirements()` is kept with `#[LegacyRequirementsHook]` for
  Drupal versions before 11.3.
- **`#[Mail]` attribute** instead of the `@Mail` annotation (deprecated in
  Drupal 11, removed in 13). This requires Drupal 10.3 or later.
- **Tests**: unit tests for MIME building (encoded subject, long body,
  attachments, refused attachments, CRLF injection) and for the OAuth `state`
  validation; kernel test for the settings form (saving, empty secret keeps the
  stored value, settings.php override).
- **composer.json**: package renamed `drupal/o365_smtp`; `guzzlehttp/guzzle`
  (provided by core) and `minimum-stability: dev` removed.

**Breaking changes**

- **Redirect URI**: in Microsoft Entra ID → App registrations → your app →
  Authentication, replace
  `https://example.com/phpmailer_oauth2/aad-callback` with
  `https://example.com/admin/config/system/o365_smtp/oauth/callback`.
  Sites that are already authorized keep sending (refreshing a token does not
  use the redirect URI); the change is needed before the next authorization.
- **Drupal 10.3 minimum** (`core_version_requirement: ^10.3 || ^11`).
- Routes `o365_smtp.callback` removed; use `o365_smtp.oauth_authorize` and
  `o365_smtp.oauth_callback`.
- Composer package name: `drupal/o365_smtp` instead of `seydou91/o365_smtp`.
