<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Michal Jonik
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Sightline\Service;

use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Pending work, one row per file. Listeners only write here; they never generate previews.
 */
class QueueService {
	public const INDEX = 'index';
	public const DELETE = 'delete';

	public function __construct(
		private IDBConnection $db,
	) {
	}

	public function enqueue(int $fileId, int $storageId, string $action): void {
		$qb = $this->db->getQueryBuilder();
		$qb->insert('sightline_queue')->values([
			'file_id' => $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT),
			'storage_id' => $qb->createNamedParameter($storageId, IQueryBuilder::PARAM_INT),
			'action' => $qb->createNamedParameter($action),
			'queued_at' => $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
		]);
		try {
			$qb->executeStatement();
		} catch (Exception $e) {
			if ($e->getReason() !== Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw $e;
			}
			$qb = $this->db->getQueryBuilder();
			$qb->update('sightline_queue')
				->set('storage_id', $qb->createNamedParameter($storageId, IQueryBuilder::PARAM_INT))
				->set('action', $qb->createNamedParameter($action))
				->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
				->executeStatement();
		}
	}

	/** @return list<array{file_id: int, storage_id: int, action: string}> */
	public function next(int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('file_id', 'storage_id', 'action')
			->from('sightline_queue')
			->orderBy('queued_at')
			->addOrderBy('file_id')
			->setMaxResults($limit);
		$rows = $qb->executeQuery()->fetchAll();
		return array_map(fn (array $r) => [
			'file_id' => (int)$r['file_id'],
			'storage_id' => (int)$r['storage_id'],
			'action' => $r['action'],
		], $rows);
	}

	public function remove(int $fileId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete('sightline_queue')
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	/** @return int how many entries were dropped */
	public function clear(): int {
		return $this->db->getQueryBuilder()->delete('sightline_queue')->executeStatement();
	}

	public function size(): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))->from('sightline_queue');
		return (int)$qb->executeQuery()->fetchOne();
	}
}
