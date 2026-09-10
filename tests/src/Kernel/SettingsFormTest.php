<?php

namespace Drupal\Tests\o365_smtp\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\KernelTests\KernelTestBase;
use Drupal\o365_smtp\Form\O365SmtpSettingsForm;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the settings form.
 *
 * @group o365_smtp
 */
#[Group('o365_smtp')]
#[RunTestsInSeparateProcesses]
class SettingsFormTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'o365_smtp'];

  /**
   * Valid form values.
   *
   * @var array
   */
  protected array $values = [
    'client_id' => 'client-id',
    'client_secret' => 'initial-secret',
    'tenant_id' => 'tenant-id',
    'from_email' => 'mailbox@example.com',
    'from_name' => 'Example Site',
    'max_attachment_size' => 5,
    'helo_hostname' => 'mail.example.com',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['o365_smtp']);
    $this->container->get('router.builder')->rebuild();
  }

  /**
   * Tests saving the form, then saving it again with an empty secret.
   */
  public function testEmptySecretKeepsStoredValue(): void {
    $this->submitSettingsForm($this->values);

    $config = $this->config('o365_smtp.settings');
    foreach ($this->values as $key => $value) {
      $this->assertSame($value, $config->get($key), "$key is saved.");
    }

    $this->submitSettingsForm(['client_secret' => '', 'client_id' => 'new-client-id'] + $this->values);

    $config = $this->config('o365_smtp.settings');
    $this->assertSame('initial-secret', $config->get('client_secret'));
    $this->assertSame('new-client-id', $config->get('client_id'));
  }

  /**
   * Tests that the settings.php secret wins and is never written to config.
   */
  public function testSettingsOverride(): void {
    $this->setSetting('o365_smtp.client_secret', 'settings-secret');

    $this->submitSettingsForm(['client_secret' => 'typed-secret'] + $this->values);

    $this->assertSame('', $this->config('o365_smtp.settings')->get('client_secret'));
    $this->assertSame('settings-secret', $this->container->get('o365_smtp.client')->getClientSecret());
  }

  /**
   * Submits the settings form and asserts it has no validation error.
   *
   * @param array $values
   *   The submitted values.
   */
  protected function submitSettingsForm(array $values): void {
    $form_state = (new FormState())->setValues($values);
    $this->container->get('form_builder')->submitForm(O365SmtpSettingsForm::class, $form_state);
    $this->assertSame([], $form_state->getErrors());
  }

}
