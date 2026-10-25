<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Michal Jonik
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Sightline\Service;

use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IPreview;

/**
 * Drains the queue: preview from IPreview → sightline-embed → bookkeeping row.
 */
class Indexer {
	/** IPreview rounds sizes up to a power of 4; 1024 is one, 512 would become 1024 anyway. */
	public const PREVIEW_SIZE = 1024;

	public function __construct(
		private QueueService $queue,
		private EmbedClient $embed,
		private FileRepository $files,
		private Reconciler $reconciler,
		private IRootFolder $rootFolder,
		private IPreview $preview,
	) {
	}

	/**
	 * Process queue entries until the queue is empty, $maxFiles are done or $seconds run out.
	 * Throws EmbedException when the service is unreachable or misconfigured; the entry
	 * stays queued.
	 *
	 * @param callable(array): void|null $onFile receives one stats row per processed file
	 */
	public function run(int $maxFiles, float $seconds, ?callable $onFile = null): int {
		$deadline = microtime(true) + $seconds;
		$done = 0;
		while ($done < $maxFiles && microtime(true) < $deadline) {
			$batch = $this->queue->next(min(50, $maxFiles - $done));
			if ($batch === []) {
				break;
			}
			foreach ($batch as $item) {
				$stats = $item['action'] === QueueService::DELETE
					? $this->delete($item['file_id'])
					: $this->index($item['file_id']);
				$this->queue->remove($item['file_id']);
				$done++;
				if ($onFile !== null) {
					$onFile($stats + ['file_id' => $item['file_id']]);
				}
				if (microtime(true) >= $deadline) {
					break;
				}
			}
		}
		return $done;
	}

	private function index(int $fileId): array {
		$node = $this->rootFolder->getFirstNodeById($fileId);
		// Only photos under a user's files/ (not trash, versions or appdata). A trashed photo
		// loses its vector; restoring it encodes it again.
		if (!$node instanceof File || !str_starts_with($node->getMimetype(), 'image/')
			|| !preg_match('#^/[^/]+/files/#', $node->getPath())) {
			return $this->delete($fileId) + ['result' => 'not indexable'];
		}
		$storageId = $node->getMountPoint()->getNumericStorageId();
		$row = $this->files->get($fileId);
		if ($row !== null && $row['etag'] === $node->getEtag() && !$this->outdated($row)) {
			if ($row['storage_id'] === $storageId) {
				return ['result' => 'unchanged'];
			}
			if ($row['status'] === FileRepository::STATUS_INDEXED) {
				$this->embed->move($fileId, $storageId);
			}
			$this->files->save($fileId, $storageId, $row['etag'], $row['model'], $row['status'], $row['error']);
			return ['result' => 'moved'];
		}

		$t0 = microtime(true);
		try {
			$preview = $this->preview->getPreview($node, self::PREVIEW_SIZE, self::PREVIEW_SIZE, false,
				IPreview::MODE_FILL, null, cacheResult: false);
			$image = $preview->getContent();
			$mime = $preview->getMimeType();
		} catch (\Throwable $e) {
			return $this->fail($fileId, $storageId, $node, 'preview: ' . $e->getMessage()) + ['result' => 'no preview'];
		}
		$t1 = microtime(true);
		try {
			$response = $this->embed->index($fileId, $storageId, $image, $mime);
		} catch (EmbedException $e) {
			if ($e->isRejected()) {
				return $this->fail($fileId, $storageId, $node, 'embed: ' . $e->getMessage()) + ['result' => 'rejected'];
			}
			// Service down or misconfigured: keep the entry and end this run.
			throw $e;
		}
		$t2 = microtime(true);
		if ($this->embed->rememberModel($response['model'])) {
			// The service switched models: queue everything indexed with the old one.
			$this->reconciler->schedule();
		}
		$this->files->save($fileId, $storageId, $node->getEtag(), $response['model'], FileRepository::STATUS_INDEXED, null);
		return [
			'result' => 'indexed',
			'source_mime' => $node->getMimetype(),
			'source_bytes' => $node->getSize(),
			'preview_mime' => $mime,
			'preview_bytes' => strlen($image),
			'preview_ms' => ($t1 - $t0) * 1e3,
			'embed_ms' => ($t2 - $t1) * 1e3,
			'encode_ms' => $response['encode_ms'],
		];
	}

	/** Indexed with a model the service no longer uses. Failed rows wait for a new etag. */
	private function outdated(array $row): bool {
		$model = $this->embed->model();
		return $row['status'] === FileRepository::STATUS_INDEXED && $model !== '' && $row['model'] !== $model;
	}

	private function fail(int $fileId, int $storageId, File $node, string $error): array {
		// A failed file must not keep an old vector for content that changed.
		if (($this->files->get($fileId)['status'] ?? null) === FileRepository::STATUS_INDEXED) {
			$this->embed->delete($fileId);
		}
		$this->files->save($fileId, $storageId, $node->getEtag(), '', FileRepository::STATUS_FAILED, $error);
		return ['source_mime' => $node->getMimetype(), 'error' => $error];
	}

	private function delete(int $fileId): array {
		if ($this->files->get($fileId) === null) {
			return ['result' => 'not indexed'];
		}
		$this->embed->delete($fileId);
		$this->files->delete($fileId);
		return ['result' => 'deleted'];
	}
}
