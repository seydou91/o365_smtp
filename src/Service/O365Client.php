<?php

namespace Drupal\o365_smtp\Service;

use Drupal\Component\Utility\EmailValidatorInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\State\StateInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Header\Headers;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\MixedPart;
use Symfony\Component\Mime\Part\TextPart;

/**
 * Service for interacting with Office 365 (OAuth2 and SMTP).
 */
class O365Client {

  /**
   * Office 365 SMTP submission host.
   */
  const SMTP_HOST = 'smtp.office365.com';

  /**
   * Office 365 SMTP submission port (STARTTLS).
   */
  const SMTP_PORT = 587;

  /**
   * Network timeout, in seconds, for the SMTP connection and the token lock.
   */
  const TIMEOUT = 30;

  /**
   * OAuth2 scopes requested from Microsoft Entra ID.
   */
  const OAUTH_SCOPE = 'offline_access https://outlook.office.com/SMTP.Send';

  /**
   * Stream wrapper schemes attachments may be read from.
   */
  const ATTACHMENT_SCHEMES = ['public', 'private', 'temporary'];

  /**
   * Default maximum size of a single attachment, in megabytes.
   */
  const DEFAULT_MAX_ATTACHMENT_SIZE = 10;

  /**
   * Name of the lock serializing token refreshes.
   */
  const TOKEN_REFRESH_LOCK = 'o365_smtp.token_refresh';

  /**
   * State keys holding the OAuth2 tokens.
   */
  const TOKEN_STATE_KEYS = [
    'o365_smtp.access_token',
    'o365_smtp.refresh_token',
    'o365_smtp.token_expires',
  ];

  /**
   * State key flagging that Microsoft rejected the refresh token.
   */
  const REAUTHORIZATION_REQUIRED = 'o365_smtp.reauthorization_required';

  /**
   * The last response read from the SMTP server, for diagnostics.
   *
   * @var string
   */
  protected string $lastSmtpResponse = '';

