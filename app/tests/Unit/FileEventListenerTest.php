<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Michal Jonik
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Sightline\Tests\Unit;

use OCA\Sightline\Listener\FileEventListener;
use OCA\Sightline\Service\QueueService;
use OCP\Files\Cache\CacheEntryInsertedEvent;
use OCP\Files\Cache\CacheEntryRemovedEvent;
use OCP\Files\Cache\CacheEntryUpdatedEvent;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Mount\IMountPoint;
use OCP\Files\Storage\IStorage;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class FileEventListenerTest extends TestCase {
	private QueueService&MockObject $queue;
	private FileEventListener $listener;

	protected function setUp(): void {
		$this->queue = $this->createMock(QueueService::class);
		$this->listener = new FileEventListener($this->queue, $this->createMock(IAppConfig::class),
			$this->createMock(LoggerInterface::class));
	}

	public function testCacheInsertOfPhotoIsQueued(): void {
		$this->queue->expects($this->once())->method('enqueue')->with(42, 7, QueueService::INDEX);
		$this->listener->handle(new CacheEntryInsertedEvent($this->createMock(IStorage::class), 'files/a.jpg', 42, 7));
	}

	public function testCacheRemovalIsQueuedAsDelete(): void {
		$this->queue->expects($this->once())->method('enqueue')->with(42, 7, QueueService::DELETE);
		$this->listener->handle(new CacheEntryRemovedEvent($this->createMock(IStorage::class), 'files/a.jpg', 42, 7));
	}

	public function testFoldersTrashAndOtherFilesAreIgnored(): void {
		$this->queue->expects($this->never())->method('enqueue');
		$storage = $this->createMock(IStorage::class);
		$this->listener->handle(new CacheEntryUpdatedEvent($storage, 'files/Photos', 1, 7));
		$this->listener->handle(new CacheEntryUpdatedEvent($storage, 'files_trashbin/files/a.jpg.d1', 42, 7));
		$this->listener->handle(new CacheEntryInsertedEvent($storage, 'files/report.pdf', 43, 7));
	}

	public function testNodeEvents(): void {
		$photo = $this->file('image/jpeg');
		$this->queue->expects($this->exactly(2))->method('enqueue')
			->willReturnCallback(function (int $fileId, int $storageId, string $action) {
				static $expected = [QueueService::INDEX, QueueService::DELETE];
				$this->assertSame([42, 7, array_shift($expected)], [$fileId, $storageId, $action]);
			});
		$this->listener->handle(new NodeWrittenEvent($photo));
		$this->listener->handle(new NodeDeletedEvent($photo));
		$this->listener->handle(new NodeWrittenEvent($this->file('application/pdf')));
		$this->listener->handle(new NodeWrittenEvent($this->createMock(Folder::class)));
	}

	private function file(string $mime): File {
		$mount = $this->createMock(IMountPoint::class);
		$mount->method('getNumericStorageId')->willReturn(7);
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(42);
		$file->method('getMimetype')->willReturn($mime);
		$file->method('getMountPoint')->willReturn($mount);
		return $file;
	}
}
