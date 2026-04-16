<?php

namespace Drupal\o365_smtp\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * Configure Office 365 SMTP settings for this site.
 */
class O365SmtpSettingsForm extends ConfigFormBase {

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

    $form['client_secret'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Client Secret Value'),
      '#default_value' => $config->get('client_secret'),
      '#required' => TRUE,
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

    // Authorization status and link.
    $access_token = \Drupal::state()->get('o365_smtp.access_token');
    $refresh_token = \Drupal::state()->get('o365_smtp.refresh_token');

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

    // Only show auth link if config is saved (we need client ID/Secret to generate the link).
    if ($config->get('client_id') && $config->get('client_secret')) {
       // We'll generate the link in the controller or here.
       // Ideally, we redirect to a controller that builds the provider and redirects.
       $auth_url = Url::fromRoute('o365_smtp.callback', ['op' => 'authorize'])->toString();
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
    $this->config('o365_smtp.settings')
      ->set('client_id', $form_state->getValue('client_id'))
      ->set('client_secret', $form_state->getValue('client_secret'))
      ->set('tenant_id', $form_state->getValue('tenant_id'))
      ->set('from_email', $form_state->getValue('from_email'))
      ->set('from_name', $form_state->getValue('from_name'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
