<?PHP
namespace semknox\search\api;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use semknox\search\Struct\ProductResult;
	class semknoxBaseApi {
	    private $logFile = ''; 
		private $base = "";		
		private $customerID = "";	/** customerID of siteSearchUser. */
		private $apiKey = "";	/** API-Key of siteSearchUser. */
		private $SessionID = ""; /** SessionID of user-session. */
		private $cURL;	/** cURL-object used for query to sitesearch. */
		private $uploadMaxBlockSize = 1000;
		public $debugMode = 0;
		public $debugTimes = array();
		public $shopControllerString=''; /** controller-Info from shopware */
		private $queryId=''; /** query-ID-param of sitesearch. */
		private $expires=0; /** expires-parameter of sitesearch. */
		public  $groupIdList=array(); /** contains assignement of groupID to articleID in searchResults in the way  [groupIdList] => Array ( [516931450] => Array ( [articles] => Array ( [0] => 183.203 [1] => 183.208 [2] => 183.203A ) ) [517051294] => Array ( [articles] => Array ( [0] => 271.467 [1] => 271.468 ) ) [516994839] => Array ( [articles] => Array ( [0] => 773.007 [1] => 773.005 [2] => 773.011 [3] => 773.009 ) )) */
		private $resultCode=0; /** if < 0 error on query */
		private $searchmode=0; /** mode for searching 0=QueryFilterOrder 1=Query 11=QuerySuggests < 0 disable ProcessData*/
		private $resultCodeToInternal = [204, 400, 401, 402, 403, 404, 405, 500, 501, 502, 503, 504 ]; 
		private $logPath = '';
		private $logPathJson = '';
		private $lastJsonLogFile = '';
        const METHODE_GET    = 'GET';
        const METHODE_PUT    = 'PUT';
        const METHODE_POST   = 'POST';
        const METHODE_DELETE = 'DELETE';
        protected $validMethods = array(
            self::METHODE_GET,
            self::METHODE_PUT,
            self::METHODE_POST,
            self::METHODE_DELETE
        );		
		public $errorcode = 0;		
		public $errorText = '';		
		public $maxResults = 10;	/** max count of results to retreive per page. */
		public $maxSuggests = 10;	/**  max count of suggests to retrieve.  */
		public $order = array();			/** list of possible sortings from sitesearch { ([id] => 0 [viewName] => Produktname) }*/ 
		public $filters = array();		/** list of possible filters from sitesearch {                (
                    [logic] =&gt; AND
                    [id] =&gt; 860
                    [viewName] =&gt; Preis
                    [unitName] =&gt; EUR
                    [type] =&gt; RANGE
                    [position] =&gt; 16
                    [autofill] =&gt; 
                    [options] =&gt; Array
                        (
                        )
                    [min] =&gt; 22.5
                    [max] =&gt; 2527
                    [step] =&gt; 10
                    [idName] =&gt; cost.PRICE
                )} */
		public $searchResults = array();	/** list of searchresults of products from sitesearch. */
		public $customSearchResults = array(); /** results of sitesearch, that are not products */
		public $interpretedQuery = array();	/** query, as sitesearch interpreted it */
		public $filterSet = array();	/** list of set filters in resultset of sitesarch             
								[FILTERID] => Array
                (
                    [optionsSet] =&gt; Array
                        (
                            [0] =&gt; OPTIONSID
                        )
                ) */
		public $orderSet = array();		/** list of set sortorder [ORDERID] => ASC */
		public $logdata = '';	/** string with log-infos */
		public $processingTime=0;	/** the time (ms) the query took at sitesearch */
		public $explanation='';	 /** description-text for query-result from sitesearch. */
		public $confidence=0;		/** confidence-param of sitesearch. */
		public $category='';		/** category of search. */
		public $normalizedCategory='';	/** normalized category of search. */
		public $corrected='';		/** corrected search-query of sitesearch. */
		public $tags=array();		/** tags-param of sitesearch. */
		public $redirectResult="";	/** redirect-result-param of sitesearch. if set, the result-page should be redirecting to this value. */
		private $callResult = array(); /** array of call-results - evluation not in processingResults */ 
		public $useGroupedResults=0;	/** use grouped Results for resultset. */
		public $useHeadResultsOnly=0;	/** use head-results for resultset, use only the results marked by head=1. */
		public $resultsAvailable=0;		/** max available results in resultset */
		public $groupedResultsAvailable=0; /** max available grouped results in resultset. */
		public $headResultsAvailable=0;	 /** max available head results in resultset. */
		public $groupedHeadResultsAvailable=0; /** max grouped-head-results in resultset. */
		/**
		 * @var EventDispatcherInterface
		 */
		public $eventDispatcher = null;
		private $headerInfoData = ['shopsys'=>'SHOPWARE', 'shopsysver'=>'', 'extver'=>'', 'clientip'=>'', 'sessionid'=>''];  /** information which should be send by header like shopware-version etc. */
		/**
		*	construktor-method. creates curl-object and takes params.
		* @param	$base	(string)	base-URL of api-endpoint at sitesearch. i.e. http://dev-api.semknox.com/
		* @param	$customerID	(string)	customer number at sitesearch
		*	@param	$apiKey	(string)	API-key for query at sitesearch
		*	@param	$SessionID	(string)	session-ID of current user
		* @param	$useGroupResults	(int)	0=dont use grouped results 1=use grouped results
		*	@param	$useHeadResultsonly (int)	0=dont use head-results 1=use head-results
		*/
		public function __construct($base, $customerID, $apiKey, $sessionID,$useGroupedResults=0,$useHeadResultsonly=0) {
			$this->base = rtrim($base, '/') . '/';
			$this->customerID=$customerID;
			$this->apiKey=$apiKey;
			$this->SessionID=$sessionID;			
			$this->useGroupedResults=$useGroupedResults;
			$this->useHeadResultsOnly=$useHeadResultsonly;
        $this->cURL = curl_init();
		}	
		/**
		 * adding data to the internal shopsys-Infodata
		 * @param array $data
		 */
		public function addHeaderInfoData(?array $data) {
		    if ((!is_array($data)) || (count($data)<=0)) { return; }
		    foreach ($data as $k=>$v) {
		        $this->headerInfoData[$k]=$v;
		    }
		}
		/**
		 * set new DebugTime for field
		 * @param String $field - field to set time of
		 * @param String $info - title of time
		 */
		private function pushDebugTime(String $field, String $info) {
		    if ($this->debugMode > 0) {
		        if (!isset($this->debugTimes[$field])) { $this->debugTimes[$field] = array(); }
		        $a = array(microtime(true), $info);
		        $this->debugTimes[$field][]=$a;
		    }
		}
		/**
		* gets data from cURL-request and saves it to internal fields
		*/
		public function processResults($result) {
			$this->filters=array();$this->order=array();$this->searchResults=array();$this->redirectResult='';
			$this->filtersSet=array();$this->orderSet=array();$this->processingTime=0;$this->queryId='';$this->expires=0;
			$this->interpretedQuery=array();$this->resultsAvailable=0;$this->groupIdList=array();
			$this->groupedResultsAvailable=0;$this->groupedHeadResultsAvailable=0;$this->headResultsAvailable=0;
			return;
		}
		/**
		 * writing logData to file
		 */
		private function writeLogFile() {
		    $h=trim($this->logFile);
		    if ($h) {
		        $d="\n[".date("Y-m-d H:i:s")."] ".$this->shopControllerString."\n";
		        file_put_contents($h, $d, FILE_APPEND);
		        file_put_contents($h, $this->logdata, FILE_APPEND);
		    }
		}
		/**
		*	checking result of sitesearch, starts processResults
		*/
        protected function prepareResponse($result, $httpCode) {
    	$this->resultCode=0;
        $this->logdata.="<h2>HTTP: $httpCode</h2>\n";
        if (is_null($result)) {
            $this->resultCode = -1;            
            return $this->getCallResult(-1, 'result of call is null', null, $httpCode, 1);
        }
        if (null === $decodedResult = json_decode($result, true)) {
            $jsonErrors = array(
                JSON_ERROR_NONE => 'Es ist kein Fehler aufgetreten',
                JSON_ERROR_DEPTH => 'Die maximale Stacktiefe wurde erreicht',
                JSON_ERROR_CTRL_CHAR => 'Steuerzeichenfehler, möglicherweise fehlerhaft kodiert',
                JSON_ERROR_SYNTAX => 'Syntaxfehler',
            );
            $this->resultCode=-1;$m='';
            $m = "HTTP-Code: ".$httpCode;
            return $this->getCallResult(-1, 'error in json-decode of call result: '.$m, null, $httpCode, 1);
        }
        if ($httpCode <> 200) {
            $this->resultCode = -1;
            return $this->getCallResult(-1, 'httpcode of call is '.$httpCode, $decodedResult, $httpCode, 0);
        }
        if ($this->debugMode>10000) {
            echo "\n semknox:resultsAvailable:".$decodedResult['resultsAvailable'];
            echo "\n semknox:limit:".$decodedResult['limit'];
            echo "\n semknox:searchResults:".count($decodedResult['searchResults']);
            echo "\n semknox:processingTimeMs:".$decodedResult['processingTimeMs'];
        }
       	$this->processResults($decodedResult);
       	return $this->getCallResult($httpCode, '', $decodedResult, $httpCode, 0);
    }
    /**
    * executing call to sitesearch api
    * GET: all params set to params-array
    * POST: all params set to data-array, json-encoded
    * @params	$url	(string)	URL of call
    * @params $method	(string) method of query (GET,POST,DELETE)
    * @params $params (array)	params of query ([KEY]=>[VALUE])
    * @params $data	(array)	data-params of query ([KEY]=>json_encoded(VALUE))
    */
		private function call($url, $method = self::METHODE_GET, $params=array(), $data=array(), $jsonPayload='') {
			$this->logdata.=var_export($method,true);
			$queryString = '';$dataString='';			
			$params['apiKey']=$this->apiKey;
			if (!isset($params['projectId'])) { $params['projectId']=$this->customerID; }
			if (!empty($this->headerInfoData['sessionid'])) { $params['sessionId']=$this->headerInfoData['sessionid']; }
            if (!empty($data)) {
      	       if ( ($method == self::METHODE_POST) || ($method == self::METHODE_PUT) ) {
			   }
               $dataString = http_build_query($data);
            }
            $queryString = http_build_query($params);
            $url = rtrim($url, '?') . '?';
			$url .=  $queryString; 
			$url = rtrim($url, '?');
			$this->logdata.=var_export($url,true);
			$this->cURL = curl_init();
            $opt = array(  
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => "",
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,                
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_HTTPHEADER => array(
                    "Content-Type: application/json",
                    "HTTP_CLIENT_IP: ".$this->headerInfoData['clientip'],
                    "SHOPSYS: ".$this->headerInfoData['shopsys'],
                    "SHOPSYSVER: ".$this->headerInfoData['shopsysver'],
                    "EXTVER: ".$this->headerInfoData['extver']
                )
            );
            if ($dataString) {
                $opt[CURLOPT_POSTFIELDS]=$dataString;
            } else if ($jsonPayload) {
                $opt[CURLOPT_POSTFIELDS]=$jsonPayload;
            }
            if (!empty($jsonPayload)) {
                $this->saveJsonLog($jsonPayload);
            }
     	    curl_setopt_array($this->cURL,$opt);
            try {
     	      $result   = curl_exec($this->cURL);
            }
            catch (\Throwable $t)
            {
                var_dump($t->getMessage());
                $result = $this->getCallResult(-1, 'error in Curl: '.curl_error($this->cURL));
            }
            $curlError = '';
            if ($result === false) {                
                $httpCode = curl_getinfo($this->cURL, CURLINFO_HTTP_CODE);
                $cf = curl_error($this->cURL);
                curl_close($this->cURL);
                return $this->getCallResult(-1, 'error on Curl-Exec: '.$cf. " with httpCode: ".$httpCode); 
            }
     	    if ($this->debugMode>0) {       	        
     	        $dca=array('total_time', 'namelookup_time', 'connect_time', 'pretransfer_time', 'starttransfer_time');
     	        $h=curl_getinfo($this->cURL);
     	        $this->debugTimes['curl_data']=array();
     	        foreach ($dca as $k) {
     	            $this->debugTimes['curl_data'][]=array($h[$k],$k);
     	        }
     	    }
     	    $httpCode = curl_getinfo($this->cURL, CURLINFO_HTTP_CODE);        
     	    curl_close($this->cURL);
     	    $this->writeLogFile();
            $h=$this->prepareResponse($result, $httpCode);
            return $h;
		}
		public function QuerySearchResultsByBody(Searchbody $body) {
            $this->searchmode=0;
		    $params=array();$data=array();
		    $params['query']=$body->getTerm();
		    $params['offset']=$body->getFrom();
		    $params['limit']=$body->getSize();
		    if ($body->getNoLog()) {
		        $params['log'] = 'false';
		    }
		    $f=array();
		    foreach($body->getSearchFilters() as $k => $v) {
		        if (!is_array($v)) {continue;}
		        if (count($v)) {
		            $e=array('name'=>$v['name'], 'type'=>'products');
		            if ( $v['type']=='minmax' ) {		                
		                if ( (isset($v['minValue'])) && ($v['minValue']!='') ) { $e['min']=($v['minValue']); } else { $e['min']=0; }
		                if ( (isset($v['maxValue'])) && ($v['maxValue']!='') ) { $e['max']=($v['maxValue']); } else { $e['max']=999999999; }
		                $f[]=$e;
		            } else {
		                $hv=[];
		                foreach ($v['valueList'] as $vv) {
		                    $hv[]=['value'=>"".$vv];
		                }
		                $e['values']=$hv;
		                $f[]=$e;
		            }
		        }
		    }
		    $params['filters']=json_encode($f);
		    $params['sort']=$body->getSortingQueryParams();
		    if ( (trim($params['sort'])=='') || ($params['sort']=='score') || ($params['sort']=='_score') ) {
		        unset($params['sort']);
		    }
		    $params['projectId'] = $this->customerID;
		    $q = $this->base."search?";
		    if ($this->eventDispatcher != null) {
		        $callBackEvent = new SemknoxCallParamsCallbackEvent($params, 'search');
    		    $this->eventDispatcher->dispatch($callBackEvent, $callBackEvent::NAME);		    
    		    if ( ($callBackEvent->isChanged()) && ($callBackEvent->checkParams() > 0) ) { 
    		        $params = $callBackEvent->getParams();
    		    }
		    }
		    $ret = $this->call($q, self::METHODE_GET, $params, $data);
		    if ($ret['status'] < 0) {
		      $body->setError($ret);
		    }
		    $this->addResultsToBody($body);		  
		    return $body;
		}
		public function getSuggests(Searchbody $body) {
		    $this->searchmode=11;
		    $params=array();$data=array();
		    $params['query']=$body->getTerm();
		    $params['userGroup'] = $body->getUserGroup();
		    $params['projectId'] = $this->customerID;
		    $q = $this->base."search/suggestions?";
		    if ($this->eventDispatcher != null) {
		        $callBackEvent = new SemknoxCallParamsCallbackEvent($params, 'suggest');
		        $this->eventDispatcher->dispatch($callBackEvent, $callBackEvent::NAME);
		        if ( ($callBackEvent->isChanged()) && ($callBackEvent->checkParams() > 0) ) {
		            $params = $callBackEvent->getParams();
		        }
		    }
		    $ret = $this->call($q, self::METHODE_GET, $params, $data);
		    if ($ret['status'] < 0) {
		        $body->setError($ret);
		    }
		    $this->addSuggestsToBody($body);
		    return $body;
		}
		private function addSuggestsToBody(Searchbody &$body) {
		    if  ( (isset($this->callResult['redirect'])) && ($this->callResult['redirect'] != '') ) {
		        $body->setRedirect($this->callResult['redirect']);
		        return;
		    }
		    $this->addSuggests2Body($body);
		}
		private function addSuggests2Body(Searchbody &$body)
		{
		    $body->setCallResult($this->callResult);
		    if ( (empty($this->callResult)) || ($this->callResult['status'] < 0) ) { return; }
		    if (isset($this->callResult['searchResults'])) { $body->addSearchResults($this->callResult['searchResults']); }
		    if (isset($this->callResult['resultsAvailable'])) { $body->setResultsAvailable($this->callResult['resultsAvailable']); }
		    if (isset($this->callResult['totalResults'])) { $body->setTotalResults($this->callResult['totalResults']); }
		    if (isset($this->callResult['groupedResultsAvailable'])) { $body->setGroupedResultsAvailable($this->callResult['groupedResultsAvailable']); } else { $body->setGroupedResultsAvailable($this->callResult['totalResults']); }
		    if (isset($this->callResult['headResultsAvailable'])) { $body->setHeadResultsAvailable($this->callResult['headResultsAvailable']); } else { $body->setHeadResultsAvailable($this->callResult['totalResults']); }
		    if (isset($this->callResult['groupedHeadResultsAvailable'])) { $body->setGroupedHeadResultsAvailable($this->callResult['groupedHeadResultsAvailable']); } else { $body->setGroupedHeadResultsAvailable($this->callResult['totalResults']); }
		    if (isset($this->callResult['answerText'])) { $body->setAnswertext($this->callResult['answerText']); }
		    if (isset($this->callResult['interpretedQuery'])) { $body->setInterpretedQueryData($this->callResult['interpretedQuery']); }
		}
		private function addResultsToBody(Searchbody &$body) {
		    $body->setCallResult($this->callResult);
		    if  ( (isset($this->callResult['redirect'])) && ($this->callResult['redirect'] != '') ) { 
		        $body->setRedirect($this->callResult['redirect']);
		        return;
		    }
		    if ( (empty($this->callResult)) || ($this->callResult['status'] < 0) ) { return; }
		    $this->addSearchResults2Body($body);
		    if (isset($this->callResult['sortingOptions'])) { $body->setSortingOptions($this->callResult['sortingOptions']); }
		    if (isset($this->callResult['activeSortingOption'])) { $body->setSortingSearchResults($this->callResult['activeSortingOption']); }
		    if (isset($this->callResult['filterOptions'])) { $body->setFilterOptions($this->callResult['filterOptions']); }
		    if (isset($this->callResult['activeFilterOptions'])) { $body->setActiveFilterOptions($this->callResult['activeFilterOptions']); }
		}
		private function addSearchResults2Body(Searchbody &$body)
		{
		    if (isset($this->callResult['searchResults'])) { $body->addSearchResults($this->callResult['searchResults']); }
		    if (isset($this->callResult['resultsAvailable'])) { $body->setResultsAvailable($this->callResult['resultsAvailable']); }
		    if (isset($this->callResult['totalResults'])) { $body->setTotalResults($this->callResult['totalResults']); }
		    if (isset($this->callResult['groupedResultsAvailable'])) { $body->setGroupedResultsAvailable($this->callResult['groupedResultsAvailable']); } else { $body->setGroupedResultsAvailable($this->callResult['totalResults']); }
		    if (isset($this->callResult['headResultsAvailable'])) { $body->setHeadResultsAvailable($this->callResult['headResultsAvailable']); } else { $body->setHeadResultsAvailable($this->callResult['totalResults']); }
		    if (isset($this->callResult['groupedHeadResultsAvailable'])) { $body->setGroupedHeadResultsAvailable($this->callResult['groupedHeadResultsAvailable']); } else { $body->setGroupedHeadResultsAvailable($this->callResult['totalResults']); }
		    if (isset($this->callResult['answerText'])) { $body->setAnswertext($this->callResult['answerText']); }
		    if (isset($this->callResult['interpretedQuery'])) { $body->setInterpretedQueryData($this->callResult['interpretedQuery']); }
		}
		/**
		*		löscht Artikel mit übergebener ID
		*	@params $id	(string)	Artikel-ID des Artikels der gelöscht werden soll
		*/
		public function DeleteArticle($id) {
			if (trim($id)=='') { return; }
			$params=array();
			$params['articleNumber']=$id;
			$q = $this->base."products?";
			$ret = $this->call($q, self::METHODE_DELETE, $params);		
			return $ret;
		}
        /**
         * generates the array to return after a call to the api
         * ret['status'] : integer = 1 (success) <=0 else
         * ret['statusRet'] : string = returned statement of api for status, if no json, then ''
         * ret['resultCode'] : integer = same as status
         * ret['resultText'] : string = returned statement of api extended bei json-content, if existing
         * ret['message'] : string = returned statement of api extended bei json-content, if existing
         * @param $status
         * @param $resultText
         * @param $jsondec
         * @param $httpResult
         * @param $useInternal
         * @return array|null
         */
		function getCallResult($status = -1, $resultText = '', $jsondec=null, $httpResult=0, $useInternal=0) {
		    $ret=['status' => $status, 'resultCode'=>$status, 'resultText' => $resultText, 'message' => $resultText, 'httpResult'=>0, 'useInternalSearch'=>0, 'jsonDecoded'=>$jsondec];
		    $ret['httpResult']=intval($httpResult);
		    if ($useInternal) {
		        $ret['useInternalSearch'] = 1;
		    } else {
		        if (in_array($ret['httpResult'], $this->resultCodeToInternal)) { $ret['useInternalSearch'] = 1;  }
		    }
		    $ret['statusRet']='';$ret['taskID']='';$ret['taskStatus']='';$ret['nrOfItems']=0;$ret['validation']=array();		    
		    if (is_array($jsondec)) {
		        $ret = array_merge($ret,$jsondec);
		        if (isset($jsondec['status'])) {
		            $ret['statusRet'] = $jsondec['status'];
		            if (strtolower($ret['statusRet'])=='success') {
		                $ret['status'] = 1; 
		            } else {
		                $ret['status']=-1;
		                $this->appendJsonLogError(json_encode($jsondec));
		            }
		            $ret['resultCode']=$ret['status'];
		            if ( ($ret['status']< 0) && (isset($jsondec['validation'])) ) {
                        $ret['validation'] = $jsondec['validation'];
		                if ( (is_array($jsondec['validation'])) && (isset($jsondec['validation'][0])) && (isset($jsondec['validation'][0]['errors'])) )
		                    $ret['message'] .= " ## validation-error: ".implode("..",$jsondec['validation'][0]['errors']);
		            }
		        }
		        $ret['message'] .= ' ### HTTP: '.$ret['httpResult'];
		        if (isset($jsondec['message'])) {
		            $ret['message'] .= " ### ".$jsondec['message'];
		        }
                if (isset($jsondec['data'])) {
                    if (isset($jsondec['data']['message'])) {
                        $ret['message'] .= " ### " . $jsondec['data']['message'];
                    }
                }
		        $ret['resultText']=$ret['message'];
		        if ( (isset($jsondec['task'])) && (is_array($jsondec['task'])) ) {
		            if (isset($jsondec['task']['id'])) {
		                $ret['taskID'] = $jsondec['task']['id'];
		            }
		            if (isset($jsondec['task']['taskStatus'])) {
		                $ret['taskStatus'] = $jsondec['task']['taskStatus'];
		            }
		            if (isset($jsondec['task']['nrOfItems'])) {
		                $ret['nrOfItems'] = $jsondec['task']['nrOfItems'];
		            }
		        }
		    }
		    $this->callResult = $ret;
		    return $ret;
		}
		/**
		 * start batchupload-process
		 * @return mixed
		 */
		public function startBatchUpload() {
		    $params=array();
		    $this->searchmode=-1;
		    $q = $this->base."products/batch/initiate?";
		    $ret = $this->call($q, self::METHODE_POST, $params);
		    return $ret;		    
		}
		/**
		 * finish batchupload-process.
		 * all transfered products will be taken to sitesearch-store, all others will be deleted
		 * @return mixed
		 */
		public function finishBatchUpload() {
		    $params=array();
		    $this->searchmode=-1;
		    $q = $this->base."products/batch/start?";
		    $ret = $this->call($q, self::METHODE_POST, $params);
		    return $ret;		    
		}
		/**
		 * sending datablock. 
		 * @params	 ProductResult $inpdata
		 * @return mixed
		 */
		public function sendBatchData(ProductResult $inpdata) {
		    $data=array();$params=array();
		    $this->searchmode=-1;		    
		    $jsonPayLoad=$inpdata->getProductJsonList();		    
		    $q = $this->base."products/batch/upload?";
		    $ret = $this->call($q, self::METHODE_POST, $params, $data, $jsonPayLoad);
		    return $ret;		    
		}
		/**
		 * sending datablocks from list. splittin data in sets of $uploadMaxBlockSize
		 * @params	 ProductResult $inpdata
		 * @return mixed
		 */
		public function sendBatchDataBlocks(ProductResult $inpdata, EventDispatcherInterface $eventDispatcher) {
		    $data=array();$params=array();
		    $this->searchmode=-1; 
            $next=0;
            do {
                $callBackEvent = new SemknoxUpdateDataCallbackEvent($inpdata->getProductJsonListBlock($this->uploadMaxBlockSize, $next));
                $eventDispatcher->dispatch($callBackEvent, $callBackEvent::NAME);
                if ($callBackEvent->checkJson() < 0) { break; }
                $q = $this->base."products/batch/upload?";
                $ret = $this->call($q, self::METHODE_POST, $params, $data, $callBackEvent->getJson());
            } while ($next > 0);
		    return $ret;
		}
		/**
		 * sending datablock - testversion
		 * @params	 ProductResult $inpdata
		 * @return mixed
		 */
		public function sendBatchDataTest(string $inpdata) {
		    $data=array();$params=array();
		    $this->searchmode=-1;
		    $jsonPayLoad=$inpdata;
		    $q = $this->base."products/batch/upload?";
		    $ret = $this->call($q, self::METHODE_POST, $params, $data, $jsonPayLoad);
		    return $ret;
		}
		public static function delTree($dir) {
		    if (empty($dir)) { return; }
		    $files = array_diff(scandir($dir), array('.','..'));
		    foreach ($files as $file) {
		        (is_dir("$dir/$file")) ? delTree("$dir/$file") : unlink("$dir/$file");
		    }
		    return rmdir($dir);
		}
		private function add_ending_slash(string $path) : string
		{
		    $slash_type = (strpos($path, '\\')===0) ? 'win' : 'unix';
		    $last_char = substr($path, strlen($path)-1, 1);
		    if ($last_char != '/' and $last_char != '\\') {
		        $path .= ($slash_type == 'win') ? '\\' : '/';
		    }
		    return $path;
		}
		/**
		 * setzt Log-Pfad-Variable, falls Verzeichnis beschreibbar ist
		 * generiert Verzeichnis, falls noch nicht existent
		 * @param string $path
		 * @return int
		 */
	   public function setLogPath(?string $path) : int
	   {
	       $ret = 0;
	       $this->logPath='';
	       if (empty($path)) { return $ret; }
	       try {
	           if (!is_dir($path)) {
	               mkdir($path, 0775, true);	               
	           }
	           $this->logPath = $path;
	           $this->logPathJson = $this->add_ending_slash($this->logPath).'json';
	           $ret=1;
	       } catch (\Throwable $t)
	           {
	               $ret=-1;
	           }
	       return $ret;
	   }
	   public function resetJsonLog() : void 
	   {
	       $this->logPathJson='';
	       if (empty($this->logPath)) { return; }
	       $path = $this->add_ending_slash($this->logPath).'json';
	       try {
	           if (is_dir($path)) {
	               $this->delTree($path);
	           }
	           mkdir($path, 0775, true);
	           $this->logPathJson=$path;
	       } catch (\Throwable $t)
	       {
	           $this->logPathJson='';
	       }	       
	   }
	   private function saveJsonLog(string $json) : void
	   {
	       if (empty($json)) { return; }
	       if (empty($this->logPathJson)) { return; }
	       list($usec, $sec) = explode(" ", microtime());
	       $usec=floor($usec*100000);
	       $filename = $this->add_ending_slash($this->logPathJson).date('Y-m-d_H:i:s').'_'.$usec.'.json';
	       $this->lastJsonLogFile = $filename;
	       file_put_contents($filename, $json);
	   }
	   private function appendJsonLogError(string $json) : void
	   {
	       if (empty($json)) { return; }
	       if (empty($this->lastJsonLogFile)) { return; }
	       file_put_contents($this->lastJsonLogFile."err.js", $json);
	   }
	   public function deleteOldCatData(int $lastUpdateTime=0) {
	       $data=array();$params=array('urlPattern'=>'.*');
           if ($lastUpdateTime) {
               $params['lastUpdateTimeBefore'] = ($lastUpdateTime - 600)*1000;
           }
	       $q = $this->base."content";
	       $ret = $this->call($q, self::METHODE_DELETE, $params, $data);
	       $ret['resultText']='';
	       switch(strtolower($ret['status'])) {
	           case 'success' :
	               $ret['resultCode']=1;
	               $ret['resultText']="Kategoriedaten wurden verarbeitet\n";
	               break;
	           default: $ret['resultCode']=-1;
	       }
	       return $ret;
	   }
	   public function sendCatDatav3($inpdata) {
	       $data=array();$params=array();
	       $updateStart=time();
	       $ip = array_chunk($inpdata,100);
	       unset($inpdata);
	       foreach ($ip as $ipdata) {
	           $json=json_encode($ipdata);
	           $q = $this->base."content";
	           $ret = $this->call($q, self::METHODE_POST, $params, $data, $json);
	           $ret['resultText']='';
	           switch(strtolower($ret['status'])) {
	               case 'success' :
	                   $ret['resultCode']=1;
	                   $ret['resultText']="Kategoriedaten wurden verarbeitet\n";
	                   break;
	               default: $ret['resultCode']=-1;
	           }
	       }
           $this->deleteOldCatData($updateStart);
	       return $ret;
	   }
	}
	/**Ende Klasse SemknoxBaseApi */	
?>
