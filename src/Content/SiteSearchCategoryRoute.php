<?php declare(strict_types=1);
namespace semknox\search\Content;
use OpenApi\Annotations as OA;
use semknox\search\Framework\SemknoxsearchHelper;
use Shopware\Core\Content\Category\CategoryDefinition;
use Shopware\Core\Content\Category\CategoryEntity;
use Shopware\Core\Content\Category\Exception\CategoryNotFoundException;
use Shopware\Core\Content\Category\SalesChannel\AbstractCategoryRoute;
use Shopware\Core\Content\Category\SalesChannel\CategoryRouteResponse;
use Shopware\Core\Content\Cms\DataResolver\ResolverContext\EntityResolverContext;
use Shopware\Core\Content\Cms\Exception\PageNotFoundException;
use Shopware\Core\Content\Cms\SalesChannel\SalesChannelCmsPageLoaderInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\Plugin\Exception\DecorationPatternException;
use Shopware\Core\Framework\Routing\Annotation\RouteScope;
use Shopware\Core\Framework\Routing\Annotation\Since;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepositoryInterface;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Shopware\Core\Framework\Struct\ArrayEntity;
use Shopware\Core\Content\Category\SalesChannel\CategoryRoute;
/**
 * @RouteScope(scopes={"store-api"})
 */
