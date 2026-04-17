<?php

namespace Drupal\o365_smtp\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;

/**
 * Service for interacting with Office 365 (OAuth2 and SMTP).
 */
class O365Client
{

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * The logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * Constructor.
   */
  public function __construct(ConfigFactoryInterface $config_factory, ClientInterface $http_client, StateInterface $state, LoggerChannelFactoryInterface $logger_factory)
  {
    $this->configFactory = $config_factory;
    $this->httpClient = $http_client;
    $this->state = $state;
    $this->logger = $logger_factory->get('o365_smtp');
  }

  /**
   * Get the authorization URL.
   */
  public function getAuthorizationUrl($redirect_uri)
  {
    $config = $this->configFactory->get('o365_smtp.settings');
    $tenant_id = $config->get('tenant_id');
    $client_id = $config->get('client_id');

    $params = [
      'client_id' => $client_id,
      'response_type' => 'code',
      'redirect_uri' => $redirect_uri,
      'response_mode' => 'query',
      'scope' => 'offline_access https://outlook.office.com/SMTP.Send',
      'state' => '12345', // Should be random
    ];

    return "https://login.microsoftonline.com/{$tenant_id}/oauth2/v2.0/authorize?" . http_build_query($params);
  }

  /**
   * Exchange authorization code for tokens.
   */
  public function getAccessTokenFromCode($code, $redirect_uri)
  {
    $config = $this->configFactory->get('o365_smtp.settings');
    $tenant_id = $config->get('tenant_id');

    $params = [
      'client_id' => $config->get('client_id'),
      'scope' => 'offline_access https://outlook.office.com/SMTP.Send',
      'code' => $code,
      'redirect_uri' => $redirect_uri,
      'grant_type' => 'authorization_code',
      'client_secret' => $config->get('client_secret'),
    ];

    return $this->requestToken($tenant_id, $params);
  }

  /**
   * Refresh the access token.
   */
  public function refreshAccessToken()
  {
    $refresh_token = $this->state->get('o365_smtp.refresh_token');
    if (!$refresh_token) {
      throw new \Exception('No refresh token available.');
    }

    $config = $this->configFactory->get('o365_smtp.settings');
    $tenant_id = $config->get('tenant_id');

    $params = [
      'client_id' => $config->get('client_id'),
      'scope' => 'offline_access https://outlook.office.com/SMTP.Send',
      'refresh_token' => $refresh_token,
      'grant_type' => 'refresh_token',
      'client_secret' => $config->get('client_secret'),
    ];

    return $this->requestToken($tenant_id, $params);
  }

  /**
   * Helper to request token from Microsoft Graph.
   */
  protected function requestToken($tenant_id, $params)
  {
    try {
      $response = $this->httpClient->post("https://login.microsoftonline.com/{$tenant_id}/oauth2/v2.0/token", [
        'form_params' => $params,
      ]);

      $data = json_decode($response->getBody(), TRUE);

      if (isset($data['access_token'])) {
        $this->state->set('o365_smtp.access_token', $data['access_token']);
        if (isset($data['refresh_token'])) {
          $this->state->set('o365_smtp.refresh_token', $data['refresh_token']);
        }
        $this->state->set('o365_smtp.token_expires', time() + $data['expires_in']);
        return $data['access_token'];
      }

      throw new \Exception('Token not found in response.');
    } catch (RequestException $e) {
      $this->logger->error('Token request failed: @message', ['@message' => $e->getMessage()]);
      throw $e;
    }
  }

  /**
   * Send email via SMTP using sockets.
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
   *   - attachments: array of file arrays with keys: filepath, filename, filemime
   *
   * @return bool
   *   TRUE if the email was sent successfully.
   *
   * @throws \Exception
   */
  public function send($from, $to, $subject, $body, ?array $params = NULL)
  {
    $access_token = $this->state->get('o365_smtp.access_token');

    $expires = $this->state->get('o365_smtp.token_expires');
    if (!$access_token || ($expires && time() > $expires - 300)) {
      $access_token = $this->refreshAccessToken();
    }

    $host = 'smtp.office365.com';
    $port = 587;

    $socket = fsockopen($host, $port, $errno, $errstr, 30);
    if (!$socket) {
      throw new \Exception("Could not connect to SMTP host: $errstr ($errno)");
    }

    $this->readResponse($socket);

    $this->sendCommand($socket, "EHLO " . $_SERVER['SERVER_NAME']);
    $this->sendCommand($socket, "STARTTLS");

    if (!stream_socket_enable_crypto($socket, TRUE, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT)) {
      throw new \Exception("Failed to enable TLS encryption.");
    }

    $this->sendCommand($socket, "EHLO " . $_SERVER['SERVER_NAME']);

    $auth_str = base64_encode("user=" . $from . "\1auth=Bearer " . $access_token . "\1\1");
    $this->sendCommand($socket, "AUTH XOAUTH2 " . $auth_str);

    $this->sendCommand($socket, "MAIL FROM:<$from>");
    $this->sendCommand($socket, "RCPT TO:<$to>");
    $this->sendCommand($socket, "DATA");

    $attachments = $params['attachments'] ?? [];

    $message = $this->buildMimeMessage($from, $to, $subject, $body, $attachments);

    $this->sendDataMessage($socket, $message);
    $this->sendCommand($socket, "QUIT");

    fclose($socket);
    return TRUE;
  }

  /**
   * Sends the message body via DATA command with proper dot-stuffing.
   *
   * @param resource $socket
   * @param string $message
   *
   * @throws \Exception
   */
  protected function sendDataMessage($socket, $message)
  {
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
      throw new \Exception("SMTP Error during DATA: $response");
    }
  }

  /**
   * Builds a MIME multipart message.
   *
   * @param string $from
   * @param string $to
   * @param string $subject
   * @param string $body
   * @param array $attachments
   *
   * @return string
   */
  protected function buildMimeMessage($from, $to, $subject, $body, array $attachments = [])
  {
    $boundary = md5(uniqid(time()));
    $mime_boundary = '----=_Part_' . $boundary;

    $headers = "From: $from\r\n";
    $headers .= "To: $to\r\n";
    $headers .= "Subject: $subject\r\n";
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
      $filepath = $attachment['filepath'];
      $filename = $attachment['filename'] ?? basename($filepath);
      $filemime = $attachment['filemime'] ?? 'application/octet-stream';

      if (!file_exists($filepath)) {
        $this->logger->warning('Attachment file not found: @file', ['@file' => $filepath]);
        continue;
      }

      $file_content = chunk_split(base64_encode(file_get_contents($filepath)));
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
   * If a line starts with a dot, an extra dot is added.
   *
   * @param string $line
   *
   * @return string
   */
  protected function smtpEscape($line)
  {
    if (strpos($line, '.') === 0) {
      return '.' . $line;
    }
    return $line;
  }

  /**
   * Helper to send command and check response.
   */
  protected function sendCommand($socket, $command)
  {
    fwrite($socket, $command . "\r\n");
    $response = $this->readResponse($socket);

    // Simple error checking: 4xx or 5xx are errors
    if (preg_match('/^[45]/', $response)) {
      throw new \Exception("SMTP Error: $response");
    }
    return $response;
  }

  /**
   * Helper to read response.
   */
  protected function readResponse($socket)
  {
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
