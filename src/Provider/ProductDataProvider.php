<?php declare(strict_types=1);
namespace semknox\search\Provider;
use Shopware\Core\Defaults;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Content\Category\CategoryEntity;
use Shopware\Core\Content\Seo\SeoUrlPlaceholderHandlerInterface;
use Throwable;
use semknox\search\Struct\Product;
use semknox\search\Struct\ProductResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainCollection;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepositoryInterface;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use semknox\search\Framework\SemknoxsearchHelper;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Adapter\Translation\Translator;
use Shopware\Administration\Snippet\SnippetFinderInterface;
use Symfony\Component\Routing\RouterInterface;
use Shopware\Core\Checkout\Cart\Price\Struct\PriceCollection;
use Shopware\Core\Content\Category\CategoryDefinition;
use Shopware\Core\Content\Product\Aggregate\ProductConfiguratorSetting\ProductConfiguratorSettingEntity;
use Shopware\Core\Content\Property\PropertyGroupCollection;
use Shopware\Core\Content\Property\Aggregate\PropertyGroupOption\PropertyGroupOptionCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
class ProductDataProvider implements ProductProviderInterface
{
    public const CHANGE_FREQ = 'hourly';
    /**
     * @var SalesChannelRepositoryInterface
     */
    private $productRepository;
    /**
     * @var SalesChannelRepositoryInterface
     */
    private $categoryRepository;
    /**
     *
     * @var SemknoxsearchHelper
     */
    private $semknoxSearchHelper;    
    /**
     * @var SeoUrlPlaceholderHandlerInterface
     */
    private $seoUrlPlaceholderHandler;
    /**
     * @var RouterInterface
     */
    private $router;
    /** 
     * Liste mit Kategorienamen, Key=KatID
     * @var array;
     */
    private $catList = [];
    /**
     * Liste mit Salescounts pro Produkt, key = product_id, item =[count=>]
     * @var array
     */
    private $salesCountList = [];
    /**
     * Liste mit Salescounts pro Produkt, key = product_id, item=[count=>,avg=>]
     * @var array
     */
    private $productReviewList = [];
    private $host;
    private $salesChannelContext;
    private $currencyID = '';
    private $allProdsList = [];
    /** @var Translator - not necessary right now, because we have to use the snippets...*/
    private $translator;
    /**
     * @var SnippetFinderInterface
     */
    private $snippetFinder;
    private $curLocale = '';
    /**
     * translation-list, array-keys like iso-code.snippets.parent1.parent2.entry i.e. "de-DE.snippets.global.sw-product.settingsForm.labelWeight"
     * @var array
     */
    private $transList = [];
    private $maxProducts = 0;
    private $preferences = [];
    private EntityRepositoryInterface $configuratorRepository;
    public function __construct(
        SalesChannelRepositoryInterface $productRepository,
        SalesChannelRepositoryInterface $categoryRepository,
        SeoUrlPlaceholderHandlerInterface $seoUrlPlaceholderHandler,         
        SemknoxsearchHelper $helper,
        Translator $translator,
        SnippetFinderInterface $snippetFinder,
		RouterInterface $router,
        EntityRepositoryInterface $configuratorRepository
        ) {
            $this->productRepository = $productRepository;
            $this->categoryRepository = $categoryRepository;
            $this->seoUrlPlaceholderHandler = $seoUrlPlaceholderHandler;
            $this->semknoxSearchHelper = $helper;
            $this->translator = $translator;
            $this->snippetFinder = $snippetFinder;
            $this->router = $router;
            $this->configuratorRepository = $configuratorRepository;
    }
    public function getName(): string
    {
        return 'product';
    }
    private function genAllProdsList(SalesChannelContext $salesChannelContext, $prodsfile, $offset, $limit)
    {
        try {
            if (is_null($offset)) { $offset = 0; }
            $this->preferences = $this->semknoxSearchHelper->getPreferences();
            $this->allProdsList=[];
            if ($this->preferences['semknoxUpdateUseVariantMaster']) {
                $query = "select HEX(id) as id, product_number from product where ( (active > 0) OR ( (isnull(active)) AND (NOT isnull(parent_id)) ) )";
            } else {
                $query = "select HEX(id) as id, product_number from product where ( (active > 0) OR ( (isnull(active)) AND (NOT isnull(parent_id)) ) ) AND (ISNULL(child_count) OR (child_count <=  0))";
            }
            $scID = $salesChannelContext->getSalesChannel()->getId();
            $doCloseOut = $this->semknoxSearchHelper->getShopwareConfigValue('core.listing.hideCloseoutProductsWhenOutOfStock', $scID,  false);
            if ($doCloseOut) {
                $query .= " AND ( (is_closeout = 0 )  OR (isnull(is_closeout)) OR ( (is_closeout > 0) AND (available_stock >= min_purchase) ) )";
            }
            $h=$this->semknoxSearchHelper->getQueryResult($query);
            file_put_contents($prodsfile, json_encode($h));
            $this->maxProducts = count($h);
        } catch (\Throwable $t) {
            $this->semknoxSearchHelper->logData(1, 'productDataProvider.genAllProdsList.ERROR', ['msg'=>$t->getMessage(), 'prodsfile'=>$prodsfile], 500);            
        }
        try {
            $this->semknoxSearchHelper->uploadblocks_generate($this->maxProducts, $limit, $salesChannelContext);
        } catch (\Throwable $t) {
            $this->semknoxSearchHelper->logData(1, 'productDataProvider.genAllProdsListBlocks.ERROR', ['msg'=>$t->getMessage(), 'prodsfile'=>$prodsfile], 500);
        }
    }
    private function getAllProdsList($prodsfile, $offset, $limit)
    {
        $this->allProdsList = [];
        if (is_null($offset)) { $offset=0; }
        try {
            $h = json_decode(file_get_contents($prodsfile), true);
            $this->maxProducts = count($h);
            $this->allProdsList = array_slice($h, $offset, $limit);
        } catch (\Throwable $t) {
            file_put_contents($prodsfile."ttt", 'error', FILE_APPEND);
            $this->semknoxSearchHelper->logData(1, 'productDataProvider.getAllProdsList.ERROR', ['msg'=>$t->getMessage(), 'prodsfile'=>$prodsfile], 500);            
        }
    }
    private function addSnippetsRec(array $snippets, string $parent) {
        foreach ($snippets as $k => $v) {
            if (is_array($v)) {
               $this->addSnippetsRec($v, $parent.$k.'.'); 
            } else {
               $this->transList[$parent.$k] = $v; 
            }
        }
    }
    /**
     * Add Snippets from Array to local Trans-Array
     * @param array $snippets
     * @param string $locale
     */
    private function addSnippetsToTransAr(array $snippets, string $locale) {
        if ( (is_array($snippets)) && (trim($locale)!='') ) {
             $this->addSnippetsRec($snippets, $locale.'.snippets.');
        }
    }
    /**
     * {@inheritdoc}
     */
    public function getProductData(SalesChannelContext $salesChannelContext, int $limit, ?int $offset = null, string $logPath = ''): ProductResult
    {
        /**debug
        $offset=0;$limit=10000;
        $nf="/var/www/stage.stoxxparts.com/var/log/semknox/allprod_".$offset."_".$limit.".js";
        */
        $np="";
        $np = $this->semknoxSearchHelper->uploadblocks_getproductfilename($salesChannelContext);
        if (!file_exists($np)) {
            $ot=time();
            $this->genAllProdsList($salesChannelContext, $np, $offset, $limit);
            $this->semknoxSearchHelper->uploadblocks_setBlockStatusBySC($salesChannelContext,-1,1, '', $ot);
            $this->semknoxSearchHelper->uploadblocks_setBlockStatusBySC($salesChannelContext,-1,100);
            return new ProductResult([], 0);
        } else {
            $this->getAllProdsList($np, $offset, $limit);
        }
        $this->semknoxSearchHelper->uploadblocks_setBlockStatusBySC($salesChannelContext,$offset,1);
        $blockstatus = 100;
        $blockerror = '';
        $this->semknoxSearchHelper->logData(100, 'getProductData.start '.$offset.'/'.$this->maxProducts, ['cap'=>count($this->allProdsList)]);
        $inpProducts = $this->getInpProducts($salesChannelContext, $limit, $offset);
        $this->salesChannelContext = $salesChannelContext;
        $this->host = $this->getHost($this->salesChannelContext);
        $langid = $this->salesChannelContext->getSalesChannel()->getLanguageId();
        $locale = $this->semknoxSearchHelper->getLanguageCodeByID($langid);
        if (trim($locale) == '') { $locale='gb-GB'; }
        $this->curLocale = $locale;
        $this->addSnippetsToTransAr($this->snippetFinder->findSnippets($this->curLocale), $this->curLocale);
        /*end translate*/
        $products = [];
        $product = new Product();
        $this->currencyID = $salesChannelContext->getCurrency()->getId();
        $this->getProdCats($salesChannelContext, 5000000, 0);
        $this->getSalesCountList();
        $this->getProductReviewList();
        foreach ($inpProducts as $inpProduct) {
            /** @var \DateTimeInterface $lastmod */
            $lastmod = $inpProduct->getUpdatedAt() ?: $inpProduct->getCreatedAt();
            try {
                $id=$inpProduct->getId();
                $newProduct = clone $product;
                $newProduct->setIdentifier($id);            
                $newProduct->setName($this->getProdName($inpProduct));
                $newProduct->setDescription($this->getProdDescription($inpProduct));
                $hd = $inpProduct->get('releaseDate');
                if (is_null($hd)) { 
                    if (!is_null($inpProduct->get('createdAt'))) {
                        $hd =  $inpProduct->get('createdAt');
                    } else {
                        $hd = new \DateTime('2010-04-16T11:12:13');
                    }
                }
                $newProduct->setReleaseDate($hd);
                $newProduct->setProductNumber($inpProduct->get('productNumber'));
                $newProduct->setIsNew($inpProduct->get('isNew'));
                $newProduct->setActive($inpProduct->get('active'));
                $newProduct->setEAN($inpProduct->get('ean'));
                $newProduct->setStock($inpProduct->get('stock'));
                $newProduct->setAvailableStock($inpProduct->get('availableStock'));
                $newProduct->setAvailable($inpProduct->get('available'));
                $newProduct->setShippingFree($inpProduct->get('shippingFree'));
                $newProduct->setPurchasePrice($this->getPurchasePrice($inpProduct));
                $newProduct->setTax($inpProduct->getTax()->getTaxRate());
                $newProduct->setCurrencyISO($salesChannelContext->getCurrency()->getIsoCode());
                $newProduct->setCurrency($salesChannelContext->getCurrency()->getName());                
                $newProduct->setCurrencySymbol($salesChannelContext->getCurrency()->getSymbol());
                $newProduct->setManufacturer($this->getManufacturer($inpProduct));
                $newProduct->setManufacturerNumber($inpProduct->getManufacturerNumber());
                $newProduct->setCoverURL($this->getCoverURL($inpProduct));
                $newProduct->setDeliveryTime($this->getDeliveryTime($inpProduct));
                $newProduct->setMetaDescription($this->getMetaDescription($inpProduct));
                $newProduct->setMetaTitle($this->getMetaTitle($inpProduct));
                $h=$this->getCategoryTreeOfProduct($inpProduct);
                foreach($h as $it) { 
                    $newProduct->addCategoryTree($it);
                }
                $newProduct->setMainProductId($inpProduct->getParentId());                
                $newProduct->setURL($this->getURL($inpProduct));
                $variantOptions = $this->loadSettings($inpProduct, $salesChannelContext);
                $newProduct->setProperties($this->getProperties($salesChannelContext, $inpProduct, $variantOptions));
                if (isset($this->salesCountList[$id])) { $newProduct->setSalesCount(intval($this->salesCountList[$id]['count'])); }
                if (isset($this->productReviewList[$id])) { 
                    $newProduct->setVotesCount(intval($this->productReviewList[$id]['count']));
                    $newProduct->setVotesValue(floatval($this->productReviewList[$id]['avg']));
               }
                /*
                $newProduct->setMetaTitle();
                $newProduct->setMetaKeywords();
                */
            } catch (\Throwable $t) {
                $this->semknoxSearchHelper->logData(100, 'productDataProvider.getProductData.ERROR', ['msg'=>$t->getMessage()]);
                $blockstatus = -1;
                $blockerror .= 'product-gen-error for id: '.$id.':::'.$t->getMessage()."\n";
            }
/*
            var_dump($inpProduct->get('productNumber'));
            var_dump($inpProduct->get('ean'));
            var_dump($inpProduct->get('availableStock'));
*/
            if ($newProduct->checkData() >= 0) {
                $products[] = $newProduct;
            }
        }
        $nOT='-';
        if (\count($this->allProdsList) < $limit) { 
            $nextOffset = null;
            $nOT='---';
        } elseif ($offset === null) { 
            $nextOffset = $limit;
            $nOT=$nextOffset;
        } else { 
            $nextOffset = $offset + $limit;
            $nOT=$nextOffset;
        }
        $this->semknoxSearchHelper->logData(100, 'getProductData.finished: '.$nOT, ['cproducts'=>count($products), 'nextOffset'=>$nOT]);
        $this->semknoxSearchHelper->uploadblocks_setBlockStatusBySC($salesChannelContext, $offset, $blockstatus, $blockerror);
        return new ProductResult($products, $nextOffset);
    }
    /**
     * {@inheritdoc}
     */
    public function getCategoryData(SalesChannelContext $salesChannelContext) : array {
        $criteria = new Criteria();
        $criteria->setLimit(500000);
        $this->salesChannelContext = $salesChannelContext;
        $this->host = $this->getHost($salesChannelContext);
        $cats = $this->categoryRepository->search($criteria, $salesChannelContext)->getEntities();
        $ret=[];
        /** @var CategoryEntity $cat*/
        foreach($cats as $cat) {
            if (!$cat->getActive()) { continue; }
            if (is_null($cat->getParentId())) { continue; }
            if ($cat->getType() !== CategoryDefinition::TYPE_PAGE) {
                continue;
            }
            $img = '';
            $media = $cat->getMedia();
            if ($media) {
                $img = $media->getUrl();
                $img = $this->encodeMediaUrl($img);
            }
            $a=array('resultGroup'=>'Kategorien', 'boost'=>'', 'imageUrl'=>'', 'url'=>'', 'title'=>'', 'content'=>'', 'dataPoints'=>[]);
            $d=['key'=> 'categoryId', 'value' => $cat->getId(), 'show' => true ];
            $a['dataPoints'][]=$d;
            $a['title'] = $cat->getName();
            $a['url'] = $this->getCatURL($cat, $salesChannelContext->getSalesChannel());
            $a['imageUrl'] = $img;
            if ($cat->getDescription()) {
                $a['content'] = $cat->getDescription();
            }
            $ret[] = $a;
        }
        return $ret;
    }
    private function getInpProducts(SalesChannelContext $salesChannelContext, int $limit, ?int $offset): ProductCollection
    {
        $productsCriteria = new Criteria();
        $productsCriteria->addAssociation('manufacturer');
        $productsCriteria->addAssociation('currency');
        $productsCriteria->addAssociation('categories');
        $productsCriteria->addAssociation('properties.group');
        /*
        $productsCriteria->addAssociation('mainCategories.category');
        */
        /*
        if ($offset !== null) {
            $productsCriteria->setOffset($offset);
        }
        */
        $ids=[];
        foreach ($this->allProdsList as $item) {
            $ids[]=strtolower($item['id']);
        }
        $productsCriteria->addFilter(new EqualsAnyFilter('id', $ids));
        /** @var ProductCollection $products */
        $products = $this->productRepository->search($productsCriteria, $salesChannelContext)->getEntities();
        $this->semknoxSearchHelper->logData(10, 'getInpProducts.finished', ['productcount'=>count($products)]);
        return $products;
    }
    private function getProdCats(SalesChannelContext $salesChannelContext, int $limit, ?int $offset): void
    {
        $criteria = new Criteria();
        $criteria->setLimit(500000);
        $cats = $this->categoryRepository->search($criteria, $salesChannelContext)->getEntities();
        $this->catList=[];
        foreach($cats as $cat) {
            $this->catList[$cat->getId()] = [
                    'name' => $cat->getName(),
                    'active' => $cat->getActive(),
                    'visible' => $cat->getVisible(),
                    'type' => $cat->getType()
            ];
        }
    }
    private function getExcludedProductIds(SalesChannelContext $salesChannelContext): array
    {
        $salesChannelId = $salesChannelContext->getSalesChannel()->getId();
        return [];
    }
    private function getManufacturer(ProductEntity $product) : string
    {
        $ret = '';
        if ($product->getManufacturer()) {
            $ret = $product->getManufacturer()->getTranslation('name');
        }
        return $ret;
    }
    public function encodeMediaUrl(?string $mediaUrl): ?string
    {
        if ( ($mediaUrl === null) || ($mediaUrl == '') ) {
            return '';
        }
        $urlInfo = parse_url($mediaUrl);
        $segments = explode('/', $urlInfo['path']);
        foreach ($segments as $index => $segment) {
            $segments[$index] = rawurlencode($segment);
        }
        $path = implode('/', $segments);
        if (isset($urlInfo['query'])) {
            $path .= "?{$urlInfo['query']}";
        }
        $encodedPath = '';
        if (isset($urlInfo['scheme'])) {
            $encodedPath = "{$urlInfo['scheme']}://";
        }
        if (isset($urlInfo['host'])) {
            $encodedPath .= "{$urlInfo['host']}";
        } else {
            $encodedPath .=  $this->host;            
        }
        if (isset($urlInfo['port'])) {
            $encodedPath .= ":{$urlInfo['port']}";
        }
        return $encodedPath . $path;
    } 
    private function getCoverURL(ProductEntity $product) : string
    {
        $ret = '';
        $cover = $product->getCover();
        if (is_null($cover)) { return $ret; }
        $m=$cover->getMedia();
        if (is_null($m)) { return $ret; }
        $ret = $m->getUrl();
        $ret = $this->encodeMediaUrl($ret);
        return $ret;
    }
    private function getURL(ProductEntity $product) : string
    {
    	$name='frontend.detail.page';
    	$parameters=['productId' => $product->getId()];
    	$path = $this->router->generate($name, $parameters, RouterInterface::ABSOLUTE_PATH);
        $ret = $this->host.$path;
        $seoPath = $this->seoUrlPlaceholderHandler->generate($name, $parameters);
        if (!empty(trim($seoPath))) {
        	$seoRet = $this->seoUrlPlaceholderHandler->replace($seoPath, $this->host, $this->salesChannelContext);
        	if (!empty(trim($seoRet))) { 
        		$h=parse_url($seoRet);
        		if (!empty($h['scheme'])) {
        			$ret = $seoRet; 
        		}
        	}
        }
        return $ret;
    }
    private function getCatURL(CategoryEntity $category, ?SalesChannelEntity $salesChannel) : string
    {
        $linkType = $category->getTranslation('linkType');
        $internalLink = $category->getTranslation('internalLink');
        if ($category->getType() === CategoryDefinition::TYPE_FOLDER) {
            return '';
        }
        if ($category->getType() !== CategoryDefinition::TYPE_LINK) {
            $linkType = CategoryDefinition::LINK_TYPE_CATEGORY;
            $internalLink = $category->getId();
        }
        $ret='';
        if (!$internalLink && $linkType && $linkType !== CategoryDefinition::LINK_TYPE_EXTERNAL) {
            return $ret;
        }
        switch ($linkType) {
            case CategoryDefinition::LINK_TYPE_PRODUCT:
                $ret = $this->seoUrlPlaceholderHandler->generate('frontend.detail.page', ['productId' => $internalLink]);
                break;
            case CategoryDefinition::LINK_TYPE_CATEGORY:
                if ($salesChannel !== null && $internalLink === $salesChannel->getNavigationCategoryId()) {
                    $ret =  $this->seoUrlPlaceholderHandler->generate('frontend.home.page');
                } else {
                    $ret = $this->seoUrlPlaceholderHandler->generate('frontend.navigation.page', ['navigationId' => $internalLink]);
                }
                break;
            case CategoryDefinition::LINK_TYPE_LANDING_PAGE:
                $ret =  $this->seoUrlPlaceholderHandler->generate('frontend.landing.page', ['landingPageId' => $internalLink]);
                break;
            case CategoryDefinition::LINK_TYPE_EXTERNAL:
            default:
                $ret = $category->getTranslation('externalLink');
        }
        if (is_null($ret)) { $ret=''; return $ret; }
        $seoPath = $ret;
        $ret = $this->host.$ret;
        if (!empty(trim($seoPath))) {
            $seoRet = $this->seoUrlPlaceholderHandler->replace($seoPath, $this->host, $this->salesChannelContext);
            if (!empty(trim($seoRet))) {
                $h=parse_url($seoRet);
                if (!empty($h['scheme'])) {
                    $ret = $seoRet;
                }
            }
        }
        return $ret;
    }
    private function getDeliveryTime(ProductEntity $product) : string
    {
        $ret = '';
        if ($product->getDeliveryTime()) {
            $ret = $product->getDeliveryTime()->getTranslation('name');
        }
        if (is_null($ret)) { $ret=''; }        
        return $ret;
    }
    private function getProdName(ProductEntity $product) : string
    {
        $ret = $product->getTranslation('name');
        if (is_null($ret)) { $ret=''; }
        return $ret;
    }
    private function getProdDescription(ProductEntity $product) : string
    {
        $ret = $product->getTranslation('description');
        if (is_null($ret)) { $ret=''; }
        return $ret;
    }
    private function getMetaDescription(ProductEntity $product) : string
    {
        $ret = $product->getTranslation('metaDescription');
        if (is_null($ret)) { $ret=''; }
        return $ret;
    }
    private function getMetaTitle(ProductEntity $product) : ?string
    {
        $ret = $product->getTranslation('metaTitle');
        if (is_null($ret)) { $ret=''; }
        return $ret;
    }
    private function getPackUnit(ProductEntity $product) : string
    {
        $ret = $product->getTranslation('packUnit');
        if (is_null($ret)) { $ret=''; }
        return $ret;
    }
    private function getCategoryIDTree(ProductEntity $product) : string
    {
        $ret = implode("|",$product->getCategoryTree());
        return $ret;
    }
    private function getPurchasePrice(ProductEntity $product) : float
    {
        $cID = Defaults::CURRENCY;
        if ($this->currencyID) { $cID = $this->currencyID; }
        $p=$product->get('calculatedPrice');
        /** @var PriceCollection $hp */        
        $hp = $product->get('calculatedPrices');
        if ($hp->count()) {
            $p = $hp->first();
        }
        if ($p) {
            $ret = $p->getUnitPrice();
            return $ret;
        }
        $p = $product->getCurrencyPrice($cID)->getGross();
        if ($p) { return $p; }
        $p = $product->get('purchasePrice');
        if ($p === null) { 
            $h = $product->get('calculatedPrice');
            if ($h === null) {
                $p=0;
            } else {
                $p=$h->getTotalPrice();
            }
        }
        return $p;
    }
    private function getPriceList(ProductEntity $product) : array
    {
        $ret = array();
        $pl = $product->getPrices();
        foreach($pl as $price) {
            $a=array('price'=>0, 'usergroups'=>array());
            $ret[]=$a;
        }
        return $ret;
    }
    /**
     * returns 0 for wrong type and -1 if not active or not visible, else +1
     * @param string $id
     * @return int
     */
    private function isCatVisible(string $id) : int
    {
        $allowType=['page'];
        $ret=-10;
        if (isset($this->catList[$id]) ) {
            $cat = $this->catList[$id];
            if (!is_array($cat)) { return $ret; }
            $ret=-3;
            if ( (!isset($cat['active'])) || (!isset($cat['visible'])) ) { return $ret; }
            if ( ($cat['active'] <= 0) || ($cat['visible'] <= 0) ) { $ret=-1; return $ret; }
            $ret=0;
            if ( (!isset($cat['type'])) || (!in_array($cat['type'],$allowType)) ) { return $ret; }
            $ret=1;            
        }
        return $ret;
    } 
    /**
     * returns List of Breadcrumbs according our rules:
     * exclude all breadcrumbs containing status <= 0
     * if no breadcrumb is left, use the first in list
     * @param array $blist
     * @return array
     */
    private function getVisibleBreadcrumbs(array $blist) : array
    {
        $ret = [];
        if (count($blist) <= 0) { return $ret; }
        $first=$blist[0];
        foreach ($blist as $breadcrumb) {
            $f=0;$bnew=[];
            foreach ($breadcrumb as $cat) {
                if ($cat['status'] <= 0) {
                    $f++;
                    break;
                } else {
                    $bnew[]=$cat;
                }
            }
            if ($f <= 0) {
                $ret[]=$bnew;
            }
        }
        if (count($ret)<=0) { $ret[]=$first; }
        return $ret;
    }
    private function getCategoryTreeOfProduct(ProductEntity $product) : array
    {
        $ret=[];$ar=[];
        $ct = $product->getCategories()->getElements();
        if (is_array($ct)) { 
            foreach($ct as $parentCat) {
                $bc = $parentCat->getPlainBreadcrumb();
                $h=[];
                foreach ($bc as $k => $v) {
                    $a = array('name' => $v, 'id' => $k, 'status'=>$this->isCatVisible($k));
                    $h[]=$a;
                }
                $ret[]=$h;
            }
        }
        if (count($ret) > 0) {
            $ret = $this->getVisibleBreadcrumbs($ret);
            return $ret; 
        }
        $ct = $product->getCategoryTree();
        if (! is_array($ct)) { return $ret; }
        foreach ($ct as $cat) {
            $h=array('id' => $cat, 'name'=>'');
            if ($this->catList[$cat]) {
                $h['name']=$this->catList[$cat]['name'];                
            }
            $ar[]=$h;
        }
        $ret[]=$ar;
        return $ret;
    }
    private function getHost(SalesChannelContext $salesChannelContext): string
    {
        $domains = $salesChannelContext->getSalesChannel()->getDomains();
        $languageId = $salesChannelContext->getSalesChannel()->getLanguageId();
        if ($domains instanceof SalesChannelDomainCollection) {
            foreach ($domains as $domain) {
                if ($domain->getLanguageId() === $languageId) {
                    return $domain->getUrl();
                }
            }
        }
        return '';
    }
    /**
     * returns Collection of master-product-data
     * @param SalesChannelContext $salesChannelContext
     * @param string $id
     * @return ProductCollection
     */
    private function getMasterProductData(SalesChannelContext $salesChannelContext, string $id): ProductCollection
    {
        $productsCriteria = new Criteria();
        $ids=[];
        $ids[]=strtolower($id);
        $productsCriteria->addFilter(new EqualsAnyFilter('id', $ids));
        /** @var ProductCollection $products */
        $products = $this->productRepository->search($productsCriteria, $salesChannelContext)->getEntities();
        return $products;
    }
    private function getProperties(SalesChannelContext $salesChannelContext, ProductEntity $product, ?array $variantOptions) : array
    {
        $ret=[];
        $tid=$this->curLocale.".snippets.sw-product.settingsForm.labelWidth";  if (isset($this->transList[$tid])) { $ename = ($this->transList[$tid]); } else { $ename = 'width'; }
        if ($product->getWidth()) { $ret['width']=['name'=>$ename, 'values'=>[['id'=>$product->getWidth(), 'name'=>$product->getWidth().'mm']]]; }
        $tid=$this->curLocale.".snippets.sw-product.settingsForm.labelHeight";  if (isset($this->transList[$tid])) { $ename = ($this->transList[$tid]); } else { $ename = 'height'; }
        if ($product->getHeight()) { $ret['height']=['name'=>$ename, 'values'=>[['id'=>$product->getHeight(), 'name'=>$product->getHeight().'mm']]]; }
        $tid=$this->curLocale.".snippets.sw-product.settingsForm.labelLength";  if (isset($this->transList[$tid])) { $ename = ($this->transList[$tid]); } else { $ename = 'length'; }
        if ($product->getLength()) { $ret['length']=['name'=>$ename, 'values'=>[['id'=>$product->getLength(), 'name'=>$product->getLength().'mm']]]; }
        $tid=$this->curLocale.".snippets.sw-product.settingsForm.labelWeight";  if (isset($this->transList[$tid])) { $ename = ($this->transList[$tid]); } else { $ename = 'weight'; }
        if ($product->getWeight()) { $ret['weight']=['name'=>$ename, 'values'=>[['id'=>$product->getWeight(), 'name'=>$product->getWeight().'kg']]]; }
        if (is_array($variantOptions)) {
                $prodOptions = $product->getOptionIds();
                if (is_array($prodOptions)) {
                    foreach ($variantOptions as $k => $opt) {
                        if (in_array($k, $prodOptions)) {
                            $id = $opt['groupId'];
                            if (!isset($ret[$id])) {
                                $ret[$id]=['name'=>$opt['groupName'], 'values'=>[]];
                            }
                            $elm=[];
                            $elm['id'] = $opt['optionId'];
                            $elm['name'] = $opt['optionName'];
                            $ret[$id]['values'][]=$elm;
                        }
                    }
                }
        }
        foreach ($product->getProperties() as $pgOptionEntity)
        {
            $group = $pgOptionEntity->getGroup();
            if ($group) {
                $id = $group->getId();
                if (!isset($ret[$id])) { 
                    $ret[$id]=['name'=>$group->getName(), 'values'=>[]];
                }
                $elm=[];
                $elm['id']=$pgOptionEntity->getId();
                $elm['name']=$pgOptionEntity->getName();
                $ret[$id]['values'][]=$elm;
            }
        }
        $trans = $product->getTranslated();
        if (is_array($trans)) { 
            if (isset($trans['customFields'])) { 
                foreach ($trans['customFields'] as $k=>$v) {
                    $id = $k;
                    if (!isset($ret[$id])) {
                        $ret[$id]=['name'=>$k, 'values'=>[]];
                    }
                    $elm=[];
                    $elm['id']=$v;
                    $elm['name']=$v;
                    $ret[$id]['values'][]=$elm;                    
                }
            }
            if ( (isset($trans['customSearchKeywords'])) && (is_array($trans['customSearchKeywords'])) ) {
                $csVals=[];
                foreach($trans['customSearchKeywords'] as $csv) {
                    $elm=[];
                    $elm['id']=$csv;
                    $elm['name']=$csv;
                    $csVals[]=$elm;
                }
                if (count($csVals)>0) {
                    $ret['customSearchKeywords']=['name'=>'customSearchKeywords', 'values'=>$csVals];                   
                }
            }
        }
        if ($product->getParentId()) {
            $masterlist = $this->getMasterProductData($salesChannelContext, $product->getParentId());
            if ($masterlist) {
                $master = $masterlist->first();
                if ($master) {
                    $pm = $master->get('productNumber');
                    $id="MasterProductNumber";
                    $ret[$id]=['name'=>$id, 'values'=>[['id'=>$pm, 'name'=>$pm]]];
                }
            }
        }
        return $ret;        
    }
    private function getSalesCountList() {
        $q  = "SELECT LOWER(hex(product_id)) as product_id, SUM(quantity) as `count` FROM `order_line_item` WHERE created_at > '2020-01-01 00:00:01' GROUP BY product_id";
        $this->salesCountList=$this->semknoxSearchHelper->getDBData($q, ['count'], 'product_id');
    }
    private function getProductReviewList() {
        $q = "SELECT LOWER(hex(product_id)) as product_id, Count(*) as `count`, AVG(points) as `avg` FROM `product_review` WHERE status > 0 GROUP BY product_id";
        $this->productReviewList=$this->semknoxSearchHelper->getDBData($q, ['count', 'avg'], 'product_id');
    }
    /**
     * get configurator-elements
     * @param ProductEntity $product
     * @param SalesChannelContext $context
     * @return array|NULL
     */
    private function loadSettings(ProductEntity $product, SalesChannelContext $context): ?array
    {
        $pId = $product->getParentId();
        if (is_null($pId)) { return null; }
        if ( (isset($this->variantOptions[$pId])) && (is_array($this->variantOptions[$pId])) ) {
            return $this->variantOptions[$pId];
        }
        $criteria = (new Criteria())->addFilter(
            new EqualsFilter('productId', $pId)
            );
        $criteria->addAssociation('option.group')
        ->addAssociation('option.media')
        ->addAssociation('media');
        $settings = $this->configuratorRepository
        ->search($criteria, $context->getContext())
        ->getEntities();
        if ($settings->count() <= 0) {
            return null;
        }
        $groups = [];
        /** @var ProductConfiguratorSettingEntity $setting */
        foreach ($settings as $setting) {
            $option = $setting->getOption();
            if ($option === null) {
                continue;
            }
            $group = $option->getGroup();
            if ($group === null) {
                continue;
            }
            $groupId = $group->getId();
            if (isset($groups[$groupId])) {
                $group = $groups[$groupId];
            }
            $groups[$groupId] = $group;
            if ($group->getOptions() === null) {
                $group->setOptions(new PropertyGroupOptionCollection());
            }
            $group->getOptions()->add($option);
            $option->setConfiguratorSetting($setting);
            $this->variantOptions[$pId][$option->getId()] = ['optionId' => $option->getId(), 'optionName' => $option->getName(), 'groupId' => $groupId, 'groupName' => $group->getName()];
        }
        return $this->variantOptions[$pId];
    }
}
