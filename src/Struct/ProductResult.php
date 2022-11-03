<?php declare(strict_types=1);
namespace semknox\search\Struct;
use Shopware\Core\Framework\Struct\Struct;
use semknox\search\Framework\SemknoxsearchHelper;
class ProductResult extends Struct
{
    /**
     * @var Product[]
     */
    private $products;
    /**
     * @var int|null
     */
    private $nextOffset;
    /**
     *
     * @var SemknoxsearchHelper
     */
    private $semknoxSearchHelper;
    public function __construct(array $products, SemknoxsearchHelper $helper, ?int $nextOffset)
    {
        $this->products = $products;
        $this->nextOffset = $nextOffset;
        $this->semknoxSearchHelper = $helper;
    }
    /**
     * @return Product[]
     */
    public function getProducts(): array
    {
        return $this->products;
    }
    public function getNextOffset(): ?int
    {
        return $this->nextOffset;
    }
    /**
     * are there products in resultset
     * @return bool
     */
    public function hasProducts() : bool
    {
        if ( (is_array($this->products)) && (count($this->products)>0) ) { return true; }
        return false;
    }
    /**
     * returning json-formatted string for sitesearch-api-update-call 
     * @return string
     */
    public function getProductJsonList() : string
    {
        $ret = [];$ret['products']=[];
        foreach($this->products as $prod) {
            $ret['products'][] = $prod->_asSemknoxApiV3Array();
        }
        return json_encode($ret);
    }
    /**
     * returning json-formatted string for sitesearch-api-update-call
     * using blocksize to split data in blocks and setting next for next blockstart
     * @return string
     */
    public function getProductJsonListBlock($maxSize, &$next) : string
    {
        $ret = [];$ret['products']=[];
        $i=0; $ms=0;$li=0;
        foreach($this->products as $prod) {
            if ($i < $next) { $i++; continue; }
            if ($li >= $maxSize) { $ms=1; break; }
            $ret['products'][] = $prod->_asSemknoxApiV3Array();
            $i++;            
            $li++;
        }
        if ($ms) { $next = $i; } else { $next = -1; }
        if (count($ret)>0) {
            return json_encode($ret);
        } else {
            return "";
        }
    }
}
