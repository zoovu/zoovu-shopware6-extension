<?php declare(strict_types=1);
namespace semknox\search\Product;
use OpenApi\Annotations as OA;
use Shopware\Core\Content\Product\Aggregate\ProductVisibility\ProductVisibilityDefinition;
use Shopware\Core\Content\Product\Events\ProductSuggestCriteriaEvent;
use Shopware\Core\Content\Product\Events\ProductSuggestResultEvent;
use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Content\Product\ProductEvents;
use Shopware\Core\Content\Product\SalesChannel\Listing\ProductListingLoader;
use Shopware\Core\Content\Product\SalesChannel\Listing\ProductListingResult;
use Shopware\Core\Content\Product\SalesChannel\ProductAvailableFilter;
use Shopware\Core\Content\Product\SearchKeyword\ProductSearchBuilderInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\RequestCriteriaBuilder;
use Shopware\Core\Framework\Plugin\Exception\DecorationPatternException;
use Shopware\Core\Framework\Routing\Annotation\Entity;
use Shopware\Core\Framework\Routing\Annotation\RouteScope;
use Shopware\Core\Framework\Routing\Annotation\Since;
use Shopware\Core\Framework\Routing\Exception\MissingRequestParameterException;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Shopware\Core\Content\Product\SalesChannel\Suggest\AbstractProductSuggestRoute;
use Shopware\Core\Content\Product\SalesChannel\Suggest\ProductSuggestRouteResponse;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use semknox\search\Framework\SemknoxsearchHelper;
/**
 * @RouteScope(scopes={"store-api"})
 */
class ProductSuggestRoute extends AbstractProductSuggestRoute
{
    /**
     * @var EventDispatcherInterface
     */
    private $eventDispatcher;
    /**
     * @var ProductSearchBuilderInterface
     */
    private $searchBuilder;
    /**
     * @var SemknoxProductListingLoader
     */
    private $productListingLoader;
    /**
     * @var ProductDefinition
     */
    private $definition;
    /**
     * @var RequestCriteriaBuilder
     */
    private $criteriaBuilder;
    public function __construct(
        ProductSearchBuilderInterface $searchBuilder,
        EventDispatcherInterface $eventDispatcher,
        SemknoxProductListingLoader $productListingLoader,
        ProductDefinition $definition,
        RequestCriteriaBuilder $criteriaBuilder,
        EntityRepositoryInterface $logRepo,
        TranslatorInterface $trans,
        SemknoxsearchHelper $helper
    ) {
        $this->eventDispatcher = $eventDispatcher;
        $this->searchBuilder = $searchBuilder;
        $this->productListingLoader = $productListingLoader;
        $this->definition = $definition;
        $this->criteriaBuilder = $criteriaBuilder;
        $this->logRepository = $logRepo;
        $this->translator = $trans;
        $this->semknoxSearchHelper = $helper;
    }
    public function getDecorated(): AbstractProductSuggestRoute
    {
        throw new DecorationPatternException(self::class);
    }
    /**
     * @Since("6.2.0.0")
     * @Entity("product")
     * @OA\Get(
     *      path="/search-suggest",
     *      summary="Search suggests",
     *      operationId="searchSuggest",
     *      tags={"Store API","Search"},
     *      @OA\Parameter(
     *          name="search",
     *          description="Search term",
     *          in="query",
     *          @OA\Schema(type="string")
     *      ),
     *      @OA\Response(
     *          response="200",
     *          description="Found products",
     *          @OA\JsonContent(ref="#/components/schemas/ProductListingResult")
     *     )
     * )
     * @Route("/store-api/v{version}/search-suggest", name="store-api.search.suggest", methods={"POST"})
     */
    public function load(Request $request, SalesChannelContext $context, ?Criteria $criteria = null): ProductSuggestRouteResponse
    {
        if (!$request->get('search')) {
            throw new MissingRequestParameterException('search');
        }
        if (!$criteria) {
            $criteria = new Criteria();
            $criteria->setLimit(10);
            $criteria->setTotalCountMode(Criteria::TOTAL_COUNT_MODE_EXACT);
            $criteria = $this->criteriaBuilder->handleRequest($request, $criteria, $this->definition, $context->getContext());
        }
        $criteria->addFilter(
            new ProductAvailableFilter($context->getSalesChannel()->getId(), ProductVisibilityDefinition::VISIBILITY_SEARCH)
        );
        $this->searchBuilder->build($request, $criteria, $context);
        $this->eventDispatcher->dispatch(
            new ProductSuggestCriteriaEvent($request, $criteria, $context),
            ProductEvents::PRODUCT_SUGGEST_CRITERIA
        );
        $result = $this->productListingLoader->load($criteria, $context);
        $result = ProductListingResult::createFrom($result);
        $this->eventDispatcher->dispatch(
            new ProductSuggestResultEvent($request, $result, $context),
            ProductEvents::PRODUCT_SUGGEST_RESULT
        );
        return new ProductSuggestRouteResponse($result);
    }
}
