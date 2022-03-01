<?php
namespace semknox\search\api;
use phpDocumentor\Reflection\Types\Integer;
/**
 * Search object that can be executed by a manager.
 */
class Searchbody
{
    /**
     * Searchterm 
     * @var string
     */
    private $term='';
    /**
     * Searchmode 0 standardsearch, 1 autosuggest 
     * @var integer
     */
    private $searchMode=0;
    /**
     * available filteroptions from sitesearch
     * @var array
     */
    private $filterOptions = array();
    /**
     * set filteroptions from sitesearch-api-call
     * @var array
     */
    private $filterOptionsActive = array();
    /**
     * set filterpoptions from shop-request 
     * @var array
     */
    private $filterOptionsSearch = array();
    /**
     * current sorting value from shop-request
     * ['name', 'key', 'sort']
     * @var array
     */
    private $sorting = array();
    /**
     * optional sortings from sitesearch-api-call
     * @var array
     */
    private $sortingOptions = array();    
    /**
     * sorting-option selected from sitesearch-api-call
     * @var array
     */
    private $sortingSearchResult = array();
    /**
     * errorcode on api-call
     * @var integer
     */
    private $errorCode = 0;
    /**
     * error-message
     * @var string
     */
    private $errorMsg = '';
    /**
     * array with data from original sitesearch-Call
     * 
     * @var array
     */
    private $callResults = [];
    /**
     * log the request at siteSearch360-server or not, default is log
     * @var int
     */
    private $noLog = 0;
    /**
     * searchresults from sitesearch-api-call
     * @var array
     */
    private $searchResults = array();
    private $searchResultsInfo = array('totalResults'=>-1, 'totalResultsVariants'=>-1);       
    private $customSearchResults = array();
    private $customSearchResultsInfo = array('totalResults'=>-1, 'totalResultsVariants'=>-1); 
    private $resultsAvailable = 0;
    private $totalResults = 0;
    private $groupedResultsAvailable = 0;
    private $headResultsAvailable = 0;
    private $groupedHeadResultsAvailable = 0;
    private $answerText = '';
    private $resultConfidence = 0;
    private $resultCategory = null;
    private $resultNormalizedCategory = null;
    private $resultRedirect = '';
    /**
     * config - only give back products in groups
     * default ja
     * @var boolean
     */
    private $configGrouped = true;
    /**
     * config - only give back head-results
     * default nein
     * @var boolean
     */
    private $configHeadOnly = false;
    /**
     * corrected searchterm
     * @var string
     */
    private $resultCorrected = '';
    private $resultQueryWasCorrected = false;
    private $resultExplanation = [];
    /**
     * usergroup for search
     * @var string
     */
    private $userGroup = '';
    /**
     * To retrieve hits from a certain offset. Defaults to 0.
     *
     * @var int
     */
    private $from;
    /**
     * The number of hits to return. Defaults to 10. If you do not care about getting some
     * hits back but only about the number of matches and/or aggregations, setting the value
     * to 0 will help performance.
     *
     * @var int
     */
    private $size;
    /**
     * Returns a version for each search hit.
     *
     * @var bool
     */
    private $version;
    /**
     * Exclude documents which have a _score less than the minimum specified in min_score.
     *
     * @var int
     */
    private $minScore;
    /**
     * Pagination of results can be done by using the from and size but the cost becomes
     * prohibitive when the deep pagination is reached. The index.max_result_window which
     * defaults to 10,000 is a safeguard, search requests take heap memory and time
     * proportional to from + size. The Scroll api is recommended for efficient deep
     * scrolling but scroll contexts are costly and it is not recommended to use it for
     * real time user requests. The search_after parameter circumvents this problem by
     * providing a live cursor. The idea is to use the results from the previous page to
     * help the retrieval of the next page.
     *
     * @var array
     */
    private $searchAfter;
    /**
     * Constructor to initialize static properties
     */
    public function __construct()
    {
    }
    public function addTerm(string $term) : void
    {
        $this->term = $term;
    }
    public function getTerm() : string
    {
        return $this->term;
    }
    public function getTermCorrected() : string
    {
        if ($this->resultQueryWasCorrected) {
            return $this->resultCorrected;
        } else { return ''; }
    }
    public function setSearchMode(int $sm) : void
    {
        $this->searchMode = $sm;
    }
    public function getSearchMode() : int
    {
        return $this->searchMode;
    }
    /**
     * adding filter from shopware-request
     * @param array $filter
     * $filter vom Type ['type', 'key', 'value', 'min', 'max']
     * type declares, whether it is a min-max or select-filter
     */    
    public function addSearchFilter(?array $filter) : void
    {
        if (! isset($this->filterOptionsSearch[$filter['key']])) {
            $this->filterOptionsSearch[$filter['key']] = $filter;
            $this->filterOptionsSearch[$filter['key']]['valueList']=[];
            $this->filterOptionsSearch[$filter['key']]['valueList']=$filter['valueList'];
        } else {
            switch ($filter['type']) {
                case 'minmax' : 
                    if ($this->filterOptionsSearch[$filter['key']]['minValue'] > $filter['minValue']) { 
                        $this->filterOptionsSearch[$filter['key']]['minValue'] = $filter['minValue'];
                    }
                    if ($this->filterOptionsSearch[$filter['key']]['maxValue'] < $filter['maxValue']) {
                        $this->filterOptionsSearch[$filter['key']]['maxValue'] = $filter['maxValue'];
                    }
                    break;
                case 'list' : 
                default :
                    foreach ($filter['valueList'] as $ent) {
                        $this->filterOptionsSearch[$filter['key']]['valueList'][]=$ent;
                    }
                    break;
            }
        }
    }
    public function getSearchFilters() : array
    {
        return $this->filterOptionsSearch;
    }
    public function setUserGroup(string $ug) : void
    {
        $this->userGroup = $ug;
    }
    public function getUserGroup () : string
    {
        return $this->userGroup;
    }
    public function setError(array $ug) : void
    {
        $this->errorCode = $ug['status'];
        $this->errorMsg = $ug['message'];
    }
    public function setRedirect(string $url) : void
    {
        $this->resultRedirect = $url;
    }
    public function getRedirect() : string
    {
        return $this->resultRedirect;
    }
    public function setCallResult(array $res) {
        $this->callResults = $res;
    }
    /**
     * returns decoded json-array from ss360-request
     * @return NULL|array of decoded json from ss360-request
     */
    public function getDecodedJson() {
        $ret = null;
        if (isset($this->callResults['jsonDecoded'])) { return $this->callResults['jsonDecoded']; }
        return $ret;
    }
    public function getUseInternalSearch() {
        $ret=0;
        if (isset($this->callResults['useInternalSearch'])) { $ret = $this->callResults['useInternalSearch']; }
        return $ret;
    }
    public function addSearchResults(array $sr) {
        $this->searchResults = [];
        $this->customSearchResults = [];
        foreach ($sr as $scat) {
            if ( (is_array($scat)) && ($scat['type']=='products') ) {
                if (isset($scat['totalResults'])) { $this->searchResultsInfo['totalResults'] = $scat['totalResults']; }
                if (isset($scat['totalResultsVariants'])) { $this->searchResultsInfo['totalResultsVariants'] = $scat['totalResultsVariants']; }
                foreach ($scat['results'] as $gr) {
                    $ngr = ['items'=>[], 'grId'=>''];
                    foreach ($gr as $it) {
                        if ($ngr['grId']=='') {
                            $ngr['grId']=$it['groupId'];
                        }
                        $nit=['id'=>'', 'name'=>'', 'image'=>'', 'link'=>'', 'master'=>false, 'head'=>false, 'datapoints'=>[]];
                        if (isset($it['identifier'])) { $nit['id'] = $it['identifier']; }
                        if (isset($it['name'])) { $nit['name'] = $it['name']; }
                        if (isset($it['image'])) { $nit['image'] = $it['image']; }
                        if (isset($it['link'])) { $nit['link'] = $it['link']; }
                        if (isset($it['master'])) { $nit['master'] = $it['master']; }
                        if (isset($it['head'])) { $nit['head'] = $it['head']; }
                        if (isset($it['datapoints'])) { $nit['datapoints'] = $it['datapoints']; }
                        $ngr['items'][]=$nit;
                    }
                    $this->searchResults[]=$ngr;
                }
            } else {
                $this->customSearchResults[]=$scat;
                if (isset($scat['totalResults'])) { $this->customSearchResultsInfo['totalResults'] = $scat['totalResults']; }
                if (isset($scat['totalResultsVariants'])) { $this->customSearchResultsInfo['totalResultsVariants'] = $scat['totalResultsVariants']; }
            }
        }
    }
    public function setResultsAvailable(int $sm) : void
    {
        $this->resultsAvailable = $sm;
    }
    public function getResultsAvailable() : int
    {
        return $this->resultsAvailable;
    }
    public function setTotalResults(int $sm) : void
    {
        $this->totalResults = $sm;
    }
    public function getTotalResults() : int
    {
        return $this->totalResults;
    }
    public function setGroupedResultsAvailable(int $sm) : void
    {
        $this->groupedResultsAvailable = $sm;
    }
    public function getGroupedResultsAvailable() : int
    {
        return $this->groupedResultsAvailable;
    }
    public function setHeadResultsAvailable(int $sm) : void
    {
        $this->headResultsAvailable = $sm;
    }
    public function getHeadResultsAvailable() : int
    {
        return $this->headResultsAvailable;
    }
    public function setGroupedHeadResultsAvailable(int $sm) : void
    {
        $this->groupedHeadResultsAvailable = $sm;
    }
    public function getGroupedHeadResultsAvailable() : int
    {
        return $this->groupedHeadResultsAvailable;
    }
    public function setAnswertext(string $at) : void
    {
        $this->answerText = $at;
    }
    public function getAnswerText () : string
    {
        return $this->answerText;
    }
    public function setInterpretedQueryData(?array $id) {
        if (isset($id['confidence'])) { $this->resultConfidence = $id['confidence']; } 
        if (isset($id['category'])) { $this->resultCategory = $id['category']; }
        if (isset($id['normalizedCategory'])) { $this->resultNormalizedCategory = $id['normalizedCategory']; }
        if (isset($id['corrected'])) { $this->resultCorrected = $id['corrected']; }
        if (isset($id['queryWasCorrected'])) { $this->resultQueryWasCorrected = $id['queryWasCorrected']; }
        if (isset($id['resultExplanation'])) { $this->resultExplanation = $id['resultExplanation']; }
    }
    public function getResultExplanation() : array 
    {
        return $this->resultExplanation;
    }
    public function getSearchMetadata() : array
    {
        $a=[];
        $a['resultExplanation'] = $this->resultExplanation;
        $a['confidence'] = $this->resultConfidence;
        $a['category'] = $this->resultCategory;
        $a['normalizedCategory'] = $this->resultNormalizedCategory;
        $a['corrected'] = $this->resultCorrected;
        $a['queryWasCorrected'] = $this->resultQueryWasCorrected;
        $a['answerText'] = $this->answerText;
        return $a;
    }
    /**
     * returns true, if opt-sorting is selected
     * @param array $opt
     * @return bool
     */
    private function isSortSelected($opt) : bool
    {
        $ret=false;
        if ( (!is_array($this->sortingSearchResult)) || (!isset($this->sortingSearchResult['key'])) || (!isset($this->sortingSearchResult['direction'])) )
        {
            return $ret;
        }
        if ( ($this->sortingSearchResult['key']==$opt['key']) && (strtolower($this->sortingSearchResult['direction']) == strtolower($opt['sort'])) ) 
            {
                $ret=true;
            }
        return $ret;    
    }
    /**
     * returning key for complex-sort-keys
     * @param array $opt
     * @return string
     */
    private function getComplexSortKey(array $opt) : string
    {
        $ret=strtolower($opt['name'].'-'.$opt['sort'].'-'.$opt['key']);        
        return $ret;
    }
    /**
     * returns sortkey for named-based parameters
     * @param array $opt
     * @return string
     */
    private function getSortKey(array $opt) : string
    {
        $ret=strtolower($opt['name']);
        return $ret;
    }
    private function getSortSnippet(array $opt) : string 
    {
        $ret=$opt['name'];
        return $ret;
        $ret='filter.sortBy';
        $ret.=$opt['name'];
        if (strtolower($opt['sort'])=='asc') {
            $ret.='Ascending';
        } else {           
            $ret.='Descending';
        }            
        return $ret;
    }
    private function getSortFields(array $opt) : array
    {
        $ret=[];
        $ret['product.'.strtolower($opt['name'])] = $opt['sort'];
        return $ret;
    }
    private function getSortExts(array $opt) : array
    {
        $ret=['sortKey'=>$opt['key']];
        return $ret;
    }
    /**
     * returning array for sorting-options from api-call 
     * array der Form 
           [ [
              "name"=> 'Price'
              "key"=> 12322
              "type"=> 'Attribute'
              "direction"=> 'ASC'|'DESC'
              "selected"=> true|false 
              "key" => 'name-asc'
              "snippet" => 'filter.sortByNameAscending'
              "fields" => ["product.name"=>"asc"]
              "exts" => [ "key"=>, "sort"=> ]
           ] ] 
     * sets selected for used sorting in api-call 
     * @return array
     */
    public function getSortData() : array
    {
        $a=[];
        foreach ($this->sortingOptions as $opt) {
            $opt['selected'] = $this->isSortSelected($opt);
            $opt['shopKey'] = $this->getSortKey($opt);
            $opt['snippet'] = $this->getSortSnippet($opt);
            $opt['fields'] = $this->getSortFields($opt);
            $opt['exts'] = $this->getSortExts($opt);
            $a[]=$opt;
        }
        return $a;
    }
    /**
     * returns true if opt-filter is selected
     * @param array $opt
     * @return bool
     */
    private function setFilterSelected(&$opt) : bool
    {
        $ret=false;
        foreach($this->filterOptionsActive as $k => $filter) {
            if ( ($k == $opt['key']) )
            {
                $ret=true;
                break;
            }
        }
        return $ret;
    }
    public function getCustomResults() : array
    {
        return $this->customSearchResults;
    }
    public function getFilterData() : array
    {
        $a=[];
        foreach ($this->filterOptions as $opt) {
            $opt['selected'] = $this->setFilterSelected($opt);
            $a[]=$opt;
        }
        return $a;        
    }
    public function setSortingOptions(?array $optList) : void
    {
        $this->sortingOptions = $optList;    
    }
    public function setSortingSearchResults(?array $optList) : void
    {
        $this->sortingSearchResult = $optList;
    }
    public function setFilterOptions(?array $optList) : void
    {
        $this->filterOptions=[];
        foreach ($optList as $option) {
            if ( (!isset($option['name'])) || (trim($option['name'])=='') ) { continue; }
            if ( (!isset($option['key'])) || (trim($option['key'])=='') ) { continue; }
            if ( (!isset($option['type'])) || (trim($option['type'])=='') ) { continue; }
            $this->filterOptions[]=$option;
        }        
    }
    public function setActiveFilterOptions(?array $optList) : void
    {
        $this->filterOptionsActive = $optList; 
    }
    /**
     * returning results-count depending on configuration
     * @return int
     */
    public function getTotalResultsCalc() : int
    {
        if ($this->configGrouped) {
            if ($this->searchResultsInfo['totalResults'] > -1) {
                return $this->searchResultsInfo['totalResults'];
            }
            if ($this->configHeadOnly) {
                return $this->groupedHeadResultsAvailable;                
            } else {
                return $this->groupedResultsAvailable;
            }
        } else {
            if ($this->searchResultsInfo['totalResultsVariants'] > -1) {
                return $this->searchResultsInfo['totalResultsVariants'];
            }
            if ($this->configHeadOnly) {
                return $this->headResultsAvailable;                
            } else {
                return $this->resultsAvailable;                
            }
        }
    }
    /**
     * returns list of results
     * items der form [id,datapoints[]]
     * @return array
     */
    public function getResultListCalc() : array
    {
        $ret = [];
        foreach ($this->searchResults as $res) {
            foreach ($res['items'] as $it) {
                if ( ($this->configHeadOnly) && (!$it['head']) ) { continue; }
                $a=[];
                $a['id']=$it['id'];
                $a['datapoints']=$it['datapoints'];
                $ret[]=$a;
                if ($this->configGrouped) {
                    break;
                }
            }
        }
        return $ret;
    }
    public function addQuery()
    {
    }
    public function getQueries()
    {
    }
    public function setQueryParameters(array $parameters)
    {
    }
    public function addPostFilter(array $filter) : void
    {
    }
    public function getPostFilters()
    {
    }
    public function setPostFilterParameters(array $parameters)
    {
    }
    /**
     * Adds sort to search.
     *
     */
    public function addSorting(array $sort) : void
    {
        if (!empty($sort)) {
            $this->sorting=$sort;
        }
    }
    /**
     * Returns all set sorts.
     *
     */
    public function getSorting()
    {
        return $this->sorting;
    }
    /**
     * returning sorting-array for complex search-params. not used right now!
     * @return array
     */
    public function getSortingComplexQueryParams() : array
    {
        if (!empty($this->sorting)) {
            $a=['key'=>$this->sorting['key'], 'sort'=>$this->sorting['sort']];
        } else {
            $a=[];
        }
        return $a;
    }
    /**
     * returning sorting-array for search-params for api-call
     * @return string
     */
    public function getSortingQueryParams() : string
    {
        if (!empty($this->sorting)) {
            $a=$this->sorting['name'];
        } else {
            $a='';
        }
        return $a;
    }
    /**
    * Adds suggest into search.
    *
    */
    public function addSuggest()
    {
    }
    /**
    * Returns all suggests.
    *
    */
    public function getSuggests()
    {
    }
    /**
     * @return int
     */
    public function getFrom()
    {
        return $this->from;
    }
    /**
     * @param int $from
     *
     * @return $this
     */
    public function setFrom($from)
    {
        $this->from = $from;
        return $this;
    }
    /**
     * @return int
     */
    public function getSize()
    {
        return $this->size;
    }
    /**
     * @param int $size
     *
     * @return $this
     */
    public function setSize($size)
    {
        $this->size = $size;
        return $this;
    }
    /**
     * @return bool
     */
    public function isVersion()
    {
        return $this->version;
    }
    /**
     * @param bool $version
     *
     * @return $this
     */
    public function setVersion($version)
    {
        $this->version = $version;
        return $this;
    }
    /**
     * @return int
     */
    public function getMinScore()
    {
        return $this->minScore;
    }
    /**
     * @param int $minScore
     *
     * @return $this
     */
    public function setMinScore($minScore)
    {
        $this->minScore = $minScore;
        return $this;
    }
    /**
     * @return array
     */
    public function getSearchAfter()
    {
        return $this->searchAfter;
    }
    /**
     * @param array $searchAfter
     *
     * @return $this
     */
    public function setSearchAfter($searchAfter)
    {
        $this->searchAfter = $searchAfter;
        return $this;
    }
    /**
     * {@inheritdoc}
     */
    public function toArray()
    {
        $output=[];
        /*
        $output = array_filter(static::$serializer->normalize($this->endpoints));
        $params = [
            'from' => 'from',
            'size' => 'size',
            'source' => '_source',
            'storedFields' => 'stored_fields',
            'scriptFields' => 'script_fields',
            'docValueFields' => 'docvalue_fields',
            'explain' => 'explain',
            'version' => 'version',
            'indicesBoost' => 'indices_boost',
            'minScore' => 'min_score',
            'searchAfter' => 'search_after',
            'trackTotalHits' => 'track_total_hits',
        ];
        foreach ($params as $field => $param) {
            if ($this->$field !== null) {
                $output[$param] = $this->$field;
            }
        }
     */
        return $output;
    }
    /**
     * @return int
     */
    public function getNoLog() : int
    {
        return $this->noLog;
    }
    /**
     * @param int $value
     */
    public function setNoLog($value) : void
    {
        $this->noLog = $value;
    }
}
