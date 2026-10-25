<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Michal Jonik
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Sightline\BackgroundJob;

use OCA\Sightline\Service\EmbedException;
use OCA\Sightline\Service\Indexer;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Drains the queue in time-boxed runs, one at a time, so cron never piles up.
 */
class IndexJob extends TimedJob {
	private const BUDGET_SECONDS = 240;

	public function __construct(
		ITimeFactory $time,
		private Indexer $indexer,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(5 * 60);
		$this->setTimeSensitivity(self::TIME_INSENSITIVE);
		$this->setAllowParallelRuns(false);
	}

	protected function run($argument): void {
		try {
			$this->indexer->run(PHP_INT_MAX, Budget::seconds($this->appConfig, self::BUDGET_SECONDS));
		} catch (EmbedException $e) {
			// Unreachable or misconfigured service: the queue waits for the next run.
			$this->logger->warning('Sightline indexing paused: ' . $e->getMessage(), ['app' => 'sightline']);
		}
	}
}
