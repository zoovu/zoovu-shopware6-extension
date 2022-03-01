<?php declare(strict_types=1);
namespace semknox\search\Content;
use Shopware\Core\Content\Cms\Aggregate\CmsBlock\CmsBlockEntity;
use Shopware\Core\Content\Cms\Aggregate\CmsSection\CmsSectionEntity;
use Shopware\Core\Content\Cms\CmsPageEntity;
use Shopware\Core\Content\Cms\DataResolver\ResolverContext\ResolverContext;
use Shopware\Core\Content\Cms\Events\CmsPageLoadedEvent;
use Shopware\Core\Content\Cms\Events\CmsPageLoaderCriteriaEvent;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Shopware\Core\Content\Cms\SalesChannel\SalesChannelCmsPageLoaderInterface;
use semknox\search\Framework\SemknoxsearchHelper;
class SiteSearchSalesChannelCmsPageLoader implements SalesChannelCmsPageLoaderInterface
{
    private EntityRepositoryInterface $cmsPageRepository;
    private SiteSearchCmsSlotsDataResolver $slotDataResolver;
    private EventDispatcherInterface $eventDispatcher;
    private Criteria $ListingCriteria;
    private Criteria $impCriteria;
    /**
     *
     * @var SalesChannelCmsPageLoaderInterface
     */
    private $decorated;
    /**
     *
     * @var SemknoxsearchHelper
     */
    private $semknoxSearchHelper;
    public function __construct(
        SalesChannelCmsPageLoaderInterface $deco,
        EntityRepositoryInterface $cmsPageRepository,
        SiteSearchCmsSlotsDataResolver $slotDataResolver,
        EventDispatcherInterface $eventDispatcher,
        SemknoxsearchHelper $helper   
    ) {
        $this->decorated = $deco;
        $this->cmsPageRepository = $cmsPageRepository;
        $this->slotDataResolver = $slotDataResolver;
        $this->eventDispatcher = $eventDispatcher;
        $this->semknoxSearchHelper = $helper;
    }
    public function load(
        Request $request,
        Criteria $criteria,
        SalesChannelContext $context,
        ?array $config = null,
        ?ResolverContext $resolverContext = null
    ): EntitySearchResult {
        if ($this->semknoxSearchHelper->useSiteSearchInListing($context, $request) === false) {
            return $this->decorated->load($request, $criteria, $context, $config, $resolverContext);
        }
        $this->impCriteria = clone $criteria;
        $limit = $this->semknoxSearchHelper->getLimit($request, $context);
        $page = $this->semknoxSearchHelper->getPage($request);        
        $offset = $limit * ($page - 1);
        $this->impCriteria->setOffset($offset);
        $this->impCriteria->setLimit($limit);
        $this->eventDispatcher->dispatch(new CmsPageLoaderCriteriaEvent($request, $criteria, $context));
        $config = $config ?? [];
        $criteria
            ->getAssociation('sections')
            ->addAssociation('backgroundMedia');
        $criteria
            ->getAssociation('sections.blocks')
            ->addAssociation('backgroundMedia')
            ->addAssociation('slots');
        $pages = $this->cmsPageRepository->search($criteria, $context->getContext());
        foreach ($pages as $page) {
            if ($page->getSections() === null) {
                continue;
            }
            $page->getSections()->sort(function (CmsSectionEntity $a, CmsSectionEntity $b) {
                return $a->getPosition() <=> $b->getPosition();
            });
            if (!$resolverContext) {
                $resolverContext = new ResolverContext($context, $request);
            }
            foreach ($page->getSections() as $section) {
                $section->getBlocks()->sort(function (CmsBlockEntity $a, CmsBlockEntity $b) {
                    return $a->getPosition() <=> $b->getPosition();
                });
            }
            $overwrite = $config[$page->getId()] ?? $config;
            $this->overwriteSlotConfig($page, $overwrite);
            $this->loadSlotData($page, $resolverContext);
        }
        $this->eventDispatcher->dispatch(new CmsPageLoadedEvent($request, $pages->getEntities(), $context));
        return $pages;
    }
    private function loadSlotData(CmsPageEntity $page, ResolverContext $resolverContext): void
    {
        $slots = $this->slotDataResolver->resolve($page->getSections()->getBlocks()->getSlots(), $resolverContext, $this->impCriteria);
        $page->getSections()->getBlocks()->setSlots($slots);
    }
    private function overwriteSlotConfig(CmsPageEntity $page, array $config): void
    {
        foreach ($page->getSections()->getBlocks()->getSlots() as $slot) {
            if ($slot->getConfig() === null && $slot->getTranslation('config') !== null) {
                $slot->setConfig($slot->getTranslation('config'));
            }
            if (empty($config)) {
                continue;
            }
            if (!isset($config[$slot->getId()])) {
                continue;
            }
            $defaultConfig = $slot->getConfig() ?? [];
            $merged = array_replace_recursive(
                $defaultConfig,
                $config[$slot->getId()]
            );
            $slot->setConfig($merged);
            $slot->addTranslated('config', $merged);
        }
    }
}
