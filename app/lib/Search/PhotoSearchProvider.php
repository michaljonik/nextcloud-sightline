<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Michal Jonik
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Sightline\Search;

use OCA\Sightline\AppInfo\Application;
use OCA\Sightline\Service\SearchService;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Search\IProvider;
use OCP\Search\ISearchQuery;
use OCP\Search\SearchResult;
use OCP\Search\SearchResultEntry;
use Psr\Log\LoggerInterface;

class PhotoSearchProvider implements IProvider {
	/** One or two characters say nothing about a photo; the server's own minimum is 1. */
	private const MIN_QUERY_LENGTH = 3;

	public function __construct(
		private SearchService $search,
		private IURLGenerator $urlGenerator,
		private IL10N $l10n,
		private LoggerInterface $logger,
	) {
	}

	public function getId(): string {
		return Application::APP_ID;
	}

	public function getName(): string {
		return $this->l10n->t('Photos by content');
	}

	public function getOrder(string $route, array $routeParameters): ?int {
		// NC 35 holds lower-priority categories back for up to 1 s; stay near Files.
		return 50;
	}

	public function search(IUser $user, ISearchQuery $query): SearchResult {
		$term = trim($query->getTerm());
		if (mb_strlen($term) < self::MIN_QUERY_LENGTH) {
			return SearchResult::complete($this->getName(), []);
		}
		try {
			$page = $this->search->search($user, $term, (int)($query->getCursor() ?? 0), $query->getLimit());
		} catch (\Throwable $e) {
			// Search must not break the search bar when the service is down.
			$this->logger->warning('Sightline search failed', ['exception' => $e]);
			return SearchResult::complete($this->getName(), []);
		}

		$entries = array_map(function (array $hit) use ($user) {
			$node = $hit['node'];
			$folder = dirname(substr($node->getPath(), strlen('/' . $user->getUID() . '/files')));
			return new SearchResultEntry(
				$this->urlGenerator->linkToRouteAbsolute('core.Preview.getPreviewByFileId',
					['fileId' => $node->getId(), 'x' => 256, 'y' => 256, 'a' => 1]),
				$node->getName(),
				ltrim($folder, '/'),
				$this->urlGenerator->linkToRouteAbsolute('files.View.showFile', ['fileid' => $node->getId()]),
				'icon-picture',
			);
		}, $page['hits']);

		return $page['cursor'] === null
			? SearchResult::complete($this->getName(), $entries)
			: SearchResult::paginated($this->getName(), $entries, $page['cursor']);
	}
}
