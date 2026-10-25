<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Michal Jonik
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Sightline\Listener;

use OCA\Sightline\AppInfo\Application;
use OCA\Sightline\Service\PhotoFilter;
use OCA\Sightline\Service\QueueService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Cache\CacheEntryRemovedEvent;
use OCP\Files\Cache\ICacheEvent;
use OCP\Files\Events\Node\AbstractNodeEvent;
use OCP\Files\Events\Node\AbstractNodesEvent;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\File;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Turns file events into queue entries. Cheap by design: no previews, no HTTP calls.
 * The worker re-reads the file and decides what to do, so a duplicate or stale entry
 * costs one lookup.
 *
 * @template-implements IEventListener<Event>
 */
class FileEventListener implements IEventListener {
	public function __construct(
		private QueueService $queue,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		if ($this->appConfig->getValueBool(Application::APP_ID, 'debug_events')) {
			$this->logEvent($event);
		}

		if ($event instanceof ICacheEvent) {
			if (!PhotoFilter::isIndexablePath($event->getPath()) || !PhotoFilter::hasPhotoExtension($event->getPath())) {
				return;
			}
			$action = $event instanceof CacheEntryRemovedEvent ? QueueService::DELETE : QueueService::INDEX;
			$this->queue->enqueue($event->getFileId(), $event->getStorageId(), $action);
		} elseif ($event instanceof AbstractNodesEvent) {
			// Rename, move, restore: the file ID is kept; the worker updates storage_id.
			$this->enqueueNode($event->getTarget(), QueueService::INDEX);
		} elseif ($event instanceof AbstractNodeEvent) {
			$this->enqueueNode($event->getNode(), $event instanceof NodeDeletedEvent ? QueueService::DELETE : QueueService::INDEX);
		}
	}

	private function enqueueNode(Node $node, string $action): void {
		try {
			if (!$node instanceof File || !str_starts_with($node->getMimetype(), 'image/')) {
				return;
			}
			$this->queue->enqueue($node->getId(), $node->getMountPoint()->getNumericStorageId(), $action);
		} catch (NotFoundException) {
			// A deleted node may no longer resolve; its CacheEntryRemovedEvent covers it.
		}
	}

	private function logEvent(Event $event): void {
		$context = ['event' => (new \ReflectionClass($event))->getShortName()];
		if ($event instanceof ICacheEvent) {
			$context += ['fileid' => $event->getFileId(), 'storage' => $event->getStorageId(), 'path' => $event->getPath()];
		} elseif ($event instanceof AbstractNodesEvent) {
			$context += ['fileid' => $this->safeId($event->getTarget()), 'source' => $event->getSource()->getPath(),
				'target' => $event->getTarget()->getPath()];
		} elseif ($event instanceof AbstractNodeEvent) {
			$context += ['fileid' => $this->safeId($event->getNode()), 'path' => $event->getNode()->getPath()];
		}
		$this->logger->warning('sightline event ' . json_encode($context), ['app' => Application::APP_ID]);
	}

	private function safeId(Node $node): ?int {
		try {
			return $node->getId();
		} catch (\Throwable) {
			return null;
		}
	}
}
