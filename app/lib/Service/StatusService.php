<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Michal Jonik
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Sightline\Service;

use OCA\Sightline\AppInfo\Application;
use OCP\IAppConfig;

/**
 * Indexing progress and service health, for the admin page and `occ sightline:status`.
 */
class StatusService {
	public function __construct(
		private EmbedClient $embed,
		private FileRepository $files,
		private QueueService $queue,
		private Reconciler $reconciler,
		private IAppConfig $appConfig,
	) {
	}

	/**
	 * @return array{
	 *     service: array{ok: bool, model?: string, dims?: int, count?: int, error?: string},
	 *     files: array{indexed: int, failed: int, outdated: int},
	 *     queue: int,
	 *     pending: array{crawls: int, sweeps: int},
	 *     last_reconcile: int,
	 * }
	 */
	public function status(): array {
		try {
			$service = ['ok' => true] + $this->embed->health();
			if ($this->embed->rememberModel($service['model'])) {
				$this->reconciler->schedule();
			}
		} catch (EmbedException $e) {
			$service = ['ok' => false, 'error' => $e->getMessage()];
		}
		return [
			'service' => $service,
			'files' => $this->files->counts($this->embed->model()),
			'queue' => $this->queue->size(),
			'pending' => $this->reconciler->pending(),
			'last_reconcile' => $this->appConfig->getValueInt(Application::APP_ID, 'last_reconcile'),
		];
	}
}
