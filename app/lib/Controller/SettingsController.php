<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Michal Jonik
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Sightline\Controller;

use OCA\Sightline\AppInfo\Application;
use OCA\Sightline\Service\Reconciler;
use OCA\Sightline\Service\StatusService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IRequest;

/**
 * Admin settings. Every route is admin-only and CSRF-checked (the framework defaults).
 */
class SettingsController extends Controller {
	public function __construct(
		IRequest $request,
		private IAppConfig $appConfig,
		private StatusService $status,
		private Reconciler $reconciler,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * @param string $token empty keeps the stored token
	 */
	#[FrontpageRoute(verb: 'PUT', url: '/settings')]
	public function save(string $url, string $token = ''): JSONResponse {
		$url = trim($url);
		$scheme = parse_url($url, PHP_URL_SCHEME);
		if (!in_array($scheme, ['http', 'https'], true) || parse_url($url, PHP_URL_HOST) === null) {
			return new JSONResponse(['error' => 'The service URL must be an http or https URL'], Http::STATUS_BAD_REQUEST);
		}
		$this->appConfig->setValueString(Application::APP_ID, 'embed_url', rtrim($url, '/'));
		if ($token !== '') {
			$this->appConfig->setValueString(Application::APP_ID, 'embed_token', $token, lazy: true, sensitive: true);
		}
		return new JSONResponse($this->status->status());
	}

	#[FrontpageRoute(verb: 'GET', url: '/settings/status')]
	public function status(): JSONResponse {
		return new JSONResponse($this->status->status());
	}

	#[FrontpageRoute(verb: 'POST', url: '/settings/reconcile')]
	public function reconcile(): JSONResponse {
		return new JSONResponse($this->reconciler->schedule());
	}
}
