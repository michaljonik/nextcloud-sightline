<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Michal Jonik
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Sightline\Tests\Unit;

use OCA\Sightline\Service\PhotoFilter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PhotoFilterTest extends TestCase {
	public static function paths(): array {
		return [
			'home photo' => ['files/Photos/2024/IMG_0001.JPG', true, true],
			'external storage root' => ['Holiday/DSC_0042.nef', true, true],
			'heic' => ['files/a.heic', true, true],
			'trash' => ['files_trashbin/files/IMG_0001.jpg.d1729700000', false, false],
			'version' => ['files_versions/Photos/IMG_0001.jpg.v1729700000', false, false],
			'preview cache' => ['appdata_oc123/preview/1/2/3/1024-1024.jpg', false, true],
			'folder' => ['files/Photos', true, false],
			'document' => ['files/notes.md', true, false],
			'macOS resource fork' => ['files/Photos/._DSC_0042.NEF', false, true],
			'AppleDouble folder' => ['files/Photos/.AppleDouble/DSC_0042.NEF', false, true],
			'AppleDouble at storage root' => ['.AppleDouble/DSC_0042.NEF', false, true],
			'dot in the name' => ['files/Photos/a._b.jpg', true, true],
		];
	}

	#[DataProvider('paths')]
	public function testPaths(string $path, bool $indexable, bool $photo): void {
		$this->assertSame($indexable, PhotoFilter::isIndexablePath($path));
		$this->assertSame($photo, PhotoFilter::hasPhotoExtension($path));
	}
}
