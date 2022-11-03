<?php declare(strict_types=1);
/**
 * class for product-data
 * currently without: ShortDescription, ShippingCost, Ranking/Position, Pseudopreis/Streichpreis, Votes, Baseprice, Impressions (no statistics at all) 
 * S6 - changes:
 *     - shippingTime = deliverytime = Text, because it is only a string internal 
 */
namespace semknox\search\Struct;
use Shopware\Core\Framework\Struct\Struct;
use semknox\search\Framework\SemknoxsearchHelper;
class Product extends Struct
{    
    /**
     * The productNumber
     *
     * @var string
     */
    private $productNumber='';
    /**
     * @var string 
     */
    private $name = '';
    /**
     * @var string|null
     */
    private $description = '';
    /**
     * Date and time of release
     *
     * @var \DateTimeInterface
     */
    private $releaseDate;
    /**
     * Timestamp of releaseDate
     * @var integer
     */
    private $releaseTimeStamp = 0;
    /**
     * default sort - by timestamp releasedate normalized to 0-1
     * @var integer
     */
    private $stdSort = 0;
    /**
     * @var string|null
     */
    private $identifier='';
    /**
     * @var bool
     */
    private $isNew=false;
    /**
     * @var bool
     */
    private $active=false;
    /**
     *
     * @var string|null
     */
    private $ean='';
    /**
     *
     * @var int
     */
    private $availableStock=0;
    /**
     *
     * @var int
     */
    private $stock=0;
    /**
     * @var bool
     */
    private $available=false;
    /**
     *
     * @var int
     */
    private $shippingFree=false;
    /**
     *
     * @var float
     */
    private $purchasePrice=0;
    /**
     *
     * @var float
     */
    private $tax=0;    
    /**
     * pseudopreis - Streichpreis/strike-price
     * @var float
     */
    private $pseudoPrice=0;    
    /**
     *
     * @var string
     */
    private $currency="";
    /**
     *
     * @var string
     */
    private $currencySymbol="";
    /**
     *
     * @var string
     */
    private $currencyISO="";
    /**
     * @var string|null
     */
    private $manufacturer = '';
    /**
     * Number of product at manufacturer
     * @var string|null
     */
    private $manufacturerNumber = '';
    /**
     * url of cover-image without leading Domain/Suffix
     * @var string|null
     */
    private $coverURL = '';
    /**
     * @var string|null
     */
    private $metaTitle = '';
    /**
     * @var string|null
     */
    private $metaKeywords = '';
    /**
     * @var string|null
     */
    private $metaDescription = '';
    /**
     * @var string|null
     */
    private $URL = '';
    /**
     * is the product a variant
     * @var boolean
     */
    private $isVariant = false;
    /**
     * id of the main-product (of a variant), id of the product else
     * @var string
     */
    private $MainProductID = '';
    /**
     * deliverytime as text 
     * @var string
     */
    private $deliveryTime = '';
    /**
     * categorytree as array
     * items like [id=>, name=>]
     * first item is main-entry
     * @var array
     */
    private $categoryTree = array();
    /**
     * votes of a product (mean)
     * @var integer
     */
    private $votingValue = 0;
    /**
     * votes of a product - count
     * @var integer
     */
    private $votingCount = 0;
    /**
     * count of sales of a product
     * @var integer
     */
    private $salesCount = 0;
    /**
     * count of product-clicks
     * @var integer
     */
    private $productClicks = 0;
    /**
     * properties of a product
     * @var array
     */
    private $properties = [];
    /**
     *
     * @var SemknoxsearchHelper
     */
    private $semknoxSearchHelper;
    public function __construct(SemknoxsearchHelper $helper)
    {
        $this->semknoxSearchHelper = $helper;
    }
    /**
     * @return string
     */
    public function __toString()
    {
        return sprintf(
            '<url><loc>%s</loc><lastmod>%s</lastmod><changefreq>%s</changefreq><priority>%.1f</priority></url>',
            $this->getLoc(),
            $this->getLastmod()->format('Y-m-d'),
            $this->getChangefreq(),
            $this->getPriority()
            );
    }
    /**
     * returns product-data as semknox-api-array for upload
     * @return array
     */
    public function _asSemknoxApiArray() : array
    {
        $ret=[];
        $ret['name'] = $this->getName();
        $ret['articleNumber'] = $this->getProductNumber();
        $ret['image'] = $this->getCoverURL();
        $ret['description'] = $this->getDescription();
        $ret['category'] = $this->getCategoryTree();
        $ret['ean'] = $this->getEAN();
        $ret['groupId'] = $this->getMainProductId();
        $ret['appendOnly'] = false;
        $ret['accessories'] = array();
        $ret['passOn'] = $this->getSemknoxPassOns();
        $ret['deeplink'] = $this->getURL();
        $ret['price'] = $this->getPurchasePrice()." ".$this->getCurrencySymbol();
        $ret['manufacturer'] = $this->getManufacturer();
        $ret['rankingImportance'] = $this->stdSort;
        $ret['attributes'] = $this->getApiSemknoxAttributes();
        $ret['secondaryCategories'] = array();
        return $ret;
    }
    /**
     * returns product-data as semknox-api-array for upload so api v3
     * @return array
     */
    public function _asSemknoxApiV3Array() : array
    {
        $ret=[];
        $ret['identifier'] = $this->getIdentifier();
        $ret['groupIdentifier'] = $this->getMainProductId();
        $ret['name'] = $this->getName();
        $ret['productUrl'] = $this->getURL();
        $ret['categories'] = $this->getApiV3CatNames();
        $ret['image'] = $this->getCoverURL();
        if (trim($ret['image']) == '') { unset($ret['image']); }
        $ret['attributes'] = $this->getApiV3SemknoxAttributes();
        return $ret;
    }
    /**
     * checkup, if all necessary entries were found
     * values < 0 are errors
     * -1003 : no url
     * -1005 : url without a scheme
     * 
     * @return int|NULL
     */
    public function checkData() : ?int
    {
        $ret = 0;
        $url = $this->getURL();
        if (!empty(trim($url))) {
            $h=parse_url($url);
            if (empty($h['scheme'])) {
                $ret=-1005;
            }
        } else {
            $ret=-1003;
        }
        return $ret;  
    }
    public function getProductNumber(): ?string
    {
        return $this->productNumber;
    }
    public function setProductNumber(string $productNumber): void
    {
        $this->productNumber = $productNumber;
    }
    public function setIdentifier(string $identifier): void
    {
        $this->identifier = $identifier;
    }
    public function getIdentifier(): ?string
    {
        return $this->identifier;
    }
    public function getReleaseDate() : \DateTimeInterface
    {
        return $this->releaseDate;
    }
    public function setReleaseDate(\DateTimeInterface $releaseDate) : void
    {
        $this->releaseDate = $releaseDate;
        $this->releaseTimeStamp = $releaseDate->getTimestamp();
        $h=strtotime('+3 months');
        $this->stdSort = $this->releaseTimeStamp / $h;
    }
    public function getIsNew() : bool
    {
        return $this->isNew;
    }
    public function setIsNew(bool $isnew) : void
    {
        $this->isNew = $isnew;
    }
    public function getActive() : bool
    {
        return $this->active;
    }
    public function setActive(bool $active) : void
    {
        $this->active = $active;
    }
    public function getEAN(): ?string
    {
        return $this->ean;
    }
    public function setEAN(?string $ean): void
    {
        $this->ean = $ean;
    }
    public function getAvailableStock() : int
    {
        return $this->availableStock;
    }
    public function setAvailableStock(?int $stock) : void
    {
        if ($stock === null) { return; }
        $this->availableStock = $stock;
    }
    public function getStock() : int
    {
        return $this->stock;
    }
    public function setStock(?int $stock) : void
    {
        if ($stock === null) { return; }
        $this->stock = $stock;
    }
    public function getAvailable() : bool
    {
        return $this->available;
    }
    public function setAvailable(bool $available) : void
    {
        $this->available = $available;
    }
    public function getShippingFree() : bool
    {
        return $this->shippingFree;
    }
    public function setShippingFree(bool $shippingFree) : void
    {
        $this->shippingFree = $shippingFree;
    }
    public function getCurPurchasePrice() : string
    {
        return number_format($this->purchasePrice, 2, ',' , ''). " ".$this->currencySymbol;
    }
    public function getPurchasePrice () : float
    {
        return $this->purchasePrice;
    }
    public function setPurchasePrice(?float $purchasePrice) : void
    {
        if ($purchasePrice === null) return;
        $this->purchasePrice = $purchasePrice;
    }
    public function getPseudoPrice () : float
    {
        return $this->pseudoPrice;
    }
    public function setPseudoPrice(?float $price) : void
    {
        if ($price === null) return;
        $this->pseudoPrice = $price;
    }
    public function getTax () : float
    {
        return $this->tax;
    }
    public function setTax(?float $tax) : void
    {
        if ($tax === null) { return; }
        $this->tax = $tax;
    }
    public function getCurrency () : string
    {
        return $this->currency;
    }
    public function setCurrency(string $cur) : void
    {
        $this->currency = $cur;
    }
    public function getCurrencySymbol () : string
    {
        return $this->currencySymbol;
    }
    public function setCurrencySymbol(string $cur) : void
    {
        $this->currencySymbol = $cur;
    }
    public function getCurrencyISO () : string
    {
        return $this->currencyISO;
    }
    public function setCurrencyISO(string $cur) : void
    {
        $this->currencyISO = $cur;
    }
    public function getName() : ?string
    {
        return $this->name;
    }
    public function setName(?string $name) : void
    {
        $this->name = $name;
    }
    public function getDescription() : ?string
    {
        return $this->description;
    }
    public function setDescription(?string $description) : void
    {
        $this->description = $description;
    }
    public function getManufacturer() : ?string
    {
        return $this->manufacturer;
    }
    public function setManufacturer(?string $manuf) : void
    {
        $this->manufacturer = $manuf;
    }
    public function getManufacturerNumber() : ?string
    {        
        return $this->manufacturerNumber;
    }
    public function setManufacturerNumber(?string $manuf) : void
    {
        if (is_null($manuf)) { return; }
        $this->manufacturerNumber = $manuf;
    }
    public function getCoverURL() : ?string
    {
        return $this->coverURL;
    }
    public function setCoverURL(?string $cover) : void
    {
        $this->coverURL = $cover;
    }
    public function getMetaTitle() : ?string
    {
        return $this->metaTitle;
    }
    public function setMetaTitle(?string $meta) : void
    {
        $this->metaTitle = $meta;
    }
    public function getMetaKeywords() : ?string
    {
        return $this->metaKeywords;
    }
    public function setMetaKeywords(?string $meta) : void
    {
        $this->metaKeywords = $meta;
    }
    public function getMetaDescription() : ?string
    {
        return $this->metaDescription;
    }
    public function setMetaDescription(?string $meta) : void
    {
        $this->metaDescription = $meta;
    }
    public function getURL() : ?string
    {
        return $this->URL;
    }
    public function setURL(?string $url) : void
    {
        $this->URL = $url;
    }
    public function getDeliveryTime() : ?string
    {
        return $this->deliveryTime;
    }
    public function setDeliveryTime(?string $time) : void
    {
        $this->deliveryTime = $time;
    }
    public function clearCategoryTreeList() : void
    {
       $this->categoryTree = [];    
    }
    public function addCategoryTree(?array $catTree) : void
    {
        $this->categoryTree[] = $catTree;
    }
    public function getMainProductId() : ?string
    {
        if (empty($this->MainProductID)) {
            return $this->identifier;            
        } else {
            return $this->MainProductID;
        }
    }
    public function setMainProductId(?string $pid) : void
    {
        if ($pid) { 
            $this->isVariant=true;
            $this->MainProductID = $pid;
        } else {
            $this->isVariant=false;
            $this->MainProductID='';
        }
    }
    public function setProperties(?array $props) : void
    {
        $this->properties = $props;
    }
    public function getProperties() : ?array
    {
        return $this->properties;
    }
    public function getVotesCount () : int
    {
        return $this->votingCount;
    }
    public function setVotesCount(int $votes) : void
    {
        $this->votingCount = $votes;
    }
    public function getVotesValue () : float
    {
        return $this->votingValue;
    }
    public function setVotesValue(float $votes) : void
    {
        $this->votingValue = $votes;
    }
    public function getSalesCount () : int
    {
        return $this->salesCount;
    }
    public function setSalesCount(int $sales) : void
    {
        $this->salesCount = $sales;
    }
    public function getProductClicks () : int
    {
        return $this->productClicks;
    }
    public function setProductClicks(int $clicks) : void
    {
        $this->productClicks = $clicks;
    }
    /**
     * returning internal properties as sitesearch-array for api-call
     * @return array
     */
    private function getApiSemknoxAttributes() : array
    {
        $ret=[];
        foreach($this->properties as $prop) {
            foreach($prop['values'] as $v) {
                if (isset($ret[$prop['name']])) { 
                    $ret[$prop['name']].=" / ".$v['name'];
                } else {
                    $ret[$prop['name']] = $v['name'];
                }
            }
        }
        $ret['shippingTime'] = $this->getDeliveryTime();
        $ret['availability'] =  $this->getStockAsString();
        $ret['pseudoprice'] = $this->getPseudoprice();
        $ret['inStock'] = $this->getStock();
        $ret['releaseDate'] = date("Y-m-d" , $this->getReleaseDate()->getTimestamp());
        $ret['salesCount'] = $this->getSalesCount();
        $ret['votingCount'] = $this->getVotesCount();
        $ret['votingValue'] = $this->getVotesValue();
        return $ret;
    }
    /**
     * returning interenal categorydata for sitesearch-api-call 
     * @return array
     */
    private function getApiV3CatNames() : array
    {
        $ret=[];
        if (is_array($this->categoryTree)) {
            foreach ($this->categoryTree as $catTree) {
                if (is_array($catTree)) {
                    $hr=[];
                    foreach ($catTree as $cat) {
                        if (isset($cat['name'])) {
                            $hr[]=$cat['name'];
                        } else {
                            $hr[]=$cat['id'];
                        }
                    }
                    $h=[];$h['path']=$hr;
                    $ret[]=$h;
                }
            }
        }        
        return $ret;
    }
    /**
     * returning properties for sitesearch-api-v3-call
     * @return array
     */
    private function getApiV3SemknoxAttributes() : array
    {
        $ret=[];
        try {
            $ret[] = ["key" => "SKU", "value" => "" . $this->getProductNumber()];
            $ret[] = ["key" => "price", "value" => "" . $this->getCurPurchasePrice()];
            $ret[] = ["key" => "description", "value" => $this->getDescription()];
            $ret[] = ["key" => "EAN", "value" => "" . $this->getEAN()];
            $ret[] = ["key" => "manufacturer", "value" => $this->getManufacturer()];
            $ret[] = ["key" => "manufacturerNumber", "value" => $this->getManufacturerNumber()];
            $ret[] = ["key" => "shippingTime", "value" => "" . $this->getDeliveryTime()];
            $ret[] = ["key" => "availability", "value" => "" . $this->getStockAsString()];
            $ret[] = ["key" => "inStock", "value" => "" . $this->getStock()];
            $ret[] = ["key" => "releaseDate", "value" => date("Y-m-d", $this->getReleaseDate()->getTimestamp())];
            $ret[] = ["key" => "salesCount", "value" => "" . $this->getSalesCount()];
            $ret[] = ["key" => "votingCount", "value" => "" . $this->getVotesCount()];
            $ret[] = ["key" => "votingValue", "value" => "" . $this->getVotesValue()];
            $ret[] = ["key" => "clickRate", "value" => "" . $this->getProductClicks()];
            foreach ($this->properties as $prop) {
                $key = $prop['name'];
                if ((is_null($key)) || (trim($key) == '')) {
                    continue;
                }
                foreach ($prop['values'] as $v) {
                    $ret[] = ["key" => $key, "value" => "" . $v['name']];
                }
            }
        } catch (\Throwable $t) {
            $this->semknoxSearchHelper->logData(100, 'getApiV3SemknoxAttributes.ERROR', ['msg' => $t->getMessage(), 'result' => $ret, 'properties' => $this->properties], 500);
        }
        return $ret;
    }
    /**
     * returning passons
     * @return array
     */
    private function getSemknoxPassOns() : array
    {
        $ret=[];
        $ret['shippingTime'] = $this->getDeliveryTime();
        $ret['availability'] =  $this->getStockAsString();
        $ret['pseudoprice'] = $this->getPseudoprice();
        $ret['productID'] = $this->getIdentifier();
        $ret['link'] = $this->getURL();
        $ret['mainOrdernumber'] = $this->getProductNumber();        
        return $ret;
    }
    private function getStockAsString() : string
    {
        $ret='not in stock'; return $ret;
        if ($this->getAvailable()) { $ret = 'in stock'; }
        if ($this->getAvailableStock()) { $ret .= " (".$this->getAvailableStock().")"; }
        return $ret;
    }
}
