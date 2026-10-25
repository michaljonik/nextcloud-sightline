<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Michal Jonik
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Sightline\Command;

use OCA\Sightline\Service\QueueService;
use OCA\Sightline\Service\Reconciler;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Runs the crawl and sweep in the foreground, or only schedules them as background jobs.
 */
class Reconcile extends Command {
	public function __construct(
		private Reconciler $reconciler,
		private QueueService $queue,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('sightline:reconcile')
			->setDescription('Queue photos that are new, changed, moved or deleted since they were indexed')
			->addOption('background', 'b', InputOption::VALUE_NONE, 'only schedule the background jobs');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		if ($input->getOption('background')) {
			$scheduled = $this->reconciler->schedule();
			$output->writeln(sprintf('scheduled %d crawl job(s)%s', $scheduled['crawls'], $scheduled['sweep'] ? ' and a sweep' : ''));
			return 0;
		}
		foreach ($this->reconciler->mounts() as $mount) {
			$seen = $queued = 0;
			$cursor = 0;
			do {
				$page = $this->reconciler->crawlPage($mount['storage_id'], $mount['root_id'], $cursor);
				$cursor = $page['cursor'];
				$seen += $page['seen'];
				$queued += $page['queued'];
			} while (!$page['done']);
			$output->writeln(sprintf('crawl storage %d root %d: %d photos, %d queued', $mount['storage_id'], $mount['root_id'], $seen, $queued));
		}
		$seen = $queued = 0;
		$cursor = 0;
		do {
			$page = $this->reconciler->sweepPage($cursor);
			$cursor = $page['cursor'];
			$seen += $page['seen'];
			$queued += $page['queued'];
		} while (!$page['done']);
		$output->writeln(sprintf('sweep: %d indexed files checked, %d queued', $seen, $queued));
		$output->writeln('queue size ' . $this->queue->size());
		return 0;
	}
}
