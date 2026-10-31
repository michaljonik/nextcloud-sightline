<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Michal Jonik
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Sightline\Tests\Unit;

use OCA\Sightline\Service\EmbedClient;
use OCA\Sightline\Service\FileRepository;
use OCA\Sightline\Service\ModelSwitch;
use OCA\Sightline\Service\Reconciler;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The service has changed from "old" to "new". Search stays on "old" until no row is left
 * with it, then moves to "new" and "old" is dropped.
 */
class ModelSwitchTest extends TestCase {
	private EmbedClient&MockObject $embed;
	private FileRepository&MockObject $files;
	private string $searchModel = 'old';
	/** @var list<string> */
	private array $dropped = [];

	protected function setUp(): void {
		$this->embed = $this->createMock(EmbedClient::class);
		$this->embed->method('searchModel')->willReturnCallback(fn () => $this->searchModel);
		$this->embed->method('setSearchModel')->willReturnCallback(function (string $m) {
			$this->searchModel = $m;
		});
		$this->embed->method('deleteModel')->willReturnCallback(function (string $m) {
			$this->dropped[] = $m;
			return 3;
		});
		$this->files = $this->createMock(FileRepository::class);
	}

	public function testPreviousModelStaysWhilePhotosAreOutdated(): void {
		$this->outdated(12);

		$this->assertSame('old', $this->check($this->health(oldSearchable: true)));
		$this->assertSame([], $this->dropped);
	}

	public function testSwitchesAndDropsOnceEveryPhotoHasTheNewModel(): void {
		$this->outdated(0);

		$this->assertSame('new', $this->check($this->health(oldSearchable: true)));
		$this->assertSame('new', $this->searchModel);
		$this->assertSame(['old'], $this->dropped);
	}

	public function testSwitchesAtOnceWhenThePreviousModelCannotBeSearched(): void {
		$this->outdated(12);

		$this->assertSame('new', $this->check($this->health(oldSearchable: false)));
		$this->assertSame(['old'], $this->dropped);
	}

	public function testNothingToDoWithOneModel(): void {
		$this->searchModel = 'new';
		$this->files->expects($this->never())->method('counts');

		$this->assertSame('new', $this->check(['model' => 'new', 'models' => [$this->model('new', true)]]));
		$this->assertSame([], $this->dropped);
	}

	public function testEmptySearchModelMeansTheCurrentOne(): void {
		$this->searchModel = '';

		$this->assertSame('new', $this->check(['model' => 'new', 'models' => [$this->model('new', true)]]));
		$this->assertSame('new', $this->searchModel);
	}

	public function testAModelChangeSchedulesReconciliation(): void {
		$this->outdated(12);
		$this->embed->method('rememberModel')->with('new')->willReturn(true);
		$reconciler = $this->createMock(Reconciler::class);
		$reconciler->expects($this->once())->method('schedule');

		(new ModelSwitch($this->embed, $this->files, $reconciler, new NullLogger()))->check($this->health(oldSearchable: true));
	}

	private function check(array $health): string {
		return (new ModelSwitch($this->embed, $this->files, $this->createMock(Reconciler::class), new NullLogger()))->check($health);
	}

	private function outdated(int $n): void {
		$this->files->method('counts')->with('new')->willReturn(['indexed' => 5, 'failed' => 0, 'outdated' => $n]);
	}

	private function health(bool $oldSearchable): array {
		return ['model' => 'new', 'dims' => 512, 'count' => 5,
			'models' => [$this->model('new', true), $this->model('old', $oldSearchable)]];
	}

	private function model(string $id, bool $searchable): array {
		return ['model' => $id, 'dims' => 512, 'count' => 5, 'searchable' => $searchable];
	}
}
