<?php declare(strict_types=1);
namespace semknox\search\Compatibility\Shopware63\Page;
use Shopware\Core\Content\Category\Exception\CategoryNotFoundException;
use Shopware\Core\Content\Product\SalesChannel\Search\AbstractProductSearchRoute;
use Shopware\Core\Framework\DataAbstractionLayer\Exception\InconsistentCriteriaIdsException;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Routing\Exception\MissingRequestParameterException;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Framework\Page\StorefrontSearchResult;
use Shopware\Storefront\Page\GenericPageLoader;
use Shopware\Storefront\Page\Search\SearchPage;
use Shopware\Storefront\Page\Search\SearchPageLoadedEvent;
use Shopware\Storefront\Page\Search\SearchPageLoader as ShopwareSearchPageLoader;
use semknox\search\Framework\SemknoxsearchHelper;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Shopware\Core\Framework\Struct\ArrayEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Content\Product\SalesChannel\Listing\ProductListingSorting;
use Shopware\Core\Content\Product\SalesChannel\Sorting\ProductSortingCollection;
use Shopware\Core\Content\Product\SalesChannel\Sorting\ProductSortingEntity;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;
use function GuzzleHttp\Psr7\parse_query;
class SearchPageLoader extends ShopwareSearchPageLoader
{
    /**
     * @var GenericPageLoader
     */
    private $genericLoader;
    /**
     * @var EventDispatcherInterface
     */
    private $eventDispatcher;
    /**
     * @var AbstractProductSearchRoute
     */
    private $productSearchRoute;
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
        GenericPageLoader $genericLoader,
        AbstractProductSearchRoute $productSearchRoute,
        EventDispatcherInterface $eventDispatcher,
        TranslatorInterface $trans,
        SemknoxsearchHelper $helper
     ) {
        parent::__construct($genericLoader, $productSearchRoute, $eventDispatcher);
        $this->genericLoader = $genericLoader;
        $this->productSearchRoute = $productSearchRoute;
        $this->eventDispatcher = $eventDispatcher;
        $this->translator = $trans;
        $this->semknoxSearchHelper = $helper;
    }
    /**
     * @throws CategoryNotFoundException
     * @throws InconsistentCriteriaIdsException
     * @throws MissingRequestParameterException
     */
    public function load(Request $request, SalesChannelContext $salesChannelContext): SearchPage
    {
        if (!$request->query->has('search')) {
            throw new MissingRequestParameterException('search');
        }
        $criteria = new Criteria();
        $this->addSortingFromRequest($request, $criteria);
        $this->addFilterFromRequest($request, $criteria);
        if (method_exists(SearchPage::class, 'setSearchResult')) {
            return $this->legacyLoad($request, $salesChannelContext);
        }
        $page = $this->genericLoader->load($request, $salesChannelContext);
        $page = SearchPage::createFrom($page);
        $this->productSearchRoute->setCriteria($criteria);
        $result = $this->productSearchRoute
            ->load($request, $salesChannelContext)
            ->getListingResult();
        $page->setListing($result);
        $page->setSearchTerm(
            (string) $request->query->get('search')
        );
        $this->eventDispatcher->dispatch(
            new SearchPageLoadedEvent($page, $salesChannelContext, $request)
        );
        return $page;
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
    private function getSortParamsFromQuery($param) : array
    {
        $ret=['name'=>'', 'sort'=>'', 'key'=>0, 'fsname'=>''];
        $ret['name']=$param;$ret['fsname']=$param;
        return $ret;
    }
    /**
     * transforms array of sortings from shopware until 6.3 to availableSortings
     * similar to funciton in our ProductListingFeatureSubscriber - maybe in the future delete it here !!!
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
    /**
     * Loads the search page for Shopware versions below 6.3.0.0.
     */
    public function legacyLoad(Request $request, SalesChannelContext $salesChannelContext): SearchPage
    {
        $page = $this->genericLoader->load($request, $salesChannelContext);
        $page = SearchPage::createFrom($page);
        $result = $this->productSearchRoute->load($request, $salesChannelContext);
        $listing = $result->getListingResult();
        $page->setListing($listing);
        $page->setSearchResult(StorefrontSearchResult::createFrom($listing));
        $page->setSearchTerm((string)$request->query->get('search'));
        $this->eventDispatcher->dispatch(
            new SearchPageLoadedEvent($page, $salesChannelContext, $request)
            );
        return $page;
    }
}
