<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Michal Jonik
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Sightline\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use OCP\IPreview;
use Psr\Log\LoggerInterface;

/**
 * Drains the queue: preview from IPreview → sightline-embed → bookkeeping row.
 */
class Indexer {
	/** IPreview rounds sizes up to a power of 4; 1024 is one, 512 would become 1024 anyway. */
	public const PREVIEW_SIZE = 1024;

	private const STATUS_INDEXED = 1;
	private const STATUS_FAILED = 2;

	public function __construct(
		private QueueService $queue,
		private EmbedClient $embed,
		private IRootFolder $rootFolder,
		private IPreview $preview,
		private IDBConnection $db,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Process queue entries until the queue is empty, $maxFiles are done or $seconds run out.
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
		// Only photos under a user's files/ (not trash, versions or appdata).
		if (!$node instanceof File || !str_starts_with($node->getMimetype(), 'image/')
			|| !preg_match('#^/[^/]+/files/#', $node->getPath())) {
			return $this->delete($fileId) + ['result' => 'not indexable'];
		}
		$storageId = $node->getMountPoint()->getNumericStorageId();
		$row = $this->row($fileId);
		if ($row !== null && $row['etag'] === $node->getEtag() && (int)$row['status'] === self::STATUS_INDEXED) {
			if ((int)$row['storage_id'] === $storageId) {
				return ['result' => 'unchanged'];
			}
			$this->embed->move($fileId, $storageId);
			$this->save($fileId, $storageId, $node->getEtag(), $row['model'], self::STATUS_INDEXED, null);
			return ['result' => 'moved'];
		}

		$t0 = microtime(true);
		try {
			$preview = $this->preview->getPreview($node, self::PREVIEW_SIZE, self::PREVIEW_SIZE, false,
				IPreview::MODE_FILL, null, cacheResult: false);
			$image = $preview->getContent();
			$mime = $preview->getMimeType();
		} catch (\Throwable $e) {
			$this->save($fileId, $storageId, $node->getEtag(), '', self::STATUS_FAILED, 'preview: ' . $e->getMessage());
			return ['result' => 'no preview', 'source_mime' => $node->getMimetype(), 'error' => $e->getMessage()];
		}
		$t1 = microtime(true);
		try {
			$response = $this->embed->index($fileId, $storageId, $image, $mime);
		} catch (\Throwable $e) {
			// Service down or overloaded: leave it for the next run rather than marking failed.
			$this->queue->enqueue($fileId, $storageId, QueueService::INDEX);
			$this->logger->warning('sightline-embed index failed', ['exception' => $e]);
			throw $e;
		}
		$t2 = microtime(true);
		$this->save($fileId, $storageId, $node->getEtag(), $response['model'], self::STATUS_INDEXED, null);
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

	private function delete(int $fileId): array {
		if ($this->row($fileId) === null) {
			return ['result' => 'not indexed'];
		}
		$this->embed->delete($fileId);
		$qb = $this->db->getQueryBuilder();
		$qb->delete('sightline_files')
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
		return ['result' => 'deleted'];
	}

	private function row(int $fileId): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('sightline_files')
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));
		$row = $qb->executeQuery()->fetch();
		return $row === false ? null : $row;
	}

	private function save(int $fileId, int $storageId, string $etag, string $model, int $status, ?string $error): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete('sightline_files')
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
		$qb = $this->db->getQueryBuilder();
		$qb->insert('sightline_files')->values([
			'file_id' => $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT),
			'storage_id' => $qb->createNamedParameter($storageId, IQueryBuilder::PARAM_INT),
			'etag' => $qb->createNamedParameter($etag),
			'model' => $qb->createNamedParameter($model),
			'status' => $qb->createNamedParameter($status, IQueryBuilder::PARAM_INT),
			'error' => $qb->createNamedParameter($error === null ? null : mb_substr($error, 0, 255)),
			'indexed_at' => $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
		])->executeStatement();
	}
}
