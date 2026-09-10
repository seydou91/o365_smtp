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
