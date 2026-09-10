<?php

namespace Drupal\o365_smtp\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Url;
use Drupal\o365_smtp\Service\O365Client;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configure Office 365 SMTP settings for this site.
 */
class O365SmtpSettingsForm extends ConfigFormBase {

  /**
   * The settings.php entry overriding the client secret, as shown to users.
   */
  const SECRET_SETTING = "\$settings['o365_smtp.client_secret']";

  /**
   * Constructs an O365SmtpSettingsForm object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Config\TypedConfigManagerInterface $typed_config_manager
   *   The typed config manager.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param \Drupal\o365_smtp\Service\O365Client $client
   *   The Office 365 client.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config_manager,
    protected StateInterface $state,
    protected O365Client $client,
  ) {
    parent::__construct($config_factory, $typed_config_manager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('state'),
      $container->get('o365_smtp.client'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'o365_smtp_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['o365_smtp.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('o365_smtp.settings');

    $form['client_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Application (Client) ID'),
      '#default_value' => $config->get('client_id'),
      '#required' => TRUE,
    ];

    $secret_overridden = $this->client->isClientSecretOverridden();
    $has_secret = $secret_overridden || (string) $config->get('client_secret') !== '';
    $form['client_secret'] = [
      '#type' => 'password',
      '#title' => $this->t('Client Secret Value'),
      '#required' => !$has_secret,
      '#disabled' => $secret_overridden,
      '#placeholder' => $has_secret ? $this->t('Leave empty to keep the current value') : '',
      '#attributes' => ['autocomplete' => 'new-password'],
      '#description' => $secret_overridden
        ? $this->t('The client secret is defined in settings.php (<code>@setting</code>) and cannot be changed here.', ['@setting' => self::SECRET_SETTING])
        : $this->t('Stored in configuration. For production sites, prefer <code>@setting</code> in settings.php so the secret is not exported with the configuration.', ['@setting' => self::SECRET_SETTING]),
    ];

    $form['tenant_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Directory (Tenant) ID'),
      '#default_value' => $config->get('tenant_id'),
      '#required' => TRUE,
    ];

    $form['from_email'] = [
      '#type' => 'email',
      '#title' => $this->t('From Email Address'),
      '#description' => $this->t('The email address to send from. Must match the authenticated user account.'),
      '#default_value' => $config->get('from_email'),
      '#required' => TRUE,
    ];

    $form['from_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('From Name'),
      '#default_value' => $config->get('from_name'),
    ];

    $form['max_attachment_size'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum attachment size (MB)'),
      '#description' => $this->t('Attachments larger than this are refused and the email is not sent.'),
      '#min' => 1,
      '#default_value' => $config->get('max_attachment_size') ?: O365Client::DEFAULT_MAX_ATTACHMENT_SIZE,
      '#required' => TRUE,
    ];

    // Authorization status and link.
    $refresh_token = $this->state->get('o365_smtp.refresh_token');

    if ($refresh_token) {
      $form['auth_status'] = [
        '#markup' => '<div class="messages messages--status">' . $this->t('Module is authenticated with Office 365.') . '</div>',
      ];
      $link_text = $this->t('Re-authorize with Office 365');
    }
    else {
      $form['auth_status'] = [
        '#markup' => '<div class="messages messages--warning">' . $this->t('Module is NOT authenticated. Please save configuration and then authorize.') . '</div>',
      ];
      $link_text = $this->t('Authorize with Office 365');
    }

    // The authorization link needs saved credentials.
    if ($this->client->isConfigured()) {
      $form['auth_link'] = [
        '#type' => 'link',
        '#title' => $link_text,
        '#url' => Url::fromRoute('o365_smtp.callback', ['op' => 'authorize']),
        '#attributes' => ['class' => ['button', 'button--primary']],
      ];
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('o365_smtp.settings')
      ->set('client_id', $form_state->getValue('client_id'))
      ->set('tenant_id', $form_state->getValue('tenant_id'))
      ->set('from_email', $form_state->getValue('from_email'))
      ->set('from_name', $form_state->getValue('from_name'))
      ->set('max_attachment_size', (int) $form_state->getValue('max_attachment_size'));

    // An empty password field keeps the stored secret.
    $secret = (string) $form_state->getValue('client_secret');
    if ($secret !== '' && !$this->client->isClientSecretOverridden()) {
      $config->set('client_secret', $secret);
    }
    $config->save();

    parent::submitForm($form, $form_state);
  }

}
