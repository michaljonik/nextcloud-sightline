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

	/**
	 * @param list<int> $storageIds
	 * @return array{model: string, results: list<array{fileid: int, score: float}>, exhausted: bool, timing_ms: array}
	 */
	public function search(string $query, array $storageIds, int $offset, int $limit): array {
		return $this->request('POST', '/search', [
			'json' => ['query' => $query, 'storage_ids' => $storageIds, 'offset' => $offset, 'limit' => $limit],
			'timeout' => self::SEARCH_TIMEOUT,
		]);
	}

	/** @return array{model: string, dims: int, count: int} */
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
		$this->appConfig->setValueString(Application::APP_ID, 'service_model', $model);
		return true;
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
