<?php

declare(strict_types=1);

namespace OCA\Cloisons\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1000Date20261016000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('cloisons_zones')) {
			$t = $schema->createTable('cloisons_zones');
			$t->addColumn('zone_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('display_name', Types::STRING, ['notnull' => true, 'length' => 255]);
			$t->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true, 'default' => 0]);
			$t->setPrimaryKey(['zone_id']);
		}
		if (!$schema->hasTable('cloisons_members')) {
			$t = $schema->createTable('cloisons_members');
			$t->addColumn('uid', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('zone', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true, 'default' => 0]);
			$t->setPrimaryKey(['uid']);
			$t->addIndex(['zone'], 'cloisons_members_zone');
		}
		if (!$schema->hasTable('cloisons_events')) {
			$t = $schema->createTable('cloisons_events');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true, 'default' => 0]);
			$t->addColumn('actor', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('vector', Types::STRING, ['notnull' => true, 'length' => 128]);
			$t->addColumn('target', Types::STRING, ['notnull' => false, 'length' => 255]);
			$t->addColumn('mode', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'audit']);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['created_at'], 'cloisons_events_time');
		}
		return $schema;
	}
}
