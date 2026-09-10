<?php

namespace Drupal\o365_smtp\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\Url;
use Drupal\o365_smtp\Service\O365Client;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Handles the Office 365 OAuth2 authorization code flow.
 */
class O365AuthController extends ControllerBase {

  /**
   * Private tempstore key holding the pending OAuth2 state value.
   */
  const STATE_KEY = 'oauth_state';

  /**
   * Constructs an O365AuthController object.
   *
   * @param \Drupal\o365_smtp\Service\O365Client $client
   *   The Office 365 client.
   * @param \Drupal\Core\TempStore\PrivateTempStoreFactory $tempStoreFactory
   *   The private tempstore factory.
   * @param \Psr\Log\LoggerInterface $logger
   *   The o365_smtp logger channel.
   */
  public function __construct(
    protected O365Client $client,
    protected PrivateTempStoreFactory $tempStoreFactory,
    protected LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('o365_smtp.client'),
      $container->get('tempstore.private'),
      $container->get('logger.channel.o365_smtp'),
    );
  }

  /**
   * Redirects the administrator to the Microsoft authorization page.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   A redirect to Microsoft, or back to the settings form.
   */
  public function authorize(): Response {
    if (!$this->client->isConfigured()) {
      $this->messenger()->addError($this->t('Missing configuration. Please configure the module first.'));
      return $this->redirect('o365_smtp.settings');
    }

    $state = bin2hex(random_bytes(32));
    $this->tempStoreFactory->get('o365_smtp')->set(self::STATE_KEY, $state);

    $response = new TrustedRedirectResponse($this->client->getAuthorizationUrl($this->getRedirectUri(), $state));
    // The redirect carries a single-use state value: it must never be cached.
    $response->getCacheableMetadata()->setCacheMaxAge(0);
    return $response;
  }

  /**
   * Handles the redirect back from Microsoft.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   A redirect to the settings form.
   */
  public function callback(Request $request): Response {
    // The state is single use: remove it before comparing.
    $store = $this->tempStoreFactory->get('o365_smtp');
    $expected_state = $store->get(self::STATE_KEY);
    $store->delete(self::STATE_KEY);
    $received_state = $request->query->get('state');
    if (!is_string($expected_state) || !is_string($received_state) || !hash_equals($expected_state, $received_state)) {
      $this->logger->warning('OAuth callback rejected: missing or invalid state parameter.');
      $this->messenger()->addError($this->t('Authorization rejected: the OAuth state parameter is missing or does not match this session. Please start the authorization again.'));
      return $this->redirect('o365_smtp.settings');
    }

    if ($request->query->has('error')) {
      $this->messenger()->addError($this->t('Authorization failed: @message', [
        '@message' => $request->query->get('error_description') ?: $request->query->get('error'),
      ]));
      return $this->redirect('o365_smtp.settings');
    }

    $code = $request->query->get('code');
    if (!is_string($code) || $code === '') {
      $this->messenger()->addError($this->t('Authorization failed: no authorization code was returned.'));
      return $this->redirect('o365_smtp.settings');
    }

    try {
      $this->client->getAccessTokenFromCode($code, $this->getRedirectUri());
      $this->messenger()->addStatus($this->t('Successfully authenticated with Office 365.'));
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Authentication failed: @message', ['@message' => $e->getMessage()]));
    }

    return $this->redirect('o365_smtp.settings');
  }

  /**
   * Returns the redirect URI to register in Microsoft Entra ID.
   *
   * @return string
   *   The absolute URL of the callback route.
   */
  protected function getRedirectUri(): string {
    return Url::fromRoute('o365_smtp.oauth_callback', [], ['absolute' => TRUE])->toString(TRUE)->getGeneratedUrl();
  }

}
