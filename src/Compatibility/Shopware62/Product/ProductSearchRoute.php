<?php declare(strict_types=1);
namespace semknox\search\Compatibility\Shopware62\Product;
use OpenApi\Annotations as OA;
use Shopware\Core\Content\Product\Aggregate\ProductVisibility\ProductVisibilityDefinition;
use Shopware\Core\Content\Product\Events\ProductSearchCriteriaEvent;
use Shopware\Core\Content\Product\Events\ProductSearchResultEvent;
use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Content\Product\ProductEvents;
use Shopware\Core\Content\Product\SalesChannel\Listing\ProductListingResult;
use Shopware\Core\Content\Product\SalesChannel\ProductAvailableFilter;
use Shopware\Core\Content\Product\SearchKeyword\ProductSearchBuilderInterface;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\RequestCriteriaBuilder;
use Shopware\Core\Framework\Plugin\Exception\DecorationPatternException;
use Shopware\Core\Framework\Routing\Annotation\RouteScope;
use Shopware\Core\Framework\Routing\Exception\MissingRequestParameterException;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Shopware\Core\Content\Product\SalesChannel\Search\AbstractProductSearchRoute;
use Shopware\Core\Content\Product\SalesChannel\Search\ProductSearchRouteResponse;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Struct\ArrayEntity;
use Shopware\Core\Content\Product\SalesChannel\Listing\ProductListingSorting;
use Shopware\Core\Content\Product\SalesChannel\Sorting\ProductSortingCollection;
use Shopware\Core\Content\Product\SalesChannel\Sorting\ProductSortingEntity;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;
use Shopware\Core\Content\Property\PropertyGroupEntity;
use Shopware\Core\Content\Property\PropertyGroupCollection;
use Shopware\Core\Content\Property\Aggregate\PropertyGroupOption\PropertyGroupOptionCollection;
use Shopware\Core\Content\Property\Aggregate\PropertyGroupOption\PropertyGroupOptionEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Metric\StatsResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Metric\EntityResult;
use PackageVersions\Versions;
use function GuzzleHttp\Psr7\parse_query;
use semknox\search\Framework\SemknoxsearchHelper;
/**
 * @RouteScope(scopes={"store-api"})
 */
