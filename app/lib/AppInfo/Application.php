<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Michal Jonik
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Sightline\AppInfo;

use OCA\Files_Trashbin\Events\NodeRestoredEvent;
use OCA\Sightline\Listener\FileEventListener;
use OCA\Sightline\Search\PhotoSearchProvider;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Files\Cache\CacheEntryInsertedEvent;
use OCP\Files\Cache\CacheEntryRemovedEvent;
use OCP\Files\Cache\CacheEntryUpdatedEvent;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;

class Application extends App implements IBootstrap {
	public const APP_ID = 'sightline';

	/** Events that may add, change or remove an indexed photo. */
	public const FILE_EVENTS = [
		NodeCreatedEvent::class,
		NodeWrittenEvent::class,
		NodeDeletedEvent::class,
		NodeRenamedEvent::class,
		NodeRestoredEvent::class,
		// Changes found by a scan (external storage, occ files:scan) only arrive as cache events.
		CacheEntryInsertedEvent::class,
		CacheEntryUpdatedEvent::class,
		CacheEntryRemovedEvent::class,
	];

	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerSearchProvider(PhotoSearchProvider::class);
		foreach (self::FILE_EVENTS as $event) {
			$context->registerEventListener($event, FileEventListener::class);
		}
	}

	public function boot(IBootContext $context): void {
	}
}
