<?php

namespace Drupal\o365_smtp\Plugin\Mail;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Mail\Attribute\Mail;
use Drupal\Core\Mail\MailFormatHelper;
use Drupal\Core\Mail\MailInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\o365_smtp\Service\O365Client;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Sends emails through Office 365 SMTP with OAuth2 authentication.
 */
#[Mail(
  id: 'o365_smtp_mail',
  label: new TranslatableMarkup('Office 365 SMTP'),
  description: new TranslatableMarkup('Sends emails via Office 365 SMTP using OAuth2.'),
)]
class O365SmtpMail implements MailInterface, ContainerFactoryPluginInterface {

  /**
   * Constructs an O365SmtpMail object.
   *
   * @param \Drupal\o365_smtp\Service\O365Client $client
   *   The Office 365 client.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Psr\Log\LoggerInterface $logger
   *   The o365_smtp logger channel.
   */
  public function __construct(
    protected O365Client $client,
    protected ConfigFactoryInterface $configFactory,
    protected LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $container->get('o365_smtp.client'),
      $container->get('config.factory'),
      $container->get('logger.channel.o365_smtp'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function format(array $message) {
    $message['body'] = implode("\n\n", $message['body']);
    // Plain text messages get the same processing as with the core PHP mail
    // plugin; HTML messages are sent as they are.
    if (!$this->isHtml($message)) {
      $message['body'] = MailFormatHelper::wrapMail(MailFormatHelper::htmlToText($message['body']));
    }
    return $message;
  }

  /**
   * {@inheritdoc}
   */
  public function mail(array $message) {
    $from_email = (string) $this->configFactory->get('o365_smtp.settings')->get('from_email');
    if ($from_email === '') {
      $this->logger->error('From email is not configured.');
      return FALSE;
    }

    try {
      $this->client->send(
        $from_email,
        (string) $message['to'],
        (string) $message['subject'],
        (string) $message['body'],
        $message['params'] ?? NULL,
        $message['headers'] ?? [],
      );
      return TRUE;
    }
    catch (\Exception $e) {
      $this->logger->error('Mail sending failed: @error', ['@error' => $e->getMessage()]);
      return FALSE;
    }
  }

  /**
   * Checks whether a message has an HTML content type.
   *
   * @param array $message
   *   The Drupal message.
   *
   * @return bool
   *   TRUE if the Content-Type header is text/html.
   */
  protected function isHtml(array $message): bool {
    $headers = array_change_key_case($message['headers'] ?? [], CASE_LOWER);
    return str_contains(strtolower((string) ($headers['content-type'] ?? '')), 'text/html');
  }

}
