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

	private IClient $client;

	public function __construct(
		IClientService $clientService,
		private IAppConfig $appConfig,
	) {
		$this->client = $clientService->newClient();
	}

	/** @return array{fileid: int, model: string, encode_ms: float} */
	public function index(int $fileId, int $storageId, string $image, string $mimeType): array {
		$response = $this->client->put($this->url('/files/' . $fileId), $this->options([
			'query' => ['storage_id' => $storageId],
			'body' => $image,
			'headers' => ['Content-Type' => $mimeType],
		]));
		return json_decode((string)$response->getBody(), true, flags: JSON_THROW_ON_ERROR);
	}

	public function move(int $fileId, int $storageId): void {
		$this->client->patch($this->url('/files/' . $fileId), $this->options(['json' => ['storage_id' => $storageId]]));
	}

	public function delete(int $fileId): void {
		$this->client->delete($this->url('/files/' . $fileId), $this->options([]));
	}

	/**
	 * @param list<int> $storageIds
	 * @return array{model: string, results: list<array{fileid: int, score: float}>, exhausted: bool, timing_ms: array}
	 */
	public function search(string $query, array $storageIds, int $offset, int $limit): array {
		$response = $this->client->post($this->url('/search'), $this->options([
			'json' => ['query' => $query, 'storage_ids' => $storageIds, 'offset' => $offset, 'limit' => $limit],
			'timeout' => self::SEARCH_TIMEOUT,
		]));
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
