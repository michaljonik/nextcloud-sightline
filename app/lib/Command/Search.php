<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Michal Jonik
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Sightline\Command;

use OCA\Sightline\Service\SearchService;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Runs a search as a given user, with the same permission filtering as the search bar.
 */
class Search extends Command {
	public function __construct(
		private SearchService $search,
		private IUserManager $userManager,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('sightline:search')
			->setDescription('Search photos as a user')
			->addArgument('user', InputArgument::REQUIRED)
			->addArgument('query', InputArgument::REQUIRED)
			->addOption('limit', 'l', InputOption::VALUE_REQUIRED, '', '10')
			->addOption('cursor', 'c', InputOption::VALUE_REQUIRED, '', '0');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$user = $this->userManager->get($input->getArgument('user'));
		if ($user === null) {
			$output->writeln('<error>no such user</error>');
			return 1;
		}
		$t0 = microtime(true);
		$page = $this->search->search($user, $input->getArgument('query'), (int)$input->getOption('cursor'), (int)$input->getOption('limit'));
		$ms = (microtime(true) - $t0) * 1e3;
		foreach ($page['hits'] as $hit) {
			$output->writeln(sprintf('%.3f  %d  %s', $hit['score'], $hit['node']->getId(), $hit['node']->getPath()));
		}
		$output->writeln(sprintf('%d hits, next cursor %s, %d round(s), %.0f ms total, service %s',
			count($page['hits']), $page['cursor'] ?? 'none', $page['rounds'], $ms, json_encode($page['timing_ms'])));
		return 0;
	}
}
