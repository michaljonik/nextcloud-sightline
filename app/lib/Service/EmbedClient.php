<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Michal Jonik
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Sightline\Service;

use OCA\Sightline\AppInfo\Application;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;

/**
 * HTTP client for sightline-embed. The service only ever sees file IDs, storage IDs and
 * preview images; it holds no Nextcloud credential.
 */
class EmbedClient {
	private const TIMEOUT = 30;
	private const SEARCH_TIMEOUT = 5;
	private const HEALTH_TIMEOUT = 3;

	private IClient $client;

	public function __construct(
		IClientService $clientService,
		private IAppConfig $appConfig,
	) {
		$this->client = $clientService->newClient();
	}

	/** @return array{fileid: int, model: string, encode_ms: float} */
	public function index(int $fileId, int $storageId, string $image, string $mimeType): array {
		return $this->request('PUT', '/files/' . $fileId, [
			'query' => ['storage_id' => $storageId],
			'body' => $image,
			'headers' => ['Content-Type' => $mimeType],
		]);
	}

	public function move(int $fileId, int $storageId): void {
		$this->request('PATCH', '/files/' . $fileId, ['json' => ['storage_id' => $storageId]]);
	}

	public function delete(int $fileId): void {
		$this->request('DELETE', '/files/' . $fileId, []);
	}

	/** Drop every vector of every model. Only for removing Sightline. @return int vectors dropped */
	public function deleteAll(): int {
		return $this->request('DELETE', '/files', [])['deleted'];
	}

	/** Drop an earlier model's vectors after a model change. @return int vectors dropped */
	public function deleteModel(string $model): int {
		return $this->request('DELETE', '/models/' . rawurlencode($model), [])['deleted'];
	}

	/**
	 * @param list<int> $storageIds
	 * @param ?string $model rank this model's vectors; null: the service's current model
	 * @return array{model: string, results: list<array{fileid: int, score: float}>, exhausted: bool, timing_ms: array}
	 */
	public function search(string $query, array $storageIds, int $offset, int $limit, ?string $model = null): array {
		$body = ['query' => $query, 'storage_ids' => $storageIds, 'offset' => $offset, 'limit' => $limit];
		if ($model !== null) {
			$body['model'] = $model;
		}
		return $this->request('POST', '/search', ['json' => $body, 'timeout' => self::SEARCH_TIMEOUT]);
	}

	/**
	 * models: every model the service holds vectors for; searchable when it can still encode
	 * queries for it.
	 *
	 * @return array{model: string, dims: int, count: int, models: list<array{model: string, dims: int, count: int, searchable: bool}>}
	 */
	public function health(): array {
		return $this->request('GET', '/health', ['timeout' => self::HEALTH_TIMEOUT]);
	}

	/**
	 * The model the service reported last; empty until it has been reached once. Rows
	 * indexed with another model are outdated.
	 */
	public function model(): string {
		return $this->appConfig->getValueString(Application::APP_ID, 'service_model');
	}

	/** @return bool whether the model changed; the caller then schedules a reconciliation */
	public function rememberModel(string $model): bool {
		if ($model === $this->model()) {
			return false;
		}
		if ($this->searchModel() === '') {
			// The first model, or an install from before search_model: search what it indexes.
			$this->setSearchModel($this->model() === '' ? $model : $this->model());
		}
		$this->appConfig->setValueString(Application::APP_ID, 'service_model', $model);
		return true;
	}

	/**
	 * The model whose vectors answer queries. After a model change it stays the previous
	 * model until the current one has every photo (ModelSwitch); empty until the service
	 * has been reached once.
	 */
	public function searchModel(): string {
		return $this->appConfig->getValueString(Application::APP_ID, 'search_model');
	}

	public function setSearchModel(string $model): void {
		$this->appConfig->setValueString(Application::APP_ID, 'search_model', $model);
	}

	private function request(string $method, string $path, array $options): array {
		try {
			$response = $this->client->request($method, $this->url($path), $this->options($options));
		} catch (\Exception $e) {
			// Guzzle's request exceptions carry the HTTP status as their code, 0 if unreachable.
			$code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 0;
			throw new EmbedException("sightline-embed $method $path failed: " . $e->getMessage(), $code, $e);
		}
		return json_decode((string)$response->getBody(), true, flags: JSON_THROW_ON_ERROR);
	}

	private function url(string $path): string {
		return rtrim($this->appConfig->getValueString(Application::APP_ID, 'embed_url', 'http://localhost:8765'), '/') . $path;
	}

	private function options(array $options): array {
		$options['headers'] = ($options['headers'] ?? []) + [
			'Authorization' => 'Bearer ' . $this->appConfig->getValueString(Application::APP_ID, 'embed_token', lazy: true),
		];
		return $options + [
			'timeout' => self::TIMEOUT,
			// The service runs on the internal network by design.
			'nextcloud' => ['allow_local_address' => true],
		];
	}
}
