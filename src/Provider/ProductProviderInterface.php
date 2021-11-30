<?php declare(strict_types=1);
namespace semknox\search\Provider;
use semknox\search\Struct\ProductResult;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
interface ProductProviderInterface
{
    public function getName(): string;
    public function getProductData(SalesChannelContext $salesChannelContext, int $limit, ?int $offset = null): ProductResult;
}
