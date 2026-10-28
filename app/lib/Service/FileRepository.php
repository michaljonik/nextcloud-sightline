<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Michal Jonik
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Sightline\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * One row per file the embedding service holds a vector for (or failed to), so
 * reconciliation is a local comparison and the service stays vectors-only.
 *
 * @psalm-type FileRow = array{file_id: int, storage_id: int, etag: string, model: string, status: int, error: ?string, indexed_at: int}
 */
class FileRepository {
	public const STATUS_INDEXED = 1;
	public const STATUS_FAILED = 2;

	// Oracle caps IN () lists at 1000 parameters.
	private const CHUNK = 1000;

	public function __construct(
		private IDBConnection $db,
	) {
	}

	/** @return FileRow|null */
	public function get(int $fileId): ?array {
		return $this->getMany([$fileId])[$fileId] ?? null;
	}

	/**
	 * @param list<int> $fileIds
	 * @return array<int, FileRow> keyed by file ID
	 */
	public function getMany(array $fileIds): array {
		$rows = [];
		foreach (array_chunk($fileIds, self::CHUNK) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('*')->from('sightline_files')
				->where($qb->expr()->in('file_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			$result = $qb->executeQuery();
			while ($row = $result->fetch()) {
				$rows[(int)$row['file_id']] = self::cast($row);
			}
			$result->closeCursor();
		}
		return $rows;
	}

	/**
	 * Rows in file ID order, for the reconciliation sweep.
	 *
	 * @return list<FileRow>
	 */
	public function page(int $afterFileId, int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('sightline_files')
			->where($qb->expr()->gt('file_id', $qb->createNamedParameter($afterFileId, IQueryBuilder::PARAM_INT)))
			->orderBy('file_id')
			->setMaxResults($limit);
		return array_map(self::cast(...), $qb->executeQuery()->fetchAll());
	}

	public function save(int $fileId, int $storageId, string $etag, string $model, int $status, ?string $error): void {
		$this->db->beginTransaction();
		try {
			$this->delete($fileId);
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
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	public function delete(int $fileId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete('sightline_files')
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	/** @return int how many rows were dropped */
	public function clear(): int {
		return $this->db->getQueryBuilder()->delete('sightline_files')->executeStatement();
	}

	/** @return array{indexed: int, failed: int, outdated: int} outdated: indexed with another model */
	public function counts(string $model): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('status', 'model')
			->selectAlias($qb->func()->count('*'), 'n')
			->from('sightline_files')
			->groupBy('status', 'model');
		$counts = ['indexed' => 0, 'failed' => 0, 'outdated' => 0];
		foreach ($qb->executeQuery()->fetchAll() as $row) {
			if ((int)$row['status'] === self::STATUS_FAILED) {
				$counts['failed'] += (int)$row['n'];
			} elseif ($model !== '' && $row['model'] !== $model) {
				$counts['outdated'] += (int)$row['n'];
			} else {
				$counts['indexed'] += (int)$row['n'];
			}
		}
		return $counts;
	}

	/** @return FileRow */
	private static function cast(array $row): array {
		return [
			'file_id' => (int)$row['file_id'],
			'storage_id' => (int)$row['storage_id'],
			'etag' => (string)$row['etag'],
			'model' => (string)$row['model'],
			'status' => (int)$row['status'],
			'error' => $row['error'] === null ? null : (string)$row['error'],
			'indexed_at' => (int)$row['indexed_at'],
		];
	}
}
