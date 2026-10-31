<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Michal Jonik
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Sightline\Service;

use OCP\Files\Config\IUserMountCache;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IUser;

class SearchService {
	/** Upper bound on service round trips per page, so a user who sees little of a storage cannot stall search. */
	private const MAX_ROUNDS = 5;

	public function __construct(
		private EmbedClient $embed,
		private IUserMountCache $mountCache,
		private IRootFolder $rootFolder,
	) {
	}

	/**
	 * One page of results the user can see.
	 *
	 * The cursor is an offset into the service's ranking, not into the filtered list, so the
	 * next page resumes exactly where this one stopped reading.
	 *
	 * @return array{hits: list<array{node: File, score: float}>, cursor: ?int, rounds: int, model: ?string, timing_ms: list<array>}
	 */
	public function search(IUser $user, string $query, int $cursor, int $limit): array {
		// The prefilter is for relevance only: a share mount reports the owner's whole
		// storage. getFirstNodeById() below is the permission check.
		$storageIds = array_values(array_unique(array_map(
			fn ($mount) => $mount->getStorageId(),
			$this->mountCache->getMountsForUser($user),
		)));
		$userFolder = $this->rootFolder->getUserFolder($user->getUID());
		// During a model change the previous model answers until the new one has every photo.
		$model = $this->embed->searchModel();
		$model = $model === '' || $model === $this->embed->model() ? null : $model;

		$hits = [];
		$timing = [];
		$answered = null;
		$offset = $cursor;
		$exhausted = false;
		for ($round = 1; $round <= self::MAX_ROUNDS; $round++) {
			$batch = max(2 * ($limit - count($hits)), 20);
			try {
				$response = $this->embed->search($query, $storageIds, $offset, $batch, $model);
			} catch (EmbedException $e) {
				if ($model === null || $e->getCode() !== 409) {
					throw $e;
				}
				// The service no longer holds the previous model; ModelSwitch catches up later.
				$model = null;
				$response = $this->embed->search($query, $storageIds, $offset, $batch);
			}
			$timing[] = $response['timing_ms'];
			$answered = $response['model'];
			foreach ($response['results'] as $result) {
				$offset++;
				$node = $userFolder->getFirstNodeById($result['fileid']);
				if ($node instanceof File) {
					$hits[] = ['node' => $node, 'score' => $result['score']];
					if (count($hits) === $limit) {
						return ['hits' => $hits, 'cursor' => $offset, 'rounds' => $round, 'model' => $answered, 'timing_ms' => $timing];
					}
				}
			}
			if ($response['exhausted']) {
				$exhausted = true;
				break;
			}
		}
		// Out of rounds but not of ranking: return a short page and let the next one continue.
		return ['hits' => $hits, 'cursor' => $exhausted ? null : $offset,
			'rounds' => min($round, self::MAX_ROUNDS), 'model' => $answered, 'timing_ms' => $timing];
	}
}
