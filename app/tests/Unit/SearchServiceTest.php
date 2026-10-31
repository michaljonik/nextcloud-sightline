<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Michal Jonik
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Sightline\Tests\Unit;

use OCA\Sightline\Service\EmbedClient;
use OCA\Sightline\Service\EmbedException;
use OCA\Sightline\Service\SearchService;
use OCP\Files\Config\ICachedMountInfo;
use OCP\Files\Config\IUserMountCache;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IUser;
use PHPUnit\Framework\TestCase;

/**
 * The service ranks file IDs 1..$total by score; the user can see only $visible of them.
 * The cursor must always be an offset into that ranking.
 */
class SearchServiceTest extends TestCase {
	/** @var list<array{offset: int, limit: int}> */
	private array $calls = [];

	public function testFullPageStopsAtTheLastConsumedResult(): void {
		$page = $this->search(total: 100, visible: range(1, 100), cursor: 0, limit: 5);

		$this->assertSame([1, 2, 3, 4, 5], $page['ids']);
		$this->assertSame(5, $page['cursor']);
		$this->assertSame([['storage_ids' => [7, 9], 'offset' => 0, 'limit' => 20, 'model' => null]], $this->calls);
	}

	public function testInvisibleResultsAreSkippedAndStillAdvanceTheCursor(): void {
		$page = $this->search(total: 100, visible: [3, 30, 31, 60], cursor: 0, limit: 3);

		$this->assertSame([3, 30, 31], $page['ids']);
		$this->assertSame(31, $page['cursor']);
		$this->assertSame([0, 20], array_column($this->calls, 'offset'));
	}

	public function testNextPageResumesFromTheCursor(): void {
		$page = $this->search(total: 100, visible: [3, 30, 31, 60], cursor: 31, limit: 3);

		$this->assertSame([60], $page['ids']);
		$this->assertNull($page['cursor'], 'ranking exhausted');
	}

	public function testShortPageWithCursorWhenRoundsRunOut(): void {
		$page = $this->search(total: 1000, visible: [500], cursor: 0, limit: 5);

		$this->assertSame([], $page['ids']);
		$this->assertCount(5, $this->calls);
		$this->assertSame(array_sum(array_column($this->calls, 'limit')), $page['cursor']);
	}

	public function testPreviousModelAnswersDuringAModelChange(): void {
		$this->search(total: 100, visible: range(1, 100), cursor: 0, limit: 5, searchModel: 'old');

		$this->assertSame(['old'], array_column($this->calls, 'model'));
	}

	public function testCurrentModelIsNotNamed(): void {
		$this->search(total: 100, visible: range(1, 100), cursor: 0, limit: 5, searchModel: 'new');

		$this->assertSame([null], array_column($this->calls, 'model'));
	}

	public function testFallsBackToTheCurrentModelWhenThePreviousIsGone(): void {
		$page = $this->search(total: 100, visible: range(1, 100), cursor: 0, limit: 5, searchModel: 'gone');

		$this->assertSame([1, 2, 3, 4, 5], $page['ids']);
		$this->assertSame(['gone', null], array_column($this->calls, 'model'));
	}

	/** @return array{ids: list<int>, cursor: ?int} */
	private function search(int $total, array $visible, int $cursor, int $limit, string $searchModel = ''): array {
		$embed = $this->createMock(EmbedClient::class);
		$embed->method('model')->willReturn('new');
		$embed->method('searchModel')->willReturn($searchModel);
		$embed->method('search')->willReturnCallback(function (string $q, array $storageIds, int $offset, int $n, ?string $model = null) use ($total) {
			$this->calls[] = ['storage_ids' => $storageIds, 'offset' => $offset, 'limit' => $n, 'model' => $model];
			if ($model === 'gone') {
				throw new EmbedException('model gone is not searchable here', 409);
			}
			$ids = range($offset + 1, min($offset + $n, $total));
			return [
				'model' => $model ?? 'new',
				'results' => array_map(fn (int $id) => ['fileid' => $id, 'score' => 1 - $id / 10000], $offset >= $total ? [] : $ids),
				'exhausted' => $offset + $n >= $total,
				'timing_ms' => [],
			];
		});

		$mounts = array_map(function (int $storageId) {
			$mount = $this->createMock(ICachedMountInfo::class);
			$mount->method('getStorageId')->willReturn($storageId);
			return $mount;
		}, [7, 9, 7]);
		$mountCache = $this->createMock(IUserMountCache::class);
		$mountCache->method('getMountsForUser')->willReturn($mounts);

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getFirstNodeById')->willReturnCallback(function (int $id) use ($visible) {
			if (!in_array($id, $visible, true)) {
				return null;
			}
			$file = $this->createMock(File::class);
			$file->method('getId')->willReturn($id);
			return $file;
		});
		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->willReturn($userFolder);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');

		$page = (new SearchService($embed, $mountCache, $rootFolder))->search($user, 'dog', $cursor, $limit);
		return ['ids' => array_map(fn (array $hit) => $hit['node']->getId(), $page['hits']), 'cursor' => $page['cursor']];
	}
}
