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
	 * @return array{hits: list<array{node: File, score: float}>, cursor: ?int, rounds: int, timing_ms: list<array>}
	 */
	public function search(IUser $user, string $query, int $cursor, int $limit): array {
		// The prefilter is for relevance only: a share mount reports the owner's whole
		// storage. getFirstNodeById() below is the permission check.
		$storageIds = array_values(array_unique(array_map(
			fn ($mount) => $mount->getStorageId(),
			$this->mountCache->getMountsForUser($user),
		)));
		$userFolder = $this->rootFolder->getUserFolder($user->getUID());

		$hits = [];
		$timing = [];
		$offset = $cursor;
		$exhausted = false;
		for ($round = 1; $round <= self::MAX_ROUNDS; $round++) {
			$batch = max(2 * ($limit - count($hits)), 20);
			$response = $this->embed->search($query, $storageIds, $offset, $batch);
			$timing[] = $response['timing_ms'];
			foreach ($response['results'] as $result) {
				$offset++;
				$node = $userFolder->getFirstNodeById($result['fileid']);
				if ($node instanceof File) {
					$hits[] = ['node' => $node, 'score' => $result['score']];
					if (count($hits) === $limit) {
						return ['hits' => $hits, 'cursor' => $offset, 'rounds' => $round, 'timing_ms' => $timing];
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
			'rounds' => min($round, self::MAX_ROUNDS), 'timing_ms' => $timing];
	}
}
