<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Michal Jonik
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Sightline\Command;

use OCA\Sightline\Service\StatusService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Status extends Command {
	public function __construct(
		private StatusService $status,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('sightline:status')->setDescription('Show indexing progress and service health');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$s = $this->status->status();
		$service = $s['service'];
		$output->writeln($service['ok']
			? sprintf('service: ok, model %s (%d dims), %d vectors', $service['model'], $service['dims'], $service['count'])
			: 'service: unreachable: ' . $service['error']);
		$output->writeln(sprintf('photos: %d indexed, %d failed, %d with an old model', $s['files']['indexed'], $s['files']['failed'], $s['files']['outdated']));
		$output->writeln(sprintf('queue: %d; background crawls pending: %d, sweeps: %d', $s['queue'], $s['pending']['crawls'], $s['pending']['sweeps']));
		$output->writeln('last reconciliation: ' . ($s['last_reconcile'] > 0 ? date('c', $s['last_reconcile']) : 'never'));
		return $service['ok'] ? 0 : 1;
	}
}
