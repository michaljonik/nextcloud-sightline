<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Michal Jonik
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Sightline\Tests\Unit;

use OCA\Sightline\BackgroundJob\CrawlJob;
use OCA\Sightline\BackgroundJob\SweepJob;
use OCA\Sightline\Service\EmbedClient;
use OCA\Sightline\Service\FileRepository;
use OCA\Sightline\Service\QueueService;
use OCA\Sightline\Service\Reconciler;
use OCP\BackgroundJob\IJobList;
use OCP\Files\Cache\ICacheEntry;
use OCP\Files\Cache\IFileAccess;
use OCP\Files\IMimeTypeLoader;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ReconcilerTest extends TestCase {
	private IFileAccess&MockObject $fileAccess;
	private FileRepository&MockObject $files;
	private IJobList&MockObject $jobList;
	/** @var list<array{int, int, string}> */
	private array $queued = [];
	private Reconciler $reconciler;

	protected function setUp(): void {
		$this->fileAccess = $this->createMock(IFileAccess::class);
		$this->files = $this->createMock(FileRepository::class);
		$this->jobList = $this->createMock(IJobList::class);
		$queue = $this->createMock(QueueService::class);
		$queue->method('enqueue')->willReturnCallback(function (int $fileId, int $storageId, string $action) {
			$this->queued[] = [$fileId, $storageId, $action];
		});
		$mimeTypes = $this->createMock(IMimeTypeLoader::class);
		$mimeTypes->method('exists')->willReturn(true);
		$mimeTypes->method('getId')->willReturn(1);
		$embed = $this->createMock(EmbedClient::class);
		$embed->method('model')->willReturn('new-model');
		$this->reconciler = new Reconciler($this->fileAccess, $mimeTypes, $this->files, $queue, $embed,
			$this->jobList, $this->createMock(IAppConfig::class));
	}

	public function testCrawlQueuesNewChangedAndOutdatedPhotos(): void {
		$this->fileAccess->method('getByAncestorInStorage')->willReturnCallback(fn () => yield from [
			$this->entry(1, 7, 'files/new.jpg', 'e1'),
			$this->entry(2, 7, 'files/unchanged.jpg', 'e2'),
			$this->entry(3, 7, 'files/changed.jpg', 'e3-new'),
			$this->entry(4, 7, 'files/old-model.jpg', 'e4'),
			$this->entry(5, 7, 'files/failed.jpg', 'e5'),
		]);
		$this->files->method('getMany')->willReturn([
			2 => $this->row(2, 7, 'e2'),
			3 => $this->row(3, 7, 'e3'),
			4 => $this->row(4, 7, 'e4', model: 'old-model'),
			5 => $this->row(5, 7, 'e5', model: '', status: FileRepository::STATUS_FAILED),
		]);

		$page = $this->reconciler->crawlPage(7, 100, 0);

		$this->assertSame([[1, 7, 'index'], [3, 7, 'index'], [4, 7, 'index']], $this->queued);
		$this->assertSame(['cursor' => 5, 'done' => true, 'seen' => 5, 'queued' => 3], $page);
	}

	public function testCrawlReportsMorePagesWhenThePageIsFull(): void {
		$this->fileAccess->method('getByAncestorInStorage')->willReturnCallback(function () {
			for ($id = 11; $id <= 10 + Reconciler::PAGE; $id++) {
				yield $this->entry($id, 7, "files/$id.jpg", 'e');
			}
		});
		$this->files->method('getMany')->willReturn([]);

		$page = $this->reconciler->crawlPage(7, 100, 10);

		$this->assertFalse($page['done']);
		$this->assertSame(10 + Reconciler::PAGE, $page['cursor']);
	}

	public function testSweepQueuesDeletedTrashedMovedAndChangedFiles(): void {
		$this->files->method('page')->willReturn([
			$this->row(1, 7, 'e1'),
			$this->row(2, 7, 'e2'),
			$this->row(3, 7, 'e3'),
			$this->row(4, 7, 'e4'),
			$this->row(5, 7, 'e5'),
		]);
		$this->fileAccess->method('getByFileIds')->with([1, 2, 3, 4, 5])->willReturn([
			// 1 is gone
			$this->entry(2, 7, 'files_trashbin/files/2.jpg.d1', 'e2'),
			$this->entry(3, 8, 'Shared/3.jpg', 'e3'),
			$this->entry(4, 7, 'files/4.jpg', 'e4-new'),
			$this->entry(5, 7, 'files/5.jpg', 'e5'),
		]);

		$page = $this->reconciler->sweepPage(0);

		$this->assertSame([[1, 7, 'delete'], [2, 7, 'delete'], [3, 8, 'index'], [4, 7, 'index']], $this->queued);
		$this->assertSame(['cursor' => 5, 'done' => true, 'seen' => 5, 'queued' => 4], $page);
	}

	public function testScheduleSkipsWhatIsStillRunning(): void {
		$this->fileAccess->method('getDistinctMounts')->willReturnCallback(fn () => yield from [
			['storage_id' => 7, 'root_id' => 1, 'overridden_root' => 2],
			['storage_id' => 8, 'root_id' => 5, 'overridden_root' => 5],
		]);
		$this->jobList->method('getJobsIterator')->willReturnCallback(
			fn (string $class) => $class === SweepJob::class ? [new \stdClass()] : []);
		$added = [];
		$this->jobList->method('add')->willReturnCallback(function (string $class, mixed $argument) use (&$added) {
			$added[] = [$class, $argument];
		});

		$this->assertSame(['crawls' => 2, 'sweep' => false], $this->reconciler->schedule());
		$this->assertSame([
			[CrawlJob::class, ['storage_id' => 7, 'root_id' => 2, 'cursor' => 0]],
			[CrawlJob::class, ['storage_id' => 8, 'root_id' => 5, 'cursor' => 0]],
		], $added);
	}

	private function entry(int $id, int $storageId, string $path, string $etag): ICacheEntry {
		$entry = $this->createMock(ICacheEntry::class);
		$entry->method('getId')->willReturn($id);
		$entry->method('getStorageId')->willReturn($storageId);
		$entry->method('getPath')->willReturn($path);
		$entry->method('getEtag')->willReturn($etag);
		return $entry;
	}

	private function row(int $id, int $storageId, string $etag, string $model = 'new-model', int $status = FileRepository::STATUS_INDEXED): array {
		return ['file_id' => $id, 'storage_id' => $storageId, 'etag' => $etag, 'model' => $model,
			'status' => $status, 'error' => null, 'indexed_at' => 0];
	}
}
