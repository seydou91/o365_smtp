<?php

namespace Drupal\o365_smtp\Hook;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\o365_smtp\Service\O365Client;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Status report requirements for the Office 365 SMTP module.
 */
class O365SmtpRequirements implements ContainerInjectionInterface {

  use AutowireTrait;
  use StringTranslationTrait;

  /**
   * Severity levels, see ::severity().
   */
  protected const OK = 0;
  protected const WARNING = 1;
  protected const ERROR = 2;

  /**
   * Constructs an O365SmtpRequirements object.
   *
   * @param \Drupal\o365_smtp\Service\O365Client $client
   *   The Office 365 client.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   */
  public function __construct(
    #[Autowire(service: 'o365_smtp.client')]
    protected O365Client $client,
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Implements hook_runtime_requirements().
   */
  #[Hook('runtime_requirements')]
  public function runtime(): array {
    $requirements = [];

    if (!extension_loaded('openssl')) {
      $requirements['o365_smtp_openssl'] = [
        'title' => $this->t('Office 365 SMTP: OpenSSL'),
        'value' => $this->t('Missing'),
        'description' => $this->t('The PHP OpenSSL extension is required to connect to smtp.office365.com with TLS.'),
        'severity' => $this->severity(self::ERROR),
      ];
    }

    $config = $this->configFactory->get('o365_smtp.settings');
    $settings_url = Url::fromRoute('o365_smtp.settings')->toString();

    $requirement = ['title' => $this->t('Office 365 SMTP')];
    if (!$this->client->isConfigured() || !$config->get('from_email')) {
      $requirement += [
        'value' => $this->t('Not configured'),
        'description' => $this->t('Enter the application credentials and the sender address on the <a href=":url">settings page</a>.', [':url' => $settings_url]),
        'severity' => $this->severity(self::WARNING),
      ];
    }
    elseif ($this->client->isReauthorizationRequired()) {
      $requirement += [
        'value' => $this->t('Re-authorization required'),
        'description' => $this->t('Microsoft rejected the refresh token (expired or revoked). No email can be sent until the module is authorized again on the <a href=":url">settings page</a>.', [':url' => $settings_url]),
        'severity' => $this->severity(self::ERROR),
      ];
    }
    elseif (!$this->client->isAuthorized()) {
      $requirement += [
        'value' => $this->t('Not authorized'),
        'description' => $this->t('Authorize the module with Office 365 on the <a href=":url">settings page</a>.', [':url' => $settings_url]),
        'severity' => $this->severity(self::WARNING),
      ];
    }
    else {
      $requirement += [
        'value' => $this->t('Authorized'),
        'severity' => $this->severity(self::OK),
      ];
    }
    $requirements['o365_smtp'] = $requirement;

    if (!$this->client->isClientSecretOverridden() && (string) $config->get('client_secret') !== '') {
      $requirements['o365_smtp_client_secret'] = [
        'title' => $this->t('Office 365 SMTP: client secret'),
        'value' => $this->t('Stored in configuration'),
        'description' => $this->t('The client secret is exported with the site configuration. Define it in settings.php with <code>@setting</code> and remove it from the configuration.', ['@setting' => "\$settings['o365_smtp.client_secret']"]),
        'severity' => $this->severity(self::WARNING),
      ];
    }

    return $requirements;
  }

  /**
   * Returns a severity value the running Drupal version accepts.
   *
   * @param int $level
   *   One of the self::OK, self::WARNING or self::ERROR constants.
   *
   * @return int|\Drupal\Core\Extension\Requirement\RequirementSeverity
   *   The enum on Drupal 11.2 and later, the legacy integer before.
   */
  protected function severity(int $level): int|RequirementSeverity {
    // @todo Return the enum only when Drupal < 11.2 support ends (2026-09-10).
    return enum_exists(RequirementSeverity::class) ? RequirementSeverity::from($level) : $level;
  }

}
