<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Michal Jonik
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Sightline\Settings;

use OCA\Sightline\AppInfo\Application;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\ISettings;
use OCP\Util;

class Admin implements ISettings {
	public function __construct(
		private IAppConfig $appConfig,
		private IURLGenerator $urlGenerator,
		private IL10N $l10n,
	) {
	}

	public function getForm(): TemplateResponse {
		Util::addScript(Application::APP_ID, 'admin');
		Util::addStyle(Application::APP_ID, 'admin');
		return new TemplateResponse(Application::APP_ID, 'admin', [
			'embedUrl' => $this->appConfig->getValueString(Application::APP_ID, 'embed_url', 'http://localhost:8765'),
			'hasToken' => $this->appConfig->getValueString(Application::APP_ID, 'embed_token', lazy: true) !== '',
			'urls' => [
				'settings' => $this->urlGenerator->linkToRoute('sightline.settings.save'),
				'status' => $this->urlGenerator->linkToRoute('sightline.settings.status'),
				'reconcile' => $this->urlGenerator->linkToRoute('sightline.settings.reconcile'),
			],
			// Strings the script shows; the page has no JS translation bundle. {name} placeholders,
			// because t() runs vsprintf() and would reject %s without arguments.
			'strings' => [
				'saved' => $this->l10n->t('Saved'),
				'saveFailed' => $this->l10n->t('Could not save'),
				'serviceOk' => $this->l10n->t('Connected: {model}, {count} vectors'),
				'serviceDown' => $this->l10n->t('Not reachable: {error}'),
				'never' => $this->l10n->t('never'),
				'scheduled' => $this->l10n->t('Scheduled; the next background job run starts it'),
				'running' => $this->l10n->t('pending: mount crawls {crawls}, sweeps {sweeps}'),
				'idle' => $this->l10n->t('idle'),
				'switching' => $this->l10n->t('{count}; search uses {model} until all are done'),
			],
		]);
	}

	public function getSection(): string {
		return Application::APP_ID;
	}

	public function getPriority(): int {
		return 10;
	}
}
