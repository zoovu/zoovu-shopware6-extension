<?php
declare(strict_types=1);
namespace semknox\search\Request\Handler;
use Shopware\Core\Content\Product\Events\ProductListingCriteriaEvent;
use Shopware\Core\Framework\Event\ShopwareEvent;
use Symfony\Component\HttpFoundation\Request;
class FilterHandler
{
    /**
     */
    public function handleSiteSearchParams(Request $request): ?string
    {
        $queryParams = $request->query->all();
        $internalParams = [];
        return null;
    }
}
