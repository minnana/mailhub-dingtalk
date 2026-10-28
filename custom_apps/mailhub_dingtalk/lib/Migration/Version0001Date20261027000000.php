<?php
declare(strict_types=1);
namespace OCA\MailhubDingtalk\Migration;
use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version0001Date20261027000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();
        if (!$schema->hasTable('mailhub_dt_users')) {
            $t = $schema->createTable('mailhub_dt_users');
            $t->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
            $t->addColumn('corp_id', 'string', ['length' => 128, 'notnull' => true]);
            $t->addColumn('ding_user_id', 'string', ['length' => 128, 'notnull' => true]);
            $t->addColumn('nc_uid', 'string', ['length' => 64, 'notnull' => true]);
            $t->addColumn('created_at', 'bigint', ['notnull' => true]);
            $t->addColumn('last_login', 'bigint', ['notnull' => true, 'default' => 0]);
            $t->setPrimaryKey(['id']);
            $t->addUniqueIndex(['corp_id', 'ding_user_id'], 'mh_dt_identity');
            $t->addUniqueIndex(['nc_uid'], 'mh_dt_ncuid');
        }
        return $schema;
    }
}
