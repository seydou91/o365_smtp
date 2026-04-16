<?php

namespace Drupal\o365_smtp\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form to test email sending.
 */
class O365SmtpTestForm extends FormBase
{

  /**
   * The mail manager.
   *
   * @var \Drupal\Core\Mail\MailManagerInterface
   */
  protected $mailManager;

  /**
   * Constructs a new O365SmtpTestForm.
   *
   * @param \Drupal\Core\Mail\MailManagerInterface $mail_manager
   *   The mail manager.
   */
  public function __construct(MailManagerInterface $mail_manager)
  {
    $this->mailManager = $mail_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container)
  {
    return new static(
      $container->get('plugin.manager.mail')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId()
  {
    return 'o365_smtp_test_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state)
  {
    $form['to'] = [
      '#type' => 'email',
      '#title' => $this->t('To Email'),
      '#description' => $this->t('Enter the email address to send a test message to.'),
      '#required' => TRUE,
    ];

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
  public function submitForm(array &$form, FormStateInterface $form_state)
  {
    $to = $form_state->getValue('to');
    $params = ['message' => 'This is a test email from the Office 365 SMTP module.'];
    $langcode = \Drupal::currentUser()->getPreferredLangcode();

    // Force usage of our mail plugin for this test if not globally set
    // But usually, we just want to test if the system works.
    // If the user hasn't set this module as the default mail system, this might fail to use our class.
    // So we should probably instantiate our client directly or ensure the mail system is used.
    // For now, let's try the standard mail manager, assuming they might have configured it or we can force it.

    // Actually, to be safe and test *our* logic specifically, let's use our client service directly.
    try {
      /** @var \Drupal\o365_smtp\Service\O365Client $client */
      $client = \Drupal::service('o365_smtp.client');
      $config = \Drupal::config('o365_smtp.settings');
      $from = $config->get('from_email');

      if (!$from) {
        $this->messenger()->addError($this->t('From email is not configured.'));
        return;
      }

      $client->send($from, $to, 'O365 SMTP Test', 'This is a test email sent via Office 365 SMTP.');
      $this->messenger()->addStatus($this->t('Test email sent successfully to @to', ['@to' => $to]));
    } catch (\Exception $e) {
      $this->messenger()->addError($this->t('Failed to send email: @message', ['@message' => $e->getMessage()]));
    }
  }

}
