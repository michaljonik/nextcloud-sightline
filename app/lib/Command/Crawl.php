<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Michal Jonik
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Sightline\Command;

use OCA\Sightline\Service\QueueService;
use OCP\Files\Cache\IFileAccess;
use OCP\Files\IMimeTypeLoader;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Queues every photo in every user-files mount. Development stand-in for the scheduled,
 * resumable per-storage crawl jobs; uses the same IFileAccess paging by file ID.
 */
class Crawl extends Command {
	public const IMAGE_MIMETYPES = [
		'image/jpeg', 'image/png', 'image/webp', 'image/avif', 'image/gif', 'image/bmp', 'image/tiff',
		'image/heic', 'image/heif', 'image/x-dcraw',
	];
	private const PAGE = 1000;

	public function __construct(
		private IFileAccess $fileAccess,
		private IMimeTypeLoader $mimeTypeLoader,
		private QueueService $queue,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('sightline:crawl')->setDescription('Queue all photos in user files for indexing');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$mimeIds = array_map(
			fn (string $mime) => $this->mimeTypeLoader->getId($mime),
			array_values(array_filter(self::IMAGE_MIMETYPES, fn (string $mime) => $this->mimeTypeLoader->exists($mime))),
		);
		$total = 0;
		foreach ($this->fileAccess->getDistinctMounts() as $mount) {
			$cursor = 0;
			$queued = 0;
			do {
				$page = 0;
				foreach ($this->fileAccess->getByAncestorInStorage($mount['storage_id'], $mount['overridden_root'],
					$cursor, self::PAGE, $mimeIds, endToEndEncrypted: false) as $entry) {
					$this->queue->enqueue($entry->getId(), $entry->getStorageId(), QueueService::INDEX);
					$cursor = $entry->getId();
					$page++;
				}
				$queued += $page;
			} while ($page === self::PAGE);
			$output->writeln(sprintf('storage %d (root %d): %d photos', $mount['storage_id'], $mount['overridden_root'], $queued));
			$total += $queued;
		}
		$output->writeln("queued $total, queue size " . $this->queue->size());
		return 0;
	}
}
