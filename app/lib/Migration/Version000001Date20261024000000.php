<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Michal Jonik
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Sightline\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version000001Date20261024000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		// Work to do: one row per file, the latest event wins.
		if (!$schema->hasTable('sightline_queue')) {
			$table = $schema->createTable('sightline_queue');
			$table->addColumn('file_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('storage_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('action', Types::STRING, ['notnull' => true, 'length' => 8]);
			$table->addColumn('queued_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->setPrimaryKey(['file_id']);
			$table->addIndex(['queued_at'], 'sightline_queue_at');
		}

		// What the embedding service holds, so reconciliation is a local comparison.
		if (!$schema->hasTable('sightline_files')) {
			$table = $schema->createTable('sightline_files');
			$table->addColumn('file_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('storage_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('etag', Types::STRING, ['notnull' => true, 'length' => 40]);
			$table->addColumn('model', Types::STRING, ['notnull' => true, 'length' => 128]);
			$table->addColumn('status', Types::SMALLINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('error', Types::STRING, ['notnull' => false, 'length' => 255]);
			$table->addColumn('indexed_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->setPrimaryKey(['file_id']);
			$table->addIndex(['storage_id'], 'sightline_files_storage');
		}

		return $schema;
	}
}
