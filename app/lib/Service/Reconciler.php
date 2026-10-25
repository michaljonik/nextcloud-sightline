<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Michal Jonik
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Sightline\Service;

use OCA\Sightline\AppInfo\Application;
use OCA\Sightline\BackgroundJob\CrawlJob;
use OCA\Sightline\BackgroundJob\SweepJob;
use OCP\BackgroundJob\IJobList;
use OCP\Files\Cache\IFileAccess;
use OCP\Files\IMimeTypeLoader;
use OCP\IAppConfig;

/**
 * Catches what events miss, by comparing the file cache with the bookkeeping table:
 *
 * - crawl: walks every user-files mount by file ID and queues photos that are new, changed
 *   or indexed with another model;
 * - sweep: walks the bookkeeping table and queues rows whose file is gone, trashed or on
 *   another storage.
 *
 * Both are pure database work in pages of PAGE files and resume from a file ID cursor, so a
 * background job can stop after any page.
 */
class Reconciler {
	public const PAGE = 1000;

	/** @var list<int>|null */
	private ?array $mimeTypeIds = null;

	public function __construct(
		private IFileAccess $fileAccess,
		private IMimeTypeLoader $mimeTypeLoader,
		private FileRepository $files,
		private QueueService $queue,
		private EmbedClient $embed,
		private IJobList $jobList,
		private IAppConfig $appConfig,
	) {
	}

	/**
	 * Schedule a crawl of every mount and a sweep, skipping whichever is still running.
	 *
	 * @return array{crawls: int, sweep: bool}
	 */
	public function schedule(): array {
		$crawls = 0;
		if (!$this->running(CrawlJob::class)) {
			foreach ($this->mounts() as $mount) {
				$this->jobList->add(CrawlJob::class, $mount + ['cursor' => 0]);
				$crawls++;
			}
		}
		$sweep = !$this->running(SweepJob::class);
		if ($sweep) {
			$this->jobList->add(SweepJob::class, ['cursor' => 0]);
		}
		$this->appConfig->setValueInt(Application::APP_ID, 'last_reconcile', time());
		return ['crawls' => $crawls, 'sweep' => $sweep];
	}

	/** @return array{crawls: int, sweeps: int} background jobs still queued */
	public function pending(): array {
		$counts = array_column($this->jobList->countByClass(), 'count', 'class');
		return [
			'crawls' => (int)($counts[CrawlJob::class] ?? 0),
			'sweeps' => (int)($counts[SweepJob::class] ?? 0),
		];
	}

	/**
	 * User-files mounts. For home storages the root is the files/ folder, so trash and
	 * versions are never crawled. A share mount points into its owner's storage, so a
	 * shared folder is crawled twice; the second pass finds nothing to do.
	 *
	 * @return \Generator<array{storage_id: int, root_id: int}>
	 */
	public function mounts(): \Generator {
		foreach ($this->fileAccess->getDistinctMounts() as $mount) {
			yield ['storage_id' => $mount['storage_id'], 'root_id' => $mount['overridden_root']];
		}
	}

	/** @return array{cursor: int, done: bool, seen: int, queued: int} */
	public function crawlPage(int $storageId, int $rootId, int $cursor): array {
		$entries = [];
		foreach ($this->fileAccess->getByAncestorInStorage($storageId, $rootId, $cursor, self::PAGE,
			$this->mimeTypeIds(), endToEndEncrypted: false) as $entry) {
			$entries[$entry->getId()] = $entry;
		}
		$rows = $this->files->getMany(array_keys($entries));
		$model = $this->embed->model();
		$queued = 0;
		foreach ($entries as $fileId => $entry) {
			$row = $rows[$fileId] ?? null;
			$stale = $row === null
				|| $row['etag'] !== $entry->getEtag()
				|| ($row['status'] === FileRepository::STATUS_INDEXED && $model !== '' && $row['model'] !== $model);
			if ($stale && PhotoFilter::isIndexablePath($entry->getPath())) {
				$this->queue->enqueue($fileId, $entry->getStorageId(), QueueService::INDEX);
				$queued++;
			}
		}
		return [
			'cursor' => $entries === [] ? $cursor : array_key_last($entries),
			'done' => count($entries) < self::PAGE,
			'seen' => count($entries),
			'queued' => $queued,
		];
	}

	/** @return array{cursor: int, done: bool, seen: int, queued: int} */
	public function sweepPage(int $cursor): array {
		$rows = $this->files->page($cursor, self::PAGE);
		$entries = [];
		foreach ($rows === [] ? [] : $this->fileAccess->getByFileIds(array_column($rows, 'file_id')) as $entry) {
			$entries[$entry->getId()] = $entry;
		}
		$queued = 0;
		foreach ($rows as $row) {
			$entry = $entries[$row['file_id']] ?? null;
			if ($entry === null || !PhotoFilter::isIndexablePath($entry->getPath())) {
				// Deleted, or trashed: a trashed photo loses its vector.
				$this->queue->enqueue($row['file_id'], $row['storage_id'], QueueService::DELETE);
				$queued++;
			} elseif ($entry->getStorageId() !== $row['storage_id'] || $entry->getEtag() !== $row['etag']) {
				$this->queue->enqueue($row['file_id'], $entry->getStorageId(), QueueService::INDEX);
				$queued++;
			}
		}
		return [
			'cursor' => $rows === [] ? $cursor : end($rows)['file_id'],
			'done' => count($rows) < self::PAGE,
			'seen' => count($rows),
			'queued' => $queued,
		];
	}

	private function running(string $class): bool {
		foreach ($this->jobList->getJobsIterator($class, 1, 0) as $job) {
			return true;
		}
		return false;
	}

	/** @return list<int> */
	private function mimeTypeIds(): array {
		return $this->mimeTypeIds ??= array_map(
			fn (string $mime) => $this->mimeTypeLoader->getId($mime),
			array_values(array_filter(PhotoFilter::MIMETYPES, fn (string $mime) => $this->mimeTypeLoader->exists($mime))),
		);
	}
}
