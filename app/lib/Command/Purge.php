<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Michal Jonik
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Sightline\Command;

use OCA\Sightline\Service\EmbedException;
use OCA\Sightline\Service\Purger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * Deletes every vector in sightline-embed and everything Sightline keeps in Nextcloud. Run
 * it before `occ app:remove sightline`; disabling the app alone deletes nothing.
 */
class Purge extends Command {
	public function __construct(
		private Purger $purger,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('sightline:purge')
			->setDescription('Delete all vectors, indexing state and settings, before removing the app')
			->addOption('force', 'f', InputOption::VALUE_NONE, 'do not ask for confirmation')
			->addOption('skip-service', null, InputOption::VALUE_NONE,
				'leave sightline-embed alone, for when it is gone already; delete its data volume yourself');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		if (!$input->getOption('force')) {
			/** @var QuestionHelper $helper */
			$helper = $this->getHelper('question');
			$question = new ConfirmationQuestion('Delete every Sightline vector, the indexing state and the settings? [y/N] ', false);
			if (!$helper->ask($input, $output, $question)) {
				return 1;
			}
		}
		try {
			$result = $this->purger->purge(!$input->getOption('skip-service'));
		} catch (EmbedException $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			$output->writeln('Nothing was deleted. If the service is gone, rerun with --skip-service and delete its data volume.');
			return 1;
		}
		$output->writeln($result['vectors'] === null
			? 'sightline-embed: skipped'
			: sprintf('sightline-embed: %d vectors deleted', $result['vectors']));
		$output->writeln(sprintf('nextcloud: %d indexed files and %d queue entries deleted, settings removed', $result['files'], $result['queued']));
		$output->writeln('Now run: occ app:remove sightline');
		return 0;
	}
}