class ProductSearchRoute extends AbstractProductSearchRoute
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
    /**
     * @var TranslatorInterface
     */
    private $translator;
    /**
     * 
     * @var Criteria
     */
    private $requestCriteria = null;
    /**
     *
     * @var SemknoxsearchHelper
     */
    private $semknoxSearchHelper;
    private $filterableType = ['COLLECTION', 'BUCKET', 'COLOR'];
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
    public function getDecorated(): AbstractProductSearchRoute
    {
        throw new DecorationPatternException(self::class);
    }
    public function setCriteria(Criteria $criteria) {
        $this->requestCriteria = $criteria;
    }
    /**
     * @OA\Get(
     *      path="/search",
     *      description="Search",
     *      operationId="searchPage",
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
     *          @OA\JsonContent(ref="#/definitions/ProductListingResult")
     *     )
     * )
     * @Route("/store-api/v{version}/search", name="store-api.search", methods={"POST"})
     */
    public function load(Request $request, SalesChannelContext $context): ProductSearchRouteResponse
    {
        if (!$request->get('search')) {
            throw new MissingRequestParameterException('search');
        }
        if (!is_null($this->requestCriteria)) {
            $criteria = $this->requestCriteria;    
        } else {
            $criteria = new Criteria();
            $this->addSortingFromRequest($request, $criteria);
            $this->addFilterFromRequest($request, $criteria);
        }
        $criteria->addFilter(
            new ProductAvailableFilter($context->getSalesChannel()->getId(), ProductVisibilityDefinition::VISIBILITY_SEARCH)
            );
        $this->searchBuilder->build($request, $criteria, $context);
        $this->eventDispatcher->dispatch(
            new ProductSearchCriteriaEvent($request, $criteria, $context),
            ProductEvents::PRODUCT_SEARCH_CRITERIA
            );
        $result = $this->productListingLoader->load($criteria, $context);
        $result = ProductListingResult::createFrom($result);
        $this->eventDispatcher->dispatch(
            new ProductSearchResultEvent($request, $result, $context),
            ProductEvents::PRODUCT_SEARCH_RESULT
            );
        $result->addCurrentFilter('search', $request->query->get('search'));
        $this->setSortorderToResult($result);
        $this->setFilterToResult($result);
        return new ProductSearchRouteResponse($result);
    }
    private function getSortParamsFromQuery($param) : array
    {
        $ret=['name'=>'', 'sort'=>'', 'key'=>0, 'fsname'=>''];
        $ret['name']=$param;$ret['fsname']=$param;
        return $ret;
    }
    private function getComplexSortParamsFromQuery($param) : array
    {
        $ret=['name'=>'', 'sort'=>'', 'key'=>0, 'fsname'=>$param];
        $h=explode('-', $param);
        if (count($h)==3) {
            $ret['name']=$h[0];
            $ret['sort']=$h[1];
            $ret['key']=$h[2];
            $ret['fsname']='__'.$ret['name'].'-'.$ret['key'];
        }
        return $ret;
    }
    private function getCodedName($name) : string
    {
        $ret=$name;
        $ret = preg_replace ( '/[^a-z0-9_\- ]/i', '', strtolower($ret) );
        return $ret;
    }
    private function addSortingFromRequest(Request $request, Criteria $criteria) : void
    {
        if (empty($request->get('order'))) { return;}
        $sortkey = trim($request->get('order'));
        if ($sortkey != '') {
            $params=$this->getSortParamsFromQuery($sortkey);
            $criteria->resetSorting();
            $sn = new FieldSorting($params['fsname'], $params['sort']);
            $sn->addExtension('semknoxData', new ArrayEntity(
                [
                    'params' => $params
                ]));
            $criteria->addSorting($sn);
            $sortings = array();
            $sn = new ProductListingSorting ('score', 'filter.sortByScore', [ "_score"=> "desc" ] );
            $sortings[]=$sn;
            $sn = new ProductListingSorting ($params['fsname'], 'filter.'.$params['fsname'], [$params['fsname']=>'desc']);
            $sn->setActive(true);
            $sortings[]=$sn;
            if ($this->semknoxSearchHelper->shopwareVersionCompare('6.3', '>')) {                
                $criteria->addExtension('sortings',  $this->getProductSortingEntities($sortings));
            }
        }
    }
    private function addComplexSortingFromRequest(Request $request, Criteria $criteria) : void    
    {
        $sortkey = $request->get('order');
        if ($sortkey != '') {
            $params=$this->getComplexSortParamsFromQuery($sortkey);
            if ($params['key'] > 0) {
                $criteria->resetSorting();
                $sn = new FieldSorting($params['fsname'], $params['sort']);
                $sn->addExtension('semknoxData', new ArrayEntity(
                    [
                        'params' => $params
                    ]));
                $criteria->addSorting($sn);
            }
        }
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
    private function addFilterFromRequest(Request &$request, Criteria $criteria) : void
    {
        $query = $request->getQueryString();
        $qa = parse_query($query);
        $filterA = $this->getFilterArrayFromRequest($qa);
        if ( ($filterA === null) || (empty($filterA)) ) return;
        $request = $request->duplicate($qa);
        $criteria->addExtension('semknoxDataFilter', new ArrayEntity(
            [
                'filter' => $filterA
            ]));
    }
    private function convertToProductSortingEntity(ProductListingSorting $sorting): ProductSortingEntity
    {
        $fields = \array_map(function ($field, $order) {
            return ['field' => $field, 'order' => $order, 'priority' => 0, 'naturalSorting' => 0];
        }, \array_keys($sorting->getFields()), $sorting->getFields());
            $productSortingEntity = new ProductSortingEntity();
            $productSortingEntity->setId(Uuid::randomHex());
            $productSortingEntity->setKey($sorting->getKey());
            $productSortingEntity->setPriority(0);
            $productSortingEntity->setActive($sorting->isActive());
            $productSortingEntity->setFields($fields);
            $productSortingEntity->setLabel($this->translator->trans($sorting->getSnippet()));
            $productSortingEntity->addTranslated('label', $this->translator->trans($sorting->getSnippet()));
            $productSortingEntity->setLocked(false);
            return $productSortingEntity;
    }
    /**
     * transforms array until shopware version 6.3 for sortings to availablesortings
     * @param array $availableSortings
     * @return ProductSortingCollection
     */
    public function getProductSortingEntities(?array $availableSortings = null): ProductSortingCollection
    {
        $productSortings = new ProductSortingCollection();
        foreach ($availableSortings as $sorting) {
            $productSortings->add($this->convertToProductSortingEntity($sorting));
        }
        return $productSortings;
    }
    /**
     * replace shopware-sortings with sitesearch-sortings
     * @param ProductListingResult $result
     */
    private function setSortorderToResult(ProductListingResult $result) : void
    {
        $stdSort = $result->getSortings();
        $semknoxSort = $result->getExtension('semknoxResultData');
        if ( ($semknoxSort===null) ) {
            return;
        }
        $result->setSortings([]);
        $semknoxData = $semknoxSort->getVars();
        if ( (!is_array($semknoxData['data'])) || (!is_array($semknoxData['data']['sortData'])) ) {
            return;
        }
        $sortings = array();$selected = 'score';
        $sn = new ProductListingSorting ('score', 'filter.sortByScore', [ "_score"=> "desc" ] );
        $sortings[]=$sn;
        foreach ($semknoxData['data']['sortData'] as $sd) {
            $sn = new ProductListingSorting ($sd['shopKey'], $sd['snippet'], $sd['fields']);
            if ($sd['selected']) {
                $sn->setActive(true);
                $selected = $sd['shopKey'];
            }
            $sortings[]=$sn;
        }
        if ($selected == 'score') {
            $sortings[0]->setActive(true);
        }
        $result->setSortings($sortings);    
        $result->setSorting($selected);
        if ($this->semknoxSearchHelper->shopwareVersionCompare('6.3', '>')) {
            $result->setAvailableSortings($this->getProductSortingEntities($sortings));
        }
    }
    private function getFilterPropertyGroupEntity(array $filter) : ?propertyGroupEntity
    {
        $ret = null;
        if (! in_array($filter['type'], $this->filterableType)) { return $ret; }
        $pge = new PropertyGroupEntity();
        $pgeID = '_'.$filter['name'];
        $pgeIDmm = '_'.$filter['name'];
        $pge->setId($pgeID);
        $pge->setName($filter['name']);
        $pge->addTranslated('name', $filter['name']);
        $displType='text';$sortType='position';
        if ($filter['type'] == 'COLOR') {
            $displType='color';
        }        
        /* deprecated - wird aus API von Semknox entfernt!
        if (isset($filter['conceptType'])) {
            switch ($filter['conceptType']) {
                case 'NUMERIC_ATTRIBUTE' : $displType='numeric';$sortType='numeric'; break;
                case 'TEXT_ATTRIBUTE' : $displType='text';$sortType='alphanumeric';break;
            }
        }
        */
        $pge->setDisplayType($displType);
        $pge->setSortingType($sortType);
        $pge->setDescription('');
        $pgo = new PropertyGroupOptionCollection();
        $pos=0;
        if ($displType == 'numeric') {
            foreach ($filter['counts'] as $k => $v) {
                $pgoe = new PropertyGroupOptionEntity();
                $pgoe->setName($k.$filter['unit']);
                $pgoe->setPosition($pos++);
                $pgoe->setId($pgeIDmm.'_'.$k);
                $pgoe->setUniqueIdentifier($pgeIDmm.'_'.$k);
                $pgoe->addTranslated('name', $k.$filter['unit']);
                $pgo->add($pgoe);
            }
        }
        if ( ($displType == 'text') || ($displType == 'color') ) {
            foreach ($filter['values'] as $value) {
                $pgoe = new PropertyGroupOptionEntity();
                $pgoe->setName($value['name']);
                $pgoe->setPosition($pos++);
                $pgoe->setId($pgeID.'_'.$value['value']);
                $pgoe->setUniqueIdentifier($pgeID.'_'.$value['value']);
                $pgoe->addTranslated('name', $value['name']);
                if ( ($filter['type'] == 'COLOR') && (isset($value['color'])) && (!empty($value['color'])) ) {
                    $pgoe->setColorHexCode($value['color']);
                }                
                $pgo->add($pgoe);
            }
        }
        $pge->setOptions($pgo);
        return $pge;
    }
    private function getFilterPropertiesFromResult(array $data) : EntityResult
    {
        $pgc = new PropertyGroupCollection() ;
        foreach ($data as $filter) {
            $pge = $this->getFilterPropertyGroupEntity($filter);
            if ($pge === null) { continue; }
            $pgc->add($pge);
        }
        return new EntityResult('properties', $pgc);
    }
    private function getPriceFromFilterData(array &$data) : ?array
    {
        $ret=null;$f=-1;
        foreach ($data as $k => $v) {
            if ($v['name']=='price') {
                $sum=0;$anz=0;
                $f=$k;
                foreach ($v['counts'] as $x => $c) {
                    $sum+=$x*$c;
                    $anz+=$c;
                }
                $v['sum']=$sum;
                $v['avg']=$sum/$anz;
                $ret=$v;
                break;
            }
            unset($v);
        }
        if ($f>-1) { unset($data[$f]); }
        return $ret;
    }
    /**
     * creating interna datasctucture of shopware from the range-values of sitesearch
     * @param array $data
     * @return array
     */
    private function getMinMaxValuesFromFilterData(array $data) : array
    {
        $ret=[];
        foreach ($data as $k => $v) {
            if ($v['type']=='RANGE') {
                $sum=0;$anz=0;
                $f=$k;
                foreach ($v['counts'] as $x => $c) {
                    $sum+=$x*$c;
                    $anz+=$c;
                }
                $v['sum']=$sum;
                $v['avg']=$sum/$anz;
                if ( (!isset($v['unit'])) || (empty($v['unit'])) ) {
                    $v['unit']='';
                    if (in_array(strtolower($v['name']), ['price','preis','verkaufspreis'])) {
                        $v['unit']='€';
                        $v['conceptType']='PRICE_ATTRIBUTE';
                    }
                }
                $v['labelMin'] = $v['name'].' ab';
                $v['labelMax'] = $v['name'].' bis';
                $v['labelError'] = 'Der min-Wert von '.$v['name'].' sollte nicht größer sein als der max-Wert!';
                if (!isset($v['min'])) { $v['min'] = null; }
                if (!isset($v['max'])) { $v['max'] = null; }
                if ( (!is_null($v['min'])) && (!is_null($v['max'])) ) {
                    $ret[]=$v;
                }
            }
            unset($v);
        }
        return $ret;
    }
    /**
     * transforming filter from sitesearch to shopware-structure
     * @param ProductListingResult $result
     */
    private function setFilterToResult(ProductListingResult $result) : void
    {
        $semknoxSort = $result->getExtension('semknoxResultData');
        if ( ($semknoxSort===null) ) {
            return;
        }
        $stdAggs = $result->getAggregations();
        $result->getAggregations()->remove('properties');
        $result->getAggregations()->remove('manufacturer');
        $result->getAggregations()->remove('price');
        $result->getAggregations()->remove('shipping-free');
        $result->getAggregations()->remove('rating');
        $semknoxData = $semknoxSort->getVars();
        if ( (!is_array($semknoxData['data'])) || (!is_array($semknoxData['data']['filterData'])) ) {
            return;
        }
        $filterData=$semknoxData['data']['filterData'];
        /* 
         * first we get the range-filter from the sets
         * as far as shopware-range-filters are price-filter
         * so we transfer the range-filter to our own structure and using them by our template-vars
         * same for prices with range-filter-attribute
         */
        $prlist = $this->getMinMaxValuesFromFilterData($filterData);$filterList=[];
        foreach ($prlist as $pr) {
            if ($pr!==null) {
                $sr = new StatsResult('_'.$pr['name'], $pr['min'], $pr['max'], $pr['avg'], $pr['sum']);
                $sr->addExtension('semknoxData', new ArrayEntity(['filter' => $pr]));
                $filterList[]= ['semkAr'=>$pr, 'semkSr'=>$sr];
            }
        }
        $result->addExtension('semknoxSearchFilter', new ArrayEntity(
            [
                'filter' => $filterList
            ]));
        $properties = $this->getFilterPropertiesFromResult($filterData);
        $result->getAggregations()->set('properties', $properties);
    }
}
