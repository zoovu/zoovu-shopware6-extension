<?php declare(strict_types=1);
namespace semknox\search\Migration;
use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;
class Migration1584591949logs extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1584591949;
    }
    public function update(Connection $connection): void
    {
        $connection->executeUpdate('
            DROP TABLE IF EXISTS `semknox_logs`;
        ');
        $connection->executeUpdate('
        CREATE TABLE `semknox_logs` (
            `id` BINARY(16) NOT NULL,
            `logtype` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
            `status` int(11) NOT NULL,
            `logtitle` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
            `logdescr` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
            `created_at` DATETIME(3) NOT NULL,
            `updated_at` DATETIME(3) NULL,
            PRIMARY KEY (`id`),
	        INDEX `key_type` (`logtype`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ');
    }
    public function updateDestructive(Connection $connection): void
    {
    }
}
