<?php
declare(strict_types=1);
namespace semknox\search\Product;
use Doctrine\DBAL\Connection;
use Psr\Cache\InvalidArgumentException;
use Shopware\Core\Content\Product\Events\ProductListingCriteriaEvent;
use Shopware\Core\Content\Product\Events\ProductListingResultEvent;
use Shopware\Core\Content\Product\Events\ProductSearchCriteriaEvent;
use Shopware\Core\Content\Product\Events\ProductSearchResultEvent;
use Shopware\Core\Content\Product\SalesChannel\Listing\ProductListingFeaturesSubscriber;
use Shopware\Core\Content\Product\SalesChannel\Sorting\ProductSortingEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\Event\ShopwareEvent;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Page\GenericPageLoader;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Shopware\Core\Content\Product\SalesChannel\Exception\ProductSortingNotFoundException;
use Shopware\Core\Content\Product\SalesChannel\Sorting\ProductSortingCollection;
use function GuzzleHttp\Psr7\parse_query;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Struct\ArrayEntity;
use semknox\search\Framework\SemknoxsearchHelper;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
class SiteSearchProductListingFeaturesSubscriber extends ProductListingFeaturesSubscriber
{
    /** @var string default sort for categories */
    public const DEFAULT_SORT = 'score';
    public const DEFAULT_SEARCH_SORT = 'score';
    /**
     * @var EntityRepositoryInterface
     */
    private $optionRepository;
    /**
     * @var EntityRepositoryInterface
     */
    private $sortingRepository;    
    /**
     * @var Connection
     */
    private $connection;
    /**
     * @var SystemConfigService
     */
    private $systemConfigService;
    /**
     * @var EventDispatcherInterface
     */
    private $dispatcher;
    /**
     * 
     * @var ProductListingFeaturesSubscriber
     */
    private $decorated;
    private $origSearchEvent=null;
    /**
     * @var TranslatorInterface
     */
    private $translator;
    /**
     *
     * @var SemknoxsearchHelper
     */
    private $semknoxSearchHelper;
    public function __construct(
        Connection $connection,
        EntityRepositoryInterface $optionRepository,
        EntityRepositoryInterface $productSortingRepository,
        SystemConfigService $systemConfigService,
        EventDispatcherInterface $dispatcher,
        ProductListingFeaturesSubscriber $decoServ,
        TranslatorInterface $trans,
        SemknoxsearchHelper $helper  
        ) {
            $this->optionRepository = $optionRepository;
            $this->sortingRepository = $productSortingRepository;
            $this->connection = $connection;
            $this->systemConfigService = $systemConfigService;
            $this->dispatcher = $dispatcher;
            $this->decorated = $decoServ;
            $this->translator = $trans;
            $this->semknoxSearchHelper = $helper;
            parent::__construct(
                $connection,
                $optionRepository,
                $productSortingRepository,
                $systemConfigService,
                $dispatcher
                );
    }
    public function handleSearchRequest(?ProductSearchCriteriaEvent $event): void
    {
        if ( ($event===null) || (get_class($event)!=='Shopware\Core\Content\Product\Events\ProductSearchCriteriaEvent') ) {
            $this->decorated->handleSearchRequest($event);
            return;
        }
        $this->origSearchEvent = $event;
        $request = $event->getRequest();
        $criteria = $event->getCriteria();
        $context = $event->getSalesChannelContext();
        if ($this->semknoxSearchHelper->useSiteSearch($context, $request) === false) {
            $this->decorated->handleSearchRequest($event);
            return;
        }
        $internal = $request->get('useinternal');
        if ( (!is_null($internal)) && ($internal == 525) ) {
            $this->decorated->handleSearchRequest($event);
            return;
        }
        $limit = $event->getCriteria()->getLimit();
        $key = $request->get('order');
        if ($key=='topseller') {
            $criteria->resetSorting();
            unset($key);
        }
        if (is_null($key)) {
            $this->addQueryToRequest($request, '&order=score');
            $event = new ProductSearchCriteriaEvent($request, $criteria, $context);
        }
        $criteria = $event->getCriteria();
        $request = $event->getRequest();
        $context = $event->getSalesChannelContext();
        $limit = $limit ?? $event->getCriteria()->getLimit();
        if ($this->siteSearch_allowRequest($event)) {
            $event=$this->addSortingFromRequest($event);
            $event=$this->addFilterFromRequest($event);
            $this->decorated->handleSearchRequest($event);
        } else {
            $this->decorated->handleSearchRequest($event);
        }
    }
    private function getRedirect($result) {
        $ret='';
        $ext=$result->getExtension('semknoxResultData');
        if ($ext) {
            $semknoxData = $ext->getVars();
            if (isset($semknoxData['data']['redirect'])) {
                $ret = $semknoxData['data']['redirect'];
            }
        }
        return $ret;
    }
    private function getUseShopwareSearch($result) {
        $ret=0;
        $ext=$result->getExtension('semknoxResultData');
        if ($ext) {
            $semknoxData = $ext->getVars();
            if (isset($semknoxData['data']['useInternalSearch'])) {
                $ret = $semknoxData['data']['useInternalSearch'];
            }
        }
        return $ret;
    }
    public function handleResult(?ProductListingResultEvent $event): void
    {
        if ( ($event===null) || (get_class($event)!=='Shopware\Core\Content\Product\Events\ProductSearchResultEvent') ) {
            $this->decorated->handleResult($event);
            return;
        }
        $ext=$event->getResult()->getExtension('semknoxResultData');
        if ($ext) {
            $semknoxData = $ext->getVars();
            if ( (!isset($semknoxData['data'])) || (!is_array($semknoxData['data'])) || (!isset($semknoxData['data']['metaData'])) ||  (!is_array($semknoxData['data']['metaData'])) ) {
                if ($this->origSearchEvent) {
                    $this->decorated->handleSearchRequest($this->origSearchEvent);
                }
                $this->decorated->handleResult($event);
                return;
            }
        } else {
            if ($this->origSearchEvent) {
                $this->decorated->handleSearchRequest($this->origSearchEvent);
            }
            $this->decorated->handleResult($event);
            return;
        }
        $result = $event->getResult();
        $redir = $this->getRedirect($result);
        $useInternal = $this->getUseShopwareSearch($result);
        if ( ($redir!='') || ($useInternal > 0) ) {            
            return;
        }
        $sortings = $result->getCriteria()->getExtension('sortings');
         $event = $this->addSortingFromSiteSearch($event);
        parent::handleResult($event);
    }
    /**
     * check whether the request should be handled by SiteSearch360
     *
     * @throws InvalidArgumentException
     */
    private function siteSearch_allowRequest(ProductListingCriteriaEvent $event): bool
    {
        return true;
    }
    private function deprecated___sitesearch_addResultSorting(ProductListingResultEvent $event): void
    {
        $defaultSort = $event instanceof ProductSearchResultEvent ? self::DEFAULT_SEARCH_SORT : self::DEFAULT_SORT;
        $currentSorting = $this->siteSearch_getCurrentSorting($event->getRequest(), $defaultSort);
        $event->getResult()->setSorting($currentSorting);
        $this->sortingRegistry->add(
            new ProductSortingEntity('score', 'filter.sortByScore', ['_score' => 'desc'])
            );
        $sortings = $this->sortingRegistry->getSortings();
        /** @var ProductSortingEntity $sorting */
        foreach ($sortings as $sorting) {
            $sorting->setActive($sorting->getKey() === $currentSorting);
        }
        $event->getResult()->setSortings($sortings);
    }
    private function deprecated___siteSearch_getCurrentSorting(Request $request, string $default): ?string
    {
        $key = $request->get('order', $default);
        /*
        if (versionLowerThan('6.2')) {
            $key = $request->get('sort', $default);
        }
        */
        if (!$key) {
            return null;
        }
        if ($this->sortingRegistry->has($key)) {
            return $key;
        }
        return $default;
    }
    private function siteSearch_removeScoreSorting(ProductListingResultEvent $event): void
    {
        $sortings = $event->getResult()->getAvailableSortings();
        $defaultSorting = $sortings->getByKey(self::DEFAULT_SEARCH_SORT);
        if ($defaultSorting !== null) {
            $sortings->remove($defaultSorting->getId());
        }
        $event->getResult()->setAvailableSortings($sortings);    
    }
    private function getSortParamsFromQuery($param) : array
    {
        $ret=['name'=>'', 'sort'=>'', 'key'=>0, 'fsname'=>''];
        $ret['name']=$param;$ret['fsname']=$param;
        return $ret;
    }
    private function addSortingFromRequest(ProductSearchCriteriaEvent $event) : ProductSearchCriteriaEvent
    {
        return $event;
        $criteria = $event->getCriteria();
        $request = $event->getRequest();
        $context = $event->getSalesChannelContext();
        if (empty($request->get('order'))) { return $event;}
        $sortkey = trim($request->get('order'));
        if ($sortkey != '') {
            $params=$this->getSortParamsFromQuery($sortkey);
            $criteria->resetSorting();
            /** @var ProductSortingCollection $sortings */
            $sortings = new ProductSortingCollection();
            $sn = new ProductSortingEntity();
            $sn->setId(Uuid::randomHex());
            $sn->setFields(
                [
                    ['field' => '_score', 'order' => 'desc', 'priority' => 1, 'naturalSorting' => 0]
                ]
                );
            $sn->setKey('score');
            $sn->setActive(false);
            $sn->setLabel('Beste Ergebnisse');
            $sn->setTranslated(['label'=>'Beste Ergebnisse']);
            $sortings->add($sn);
            $sn = new ProductSortingEntity();
            $sn->setId(Uuid::randomHex());
            $sn->setFields(
                [
                    ['field' => $sortkey, 'order' => 'desc', 'priority' => 1, 'naturalSorting' => 0]
                ]
                );
            $sn->setKey($sortkey);
            $sn->setLabel($sortkey);
            $sn->setActive(true);
            $sn->addExtension('semknoxData', new ArrayEntity(
                [
                    'params' => $params
                ]));
            $sn->setTranslated(['label'=>$sortkey]);
            $sortings->add($sn);
            $fsorting = $sn->createDalSorting();
            $fsorting[0]->addExtension('semknoxData', new ArrayEntity(
                [
                    'params' => $params
                ]));
            $criteria->addSorting(...$fsorting);
            $criteria->addExtension('sortings',  $sortings);
            $event = new ProductSearchCriteriaEvent($request, $criteria, $context);
        }
        return $event;
    }
    private function addQueryToRequest(Request &$request, string $add) {
        $query = $request->getQueryString();
        $query.=$add;
        $qa = parse_query($query);
        $request = $request->duplicate($qa);        
    }
    private function changeQueryInRequest(Request &$request, array $add) {
        $query = $request->getQueryString();
        $qa = parse_query($query);
        foreach ($add as $k => $v) {
            $qa[$k] = $v;
        }
        $request = $request->duplicate($qa);
    }
    private function getAvailableSortings(array $semknoxSortData): EntityCollection
    {
        /** @var ProductSortingCollection $sortings */
        $sortings = new ProductSortingCollection ();
        $selected = 'score';
        $sn = new ProductSortingEntity();
        $sn->setId(Uuid::randomHex());
        $sn->setFields(
            [
                ['field' => '_score', 'order' => 'desc', 'priority' => 1, 'naturalSorting' => 0]
            ]
            );
        $sn->setKey('score');
        $sn->setActive(false);
        $sn->setLabel('Beste Ergebnisse');
        $sn->setTranslated(['label'=>'Beste Ergebnisse']);
        $sortings->add($sn);
        foreach ($semknoxSortData as $sd) {
            $sn = new ProductSortingEntity();
            $sn->setId(Uuid::randomHex());
            $fields=[];
            foreach ($sd['fields'] as $fk => $fv) {
                $f=['field' => $fk, 'order' => $fv, 'priority'=>1, 'naturalSorting' => 0];
                $fields[]=$f;
            }
            $sn->setFields($fields);
            $sn->setKey($sd['shopKey']);
            $sn->setLabel($sd['name']);
            $sn->setTranslated(['label'=>$sd['name']]);
            $sn->setActive(false);
            if ($sd['selected']) {
                $sn->setActive(true);
                $selected = $sd['shopKey'];
            }
            $sortings->add($sn);
        }
        if ($selected == 'score') {
            $sortings->first()->setActive(true);
        }
        return $sortings;
    }
    private function getCurrentSorting(ProductSortingCollection $sortings, Request $request): ProductSortingEntity
    {
        $key = $request->get('order');
        foreach($sortings->getElements() as $sorting) {
            if ($sorting->isActive()) {
                return $sorting;
            }
        }
        throw new ProductSortingNotFoundException($key);
    }
    /**
     * replace the sorting with sitesearch-Sorting
     */
    private function addSortingFromSiteSearch(ProductListingResultEvent $event) : ProductListingResultEvent
    {
        $result = $event->getResult();
        $criteria = $result->getCriteria();
        $request = $event->getRequest();
        $context = $event->getSalesChannelContext();
        $semknoxSort = $result->getExtension('semknoxResultData');
        if ( ($semknoxSort===null) ) {
            return $event;
        }
        $result->getCriteria()->resetSorting();
        $semknoxData = $semknoxSort->getVars();
        if ( (!is_array($semknoxData['data'])) || (!is_array($semknoxData['data']['sortData'])) ) {
            return event;
        }
        $sortings = array();$selected = 'score';
        /** @var ProductSortingCollection $sortings */
        $sortings = new ProductSortingCollection();
        $sortings->merge($this->getAvailableSortings($semknoxData['data']['sortData']));
        $currentSorting = $this->getCurrentSorting($sortings, $request);
        $criteria->addSorting(
            ...$currentSorting->createDalSorting()
            );
        $criteria->addExtension('sortings', $sortings);
        $this->changeQueryInRequest($request, ['order' => $currentSorting->getKey()]);
        $event = new ProductListingResultEvent($request, $result, $context);
        /*
        if (empty($request->get('order'))) {
            $this->addQueryToRequest($request, '&order=score');
        }
        */
        return $event;
    }
    private function getFilterArrayFromRequest(&$qa) : ?array
    {
        $noFilter=['p', 'order', 'search', 's', 'page'];
        $ret=null;
        if (!is_array($qa)) { return $ret; }
        $ret=[];$newf=[];
        foreach ($qa as $key => $qitem) {
            $f=0;
            if  (substr($key,0,1)=='_')   {
                $h=explode('_', $key);
                if (count($h)>0) {
                    $ha = ['name'=>'', 'valueList'=>[], 'value'=>'', 'valType'=>'', 'minValue'=>'', 'maxValue'=>''];
                    $ha['name']=$h[1];
                    $ha['value']=''.$qitem;
                    if (count($h)>2) {
                        $ha['valType'] = strtolower($h[2]);
                        if (strtolower($ha['valType'])=='min') {
                            $ha['minValue']=floatval($ha['value']);
                        } else {
                            $ha['maxValue']=floatval($ha['value']);
                        }
                    }
                    if ( (isset($ret[$ha['name']])) && (in_array($ha['valType'], ['min','max'])) ) {
                        if ($ha['valType']=='min') {
                            $ret[$ha['name']]['minValue']=floatval($ha['value']);
                        } else {
                            $ret[$ha['name']]['maxValue']=floatval($ha['value']);
                        }
                    } else {
                        $ret[$ha['name']]=$ha;
                    }
                    $f=1;
                }
            }
            if (strtolower($key)=='properties') {
                $x=explode('|',$qitem);
                foreach ($x as $xqitem) {
                    $h=explode('_',$xqitem);
                    if (count($h)>2) {
                        $ha = ['name'=>'', 'valueList'=>[], 'valType'=>'list', 'value'=>''];
                        $ha['name']=$h[1];
                        $ha['valueList'][]=''.$h[2];
                        if (isset($ret[$ha['name']])) {
                            $ret[$ha['name']]['valueList'][]=''.$h[2];
                        } else {
                            $ret[$ha['name']]=$ha;
                        }
                    }
                }
                $f=1;
            }
            if ($f==0) {
                $newf[$key]=$qitem;
            }
        }
        $qa=$newf;
        return $ret;
    }
    private function addFilterFromRequest(ProductSearchCriteriaEvent $event) : ProductSearchCriteriaEvent
    {
        $criteria = $event->getCriteria();
        $request = $event->getRequest();
        $context = $event->getSalesChannelContext();
        $query = $request->getQueryString();
        $qa = parse_query($query);
        $filterA = $this->getFilterArrayFromRequest($qa);
        if ( ($filterA === null) || (empty($filterA)) ) return $event;
        $request = $request->duplicate($qa);
        $criteria->addExtension('semknoxDataFilter', new ArrayEntity(
            [
                'filter' => $filterA
            ]));
        $event = new ProductSearchCriteriaEvent($request, $criteria, $context);
        return $event;
    }
}
