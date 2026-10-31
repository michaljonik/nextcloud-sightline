<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Michal Jonik
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Sightline\Service;

use OCA\Sightline\AppInfo\Application;
use OCA\Sightline\BackgroundJob\CrawlJob;
use OCA\Sightline\BackgroundJob\SweepJob;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;

/**
 * Removes everything Sightline stored, before the app is removed. Nextcloud runs an app's
 * uninstall steps on every disable, so this is a command instead: disabling the app keeps
 * the index, and enabling it again does not re-encode the library.
 */
class Purger {
	/** App config keys Sightline writes. Nextcloud's own keys (enabled, installed_version) stay. */
	public const CONFIG_KEYS = ['embed_url', 'embed_token', 'service_model', 'search_model', 'last_reconcile', 'debug_events'];

	public function __construct(
		private EmbedClient $embed,
		private QueueService $queue,
		private FileRepository $files,
		private IJobList $jobList,
		private IAppConfig $appConfig,
	) {
	}

	/**
	 * The service goes first: if it is unreachable, nothing in Nextcloud is touched and the
	 * command can be rerun. Throws EmbedException in that case.
	 *
	 * @return array{vectors: ?int, files: int, queued: int} vectors is null when the service was skipped
	 */
	public function purge(bool $includeService): array {
		$vectors = $includeService ? $this->embed->deleteAll() : null;
		$this->jobList->remove(CrawlJob::class);
		$this->jobList->remove(SweepJob::class);
		$result = ['vectors' => $vectors, 'files' => $this->files->clear(), 'queued' => $this->queue->clear()];
		foreach (self::CONFIG_KEYS as $key) {
			$this->appConfig->deleteKey(Application::APP_ID, $key);
		}
		return $result;
	}
}
