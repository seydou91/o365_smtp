<?php

namespace Drupal\Tests\o365_smtp\Unit;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\GeneratedUrl;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Drupal\Core\TempStore\PrivateTempStore;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Tests\UnitTestCase;
use Drupal\o365_smtp\Controller\O365AuthController;
use Drupal\o365_smtp\Service\O365Client;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the OAuth2 state handling of the authorization controller.
 *
 * @group o365_smtp
 */
#[Group('o365_smtp')]
class OAuthStateTest extends UnitTestCase {

  /**
   * The mocked Office 365 client.
   *
   * @var \Drupal\o365_smtp\Service\O365Client&\PHPUnit\Framework\MockObject\MockObject
   */
  protected O365Client&MockObject $client;

  /**
   * The mocked private tempstore.
   *
   * @var \Drupal\Core\TempStore\PrivateTempStore&\PHPUnit\Framework\MockObject\MockObject
   */
  protected PrivateTempStore&MockObject $store;

  /**
   * The mocked messenger.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface&\PHPUnit\Framework\MockObject\MockObject
   */
  protected MessengerInterface&MockObject $messenger;

  /**
   * The controller under test.
   *
   * @var \Drupal\o365_smtp\Controller\O365AuthController
   */
  protected O365AuthController $controller;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // URLs are "https://example.com/<route name>".
    $url_generator = $this->createMock(UrlGeneratorInterface::class);
    $url_generator->method('generateFromRoute')->willReturnCallback(
      fn ($route_name, $parameters = [], $options = [], $collect_metadata = FALSE) => $collect_metadata
        ? (new GeneratedUrl())->setGeneratedUrl('https://example.com/' . $route_name)
        : 'https://example.com/' . $route_name
    );
    $container = new ContainerBuilder();
    $container->set('url_generator', $url_generator);
    \Drupal::setContainer($container);

    $this->client = $this->createMock(O365Client::class);
    $this->client->method('isConfigured')->willReturn(TRUE);
    $this->store = $this->createMock(PrivateTempStore::class);
    $store_factory = $this->createMock(PrivateTempStoreFactory::class);
    $store_factory->method('get')->with('o365_smtp')->willReturn($this->store);
    $this->messenger = $this->createMock(MessengerInterface::class);

    $this->controller = new O365AuthController($this->client, $store_factory, $this->createMock(LoggerInterface::class));
    $this->controller->setStringTranslation($this->getStringTranslationStub());
    $this->controller->setMessenger($this->messenger);
  }

  /**
   * Tests that authorization stores a random state sent to Microsoft.
   */
  public function testAuthorizeStoresRandomState(): void {
    $stored_state = NULL;
    $this->store->expects($this->once())
      ->method('set')
      ->with(O365AuthController::STATE_KEY, $this->callback(function ($value) use (&$stored_state) {
        $stored_state = $value;
        return TRUE;
      }));
    $this->client->method('getAuthorizationUrl')
      ->willReturnCallback(fn ($redirect_uri, $state) => 'https://login.microsoftonline.com/authorize?state=' . $state);

    $response = $this->controller->authorize();

    $this->assertInstanceOf(TrustedRedirectResponse::class, $response);
    $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $stored_state);
    $this->assertSame('https://login.microsoftonline.com/authorize?state=' . $stored_state, $response->getTargetUrl());
  }

  /**
   * Tests that the callback rejects a missing or mismatching state.
   */
  #[DataProvider('invalidStateProvider')]
  public function testCallbackRejectsInvalidState(?string $stored_state, ?string $received_state): void {
    $this->store->method('get')->with(O365AuthController::STATE_KEY)->willReturn($stored_state);
    $this->store->expects($this->once())->method('delete')->with(O365AuthController::STATE_KEY);
    $this->client->expects($this->never())->method('getAccessTokenFromCode');
    $this->messenger->expects($this->once())->method('addError');

    $query = ['code' => 'authorization-code'];
    if ($received_state !== NULL) {
      $query['state'] = $received_state;
    }
    $response = $this->controller->callback(Request::create('/callback', 'GET', $query));

    $this->assertInstanceOf(RedirectResponse::class, $response);
    $this->assertSame('https://example.com/o365_smtp.settings', $response->getTargetUrl());
  }

  /**
   * Data provider for ::testCallbackRejectsInvalidState().
   */
  public static function invalidStateProvider(): array {
    return [
      'mismatch' => ['expected-state', 'forged-state'],
      'missing from the callback' => ['expected-state', NULL],
      'nothing pending in the session' => [NULL, 'expected-state'],
    ];
  }

  /**
   * Tests that a matching state lets the code be exchanged once.
   */
  public function testCallbackAcceptsMatchingState(): void {
    $this->store->method('get')->willReturn('expected-state');
    $this->store->expects($this->once())->method('delete')->with(O365AuthController::STATE_KEY);
    $this->client->expects($this->once())
      ->method('getAccessTokenFromCode')
      ->with('authorization-code', 'https://example.com/o365_smtp.oauth_callback')
      ->willReturn('access-token');
    $this->messenger->expects($this->never())->method('addError');
    $this->messenger->expects($this->once())->method('addStatus');

    $this->controller->callback(Request::create('/callback', 'GET', [
      'state' => 'expected-state',
      'code' => 'authorization-code',
    ]));
  }

}
