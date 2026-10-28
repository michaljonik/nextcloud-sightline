<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Michal Jonik
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Sightline\Service;

/**
 * Which files are photos worth indexing, decided from what cache entries and cache events
 * carry: a storage-relative path, sometimes a mimetype.
 */
final class PhotoFilter {
	public const MIMETYPES = [
		'image/jpeg', 'image/png', 'image/webp', 'image/avif', 'image/gif', 'image/bmp', 'image/tiff',
		'image/heic', 'image/heif', 'image/x-dcraw',
	];

	// Storage-relative paths that never hold user photos.
	private const SKIP_PREFIXES = ['files_trashbin/', 'files_versions/', 'appdata_', 'cache/', 'uploads/', 'thumbnails/'];
	// Cache events carry no mimetype, and every folder above a changed file gets one too.
	// Nextcloud derives image mimetypes from the extension, so filter on that.
	private const EXTENSIONS = '/\.(jpe?g|png|webp|avif|gif|bmp|tiff?|heic|heif|dng|nef|cr2|cr3|arw|orf|rw2|raf)$/i';

	public static function isIndexablePath(string $storagePath): bool {
		foreach (self::SKIP_PREFIXES as $prefix) {
			if (str_starts_with($storagePath, $prefix)) {
				return false;
			}
		}
		return !self::isMacMetadata($storagePath);
	}

	/**
	 * Resource-fork copies that macOS writes to SMB and FAT volumes (`._IMG_0001.JPG`, or
	 * files under `.AppleDouble/`). They carry a photo extension but no image.
	 */
	public static function isMacMetadata(string $path): bool {
		return str_starts_with(basename($path), '._')
			|| preg_match('#(^|/)\.AppleDouble/#', $path) === 1;
	}

	public static function hasPhotoExtension(string $path): bool {
		return preg_match(self::EXTENSIONS, $path) === 1;
	}
}
