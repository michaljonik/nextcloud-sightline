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
use OCA\Sightline\Service\Purger;
use OCA\Sightline\Service\QueueService;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class PurgerTest extends TestCase {
	private EmbedClient&MockObject $embed;
	private QueueService&MockObject $queue;
	private FileRepository&MockObject $files;
	private IJobList&MockObject $jobList;
	private IAppConfig&MockObject $appConfig;
	private Purger $purger;

	protected function setUp(): void {
		$this->embed = $this->createMock(EmbedClient::class);
		$this->queue = $this->createMock(QueueService::class);
		$this->files = $this->createMock(FileRepository::class);
		$this->jobList = $this->createMock(IJobList::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->purger = new Purger($this->embed, $this->queue, $this->files, $this->jobList, $this->appConfig);
	}

	public function testDeletesVectorsStateAndSettings(): void {
		$this->embed->expects($this->once())->method('deleteAll')->willReturn(3);
		$this->files->method('clear')->willReturn(4);
		$this->queue->method('clear')->willReturn(2);
		$this->jobList->expects($this->exactly(2))->method('remove');
		$deleted = [];
		$this->appConfig->method('deleteKey')->willReturnCallback(function (string $app, string $key) use (&$deleted) {
			$deleted[] = $key;
		});

		$this->assertSame(['vectors' => 3, 'files' => 4, 'queued' => 2], $this->purger->purge(true));
		$this->assertContains('embed_token', $deleted);
		$this->assertNotContains('enabled', $deleted);
		$this->assertNotContains('installed_version', $deleted);
	}

	public function testUnreachableServiceLeavesNextcloudUntouched(): void {
		$this->embed->method('deleteAll')->willThrowException(new EmbedException('down', 0));
		$this->files->expects($this->never())->method('clear');
		$this->queue->expects($this->never())->method('clear');
		$this->appConfig->expects($this->never())->method('deleteKey');

		$this->expectException(EmbedException::class);
		$this->purger->purge(true);
	}

	public function testServiceCanBeSkipped(): void {
		$this->embed->expects($this->never())->method('deleteAll');

		$this->assertNull($this->purger->purge(false)['vectors']);
	}
}
