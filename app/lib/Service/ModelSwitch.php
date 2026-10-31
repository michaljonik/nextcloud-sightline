<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Michal Jonik
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Sightline\Service;

use Psr\Log\LoggerInterface;

/**
 * Switches search to a new model in one step.
 *
 * After the service changes models, queries keep going to the previous model's vectors
 * while every photo is encoded again. Once no bookkeeping row is left with the previous
 * model, search moves to the new one and the service drops the other models' vectors
 * (embeddings are personal data). If the service can no longer encode queries for the
 * previous model (its export is gone), search moves at once and covers what the new model
 * has so far.
 */
class ModelSwitch {
	public function __construct(
		private EmbedClient $embed,
		private FileRepository $files,
		private Reconciler $reconciler,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @param array|null $health a /health response the caller already has
	 * @return string the model search uses after this check
	 */
	public function check(?array $health = null): string {
		$health ??= $this->embed->health();
		$current = $health['model'];
		if ($this->embed->rememberModel($current)) {
			// Queue everything indexed with the previous model.
			$this->reconciler->schedule();
		}
		$search = $this->embed->searchModel() ?: $current;
		if ($search !== $current) {
			$searchable = in_array($search, array_column(
				array_filter($health['models'] ?? [], fn (array $m) => $m['searchable']), 'model'), true);
			$outdated = $this->files->counts($current)['outdated'];
			if ($outdated === 0 || !$searchable) {
				$this->logger->info("Sightline search switches from $search to $current"
					. ($searchable ? '' : " ($search can no longer be searched; $outdated photos still to encode)"),
					['app' => 'sightline']);
				$search = $current;
			}
		}
		if ($search !== $this->embed->searchModel()) {
			$this->embed->setSearchModel($search);
		}
		if ($search === $current) {
			foreach ($health['models'] ?? [] as $model) {
				if ($model['model'] !== $current) {
					$dropped = $this->embed->deleteModel($model['model']);
					$this->logger->info("Sightline dropped $dropped vectors of {$model['model']}", ['app' => 'sightline']);
				}
			}
		}
		return $search;
	}
}
