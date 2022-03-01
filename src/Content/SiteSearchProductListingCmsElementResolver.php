<?php declare(strict_types=1);
namespace semknox\search\Content;
use Shopware\Core\Content\Cms\Aggregate\CmsSlot\CmsSlotEntity;
use Shopware\Core\Content\Cms\DataResolver\CriteriaCollection;
use Shopware\Core\Content\Cms\DataResolver\Element\AbstractCmsElementResolver;
use Shopware\Core\Content\Cms\DataResolver\Element\ElementDataCollection;
use Shopware\Core\Content\Cms\DataResolver\ResolverContext\ResolverContext;
use Shopware\Core\Content\Cms\SalesChannel\Struct\ProductListingStruct;
use Shopware\Core\Content\Product\SalesChannel\Listing\AbstractProductListingRoute;
use Shopware\Core\Content\Product\SalesChannel\Listing\ProductListingFeaturesSubscriber;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;
use semknox\search\Framework\SemknoxsearchHelper;
class SiteSearchProductListingCmsElementResolver extends AbstractCmsElementResolver
{
    private AbstractProductListingRoute $listingRoute;
    /**
     *
     * @var SemknoxsearchHelper
     */
    private $semknoxSearchHelper;
    /**
     * 
     * @var Criteria
     */
    private $impCriteria;
    public function __construct(AbstractProductListingRoute $listingRoute, SemknoxsearchHelper $helper)
    {
        $this->listingRoute = $listingRoute;
        $this->semknoxSearchHelper = $helper;       
    }
    public function getType(): string
    {
        return 'product-listing';
    }
    public function collect(CmsSlotEntity $slot, ResolverContext $resolverContext): ?CriteriaCollection
    {
        return null;
    }
    public function setCriteria(Criteria $impCriteria) {
        $this->impCriteria = $impCriteria;
    }
    public function enrich(CmsSlotEntity $slot, ResolverContext $resolverContext, ElementDataCollection $result): void
    {
        $data = new ProductListingStruct();
        $slot->setData($data);
        $request = $resolverContext->getRequest();
        $context = $resolverContext->getSalesChannelContext();
        $criteria = $this->impCriteria;
        $criteria->setTitle('cms::product-listing');
        $navigationId = $this->getNavigationId($request, $context);
        $this->restrictFilters($slot, $request);
        if ($this->isCustomSorting($slot)) {
            $this->restrictSortings($request, $slot);
            $this->addDefaultSorting($request, $slot);
        }
        $listing = $this->listingRoute
            ->load($navigationId, $request, $context, $criteria)
            ->getResult();
        $data->setListing($listing);
    }
    private function getNavigationId(Request $request, SalesChannelContext $salesChannelContext): string
    {
        if ($navigationId = $request->get('navigationId')) {
            return $navigationId;
        }
        $params = $request->attributes->get('_route_params');
        if ($params && isset($params['navigationId'])) {
            return $params['navigationId'];
        }
        return $salesChannelContext->getSalesChannel()->getNavigationCategoryId();
    }
    private function isCustomSorting(CmsSlotEntity $slot): bool
    {
        $config = $slot->getTranslation('config');
        if ($config && isset($config['useCustomSorting']) && isset($config['useCustomSorting']['value'])) {
            return $config['useCustomSorting']['value'];
        }
        return false;
    }
    private function addDefaultSorting(Request $request, CmsSlotEntity $slot): void
    {
        if ($request->get('order')) {
            return;
        }
        $config = $slot->getTranslation('config');
        if ($config && isset($config['defaultSorting']) && isset($config['defaultSorting']['value']) && $config['defaultSorting']['value']) {
            $request->request->set('order', $config['defaultSorting']['value']);
            return;
        }
        if ($request->get('availableSortings')) {
            $availableSortings = $request->get('availableSortings');
            arsort($availableSortings, \SORT_DESC | \SORT_NUMERIC);
            $request->request->set('order', array_key_first($availableSortings));
        }
    }
    private function restrictSortings(Request $request, CmsSlotEntity $slot): void
    {
        $config = $slot->getTranslation('config');
        if (!$config || !isset($config['availableSortings']) || !isset($config['availableSortings']['value'])) {
            return;
        }
        $request->request->set('availableSortings', $config['availableSortings']['value']);
    }
    private function restrictFilters(CmsSlotEntity $slot, Request $request): void
    {
        $defaults = ['manufacturer-filter', 'rating-filter', 'shipping-free-filter', 'price-filter', 'property-filter'];
        $request->request->set(ProductListingFeaturesSubscriber::PROPERTY_GROUP_IDS_REQUEST_PARAM, null);
        $config = $slot->get('config');
        if (isset($config['propertyWhitelist']['value']) && \count($config['propertyWhitelist']['value']) > 0) {
            $request->request->set(ProductListingFeaturesSubscriber::PROPERTY_GROUP_IDS_REQUEST_PARAM, $config['propertyWhitelist']['value']);
        }
        if (!isset($config['filters']['value'])) {
            return;
        }
        $config = explode(',', $config['filters']['value']);
        foreach ($defaults as $filter) {
            if (\in_array($filter, $config, true)) {
                continue;
            }
            $request->request->set($filter, false);
        }
    }
}
