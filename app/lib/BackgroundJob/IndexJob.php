<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Michal Jonik
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Sightline\BackgroundJob;

use OCA\Sightline\Service\Indexer;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/**
 * Drains the queue in time-boxed runs, one at a time, so cron never piles up.
 */
class IndexJob extends TimedJob {
	private const BUDGET_SECONDS = 240;

	public function __construct(
		ITimeFactory $time,
		private Indexer $indexer,
	) {
		parent::__construct($time);
		$this->setInterval(5 * 60);
		$this->setTimeSensitivity(self::TIME_INSENSITIVE);
		$this->setAllowParallelRuns(false);
	}

	protected function run($argument): void {
		$this->indexer->run(PHP_INT_MAX, self::BUDGET_SECONDS);
	}
}