  /**
   * Constructs an O365Client object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \GuzzleHttp\ClientInterface $httpClient
   *   The HTTP client.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param \Psr\Log\LoggerInterface $logger
   *   The o365_smtp logger channel.
   * @param \Drupal\Core\File\FileSystemInterface $fileSystem
   *   The file system service.
   * @param \Drupal\Component\Utility\EmailValidatorInterface $emailValidator
   *   The email validator.
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   The request stack.
   * @param \Drupal\Core\Lock\LockBackendInterface $lock
   *   The lock backend.
   */
  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected ClientInterface $httpClient,
    protected StateInterface $state,
    protected LoggerInterface $logger,
    protected FileSystemInterface $fileSystem,
    protected EmailValidatorInterface $emailValidator,
    protected RequestStack $requestStack,
    protected LockBackendInterface $lock,
  ) {}

  /**
   * Returns the client secret.
   *
   * A value defined in settings.php takes precedence over configuration, so
   * the secret can be kept out of exported configuration.
   *
   * @return string
   *   The client secret, or an empty string when none is set.
   */
  public function getClientSecret(): string {
    return (string) (Settings::get('o365_smtp.client_secret')
      ?: $this->configFactory->get('o365_smtp.settings')->get('client_secret'));
  }

  /**
   * Checks whether the client secret is defined in settings.php.
   *
   * @return bool
   *   TRUE if $settings['o365_smtp.client_secret'] is set.
   */
  public function isClientSecretOverridden(): bool {
    return (string) Settings::get('o365_smtp.client_secret') !== '';
  }

  /**
   * Checks whether the OAuth2 application credentials are configured.
   *
   * @return bool
   *   TRUE if client ID, tenant ID and client secret are all available.
   */
  public function isConfigured(): bool {
    $config = $this->configFactory->get('o365_smtp.settings');
    return $config->get('client_id') && $config->get('tenant_id') && $this->getClientSecret() !== '';
  }

  /**
   * Checks whether the module holds a refresh token.
   *
   * @return bool
   *   TRUE if the module has been authorized.
   */
  public function isAuthorized(): bool {
    return (bool) $this->state->get('o365_smtp.refresh_token');
  }

  /**
   * Checks whether Microsoft rejected the refresh token.
   *
   * @return bool
   *   TRUE if an administrator must authorize the module again.
   */
  public function isReauthorizationRequired(): bool {
    return (bool) $this->state->get(self::REAUTHORIZATION_REQUIRED);
  }

  /**
   * Builds the Microsoft authorization URL.
   *
   * @param string $redirect_uri
   *   The absolute redirect URI registered in Microsoft Entra ID.
   * @param string $state
   *   An unguessable value the callback must receive back unchanged.
   *
   * @return string
   *   The URL the administrator must be redirected to.
   */
  public function getAuthorizationUrl(string $redirect_uri, string $state): string {
    $params = [
      'client_id' => $this->configFactory->get('o365_smtp.settings')->get('client_id'),
      'response_type' => 'code',
      'redirect_uri' => $redirect_uri,
      'response_mode' => 'query',
      'scope' => self::OAUTH_SCOPE,
      'state' => $state,
    ];
    return $this->getAuthorityUrl() . '/authorize?' . http_build_query($params);
  }

  /**
   * Exchanges an authorization code for tokens.
   *
   * @param string $code
   *   The authorization code returned by Microsoft.
   * @param string $redirect_uri
   *   The redirect URI used for the authorization request.
   *
   * @return string
   *   The access token.
   */
  public function getAccessTokenFromCode(string $code, string $redirect_uri): string {
    return $this->requestToken([
      'client_id' => $this->configFactory->get('o365_smtp.settings')->get('client_id'),
      'scope' => self::OAUTH_SCOPE,
      'code' => $code,
      'redirect_uri' => $redirect_uri,
      'grant_type' => 'authorization_code',
      'client_secret' => $this->getClientSecret(),
    ]);
  }

  /**
   * Returns a valid access token, refreshing it when needed.
   *
   * @return string
   *   The access token.
   */
  public function getAccessToken(): string {
    return $this->getStoredAccessToken() ?? $this->refreshAccessToken();
  }

  /**
   * Refreshes the access token.
   *
   * Microsoft rotates refresh tokens: two concurrent refreshes would make one
   * of them use an invalidated token. Refreshes are therefore serialized with
   * a lock, and a request that waited for another one reuses its result.
   *
   * @return string
   *   The new access token.
   */
  public function refreshAccessToken(): string {
    $previous_refresh_token = $this->state->get('o365_smtp.refresh_token');
    if (!$this->lock->acquire(self::TOKEN_REFRESH_LOCK, self::TIMEOUT)) {
      $this->lock->wait(self::TOKEN_REFRESH_LOCK, self::TIMEOUT);
      if (!$this->lock->acquire(self::TOKEN_REFRESH_LOCK, self::TIMEOUT)) {
        throw new \RuntimeException('Could not acquire the token refresh lock.');
      }
    }

    try {
      // Another request may have rotated the tokens in the meantime.
      $this->state->resetCache();
      $refresh_token = $this->state->get('o365_smtp.refresh_token');
      if ($refresh_token !== $previous_refresh_token && ($access_token = $this->getStoredAccessToken())) {
        return $access_token;
      }
      if (!$refresh_token) {
        throw new \RuntimeException('No refresh token available. Authorize the module with Office 365.');
      }

      try {
        return $this->requestToken([
          'client_id' => $this->configFactory->get('o365_smtp.settings')->get('client_id'),
          'scope' => self::OAUTH_SCOPE,
          'refresh_token' => $refresh_token,
          'grant_type' => 'refresh_token',
          'client_secret' => $this->getClientSecret(),
        ]);
      }
      catch (ClientException $e) {
        $error = json_decode((string) $e->getResponse()->getBody(), TRUE)['error'] ?? '';
        // The refresh token expired or was revoked: only a new authorization
        // can fix it.
        if (in_array($error, ['invalid_grant', 'interaction_required'], TRUE)) {
          $this->state->deleteMultiple(self::TOKEN_STATE_KEYS);
          $this->state->set(self::REAUTHORIZATION_REQUIRED, TRUE);
          $this->logger->error('Microsoft rejected the refresh token (@error): the module must be authorized again with Office 365.', ['@error' => $error]);
        }
        throw $e;
      }
    }
    finally {
      $this->lock->release(self::TOKEN_REFRESH_LOCK);
    }
  }

  /**
   * Returns the stored access token if it is still valid for 5 minutes.
   *
   * @return string|null
   *   The access token, or NULL if it is missing or about to expire.
   */
  protected function getStoredAccessToken(): ?string {
    $access_token = $this->state->get('o365_smtp.access_token');
    $expires = (int) $this->state->get('o365_smtp.token_expires');
    return $access_token && (!$expires || time() < $expires - 300) ? $access_token : NULL;
  }

  /**
   * Returns the OAuth2 v2.0 endpoint base URL for the configured tenant.
   *
   * @return string
   *   The authority URL, without trailing slash.
   */
  protected function getAuthorityUrl(): string {
    $tenant_id = (string) $this->configFactory->get('o365_smtp.settings')->get('tenant_id');
    return 'https://login.microsoftonline.com/' . rawurlencode($tenant_id) . '/oauth2/v2.0';
  }

  /**
   * Requests a token from the Microsoft identity platform and stores it.
   *
   * @param array $params
   *   The token request form parameters.
   *
   * @return string
   *   The access token.
   */
  protected function requestToken(array $params): string {
    try {
      $response = $this->httpClient->post($this->getAuthorityUrl() . '/token', [
        'form_params' => $params,
      ]);
    }
    catch (RequestException $e) {
      $this->logger->error('Token request failed: @message', ['@message' => $e->getMessage()]);
      throw $e;
    }

    $data = json_decode((string) $response->getBody(), TRUE);
    if (empty($data['access_token'])) {
      throw new \RuntimeException('Token not found in response.');
    }

    $this->state->set('o365_smtp.access_token', $data['access_token']);
    if (isset($data['refresh_token'])) {
      $this->state->set('o365_smtp.refresh_token', $data['refresh_token']);
    }
    $this->state->set('o365_smtp.token_expires', time() + (int) ($data['expires_in'] ?? 0));
    $this->state->delete(self::REAUTHORIZATION_REQUIRED);
    return $data['access_token'];
  }

  /**
   * Returns the last response of the SMTP server during ::send().
   *
   * It never contains credentials: only server replies are recorded.
   *
   * @return string
   *   The raw response, or an empty string if the server was not reached.
   */
  public function getLastSmtpResponse(): string {
    return $this->lastSmtpResponse;
  }

  /**
   * Sends an email via SMTP.
   *
   * @param string $from
   *   The mailbox address: used to authenticate, as envelope sender and as
   *   address of the From header.
   * @param string $to
   *   The recipients, as a comma-separated list of addresses, optionally with
   *   display names ("Name" <address>).
   * @param string $subject
   *   The email subject.
   * @param string $body
   *   The email body.
   * @param array|null $params
   *   Optional params array. Supported keys:
   *   - attachments: list of arrays with the keys:
   *     - filepath: a public://, private:// or temporary:// URI; or
   *     - filecontent: the raw file content;
   *     - filename: (optional) the file name shown to the recipient;
   *     - filemime: (optional) the MIME type.
   * @param array $headers
   *   Drupal message headers. Supported (case-insensitive): Content-Type
   *   (text/plain or text/html, default text/html), Content-Transfer-Encoding
   *   (base64 is honored, anything else becomes quoted-printable), From (its
   *   display name is used when no From name is configured), Reply-To, Cc and
   *   Bcc (envelope only).
   *
   * @return bool
   *   TRUE if the email was sent successfully.
   *
   * @throws \Exception
   */
  public function send(string $from, string $to, string $subject, string $body, ?array $params = NULL, array $headers = []): bool {
    $this->lastSmtpResponse = '';
    $sender = $this->validateAddress($from);
    $headers = array_change_key_case(array_map('strval', $headers), CASE_LOWER);

    // Every recipient gets an RCPT TO; Bcc recipients never appear in headers.
    $recipients = [];
    foreach ([$to, $headers['cc'] ?? '', $headers['bcc'] ?? ''] as $list) {
      foreach ($this->parseAddressList($list) as $address) {
        $recipients[strtolower($address->getAddress())] = $address->getAddress();
      }
    }
    if (!$recipients) {
      throw new \InvalidArgumentException('The email has no recipient.');
    }

    // Build the message first: a refused attachment must fail before any
    // connection is opened.
    $message = $this->buildMimeMessage($sender, $to, $subject, $body, $params['attachments'] ?? [], $headers);
    $access_token = $this->getAccessToken();
    $helo = $this->getHeloHostname();

    $socket = $this->connect();
    try {
      $this->expectResponse($socket, [220], 'connection');
      $this->sendCommand($socket, 'EHLO ' . $helo, [250]);
      $this->sendCommand($socket, 'STARTTLS', [220]);
      if (!stream_socket_enable_crypto($socket, TRUE, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
        throw new \RuntimeException('Failed to enable TLS encryption: the certificate of ' . self::SMTP_HOST . ' could not be verified.');
      }
      $this->sendCommand($socket, 'EHLO ' . $helo, [250]);
      $this->authenticate($socket, $sender, $access_token);
      $this->sendCommand($socket, "MAIL FROM:<$sender>", [250]);
      foreach ($recipients as $recipient) {
        $this->sendCommand($socket, "RCPT TO:<$recipient>", [250, 251]);
      }
      $this->sendCommand($socket, 'DATA', [354]);
      $this->sendDataMessage($socket, $message);
      try {
        $this->sendCommand($socket, 'QUIT', [221]);
      }
      catch (\RuntimeException $e) {
        // The message has already been accepted: ignore a failed QUIT.
      }
    }
    finally {
      fclose($socket);
    }
    return TRUE;
  }

  /**
   * Builds the MIME message.
   *
   * @param string $from
   *   The mailbox address.
   * @param string $to
   *   The comma-separated recipients.
   * @param string $subject
   *   The subject.
   * @param string $body
   *   The body.
   * @param array $attachments
   *   The attachments, see ::send().
   * @param array $headers
   *   The Drupal message headers, see ::send().
   *
   * @return string
   *   The MIME message, with CRLF line endings.
   */
  public function buildMimeMessage(string $from, string $to, string $subject, string $body, array $attachments = [], array $headers = []): string {
    $headers = array_change_key_case(array_map('strval', $headers), CASE_LOWER);

    $mime_headers = new Headers();
    $mime_headers->addMailboxListHeader('From', [
      new Address($this->validateAddress($from), $this->getFromName($headers['from'] ?? '')),
    ]);
    $address_headers = [
      'To' => $to,
      'Cc' => $headers['cc'] ?? '',
      'Reply-To' => $headers['reply-to'] ?? '',
    ];
    foreach ($address_headers as $name => $list) {
      if ($addresses = $this->parseAddressList($list)) {
        $mime_headers->addMailboxListHeader($name, $addresses);
      }
    }
    // Non-ASCII subjects are encoded as RFC 2047 encoded-words.
    $mime_headers->addTextHeader('Subject', $this->sanitizeHeaderValue($subject));

    // Quoted-printable (or base64) keeps every line under the 998 octet limit
    // of RFC 5321, whatever the body.
    $content_type = strtolower($headers['content-type'] ?? 'text/html');
    $text_part = new TextPart(
      $body,
      'utf-8',
      str_starts_with($content_type, 'text/plain') ? 'plain' : 'html',
      str_contains(strtolower($headers['content-transfer-encoding'] ?? ''), 'base64') ? 'base64' : 'quoted-printable',
    );

    $attachment_parts = [];
    foreach ($attachments as $attachment) {
      $filename = $this->sanitizeHeaderValue((string) ($attachment['filename'] ?? basename((string) ($attachment['filepath'] ?? ''))));
      $filemime = $this->sanitizeHeaderValue((string) ($attachment['filemime'] ?? ''));
      if (!preg_match('@^[\w.+-]+/[\w.+-]+$@', $filemime)) {
        $filemime = 'application/octet-stream';
      }
      $attachment_parts[] = new DataPart($this->readAttachment($attachment), $filename ?: 'attachment', $filemime);
    }

    $message = new Message($mime_headers, $attachment_parts ? new MixedPart($text_part, ...$attachment_parts) : $text_part);
    return $message->toString();
  }

  /**
   * Parses a comma-separated address list.
   *
   * @param string $list
   *   Addresses, optionally with display names ("Name" <address>).
   *
   * @return \Symfony\Component\Mime\Address[]
   *   The addresses.
   *
   * @throws \InvalidArgumentException
   *   When an address is not valid.
   */
  protected function parseAddressList(string $list): array {
    $addresses = [];
    // Split on commas that are not inside a quoted display name.
    foreach (preg_split('/,(?=(?:[^"]*"[^"]*")*[^"]*$)/', $this->sanitizeHeaderValue($list)) as $item) {
      if (trim($item) === '') {
        continue;
      }
      $address = Address::create(trim($item));
      $this->validateAddress($address->getAddress());
      $addresses[] = $address;
    }
    return $addresses;
  }

  /**
   * Returns the display name of the From header.
   *
   * @param string $from_header
   *   The From header provided by Drupal, if any.
   *
   * @return string
   *   The configured From name, else the display name of the Drupal header.
   */
  protected function getFromName(string $from_header): string {
    $name = (string) $this->configFactory->get('o365_smtp.settings')->get('from_name');
    if ($name === '' && $from_header !== '') {
      try {
        $name = Address::create($this->sanitizeHeaderValue($from_header))->getName();
      }
      catch (\InvalidArgumentException $e) {
        return '';
      }
      // The mail manager encodes non-ASCII display names (RFC 2047).
      if (str_contains($name, '=?') && extension_loaded('mbstring')) {
        $name = mb_decode_mimeheader($name);
      }
    }
    return $this->sanitizeHeaderValue($name);
  }

  /**
   * Returns the host name announced with EHLO.
   *
   * $_SERVER['SERVER_NAME'] is not set under Drush or cron, which made the
   * module send a bare "EHLO" rejected with "500 5.3.3 Unrecognized command".
   *
   * @return string
   *   The configured HELO host name, else the current request host, else the
   *   machine host name.
   */
  protected function getHeloHostname(): string {
    $candidates = [
      $this->configFactory->get('o365_smtp.settings')->get('helo_hostname'),
      $this->requestStack->getCurrentRequest()?->getHost(),
      php_uname('n'),
    ];
    foreach ($candidates as $candidate) {
      $hostname = preg_replace('/[^A-Za-z0-9.-]/', '', (string) $candidate);
      // Drush uses "default" as request host when no --uri is given.
      if ($hostname !== '' && $hostname !== 'default') {
        return $hostname;
      }
    }
    return 'localhost';
  }

  /**
   * Opens a TCP connection to the SMTP server.
   *
   * The TLS options apply when STARTTLS enables encryption on the stream: the
   * server certificate and host name are verified.
   *
   * @return resource
   *   The socket.
   */
  protected function connect() {
    $context = stream_context_create([
      'ssl' => [
        'verify_peer' => TRUE,
        'verify_peer_name' => TRUE,
        'peer_name' => self::SMTP_HOST,
        'SNI_enabled' => TRUE,
        'allow_self_signed' => FALSE,
      ],
    ]);
    $socket = stream_socket_client('tcp://' . self::SMTP_HOST . ':' . self::SMTP_PORT, $errno, $errstr, self::TIMEOUT, STREAM_CLIENT_CONNECT, $context);
    if (!$socket) {
      throw new \RuntimeException("Could not connect to SMTP host: $errstr ($errno)");
    }
    stream_set_timeout($socket, self::TIMEOUT);
    return $socket;
  }

  /**
   * Authenticates with XOAUTH2.
   *
   * @param resource $socket
   *   The SMTP socket.
   * @param string $user
   *   The mailbox address.
   * @param string $access_token
   *   The OAuth2 access token.
   *
   * @throws \RuntimeException
   *   When authentication fails, with the error returned by Microsoft.
   */
  protected function authenticate($socket, string $user, string $access_token): void {
    $initial_response = base64_encode("user=$user\1auth=Bearer $access_token\1\1");
    $response = $this->sendCommand($socket, 'AUTH XOAUTH2 ' . $initial_response, [235, 334]);
    if ($this->getResponseCode($response) === 235) {
      return;
    }

    // On failure the server sends a 334 challenge holding a base64-encoded
    // JSON error, and waits for an empty line before its final reply.
    $error = base64_decode(trim(substr($response, 4)), TRUE) ?: trim($response);
    $this->write($socket, "\r\n");
    $final_response = trim($this->readResponse($socket));
    $this->logger->error('SMTP XOAUTH2 authentication failed for @user: @error @response', [
      '@user' => $user,
      '@error' => $error,
      '@response' => $final_response,
    ]);
    throw new \RuntimeException(sprintf('SMTP authentication failed: %s %s', $error, $final_response));
  }

  /**
   * Sends a command and checks the reply code.
   *
   * @param resource $socket
   *   The SMTP socket.
   * @param string $command
   *   The command, without trailing CRLF.
   * @param int[] $expected_codes
   *   The accepted reply codes.
   *
   * @return string
   *   The server response.
   */
  protected function sendCommand($socket, string $command, array $expected_codes): string {
    $this->write($socket, $command . "\r\n");
    // Only the verb goes into error messages: AUTH carries the access token.
    return $this->expectResponse($socket, $expected_codes, explode(' ', $command, 2)[0]);
  }

  /**
   * Reads a response and checks its reply code.
   *
   * @param resource $socket
   *   The SMTP socket.
   * @param int[] $expected_codes
   *   The accepted reply codes.
   * @param string $context
   *   What the response answers, for error messages.
   *
   * @return string
   *   The server response.
   *
   * @throws \RuntimeException
   *   When the reply code is not expected.
   */
  protected function expectResponse($socket, array $expected_codes, string $context): string {
    $response = $this->readResponse($socket);
    if (!in_array($this->getResponseCode($response), $expected_codes, TRUE)) {
      throw new \RuntimeException(sprintf('Unexpected SMTP response to %s: %s', $context, trim($response)));
    }
    return $response;
  }

  /**
   * Sends the message after DATA, with dot-stuffing.
   *
   * @param resource $socket
   *   The SMTP socket.
   * @param string $message
   *   The full MIME message.
   */
  protected function sendDataMessage($socket, string $message): void {
    $data = '';
    foreach (preg_split('/\r\n|\r|\n/', rtrim($message, "\r\n")) as $line) {
      // Dot-stuffing, RFC 5321 section 4.5.2.
      $data .= (str_starts_with($line, '.') ? '.' : '') . $line . "\r\n";
    }
    $this->write($socket, $data . ".\r\n");
    $this->expectResponse($socket, [250], 'DATA');
  }

  /**
   * Writes data to the socket.
   *
   * @param resource $socket
   *   The SMTP socket.
   * @param string $data
   *   The data.
   *
   * @throws \RuntimeException
   *   When the connection is closed or times out.
   */
  protected function write($socket, string $data): void {
    while ($data !== '') {
      $written = fwrite($socket, $data);
      if (!$written) {
        throw new \RuntimeException('Failed to write to the SMTP connection.');
      }
      $data = substr($data, $written);
    }
  }

  /**
   * Reads a (possibly multi-line) server response.
   *
   * @param resource $socket
   *   The SMTP socket.
   *
   * @return string
   *   The response.
   *
   * @throws \RuntimeException
   *   When the connection is closed or times out before the last line.
   */
  protected function readResponse($socket): string {
    $response = '';
    do {
      $line = fgets($socket, 1024);
      if ($line === FALSE) {
        $reason = stream_get_meta_data($socket)['timed_out'] ? 'Timed out waiting for the SMTP server' : 'The SMTP server closed the connection';
        $this->lastSmtpResponse = $response;
        throw new \RuntimeException(sprintf('%s. Partial response: %s', $reason, trim($response)));
      }
      $response .= $line;
      // Every line of a multi-line reply but the last has "-" after the code.
    } while (isset($line[3]) && $line[3] === '-');
    $this->lastSmtpResponse = $response;
    return $response;
  }

  /**
   * Extracts the reply code of a response.
   *
   * @param string $response
   *   The server response.
   *
   * @return int
   *   The three-digit reply code, 0 if there is none.
   */
  protected function getResponseCode(string $response): int {
    return (int) substr($response, 0, 3);
  }

  /**
   * Removes characters that could inject SMTP commands or MIME headers.
   *
   * @param string $value
   *   A value to be used in a header or an SMTP command.
   *
   * @return string
   *   The value without CR, LF and NUL characters.
   */
  protected function sanitizeHeaderValue(string $value): string {
    return str_replace(["\r", "\n", "\0"], '', $value);
  }

  /**
   * Sanitizes and validates an email address.
   *
   * @param string $address
   *   The email address.
   *
   * @return string
   *   The sanitized address.
   *
   * @throws \InvalidArgumentException
   *   When the address is not valid.
   */
  protected function validateAddress(string $address): string {
    $address = trim($this->sanitizeHeaderValue($address));
    if (!$this->emailValidator->isValid($address)) {
      throw new \InvalidArgumentException(sprintf('Invalid email address "%s".', $address));
    }
    return $address;
  }

  /**
   * Returns the maximum allowed size of a single attachment.
   *
   * @return int
   *   The size in bytes.
   */
  protected function getMaxAttachmentSize(): int {
    $megabytes = (int) $this->configFactory->get('o365_smtp.settings')->get('max_attachment_size');
    return ($megabytes > 0 ? $megabytes : self::DEFAULT_MAX_ATTACHMENT_SIZE) * 1024 * 1024;
  }

  /**
   * Reads the content of an attachment.
   *
   * Files are only read from the public, private and temporary stream
   * wrappers, after resolving the real path, and up to the configured size.
   *
   * @param array $attachment
   *   The attachment definition, see ::send().
   *
   * @return string
   *   The file content.
   *
   * @throws \InvalidArgumentException
   *   When the attachment is refused.
   */
  protected function readAttachment(array $attachment): string {
    $max_size = $this->getMaxAttachmentSize();

    if (isset($attachment['filecontent'])) {
      $content = (string) $attachment['filecontent'];
      if (strlen($content) > $max_size) {
        $this->refuseAttachment($attachment['filename'] ?? '(inline content)', 'file exceeds the maximum attachment size');
      }
      return $content;
    }

    $uri = (string) ($attachment['filepath'] ?? '');
    $scheme = strstr($uri, '://', TRUE);
    if (!in_array($scheme, self::ATTACHMENT_SCHEMES, TRUE)) {
      $this->refuseAttachment($uri, 'only public://, private:// and temporary:// URIs are allowed');
    }

    $root = $this->fileSystem->realpath($scheme . '://');
    $path = $this->fileSystem->realpath($uri);
    if (!$root || !$path || !str_starts_with($path, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) || !is_file($path)) {
      $this->refuseAttachment($uri, 'file not found in its stream wrapper directory');
    }

    $size = filesize($path);
    if ($size === FALSE || $size > $max_size) {
      $this->refuseAttachment($uri, 'file exceeds the maximum attachment size');
    }

    $content = file_get_contents($path);
    if ($content === FALSE) {
      $this->refuseAttachment($uri, 'file is not readable');
    }
    return $content;
  }

  /**
   * Logs and throws an attachment refusal.
   *
   * @param string $file
   *   The attachment URI or name.
   * @param string $reason
   *   Why the attachment is refused.
   *
   * @throws \InvalidArgumentException
   */
  protected function refuseAttachment(string $file, string $reason): never {
    $this->logger->warning('Attachment @file refused: @reason.', ['@file' => $file, '@reason' => $reason]);
    throw new \InvalidArgumentException(sprintf('Attachment %s refused: %s.', $file, $reason));
  }

}
