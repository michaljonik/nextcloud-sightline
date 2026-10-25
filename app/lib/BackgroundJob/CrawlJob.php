<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Michal Jonik
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Sightline\BackgroundJob;

use OCA\Sightline\Service\Reconciler;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use OCP\IAppConfig;

/**
 * Crawls one mount for a while, then re-queues itself with the cursor it reached.
 * Argument: {storage_id, root_id, cursor}.
 */
class CrawlJob extends QueuedJob {
	private const BUDGET_SECONDS = 60;

	public function __construct(
		ITimeFactory $time,
		private Reconciler $reconciler,
		private IJobList $jobList,
		private IAppConfig $appConfig,
	) {
		parent::__construct($time);
		$this->setAllowParallelRuns(false);
	}

	protected function run($argument): void {
		$deadline = microtime(true) + Budget::seconds($this->appConfig, self::BUDGET_SECONDS);
		$cursor = (int)$argument['cursor'];
		do {
			$page = $this->reconciler->crawlPage((int)$argument['storage_id'], (int)$argument['root_id'], $cursor);
			$cursor = $page['cursor'];
		} while (!$page['done'] && microtime(true) < $deadline);
		if (!$page['done']) {
			$this->jobList->add(self::class, ['cursor' => $cursor] + $argument);
		}
	}
}
