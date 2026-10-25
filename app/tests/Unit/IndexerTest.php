<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Michal Jonik
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Sightline\Tests\Unit;

use OCA\Sightline\Service\EmbedClient;
use OCA\Sightline\Service\EmbedException;
use OCA\Sightline\Service\FileRepository;
use OCA\Sightline\Service\Indexer;
use OCA\Sightline\Service\QueueService;
use OCA\Sightline\Service\Reconciler;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\Mount\IMountPoint;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\IPreview;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class IndexerTest extends TestCase {
	private QueueService&MockObject $queue;
	private EmbedClient&MockObject $embed;
	private FileRepository&MockObject $files;
	private Reconciler&MockObject $reconciler;
	private IRootFolder&MockObject $rootFolder;
	private Indexer $indexer;

	protected function setUp(): void {
		$this->queue = $this->createMock(QueueService::class);
		$this->embed = $this->createMock(EmbedClient::class);
		$this->embed->method('model')->willReturn('m1');
		$this->files = $this->createMock(FileRepository::class);
		$this->reconciler = $this->createMock(Reconciler::class);
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$preview = $this->createMock(ISimpleFile::class);
		$preview->method('getContent')->willReturn('jpeg bytes');
		$preview->method('getMimeType')->willReturn('image/jpeg');
		$previews = $this->createMock(IPreview::class);
		$previews->method('getPreview')->willReturn($preview);
		$this->indexer = new Indexer($this->queue, $this->embed, $this->files, $this->reconciler, $this->rootFolder, $previews);
	}

	public function testIndexesAndRecordsTheModel(): void {
		$this->queueOne(QueueService::INDEX);
		$this->rootFolder->method('getFirstNodeById')->willReturn($this->photo('/alice/files/a.jpg'));
		$this->embed->method('index')->with(42, 7, 'jpeg bytes', 'image/jpeg')
			->willReturn(['fileid' => 42, 'model' => 'm1', 'encode_ms' => 40.0]);
		$this->files->expects($this->once())->method('save')->with(42, 7, 'etag', 'm1', FileRepository::STATUS_INDEXED, null);
		$this->queue->expects($this->once())->method('remove')->with(42);

		$this->assertSame(['indexed'], $this->drain());
	}

	public function testUnchangedFileIsSkipped(): void {
		$this->queueOne(QueueService::INDEX);
		$this->rootFolder->method('getFirstNodeById')->willReturn($this->photo('/alice/files/a.jpg'));
		$this->files->method('get')->willReturn($this->row('m1'));
		$this->embed->expects($this->never())->method('index');

		$this->assertSame(['unchanged'], $this->drain());
	}

	public function testFileIndexedWithAnOldModelIsEncodedAgain(): void {
		$this->queueOne(QueueService::INDEX);
		$this->rootFolder->method('getFirstNodeById')->willReturn($this->photo('/alice/files/a.jpg'));
		$this->files->method('get')->willReturn($this->row('m0'));
		$this->embed->expects($this->once())->method('index')->willReturn(['fileid' => 42, 'model' => 'm1', 'encode_ms' => 1.0]);

		$this->assertSame(['indexed'], $this->drain());
	}

	public function testTrashedFileLosesItsVector(): void {
		$this->queueOne(QueueService::INDEX);
		$this->rootFolder->method('getFirstNodeById')->willReturn($this->photo('/alice/files_trashbin/files/a.jpg.d1'));
		$this->files->method('get')->willReturn($this->row('m1'));
		$this->embed->expects($this->once())->method('delete')->with(42);
		$this->files->expects($this->once())->method('delete')->with(42);

		$this->assertSame(['deleted'], $this->drain());
	}

	public function testRejectedImageIsMarkedFailedAndDoesNotBlockTheQueue(): void {
		$this->queueOne(QueueService::INDEX);
		$this->rootFolder->method('getFirstNodeById')->willReturn($this->photo('/alice/files/a.jpg'));
		$this->embed->method('index')->willThrowException(new EmbedException('cannot decode', 422));
		$this->files->expects($this->once())->method('save')
			->with(42, 7, 'etag', '', FileRepository::STATUS_FAILED, $this->stringContains('cannot decode'));
		$this->queue->expects($this->once())->method('remove')->with(42);

		$this->assertSame(['rejected'], $this->drain());
	}

	public function testUnreachableServiceStopsTheRunAndKeepsTheEntry(): void {
		$this->queueOne(QueueService::INDEX);
		$this->rootFolder->method('getFirstNodeById')->willReturn($this->photo('/alice/files/a.jpg'));
		$this->embed->method('index')->willThrowException(new EmbedException('connection refused', 0));
		$this->queue->expects($this->never())->method('remove');
		$this->files->expects($this->never())->method('save');

		$this->expectException(EmbedException::class);
		$this->drain();
	}

	public function testModelSwitchSchedulesReconciliation(): void {
		$this->queueOne(QueueService::INDEX);
		$this->rootFolder->method('getFirstNodeById')->willReturn($this->photo('/alice/files/a.jpg'));
		$this->embed->method('index')->willReturn(['fileid' => 42, 'model' => 'm2', 'encode_ms' => 1.0]);
		$this->embed->method('rememberModel')->with('m2')->willReturn(true);
		$this->reconciler->expects($this->once())->method('schedule');

		$this->drain();
	}

	private function drain(): array {
		$results = [];
		$this->indexer->run(10, 60, function (array $row) use (&$results) {
			$results[] = $row['result'];
		});
		return $results;
	}

	private function queueOne(string $action): void {
		$this->queue->method('next')->willReturnOnConsecutiveCalls([['file_id' => 42, 'storage_id' => 7, 'action' => $action]], []);
	}

	private function photo(string $path): File {
		$mount = $this->createMock(IMountPoint::class);
		$mount->method('getNumericStorageId')->willReturn(7);
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(42);
		$file->method('getPath')->willReturn($path);
		$file->method('getMimetype')->willReturn('image/jpeg');
		$file->method('getEtag')->willReturn('etag');
		$file->method('getMountPoint')->willReturn($mount);
		return $file;
	}

	private function row(string $model): array {
		return ['file_id' => 42, 'storage_id' => 7, 'etag' => 'etag', 'model' => $model,
			'status' => FileRepository::STATUS_INDEXED, 'error' => null, 'indexed_at' => 0];
	}
}
