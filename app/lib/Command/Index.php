<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Michal Jonik
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Sightline\Command;

use OCA\Sightline\Service\EmbedException;
use OCA\Sightline\Service\Indexer;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Drains the queue in the foreground and reports per-stage timings.
 */
class Index extends Command {
	public function __construct(
		private Indexer $indexer,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('sightline:index')
			->setDescription('Process the indexing queue now and print timings')
			->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'files to process', '100000')
			->addOption('seconds', 's', InputOption::VALUE_REQUIRED, 'time budget', '86400')
			->addOption('jsonl', null, InputOption::VALUE_REQUIRED, 'write one JSON line per file to this path');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$rows = [];
		$log = $input->getOption('jsonl') ? fopen($input->getOption('jsonl'), 'w') : null;
		$t0 = microtime(true);
		$stopped = null;
		try {
			$this->indexer->run((int)$input->getOption('limit'), (float)$input->getOption('seconds'),
				function (array $row) use (&$rows, $log, $output) {
					$rows[] = $row;
					if ($log) {
						fwrite($log, json_encode($row) . "\n");
					}
					if ($output->isVerbose()) {
						$output->writeln(json_encode($row));
					} elseif (count($rows) % 25 === 0) {
						$output->writeln(count($rows) . ' done');
					}
				});
		} catch (EmbedException $e) {
			$stopped = $e->getMessage();
		}
		$wall = microtime(true) - $t0;

		$output->writeln(sprintf('%d files in %.1f s (%.2f files/s)', count($rows), $wall, count($rows) / max($wall, 1e-9)));
		$results = array_count_values(array_column($rows, 'result'));
		ksort($results);
		foreach ($results as $result => $n) {
			$output->writeln("  $result: $n");
		}
		$indexed = array_filter($rows, fn ($r) => $r['result'] === 'indexed');
		$bySource = [];
		foreach ($indexed as $r) {
			$bySource[$r['source_mime']][] = $r;
		}
		ksort($bySource);
		$output->writeln(sprintf('  %-14s %5s %22s %10s %10s %12s', 'source', 'n', 'preview ms p50/p95', 'embed p50', 'encode p50', 'preview KB'));
		foreach ($bySource as $mime => $group) {
			$output->writeln(sprintf('  %-14s %5d %10.0f / %9.0f %10.0f %10.0f %12.0f', $mime, count($group),
				self::pct(array_column($group, 'preview_ms'), 50), self::pct(array_column($group, 'preview_ms'), 95),
				self::pct(array_column($group, 'embed_ms'), 50), self::pct(array_column($group, 'encode_ms'), 50),
				array_sum(array_column($group, 'preview_bytes')) / count($group) / 1024));
		}
		foreach (array_filter($rows, fn ($r) => isset($r['error'])) as $r) {
			$output->writeln(sprintf('  failed %d (%s): %s', $r['file_id'], $r['source_mime'] ?? '?', $r['error']));
		}
		if ($stopped !== null) {
			$output->writeln("<error>stopped, queue kept: $stopped</error>");
			return 1;
		}
		return 0;
	}

	private static function pct(array $values, int $p): float {
		sort($values);
		return $values === [] ? 0.0 : (float)$values[(int)min(count($values) - 1, floor(count($values) * $p / 100))];
	}
}
