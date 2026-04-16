<?php

namespace Drupal\o365_smtp\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\Core\Url;

/**
 * Controller for Office 365 OAuth flow.
 */
class O365AuthController extends ControllerBase
{

  /**
   * Callback for OAuth flow.
   */
  public function callback(Request $request)
  {
    $config = $this->config('o365_smtp.settings');
    $clientId = $config->get('client_id');
    $clientSecret = $config->get('client_secret');
    $tenantId = $config->get('tenant_id');

    if (!$clientId || !$clientSecret || !$tenantId) {
      $this->messenger()->addError($this->t('Missing configuration. Please configure the module first.'));
      return new RedirectResponse(Url::fromRoute('o365_smtp.settings')->toString(TRUE)->getGeneratedUrl());
    }

    /** @var \Drupal\o365_smtp\Service\O365Client $client */
    $client = \Drupal::service('o365_smtp.client');

    $op = $request->query->get('op');
    $code = $request->query->get('code');

    // Step 1: Redirect to Authorization URL
    if ($op == 'authorize') {
      $url_object = Url::fromRoute('o365_smtp.callback', [], ['absolute' => TRUE]);
      $authorizationUrl = $client->getAuthorizationUrl($url_object->toString(TRUE)->getGeneratedUrl());

      $response = new TrustedRedirectResponse($authorizationUrl);
      $response->addCacheableDependency($url_object);
      return $response;
    }

    // Step 2: Handle Callback
    if ($code) {
      try {
        $url_object = Url::fromRoute('o365_smtp.callback', [], ['absolute' => TRUE]);
        $client->getAccessTokenFromCode($code, $url_object->toString(TRUE)->getGeneratedUrl());
        $this->messenger()->addStatus($this->t('Successfully authenticated with Office 365.'));
      } catch (\Exception $e) {
        $this->messenger()->addError($this->t('Authentication failed: @message', ['@message' => $e->getMessage()]));
      }
    }

    return new RedirectResponse(Url::fromRoute('o365_smtp.settings')->toString(TRUE)->getGeneratedUrl());
  }

}
