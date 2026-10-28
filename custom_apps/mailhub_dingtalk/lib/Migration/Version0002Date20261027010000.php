<?php
declare(strict_types=1);
namespace OCA\MailhubDingtalk\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use OCP\DB\Types;

final class Version0002Date20261027010000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();
        if (!$schema->hasTable('mailhub_dt_ncursors')) {
            $t = $schema->createTable('mailhub_dt_ncursors');
            $t->addColumn('mailbox_id', Types::BIGINT, ['notnull' => true]);
            $t->addColumn('account_id', Types::BIGINT, ['notnull' => true]);
            $t->addColumn('nc_uid', Types::STRING, ['length' => 64, 'notnull' => true]);
            $t->addColumn('corp_id', Types::STRING, ['length' => 128, 'notnull' => true]);
            $t->addColumn('ding_user_id', Types::STRING, ['length' => 128, 'notnull' => true]);
            $t->addColumn('uid_validity', Types::STRING, ['length' => 32, 'notnull' => true]);
            $t->addColumn('high_uid', Types::BIGINT, ['notnull' => true]);
            $t->addColumn('epoch', Types::STRING, ['length' => 64, 'notnull' => true]);
            $t->addColumn('active', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
            $t->addColumn('checked_at', Types::BIGINT, ['notnull' => true]);
            $t->setPrimaryKey(['mailbox_id']);
            $t->addIndex(['nc_uid'], 'mhdt_nc_user');
        }
        if (!$schema->hasTable('mailhub_dt_nitems')) {
            $t = $schema->createTable('mailhub_dt_nitems');
            $t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
            $t->addColumn('event_key', Types::STRING, ['length' => 64, 'notnull' => true]);
            $t->addColumn('nc_uid', Types::STRING, ['length' => 64, 'notnull' => true]);
            $t->addColumn('corp_id', Types::STRING, ['length' => 128, 'notnull' => true]);
            $t->addColumn('ding_user_id', Types::STRING, ['length' => 128, 'notnull' => true]);
            $t->addColumn('mailbox_id', Types::BIGINT, ['notnull' => true]);
            $t->addColumn('imap_uid', Types::BIGINT, ['notnull' => true]);
            $t->addColumn('created_at', Types::BIGINT, ['notnull' => true]);
            $t->addColumn('batch_key', Types::STRING, ['length' => 32, 'notnull' => false]);
            $t->setPrimaryKey(['id']);
            $t->addUniqueIndex(['event_key'], 'mhdt_ni_event');
            $t->addIndex(['nc_uid', 'batch_key'], 'mhdt_ni_pending');
            $t->addIndex(['batch_key'], 'mhdt_ni_batch');
        }
        if (!$schema->hasTable('mailhub_dt_nbatches')) {
            $t = $schema->createTable('mailhub_dt_nbatches');
            $t->addColumn('batch_key', Types::STRING, ['length' => 32, 'notnull' => true]);
            $t->addColumn('nc_uid', Types::STRING, ['length' => 64, 'notnull' => true]);
            $t->addColumn('corp_id', Types::STRING, ['length' => 128, 'notnull' => true]);
            $t->addColumn('ding_user_id', Types::STRING, ['length' => 128, 'notnull' => true]);
            $t->addColumn('epoch', Types::STRING, ['length' => 64, 'notnull' => true]);
            $t->addColumn('message_count', Types::INTEGER, ['notnull' => true]);
            $t->addColumn('state', Types::STRING, ['length' => 24, 'notnull' => true]);
            $t->addColumn('attempts', Types::INTEGER, ['notnull' => true, 'default' => 0]);
            $t->addColumn('poll_attempts', Types::INTEGER, ['notnull' => true, 'default' => 0]);
            $t->addColumn('created_at', Types::BIGINT, ['notnull' => true]);
            $t->addColumn('updated_at', Types::BIGINT, ['notnull' => true]);
            $t->addColumn('next_attempt', Types::BIGINT, ['notnull' => true]);
            $t->addColumn('task_id', Types::STRING, ['length' => 64, 'notnull' => false]);
            $t->addColumn('error_code', Types::STRING, ['length' => 96, 'notnull' => false]);
            $t->setPrimaryKey(['batch_key']);
            $t->addIndex(['state', 'next_attempt'], 'mhdt_nb_due');
            $t->addIndex(['nc_uid', 'updated_at'], 'mhdt_nb_user');
        }
        return $schema;
    }
}
