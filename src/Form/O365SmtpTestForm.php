<?php

namespace Drupal\o365_smtp\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\o365_smtp\Service\O365Client;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Sends a test email directly through the Office 365 client.
 *
 * The client is used instead of the mail manager so the test exercises this
 * module even when it is not the site's default mail system.
 */
class O365SmtpTestForm extends FormBase {

  /**
   * Constructs an O365SmtpTestForm object.
   *
   * @param \Drupal\o365_smtp\Service\O365Client $client
   *   The Office 365 client.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   */
  public function __construct(
    protected O365Client $client,
    ConfigFactoryInterface $config_factory,
  ) {
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('o365_smtp.client'),
      $container->get('config.factory'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'o365_smtp_test_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['to'] = [
      '#type' => 'email',
      '#title' => $this->t('To Email'),
      '#description' => $this->t('Enter the email address to send a test message to.'),
      '#required' => TRUE,
    ];

    $form['subject'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Subject'),
      '#default_value' => $this->t('Office 365 SMTP test'),
      '#required' => TRUE,
    ];

    $form['body'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Body'),
      '#description' => $this->t('Sent as plain text.'),
      '#default_value' => $this->t('This is a test email sent via Office 365 SMTP.'),
      '#required' => TRUE,
    ];

    // Shown after a failure: the raw server reply is what support needs.
    $smtp_response = (string) $form_state->get('smtp_response');
    if ($smtp_response !== '') {
      $form['smtp_response'] = [
        '#type' => 'textarea',
        '#title' => $this->t('Last SMTP server response'),
        '#value' => $smtp_response,
        '#rows' => 4,
        '#attributes' => ['readonly' => 'readonly'],
      ];
    }

    $form['actions'] = [
      '#type' => 'actions',
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Send Test Email'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $from = (string) $this->config('o365_smtp.settings')->get('from_email');
    if ($from === '') {
      $this->messenger()->addError($this->t('From email is not configured.'));
      return;
    }

    $to = $form_state->getValue('to');
    try {
      $this->client->send(
        $from,
        $to,
        (string) $form_state->getValue('subject'),
        (string) $form_state->getValue('body'),
        NULL,
        ['Content-Type' => 'text/plain; charset=UTF-8'],
      );
      $this->messenger()->addStatus($this->t('Test email sent successfully to @to', ['@to' => $to]));
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Failed to send email: @message', ['@message' => $e->getMessage()]));
      $form_state->set('smtp_response', $this->client->getLastSmtpResponse())->setRebuild();
    }
  }

}
