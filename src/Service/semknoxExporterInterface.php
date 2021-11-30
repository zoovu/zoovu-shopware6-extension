<?php declare(strict_types=1);
namespace semknox\search\Service;
use semknox\search\Exception\AlreadyLockedException;
use semknox\search\Struct\semknoxGenerationResult;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
interface semknoxExporterInterface
{
    /**
     * @throws AlreadyLockedException
     */
    public function generate(SalesChannelContext $salesChannelContext, bool $force = false, ?string $lastProvider = null, ?int $offset = null, ?int $batchSize = 500): semknoxGenerationResult;
    public function resetUpload(string $scID, string $langID, string $domainID) : int;
}