class SiteSearchCategoryRoute extends AbstractCategoryRoute
{
    public const HOME = 'home';
    /**
     * @var SalesChannelRepositoryInterface
     */
    private $categoryRepository;
    /**
     * @var SalesChannelCmsPageLoaderInterface
     */
    private $cmsPageLoader;
    /**
     * @var CategoryDefinition
     */
    private $categoryDefinition;
    /**
     * @var SemknoxsearchHelper
     */
    private $helper;
    /**
     *
     * @var CategoryRoute
     */
    private $decorated;
    public function __construct(
        SalesChannelRepositoryInterface $categoryRepository,
        SalesChannelCmsPageLoaderInterface $cmsPageLoader,
        CategoryDefinition $categoryDefinition,
        SemknoxsearchHelper $helper,
        CategoryRoute $deco
    ) {
        $this->categoryRepository = $categoryRepository;
        $this->cmsPageLoader = $cmsPageLoader;
        $this->categoryDefinition = $categoryDefinition;
        $this->helper = $helper;        
        $this->decorated = $deco;
    }
    public function getDecorated(): AbstractCategoryRoute
    {
        throw new DecorationPatternException(self::class);
    }
    /**
     * @Since("6.2.0.0")
     * @OA\Post(
     *     path="/category/{categoryId}",
     *     summary="Fetch a single category",
     *     description="This endpoint returns information about the category, as well as a fully resolved (hydrated with mapping values) CMS page, if one is assigned to the category. You can pass slots which should be resolved exclusively.",
     *     operationId="readCategory",
     *     tags={"Store API", "Category"},
     *     @OA\RequestBody(
     *         @OA\JsonContent(
     *             description="The product listing criteria only has an effect, if the category contains a product listing.",
     *             ref="#/components/schemas/ProductListingCriteria"
     *         )
     *     ),
     *     @OA\Parameter(
     *         name="categoryId",
     *         description="Identifier of the category to be fetched",
     *         @OA\Schema(type="string", pattern="^[0-9a-f]{32}$"),
     *         in="path",
     *         required=true
     *     ),
     *     @OA\Parameter(
     *         name="slots",
     *         description="Resolves only the given slot identifiers. The identifiers have to be seperated by a '|' character",
     *         @OA\Schema(type="string"),
     *         in="query",
     *     ),
     *     @OA\Parameter(name="Api-Basic-Parameters"),
     *     @OA\Response(
     *          response="200",
     *          description="The loaded category with cms page",
     *          @OA\JsonContent(ref="#/components/schemas/Category")
     *     )
     * )
     *
     * @Route("/store-api/category/{navigationId}", name="store-api.category.detail", methods={"GET","POST"})
     */
    public function load(string $navigationId, Request $request, SalesChannelContext $context): CategoryRouteResponse
    {
        if ($navigationId === self::HOME) {
            $navigationId = $context->getSalesChannel()->getNavigationCategoryId();
            $request->attributes->set('navigationId', $navigationId);
            $routeParams = $request->attributes->get('_route_params', []);
            $routeParams['navigationId'] = $navigationId;
            $request->attributes->set('_route_params', $routeParams);
        }
        $category = $this->loadCategory($navigationId, $context);
        if (($category->getType() === CategoryDefinition::TYPE_FOLDER
                || $category->getType() === CategoryDefinition::TYPE_LINK)
            && $context->getSalesChannel()->getNavigationCategoryId() !== $navigationId
        ) {
            throw new CategoryNotFoundException($navigationId);
        }
        $pageId = $category->getCmsPageId();
        $slotConfig = $category->getTranslation('slotConfig');
        $salesChannel = $context->getSalesChannel();
        if ($category->getId() === $salesChannel->getNavigationCategoryId() && $salesChannel->getHomeCmsPageId()) {
            $pageId = $salesChannel->getHomeCmsPageId();
            $slotConfig = $salesChannel->getTranslation('homeSlotConfig');
        }
        if (!$pageId) {
            return new CategoryRouteResponse($category);
        }
        $resolverContext = new EntityResolverContext($context, $request, $this->categoryDefinition, $category);
        $foundBreadcrumb = 0;
        $newCriteria = $this->createCriteria($pageId, $request, $category, $context, $foundBreadcrumb);
        if ($foundBreadcrumb<=0) { 
            return $this->decorated->load($navigationId, $request, $context);
        }
        $pages = $this->cmsPageLoader->load(
            $request,
            $newCriteria,
            $context,
            $slotConfig,
            $resolverContext
        );
        if (!$pages->has($pageId)) {
            throw new PageNotFoundException($pageId);
        }
        $category->setCmsPage($pages->get($pageId));
        $category->setCmsPageId($pageId);
        return new CategoryRouteResponse($category);
    }
    private function loadCategory(string $categoryId, SalesChannelContext $context): CategoryEntity
    {
        $criteria = new Criteria([$categoryId]);
        $criteria->setTitle('category::data');
        $criteria->addAssociation('media');
        $category = $this->categoryRepository
            ->search($criteria, $context)
            ->get($categoryId);
        if (!$category) {
            throw new CategoryNotFoundException($categoryId);
        }
        return $category;
    }
    private function createCriteria(string $pageId, Request $request, CategoryEntity $category, $context, int &$foundBreadcrumb): Criteria
    {
        $foundBreadcrumb = 0;
        $criteria = new Criteria([$pageId]);
        $criteria->setTitle('category::cms-page');
        $slots = $request->get('slots');
        if (\is_string($slots)) {
            $slots = explode('|', $slots);
        }
        if (!empty($slots) && \is_array($slots)) {
            $criteria
                 ->getAssociation('sections.blocks')
                ->addFilter(new EqualsAnyFilter('slots.id', $slots));
        }
        $bc = $category->getBreadcrumb();
        if (count($bc)) {
            $foundBreadcrumb = 1;
            $term = "_#".implode('#',str_replace('#', '\#', $bc));
            $scID=$this->helper->getSalesChannelFromSCContext($context);
            $langID = $this->helper->getLanguageFromSCContext($context);
            $domainID = $this->helper->getDomainFromSCContext($context);
            $contr = 'siteSearchCMSController';
            $criteria->addExtension('semknoxData', new ArrayEntity(
                [
                    'salesChannelID' => $scID,
                    'languageID' => $langID,
                    'domainID' => $domainID,
                    'controller' => $contr,
                    'term' => $term
                ]
                ));
        }
        return $criteria;
    }
}
