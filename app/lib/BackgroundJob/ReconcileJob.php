<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Michal Jonik
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Sightline\BackgroundJob;

use OCA\Sightline\Service\EmbedClient;
use OCA\Sightline\Service\EmbedException;
use OCA\Sightline\Service\Reconciler;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/**
 * Daily safety net behind the file events. Its first run after installation is the initial
 * crawl.
 */
class ReconcileJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private Reconciler $reconciler,
		private EmbedClient $embed,
	) {
		parent::__construct($time);
		$this->setInterval(24 * 60 * 60);
		$this->setTimeSensitivity(self::TIME_INSENSITIVE);
		$this->setAllowParallelRuns(false);
	}

	protected function run($argument): void {
		try {
			// Learn the service's current model, so the crawl queues photos indexed with another.
			$this->embed->rememberModel($this->embed->health()['model']);
		} catch (EmbedException) {
			// The crawl only fills the local queue; the worker waits for the service.
		}
		$this->reconciler->schedule();
	}
}
