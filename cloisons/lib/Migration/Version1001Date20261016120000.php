<?php

declare(strict_types=1);

namespace OCA\Cloisons\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Correspondances fantôme → valeur saisie, pour les restaurer durablement. */
class Version1001Date20261016120000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('cloisons_ghosts')) {
			$t = $schema->createTable('cloisons_ghosts');
			$t->addColumn('ghost', Types::STRING, ['notnull' => true, 'length' => 128]);
			$t->addColumn('original', Types::STRING, ['notnull' => true, 'length' => 255]);
			$t->addColumn('zone', Types::STRING, ['notnull' => true, 'length' => 128]);
			$t->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true, 'default' => 0]);
			$t->setPrimaryKey(['ghost']);
			$t->addIndex(['zone'], 'cloisons_ghosts_zone');
		}
		return $schema;
	}
}
