<?php declare(strict_types=1);
namespace semknox\search\Migration;
/**
 * restart destructive update: sudo -uwww-data ./bin/console database:migrate-destructive semknoxSearch 1621238871
 */
use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;
class Migration1621238871config extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1621238871;
    }
    public function update(Connection $connection): void
    {
        $query = "
					CREATE TABLE IF NOT EXISTS `semknox_config` (
						`id` BINARY(16) NOT NULL,
						`configuration_key` VARCHAR(255) NOT NULL COLLATE 'utf8mb4_unicode_ci',
						`configuration_value` LONGTEXT NOT NULL COLLATE 'utf8mb4_bin',
						`sales_channel_id` BINARY(16) NOT NULL,
						`domain_id` BINARY(16) NOT NULL,
						`language_id` BINARY(16) NOT NULL,
						`created_at` DATETIME(3) NOT NULL,
						`updated_at` DATETIME(3) NULL DEFAULT NULL,
						PRIMARY KEY (`id`) USING BTREE,
						UNIQUE INDEX `uniq.semknox_config.config_key__sc_id__lang_id` (`configuration_key`, `sales_channel_id`, `domain_id`) USING BTREE,
                        CONSTRAINT `fk.semknox_config.sales_channel_id` FOREIGN KEY (`sales_channel_id`) REFERENCES `sales_channel` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
                        CONSTRAINT `fk.semknox_config.sales_channel_domain` FOREIGN KEY (`domain_id`) REFERENCES `sales_channel_domain` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
                        CONSTRAINT `fk.semknox_config.language_id` FOREIGN KEY (`language_id`) REFERENCES `language` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
					) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci; ";
        if (method_exists($connection, 'executeStatement')) {
            $connection->executeStatement($query);            
        } else {
            $connection->exec($query);
        }
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
						UNIQUE INDEX `uniq.semknox_config.config_key__sc_id__lang_id` (`configuration_key`, `sales_channel_id`, `domain_id`) USING BTREE,
                        CONSTRAINT `fk.semknox_config.sales_channel_id` FOREIGN KEY (`sales_channel_id`) REFERENCES `sales_channel` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
                        CONSTRAINT `fk.semknox_config.sales_channel_domain` FOREIGN KEY (`domain_id`) REFERENCES `sales_channel_domain` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
                        CONSTRAINT `fk.semknox_config.language_id` FOREIGN KEY (`language_id`) REFERENCES `language` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
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
