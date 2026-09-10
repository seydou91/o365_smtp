<?php

namespace Drupal\o365_smtp\Service;

use Drupal\Component\Utility\EmailValidatorInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\State\StateInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;

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
   */
  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected ClientInterface $httpClient,
    protected StateInterface $state,
    protected LoggerInterface $logger,
    protected FileSystemInterface $fileSystem,
    protected EmailValidatorInterface $emailValidator,
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
   * Refreshes the access token.
   *
   * @return string
   *   The new access token.
   */
  public function refreshAccessToken(): string {
    $refresh_token = $this->state->get('o365_smtp.refresh_token');
    if (!$refresh_token) {
      throw new \RuntimeException('No refresh token available.');
    }

    return $this->requestToken([
      'client_id' => $this->configFactory->get('o365_smtp.settings')->get('client_id'),
      'scope' => self::OAUTH_SCOPE,
      'refresh_token' => $refresh_token,
      'grant_type' => 'refresh_token',
      'client_secret' => $this->getClientSecret(),
    ]);
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
    return $data['access_token'];
  }

  /**
   * Sends an email via SMTP.
   *
   * @param string $from
   *   The sender email address.
   * @param string $to
   *   The recipient email address.
   * @param string $subject
   *   The email subject.
   * @param string $body
   *   The email body (HTML).
   * @param array|null $params
   *   Optional params array. Supported keys:
   *   - attachments: list of arrays with the keys:
   *     - filepath: a public://, private:// or temporary:// URI; or
   *     - filecontent: the raw file content;
   *     - filename: (optional) the file name shown to the recipient;
   *     - filemime: (optional) the MIME type.
   *
   * @return bool
   *   TRUE if the email was sent successfully.
   *
   * @throws \Exception
   */
  public function send(string $from, string $to, string $subject, string $body, ?array $params = NULL): bool {
    $from = $this->validateAddress($from);
    $to = $this->validateAddress($to);
    // Build the message first: a refused attachment must fail before any
    // connection is opened.
    $message = $this->buildMimeMessage($from, $to, $subject, $body, $params['attachments'] ?? []);

    $access_token = $this->state->get('o365_smtp.access_token');
    $expires = $this->state->get('o365_smtp.token_expires');
    if (!$access_token || ($expires && time() > $expires - 300)) {
      $access_token = $this->refreshAccessToken();
    }

    $socket = $this->connect();

    $this->readResponse($socket);

    $this->sendCommand($socket, "EHLO " . $_SERVER['SERVER_NAME']);
    $this->sendCommand($socket, "STARTTLS");

    if (!stream_socket_enable_crypto($socket, TRUE, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
      throw new \RuntimeException('Failed to enable TLS encryption.');
    }

    $this->sendCommand($socket, "EHLO " . $_SERVER['SERVER_NAME']);

    $auth_str = base64_encode("user=" . $from . "\1auth=Bearer " . $access_token . "\1\1");
    $this->sendCommand($socket, "AUTH XOAUTH2 " . $auth_str);

    $this->sendCommand($socket, "MAIL FROM:<$from>");
    $this->sendCommand($socket, "RCPT TO:<$to>");
    $this->sendCommand($socket, "DATA");

    $this->sendDataMessage($socket, $message);
    $this->sendCommand($socket, "QUIT");

    fclose($socket);
    return TRUE;
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
    $socket = stream_socket_client('tcp://' . self::SMTP_HOST . ':' . self::SMTP_PORT, $errno, $errstr, 30, STREAM_CLIENT_CONNECT, $context);
    if (!$socket) {
      throw new \RuntimeException("Could not connect to SMTP host: $errstr ($errno)");
    }
    return $socket;
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

  /**
   * Sends the message body via DATA command with proper dot-stuffing.
   *
   * @param resource $socket
   *   The SMTP socket.
   * @param string $message
   *   The full MIME message.
   *
   * @throws \Exception
   */
  protected function sendDataMessage($socket, string $message): void {
    $lines = explode("\r\n", $message);
    foreach ($lines as $line) {
      if ($line === '') {
        fwrite($socket, "\r\n");
      }
      else {
        fwrite($socket, $this->smtpEscape($line) . "\r\n");
      }
    }
    fwrite($socket, ".\r\n");
    $response = $this->readResponse($socket);
    if (preg_match('/^[45]/', $response)) {
      throw new \RuntimeException("SMTP Error during DATA: $response");
    }
  }

  /**
   * Builds a MIME multipart message.
   *
   * @param string $from
   *   The validated sender address.
   * @param string $to
   *   The validated recipient address.
   * @param string $subject
   *   The subject.
   * @param string $body
   *   The HTML body.
   * @param array $attachments
   *   The attachments, see ::send().
   *
   * @return string
   *   The MIME message.
   */
  public function buildMimeMessage(string $from, string $to, string $subject, string $body, array $attachments = []): string {
    $boundary = md5(uniqid(time()));
    $mime_boundary = '----=_Part_' . $boundary;

    $headers = "From: $from\r\n";
    $headers .= "To: $to\r\n";
    $headers .= "Subject: " . $this->sanitizeHeaderValue($subject) . "\r\n";
    $headers .= "MIME-Version: 1.0\r\n";

    if (empty($attachments)) {
      $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
      $headers .= "Content-Transfer-Encoding: 8bit\r\n";
      $body .= "\r\n";
      return $headers . "\r\n" . $body;
    }

    $headers .= "Content-Type: multipart/mixed; boundary=\"$mime_boundary\"\r\n";

    $message = $headers . "\r\n";

    $message .= "--$mime_boundary\r\n";
    $message .= "Content-Type: text/html; charset=UTF-8\r\n";
    $message .= "Content-Transfer-Encoding: 8bit\r\n";
    $message .= "\r\n" . $body . "\r\n";

    foreach ($attachments as $attachment) {
      $file_content = chunk_split(base64_encode($this->readAttachment($attachment)));
      $filename = $this->sanitizeHeaderValue((string) ($attachment['filename'] ?? basename((string) ($attachment['filepath'] ?? 'attachment'))));
      $filemime = $this->sanitizeHeaderValue((string) ($attachment['filemime'] ?? 'application/octet-stream'));

      $message .= "--$mime_boundary\r\n";
      $message .= "Content-Type: $filemime; name=\"$filename\"\r\n";
      $message .= "Content-Disposition: attachment; filename=\"$filename\"\r\n";
      $message .= "Content-Transfer-Encoding: base64\r\n";
      $message .= "\r\n" . $file_content . "\r\n";
    }

    $message .= "--$mime_boundary--\r\n";
    return $message;
  }

  /**
   * Escapes a line for SMTP dot-stuffing (RFC 5321).
   *
   * @param string $line
   *   A message line.
   *
   * @return string
   *   The line, with an extra leading dot if it starts with a dot.
   */
  protected function smtpEscape(string $line): string {
    if (str_starts_with($line, '.')) {
      return '.' . $line;
    }
    return $line;
  }

  /**
   * Sends a command and checks the response.
   *
   * @param resource $socket
   *   The SMTP socket.
   * @param string $command
   *   The command, without trailing CRLF.
   *
   * @return string
   *   The server response.
   */
  protected function sendCommand($socket, string $command): string {
    fwrite($socket, $command . "\r\n");
    $response = $this->readResponse($socket);

    if (preg_match('/^[45]/', $response)) {
      throw new \RuntimeException("SMTP Error: $response");
    }
    return $response;
  }

  /**
   * Reads a (possibly multi-line) server response.
   *
   * @param resource $socket
   *   The SMTP socket.
   *
   * @return string
   *   The response.
   */
  protected function readResponse($socket): string {
    $response = "";
    while ($str = fgets($socket, 515)) {
      $response .= $str;
      if (substr($str, 3, 1) == " ") {
        break;
      }
    }
    return $response;
  }

}
