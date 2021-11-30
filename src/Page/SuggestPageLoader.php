<?php declare(strict_types=1);
namespace semknox\search\Page;
use Shopware\Core\Content\Category\Exception\CategoryNotFoundException;
use Shopware\Core\Content\Product\SalesChannel\Suggest\AbstractProductSuggestRoute;
use Shopware\Core\Framework\DataAbstractionLayer\Exception\InconsistentCriteriaIdsException;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Routing\Exception\MissingRequestParameterException;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Page\GenericPageLoaderInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Shopware\Storefront\Page\Suggest\SuggestPageLoader as ShopwareSuggestPageLoader;
use Shopware\Storefront\Page\Suggest\SuggestPage;
use Shopware\Storefront\Page\Suggest\SuggestPageLoadedEvent;
class SuggestPageLoader extends ShopwareSuggestPageLoader
{
    /**
     * @var EventDispatcherInterface
     */
    private $eventDispatcher;
    /**
     * @var AbstractProductSuggestRoute
     */
    private $productSuggestRoute;
    /**
     * @var GenericPageLoaderInterface
     */
    private $genericLoader;
    public function __construct(
        EventDispatcherInterface $eventDispatcher,
        AbstractProductSuggestRoute $productSuggestRoute,
        GenericPageLoaderInterface $genericLoader
    ) {
        $this->eventDispatcher = $eventDispatcher;
        $this->productSuggestRoute = $productSuggestRoute;
        $this->genericLoader = $genericLoader;
    }
    /**
     * @throws CategoryNotFoundException
     * @throws InconsistentCriteriaIdsException
     * @throws MissingRequestParameterException
     */
    public function load(Request $request, SalesChannelContext $salesChannelContext): SuggestPage
    {
        $page = $this->genericLoader->load($request, $salesChannelContext);
        $page = SuggestPage::createFrom($page);
        $criteria = new Criteria();
        $criteria->setLimit(10);
        $criteria->setTotalCountMode(Criteria::TOTAL_COUNT_MODE_EXACT);
        $page->setSearchResult(
            $this->productSuggestRoute
                ->load($request, $salesChannelContext, $criteria)
                ->getListingResult()
        );
        $page->setSearchTerm($request->query->get('search'));
        $this->eventDispatcher->dispatch(
            new SuggestPageLoadedEvent($page, $salesChannelContext, $request)
        );
        return $page;
    }
}
