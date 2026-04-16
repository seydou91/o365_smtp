<?php

namespace Drupal\o365_smtp\Plugin\Mail;

use Drupal\Core\Mail\MailInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Site\Settings;

/**
 * Defines the O365 SMTP Mail plugin.
 *
 * @Mail(
 *   id = "o365_smtp_mail",
 *   label = @Translation("Office 365 SMTP"),
 *   description = @Translation("Sends emails via Office 365 SMTP using OAuth2.")
 * )
 */
class O365SmtpMail implements MailInterface, ContainerFactoryPluginInterface
{

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition)
  {
    return new static();
  }

  /**
   * {@inheritdoc}
   */
  public function format(array $message)
  {
    // Join the body array into one string.
    $message['body'] = implode("\n\n", $message['body']);
    return $message;
  }

  /**
   * {@inheritdoc}
   */
  public function mail(array $message)
  {
    $config = \Drupal::config('o365_smtp.settings');
    $from_email = $config->get('from_email');

    // If from_email is not configured, we can't send.
    if (empty($from_email)) {
      \Drupal::logger('o365_smtp')->error('From email is not configured.');
      return FALSE;
    }

    try {
      /** @var \Drupal\o365_smtp\Service\O365Client $client */
      $client = \Drupal::service('o365_smtp.client');

      $params = $message['params'] ?? NULL;
      $client->send(
        $from_email,
        $message['to'],
        $message['subject'],
        $message['body'],
        $params
      );

      return TRUE;
    } catch (\Exception $e) {
      \Drupal::logger('o365_smtp')->error('Mail sending failed: @error', ['@error' => $e->getMessage()]);
      return FALSE;
    }
  }
}
