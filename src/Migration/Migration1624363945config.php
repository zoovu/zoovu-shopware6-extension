<?php declare(strict_types=1);
namespace semknox\search\Migration;
/**
 * restart destructive update: sudo -uwww-data ./bin/console database:migrate-destructive semknoxSearch 1621238871
 */
use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;
class Migration1624363945config extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1624363945;
    }
    public function update(Connection $connection): void
    {
        $query = "
set @var=if((SELECT true FROM information_schema.TABLE_CONSTRAINTS WHERE
            CONSTRAINT_SCHEMA = DATABASE() AND
            TABLE_NAME        = 'semknox_config' AND
            CONSTRAINT_NAME   = 'fk.semknox_config.sales_channel_id' AND
            CONSTRAINT_TYPE   = 'FOREIGN KEY') = true,'ALTER TABLE `semknox_config`
            drop foreign key `fk.semknox_config.sales_channel_id`','select 1');
prepare stmt from @var;
execute stmt;
deallocate prepare stmt;
";
        if (method_exists($connection, 'executeStatement')) {
            $connection->executeStatement($query);
        } else {
            $connection->exec($query);
        }
        $query = "
set @var=if((SELECT true FROM information_schema.TABLE_CONSTRAINTS WHERE
            CONSTRAINT_SCHEMA = DATABASE() AND
            TABLE_NAME        = 'semknox_config' AND
            CONSTRAINT_NAME   = 'fk.semknox_config.sales_channel_domain' AND
            CONSTRAINT_TYPE   = 'FOREIGN KEY') = true,'ALTER TABLE `semknox_config`
            drop foreign key `fk.semknox_config.sales_channel_domain`','select 1');
prepare stmt from @var;
execute stmt;
deallocate prepare stmt;
";
        if (method_exists($connection, 'executeStatement')) {
            $connection->executeStatement($query);
        } else {
            $connection->exec($query);
        }
        $query = "
set @var=if((SELECT true FROM information_schema.TABLE_CONSTRAINTS WHERE
            CONSTRAINT_SCHEMA = DATABASE() AND
            TABLE_NAME        = 'semknox_config' AND
            CONSTRAINT_NAME   = 'fk.semknox_config.language_id' AND
            CONSTRAINT_TYPE   = 'FOREIGN KEY') = true,'ALTER TABLE `semknox_config`
            drop foreign key `fk.semknox_config.language_id`','select 1');
prepare stmt from @var;
execute stmt;
deallocate prepare stmt;
";
        if (method_exists($connection, 'executeStatement')) {
            $connection->executeStatement($query);
        } else {
            $connection->exec($query);
        }
            /*
					ALTER TABLE `semknox_config`
	DROP FOREIGN KEY `fk.semknox_config.sales_channel_id`,
	DROP FOREIGN KEY `fk.semknox_config.sales_channel_domain`,
	DROP FOREIGN KEY `fk.semknox_config.language_id`;
           ";
           */
    }
    public function updateDestructive(Connection $connection): void
    {
        $query = "
					CREATE TABLE `semknox_config` (
						`id` BINARY(16) NOT NULL,
						`configuration_key` VARCHAR(255) NOT NULL COLLATE 'utf8mb4_unicode_ci',
						`configuration_value` LONGTEXT NOT NULL COLLATE 'utf8mb4_bin',
						`sales_channel_id` BINARY(16) NOT NULL,
						`domain_id` BINARY(16) NOT NULL,
						`language_id` BINARY(16) NOT NULL,
						`created_at` DATETIME(3) NOT NULL,
						`updated_at` DATETIME(3) NULL DEFAULT NULL,
						PRIMARY KEY (`id`) USING BTREE,
						UNIQUE INDEX `uniq.semknox_config.config_key__sc_id__lang_id` (`configuration_key`, `sales_channel_id`, `domain_id`) USING BTREE
					) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
        if (method_exists($connection, 'executeStatement')) {
            $connection->executeStatement("DROP TABLE IF EXISTS `semknox_config`;");
            $connection->executeStatement($query);
        } else {
            $connection->exec("DROP TABLE IF EXISTS `semknox_config`;");
            $connection->exec($query);
        }
    }
}
