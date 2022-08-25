<?php declare(strict_types=1);
namespace semknox\search\Framework;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\MultiFilter;
use Shopware\Core\System\SystemConfig\SystemConfigDefinition;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware;
use semknox\search\Exception\NoIndexedDocumentsException;
use semknox\search\Exception\ServerNotAvailableException;
use semknox\search\Framework\DataAbstractionLayer\CriteriaParser;
use semknox\search\api\Client;
use semknox\search\api\Searchbody;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\FetchMode;
use Shopware\Core\PlatformRequest;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Defaults;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Framework\Api\Context\ContextSource;
use function GuzzleHttp\json_encode;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpFoundation\Request;
use Shopware\Core\Content\Product\ProductDefinition;
use PackageVersions\Versions;
use Symfony\Component\HttpFoundation\RequestStack;
use Shopware\Core\Framework\Struct\ArrayEntity;
use Composer\InstalledVersions;
class SemknoxsearchHelper
{
    const FilterPrefix = '~';
    const FilterListSeparator = '|';
    /**
     * @var Client
     */
    private $client;
    /**
     * @var string
     */
    private $environment;
    /**
     * @var LoggerInterface
     */
    private $logger;
    /**
     * 
     * @var EntityRepositoryInterface
    */ 
    private $logRepository = null;
    /**
     * @var SystemConfigService
     */
    private $systemConfigService=null;
    /**
     * @var string
     */
    private $prefix = ''; 
    /**
     * root-Dir of shopware-installation
     * @var string
     */
    private $rootDir='';
    /**
     * ID of the current saleschannel
     * Default-Saleschannel: DEFAULTS::SALES_CHANNEL
     * @var string
     */
    private $salesChannelID = '';
    private $salesChannelContext = null;
    private $productDefinition;
    private $sessionId = '';
    private $pluginVersion = '';
    /**
     * @var Connection|null
     */
    protected static $connection=null;
    /**
     * @var RequestStack
     */
    private $requestStack;
    private $searchEnabled = true;
    private $supportedControllers = array('Shopware\Storefront\Controller\SearchController::search', 'Shopware\Storefront\Controller\SearchController::pagelet', 'Shopware\Storefront\Controller\SearchController::suggest', 'Shopware\Storefront\Controller\SearchController::ajax', 'Shopware\Storefront\Controller\SearchController::filter', 'siteSearchCMSController');
    private $supportedControllersInListing = array('Shopware\Storefront\Controller\NavigationController::index', 'Shopware\Storefront\Controller\CmsController::category');
    private $langTrans = []; 
    private $langTransX = []; 
    private $mainConfigVars = null; 
    private $shopwareConfig = null; 
    private $outputInt = null; 
    /**
     * 
      public $calculator;
    */
    public $logDir='';
    public function __construct(
        string $environment,
        Client $client,
        LoggerInterface $logger,
        SystemConfigDefinition $SystemConfigDefinition,
        CriteriaParser $parser,
        ProductDefinition $pd,
        string $rootDir,
        RequestStack $requestStack,
        SystemConfigService $systemConfigService
    ) {
        $this->requestStack = $requestStack;
        $this->client = $client;
        $this->environment = $environment;
        $this->parser = $parser;
        $this->logger = $logger;
        $this->productDefinition = $pd;
        $this->rootDir = $rootDir;
        $this->logDir = $this->add_ending_slash($rootDir).'semknox';
        $this->systemConfigService = $systemConfigService;
        $this->getConnection();
        $this->getSemknoxDBConfig();
        $this->getShopwareConfig();
    }
    public function logOrThrowException(\Throwable $exception): bool
    {
        if ($this->environment !== 'prod') {
            throw new \RuntimeException($exception->getMessage());
        }
        $this->logger->error($exception->getMessage());
        return false;
    }
    public function setLogRepository(EntityRepositoryInterface $logRepo) : void 
    {
        $this->logRepository = $logRepo;
    }
    public function setOutputInterface(OutputInterface $oi)
    {
      $this->outputInt = $oi;  
    }
    /**
     * logging considering logtypes to internal logs, stdout and/or DB
     * @param int $logType  = 0 -> only shopware-logging = 10 -> enable output-logging = 100->DB-logging
     * @param string $entryName
     * @param array $additionalData
     * @param int $doShow
     * @param int $logLevel
     * @param string $logTitle
     */
    public function logData(int $logType, string $entryName, array $additionalData = [], int $logLevel = 100, string $logTitle='') : void
    {
        if ($logType > 1) {
            switch ($logLevel) {
                case 800    :  $this->logger->emergency(trim('semknox.'.$entryName." (".$logLevel.") ".$logTitle));break;
                case 700    :  $this->logger->alert(trim('semknox.'.$entryName." (".$logLevel.") ".$logTitle));break;
                case 600    :  $this->logger->critical(trim('semknox.'.$entryName." (".$logLevel.") ".$logTitle));break;
                case 500    :  $this->logger->error(trim('semknox.'.$entryName." (".$logLevel.") ".$logTitle));break;
                case 400    :  $this->logger->warning(trim('semknox.'.$entryName." (".$logLevel.") ".$logTitle));break;
                case 300    :  $this->logger->notice(trim('semknox.'.$entryName." (".$logLevel.") ".$logTitle));break;
                case 200    :  $this->logger->info(trim('semknox.'.$entryName." (".$logLevel.") ".$logTitle));break;
                default        :  $this->logger->debug(trim('semknox.'.$entryName." (".$logLevel.") ".$logTitle));
            }
        }
        if ($logType > 0) {
            $outT=date('Y-m-d H:i:s').' :: '.$entryName;
            if (is_null($this->outputInt)) {
                echo "\n$outT";
            } else {
                $this->outputInt->writeln($outT);
            }
        }
        if ( ($this->logRepository === null) || ($logType < 100) )  { return; }
        if ($logTitle == '') { $logTitle = $entryName; }
        $this->logRepository->create(
            [
                ['logType'=>$entryName, 'logStatus'=>$logLevel, 'logTitle'=>$logTitle, 'logDescr'=>json_encode($additionalData)]
            ], \Shopware\Core\Framework\Context::createDefaultContext());
    }
    /** should be replaced by logdata! deprecated!
     * logged in die interne DB von Shopware
     * @param string $entryName
     * @param array $additionalData
     */
    public function log2ShopDB(string $entryName, array $additionalData = [], int $doShow=0, int $logLevel = 100, string $logTitle='') : void
    {   
        $this->logger->debug(trim('semknox.'.$entryName." (".$logLevel.") ".$logTitle));
        if ($doShow) {
            if (is_null($this->outputInt)) {
                echo "\n$entryName";
            }
        }
        if (!is_null($this->outputInt)) {
            $this->outputInt->writeln($entryName);
        }
        if ($this->logRepository === null) { return; }
        if ($logTitle == '') { $logTitle = $entryName; }
        $this->logRepository->create(
            [ 
                ['logType'=>$entryName, 'logStatus'=>$logLevel, 'logTitle'=>$logTitle, 'logDescr'=>json_encode($additionalData)] 
            ], \Shopware\Core\Framework\Context::createDefaultContext());
        /* loggt leider nicht im scheduler...
        $this->monologger->addRecord(
            $logLevel,
            'semknox.search.'.$entryName,
            [
                'source' => 'semknox',
                'environment' => $this->environment ,
                'additionalData' => $additionalData,
            ]
            );
        */            
    }
    public function getIndexName(EntityDefinition $definition, string $languageId): string
    {
        return $this->prefix . '_' . $definition->getEntityName() . '_' . $languageId;
    }
    public function allowIndexing(): bool
    {
        if (!$this->indexingEnabled) {
            return false;
        }
        if (!$this->client->ping()) {
            return $this->logOrThrowException(new ServerNotAvailableException());
        }
        return true;
    }
    public  function getLanguageCodeByID($id) : string 
    {
        if ( (is_array($this->langTrans)) && (isset($this->langTrans[$id])) ) {
            return $this->langTrans[$id];
        }
        $ret='';
        $this->getConnection();
        $q = "SELECT lang.name, loc.code FROM language lang, locale loc WHERE loc.id = lang.locale_id AND lang.id = 0x$id ";
        $ta = self::$connection->executeQuery($q)->fetchAll(FetchMode::ASSOCIATIVE);
        foreach ($ta as $it) {
            $ret = $it['code'];
        }
        if (!empty($ret)) {
            $this->langTrans[$id] = $ret;
        }
        return $ret;
    }
    public  function getLanguageIDByCode($code) : string
    {
        if ( (is_array($this->langTransX)) && (isset($this->langTransX[$code])) ) {
            return $this->langTransX[$code];
        }
        $ret='';
        $this->getConnection();
        $q = "SELECT lang.name, lang.id FROM language lang, locale loc WHERE loc.id = lang.locale_id AND loc.code = '$code' ";
        $ta = self::$connection->executeQuery($q)->fetchAll(FetchMode::ASSOCIATIVE);
        foreach ($ta as $it) {
            $id = $this->getDBConfigChannelID($it['id']);
            if (substr($id,0,2)=='0x') {
                $ret = substr($id,2,10000);                
            } else {
                $ret = $id;
            }
        }
        if (!empty($ret)) {
            $this->langTransX[$code] = $ret;
        }
        return $ret;
    }
    public function getQueryResult(String $query) : array
    {
        $ret=[];
        $this->getConnection();
        $ta = self::$connection->executeQuery($query)->fetchAll(FetchMode::ASSOCIATIVE);
        foreach ($ta as $it) {
            $ret[] = $it;
        }
        return $ret;
    }
    public function execQuery(String $query) : int
    {
        $ret=0;
        $this->getConnection();
        $ta = self::$connection->executeQuery($query);
        $ret=1;
        return $ret;
    }
    /**
     * returns the array of semknox-preferences for the whole system
     * @return array
     */
    public function getPreferences() : array {
        $ret=['semknoxUpdateCronTime' => 0, 'semknoxUpdateCronInterval' => 24, 'semknoxUpdateBlocksize' => 500, 'semknoxUpdateUseVariantMaster' => false];
        $h = $this->getMainConfigParams('00000000000000000000000000000000', '00000000000000000000000000000000');
        if ( (isset ($h['semknoxUpdateCronTime'])) && 
                  ($h['semknoxUpdateCronTime']>-1) &&
                  ($h['semknoxUpdateCronTime']<24) ) {
                      $ret['semknoxUpdateCronTime'] = intval($h['semknoxUpdateCronTime']);  
        }
        if ( (isset ($h['semknoxUpdateCronInterval'])) &&
                ($h['semknoxUpdateCronInterval']>2) &&
                ($h['semknoxUpdateCronInterval']<25) ) {
                    $ret['semknoxUpdateCronInterval'] = intval($h['semknoxUpdateCronInterval']);
        }
        if ( (isset ($h['semknoxUpdateBlocksize'])) &&
                ($h['semknoxUpdateBlocksize'] > 20) &&
                ($h['semknoxUpdateBlocksize'] < 200000) ) {
                    $ret['semknoxUpdateBlocksize'] = intval($h['semknoxUpdateBlocksize']);
        }
        if (isset ($h['semknoxUpdateUseVariantMaster'])) {
            $ret['semknoxUpdateUseVariantMaster'] = $h['semknoxUpdateUseVariantMaster'];
        }
        $ret['semknoxUpdateCronTimeList']=[];
        $i=$ret['semknoxUpdateCronTime'];
        do {
            $start = $i;
            $i -= $ret['semknoxUpdateCronInterval'];
        } while ($i > -1);
        do {
            $ret['semknoxUpdateCronTimeList'][]=$start;
            $start += $ret['semknoxUpdateCronInterval'];
        } while ($start < 24);
        return $ret;
    }
    /**
     * returns semknox-configuration as array by salesChannel and language if correct else null
     * @param string $salesChannelID
     * @param string $domainID
     * @param number $doUpdate
     * @return NULL|NULL|mixed
     */
    public function allowSalesChannel($scID, $domainID, $doUpdate=0) {
        $ret = null;
        $ret = $this->getMainConfigParams($scID, $domainID);
        if (is_null($ret)) {
            return null;
        }
        if  (!$ret['valid']) {
            return null;
        }
        if ($doUpdate) {
            if ( (!$ret['semknoxActivate']) && (!$ret['semknoxActivateUpdate']) ) {
                $ret=null;
            }
        } else {
            if (!$ret['semknoxActivate']) {
                $ret=null;
            }
        }
        return $ret;
    }
    public function allowSearchByContext(EntityDefinition $definition, Context $context, string $controller=''): ?array
    {
        $scId = $this->getSalesChannelFromSCContext($context);
        if ($scId=='') { return null; }
        $domainId = $this->getDomainFromSCContext($context);
        return $this->allowSearch($definition, $context, $scId, $domainId, $controller);
    }
    /**
     * function checks, if the use of sitesearch is allowed for saleschannel, language and controller
     * @param SalesChannelContext $context
     * @param Request $request
     * @return boolean
     */
    public function useSiteSearch(SalesChannelContext $context, Request $request, ?EntityDefinition $definition=null) {
        $this->setSessionID($request);
        $scID=$this->getSalesChannelFromSCContext($context);
        $domainID = $this->getDomainFromSCContext($context);
        $contr=$request->attributes->get('_controller');
        if (is_null($definition)) { $definition = $this->productDefinition; }
        $mainConfig=$this->allowSearch($definition, $context->getContext(), $scID, $domainID, $contr);
        if ($mainConfig===null) {
            return false;
        }
        return true;
    }
    public function useSiteSearchInListing(SalesChannelContext $context, Request $request, ?EntityDefinition $definition=null) {
        $this->setSessionID($request);
        $scID=$this->getSalesChannelFromSCContext($context);
        $domainID = $this->getDomainFromSCContext($context);
        $contr=$request->attributes->get('_controller');
        if (is_null($definition)) { $definition = $this->productDefinition; }
        if (is_null($contr)) { return false; }
        if (is_null($domainID)) { return false; }
        if (is_null($scID)) { return false; }
        $mainConfig=$this->allowCatListing($definition, $context->getContext(), $scID, $domainID, $contr);
        if ($mainConfig===null) {
            return false;
        }
        return true;
    }
    /**
     * Validates if it is allowed do execute the search request over semknoxsearch
     * used in ProductSearchbuilder und SemknoxsearchEntityServer
     */
    public function allowSearch(EntityDefinition $definition, Context $context, string $salesChannelID='', string $domainID='', string $controller=''): ?array
    {
        $ret = null;
        if (!$this->searchEnabled) {
            return $ret;
        }
        $ret = $this->getMainConfigParams($salesChannelID, $domainID);
        if (is_null($ret)) {
            return $ret;
        }
        if ( (!$ret['valid']) || (!$ret['semknoxActivate']) ) {
            $ret=null;
        }
        if (!$this->isSupported($definition, $controller)) {
            $ret=null;
        }
        return $ret;
        return $this->logOrThrowException(new NoIndexedDocumentsException($definition->getEntityName()));
    }
    /**
     * Validates if it is allowed do execute the cat-listing request over sitesearch360
     */
    public function allowCatListing(EntityDefinition $definition, Context $context, string $salesChannelID='', string $domainID='', string $controller=''): ?array
    {
        $ret = null;
        if (!$this->searchEnabled) {
            return $ret;
        }
        $ret = $this->getMainConfigParams($salesChannelID, $domainID);
        if (is_null($ret)) {
            return $ret;
        }
        if ( (!$ret['valid']) || (!$ret['semknoxActivate']) || (!$ret['semknoxActivateCategoryListing']) ) {
            $ret=null;
        }
        if (!$this->isSupportedInListing($definition, $controller)) {
            $ret=null;
        }
        return $ret;
    }
    public function handleIds(EntityDefinition $definition, Criteria $criteria, Searchbody $search, Context $context): void
    {
        return;
    }
    public function addFilters(EntityDefinition $definition, Criteria $criteria, Searchbody $search, Context $context): void
    {
        return;
    }
    /**
     * function used to extract filters
     * @param EntityDefinition $definition
     * @param Criteria $criteria
     * @param Searchbody $search
     * @param Context $context
     */
    public function addPostFilters(EntityDefinition $definition, Criteria $criteria, Searchbody $search, Context $context): void
    {
        $postFilters = $criteria->getPostFilters();
        if (!empty($postFilters)) {
            $pr = null;
            foreach ($postFilters as $filter) {
                foreach ($filter->getFields() as $f) {
                    if ($f=='product.listingPrices') {
                        $pr = $filter;break;
                    }
                }
                if ($pr === null) { continue; }
            }
            if ($pr !== null ) {                
                $search->addSearchFilter(['type'=>'minmax', 'key'=>'price', 'value'=>null, 'minValue'=>$pr->getVars()['parameters']['gte'], 'maxValue'=>$pr->getVars()['parameters']['lte']]);
            }
        }
        $semknoxFilter = $criteria->getExtension('semknoxDataFilter');
        if ( ($semknoxFilter===null) ) {
            return;
        }
        $semknoxData = $semknoxFilter->getVars();        
        if ( (!is_array($semknoxData['data'])) || (!is_array($semknoxData['data']['filter'])) ) {
            return;
        }
        foreach ($semknoxData['data']['filter'] as $fi) {
            $filter = ['type'=>'', 'key'=>'', 'value'=>'', 'minValue'=>0, 'maxValue'=>0];
            $filter['key'] = $fi['name'];
            $filter['name'] = $fi['name'];
            $filter['value'] = $fi['value'];
            $filter['valueList'] = $fi['valueList'];
            $filter['type']=$fi['valType'];
            if (in_array(trim($fi['valType']), ['min', 'max'])) {
                $filter['type']='minmax';
                $filter['minValue'] = $fi['minValue'];                
                $filter['maxValue'] = $fi['maxValue'];
            }
            $search->addSearchFilter($filter) ;
        }
    }
    public function addTerm(Criteria $criteria, Searchbody $search, Context $context): void
    {
        $search->addTerm('');
        if (!$criteria->getTerm()) {
            return;
        }
        $term = $criteria->getTerm();
        if (trim($term)=='') { return; }
        $search->addTerm($term);        
        return;
        $reg = $this->getConfigSemknoxRegEx();
        $regRepl = $this->getConfigSemknoxRegExRepl();
        if ( ($reg!='') && ($regRepl!='') ) {
                try {
                    $term = preg_replace($reg, $regRepl , $term);
                } catch (\Throwable $e) {
                    $this->logOrThrowException($e);
                }
            }        
        $search->addTerm($term);
    }
    public function addQueries(EntityDefinition $definition, Criteria $criteria, Searchbody $search, Context $context): void
    {
        $queries = $criteria->getQueries();
        if (empty($queries)) {
            return;
        }
    }
    public function addSortings(EntityDefinition $definition, Criteria $criteria, Searchbody $search, Context $context): void
    {
        foreach ($criteria->getSorting() as $sorting) {
            /**
             * S6-Standards: direction: ASC DESC
             *                      name: _score 
             *                               product.name
             *                               product.listingPrices 
             */
            $search->addSorting(
                $this->parser->parseSorting($sorting, $definition, $context)
            );
        }
    }
    public function addAggregations(EntityDefinition $definition, Criteria $criteria, Searchbody $search, Context $context): void
    {
        return;
    }
    /**
     * Only used for unit tests because the container parameter bag is frozen and can not be changed at runtime.
     * Therefore this function can be used to test different behaviours
     *
     * @internal
     */
    public function setEnabled(bool $enabled): self
    {
        $this->searchEnabled = $enabled;
        $this->indexingEnabled = $enabled;
        return $this;
    }
    public function isSupported(EntityDefinition $definition, string $controller): bool
    {                        
        foreach ($this->supportedControllers as $k) {
            if ($k === $controller) {
                return true;
            }
        }
        return false;
    }
    public function isSupportedInListing(EntityDefinition $definition, string $controller): bool
    {
        foreach ($this->supportedControllersInListing as $k) {
            if ($k === $controller) {
                return true;
            }
        }
        return false;
    }
    /**
     * returns int-values of  bool/int/_woso_-values
     * @param bool|string $v
     * @param number $def
     * @return number|bool|string
     */
    private function getConfigSelectIntValue($v,$def=0) {
        $ret=$v;
        if (is_bool($v)) {
            $v ?  $ret=1 : $ret=0;
        } else {
            if (trim($v)=='') { $ret=$def; } else {
                if (substr($v,0,6)=='_woso_') { $v=substr($v,6); }
                if (!(ctype_digit($v))) { $ret=$def; } else { $ret=intval($v); }
            }
        }
        return $ret;
    }
    /**
     * returns semknox-api-url of config-id
     * @param int $id
     * @return string
     */
    private function getBaseURLByID($id) {
        $ret="stage-shopware.semknox.com/";
        switch ($id) {
            case 0  : 
            case 1  : $ret="https://api-shopware.sitesearch360.com/"; break;
        }
        return $ret;
    }    
    public static function getConnection(): Connection
    {
        if (!self::$connection) {
            $url = $_ENV['DATABASE_URL']
            ?? $_SERVER['DATABASE_URL']
            ?? getenv('DATABASE_URL');
            $parameters = [
                'url' => $url,
                'charset' => 'utf8mb4',
            ];
            self::$connection = DriverManager::getConnection($parameters, new Configuration());
        }
        return self::$connection;
    }
    /** 
     * returns value of a config-parameter 
     * @param string $value
     */
    private function getDBConfigValue($value) 
    {
        $value=trim($value);
        $ret=$value;
        try {
            $h=json_decode($value, true);
            if ( (is_array($h)) && (count($h)==1) && (isset($h['_value']))) {
                if (is_string($h['_value'])) { $h['_value'] = trim($h['_value']); }
                $ret=$h['_value'];
            }
        } catch (\Throwable $e) {
            $this->logOrThrowException($e);
        }
        return $ret;
    }
    /**
     * returns hex-transformed channelID 
     * @param string $channelID
     */
    private function getDBConfigChannelID($channelID)
    {
        return UUID::fromBytesToHex($channelID);
    }
    /**
     * returns hex-transformed UUID
     * @param string $id
     */
    private function getHexUUID($id)
    {
        return UUID::fromBytesToHex($id);
    }
    /**
     * returns shortened config-key
     * @param string $key
     */
    private function getDBConfigKey(string $key) 
    {
        return substr($key,21,1000);        
    }
    public function getShopwareConfigValue(string $configKey, string $scID = 'null', $def = null) {
        $ret = $def;
        if (isset($this->shopwareConfig[$configKey])) {
            if (isset($this->shopwareConfig[$configKey][$scID])) {
                $ret = $this->shopwareConfig[$configKey][$scID];
            } else {
                if (isset($this->shopwareConfig[$configKey]['null'])) {
                    $ret = $this->shopwareConfig[$configKey]['null'];
                }
            }
        }
        return $ret;
    }
    /**
     * setup Shopware-Config-Data
     * [config-key => [ salesChannel => value] 
     */
    private function getShopwareConfig() {
        if (! is_null($this->shopwareConfig)) return;
        $this->shopwareConfig=[];
        $ta = self::$connection->executeQuery('
            SELECT *
            FROM `system_config`
            WHERE `configuration_key` LIKE "core.%"
        ')->fetchAll(FetchMode::ASSOCIATIVE);
        foreach ($ta as $it) {
            $key = $it['configuration_key'];
            if ($it['sales_channel_id']) {
                $scid = $this->getHexUUID($it['sales_channel_id']);
            } else { $scid = 'null'; }
            $val = $this->getDBConfigValue($it['configuration_value']);
            if (!isset($this->shopwareConfig[$key])) { $this->shopwareConfig[$key] = []; }
            $this->shopwareConfig[$key][$scid] = $val;
        }
    }
    /**
     * selects data of plugin-configuration direct from database
     */
    private function getSemknoxDBConfig()
    {
        if (! is_null($this->mainConfigVars)) return;
        $this->mainConfigVars=[];
        $ta = self::$connection->executeQuery('
            SELECT *
            FROM `semknox_config`
            WHERE `configuration_key` LIKE "semknoxSearch%"
        ')->fetchAll(FetchMode::ASSOCIATIVE);
        $defFound=0;
        foreach ($ta as $it) {
            $k1='';$k2='';                
            if ($it['sales_channel_id']) {
                $k1=$this->getHexUUID($it['sales_channel_id']);
            }
            if ($it['domain_id']) {
                $k2=$this->getHexUUID($it['domain_id']);
            }
            if ($it['language_id']) {
                $k3=$this->getHexUUID($it['language_id']);
            }            
            if (($k1!='') && ($k2!='')) {
                $this->mainConfigVars[$k1][$k2][$this->getDBConfigKey($it['configuration_key'])] = $this->getDBConfigValue($it['configuration_value']);
                if ( ($k3!='') && (!isset($this->mainConfigVars[$k1][$k2]['lang_id'])) ) {
                    $this->mainConfigVars[$k1][$k2]['language_id'] = $k3;
                }
            }
        }
        $this->checkMainSemknoxConfig($this->mainConfigVars);
        $this->setPluginVersion();
    }
    /**
     * checking main config, sets the baseURL and valid-tag
     * @param array $config
     */
    private function checkMainSemknoxConfig(&$config) {
        foreach($config as $k1 => &$sce) {
            if ($k1 == '00000000000000000000000000000000') { continue; }
            foreach($sce as $k2 => &$lange) {
                $lange['valid']=false;$valid=true;
                $lange['semknoxBaseUrlID'] = 1;
                if ($lange['semknoxBaseUrlID']>-1) { $lange['semknoxBaseUrl'] = $this->getBaseURLByID($lange['semknoxBaseUrlID']); }
                $lange['semknoxCustomerId'] = $lange['semknoxC01CustomerId']; unset ($lange['semknoxC01CustomerId']);
                $lange['semknoxApiKey'] = $lange['semknoxC01ApiKey']; unset ($lange['semknoxC01ApiKey']);
                $lange['semknoxLang'] = $this->getLanguageCodeById($lange['language_id']);
                if (trim($lange['semknoxBaseUrl'])=='') { $valid=false; }
                if (trim($lange['semknoxCustomerId'])=='') { $valid=false; }
                if (trim($lange['semknoxApiKey'])=='') { $valid=false; }
                if (empty($lange['semknoxUpdateBlocksize'])) { $lange['semknoxUpdateBlocksize']=500; }
                if (empty($lange['semknoxActivateCategoryListing'])) { $lange['semknoxActivateCategoryListing']=false; }
                if (empty($lange['semknoxActivateSearchTemplate'])) { $lange['semknoxActivateSearchTemplate']=false; }
                if (empty($lange['semknoxActivateAutosuggest'])) { $lange['semknoxActivateAutosuggest']=false; }
                $lange['semknoxUpdateBlocksize'] = intval($lange['semknoxUpdateBlocksize']);
                if ($valid) {
                    $lange['valid'] = true;
                } else {
                    $lange['semknoxActivate'] = false;
                    $lange['semknoxActivateUpdate'] = false;
                    $lange['semknoxActivateCategoryListing'] = false;
                    $lange['semknoxActivateSearchTemplate'] = false;
                }
                unset($lange);
            }
            unset($sce);
        }        
    }
    /**
     * checking main config, sets the baseURL and valid-tag
     * @param array $config
     */
    public function checkMainConfig(&$config) {
        $config['valid']=false;$valid=true;
        if ($config['semknoxBaseUrlID']>-1) { $config['semknoxBaseUrl'] = $this->getBaseURLByID($config['semknoxBaseUrlID']); }
        if (trim($config['semknoxBaseUrl'])=='') { $valid=false; }
        if (trim($config['semknoxCustomerId'])=='') { $valid=false; }
        if (trim($config['semknoxApiKey'])=='') { $valid=false; }
        if ($valid) {
            $config['valid'] = true;
        } else {
            $config['semknoxActivate'] = false;
            $config['semknoxActivateUpdate'] = false;
            $config['semknoxActivateCategoryListing'] = false;
            $config['semknoxActivateSearchTemplate'] = false;            
        }
    }
    /**
     * returns base-config by saleschannelID and LanguageID or null if not set
     * @param string $scID
     * @param string $domainID
     */
    public function getMainConfigParams(string $scID, string $domainID) {
        $ret=null;
        if ( (empty($scID)) || (empty($domainID)) ) { return $ret; }
        if ( (is_array($this->mainConfigVars)) && (isset($this->mainConfigVars[$scID])) && (isset($this->mainConfigVars[$scID][$domainID])) ) {
            $ret=null;
            if ( (is_array($this->mainConfigVars[$scID][$domainID])) ) {
                $ret = $this->mainConfigVars[$scID][$domainID];
            }
            return $ret;
        }
        return $ret;
    }
    /**
     * returns currently used slaesChannelID
     * is useDefault = true, returns default saleschannel if there is none found
     * @param bool $useDefault
     * @return string
     */
    public function getSalesChannelID(bool $useDefault=true) {
        if (trim($this->salesChannelID)!='') {
            return $this->salesChannelID;
        } elseif ($useDefault) {
            return DEFAULTS::SALES_CHANNEL;
        }
        return '';
    }
    public function setSalesChannelID(string $scID) : void
    {
        $this->salesChannelID = $scID;
    }
    /**
     * returns ID of salesChannel from Contents
     * @param SalesChannelContext $context
     * @return string
     */
    public function getSalesChannelFromSCContext(SalesChannelContext $context) : string
    {
        $ret='';
        $sc = $context->getSalesChannel();
        if (is_object($sc)) {
            $ret=$sc->getId();
        } else {
        }
        return $ret;
    }
    /**
     * returns languageID from context
     * @param SalesChannelContext $context
     * @return string
     */
    public function getLanguageFromSCContext(SalesChannelContext $context) : string
    {
        $ret='';
        $sc = $context->getSalesChannel();
        if (is_object($sc)) {
            $ret = $sc->getLanguageId();
        } else {
        }
        return $ret;
    }
    public function setDomainToSCContextExt(SalesChannelContext $context, array $data) {
        $context->addExtension('semknoxDataDomain', new ArrayEntity(
            [
                'data' => $data
            ]));
    }
    public function getDomainFromSCContextExt(SalesChannelContext $context): string {
        $ret='';
        $domData = $context->getExtension('semknoxDataDomain');
        if ( ($domData===null) ) {
            return $ret;
        }
        $domain = $domData->getVars();
        if ( (!is_array($domain)) || (!isset($domain['data']))   ) {
            return $ret;
        }
        $data = [];
        if (isset($domain['data']['data'])) {
            if (!is_array($domain['data']['data'])) {
                return $ret;
            }
            $data=$domain['data']['data'];
        } else {
            if (isset($domain['data'])) {
                if (!is_array($domain['data'])) {
                    return $ret;
                }
                $data=$domain['data'];
            }
        }
        if (empty($data)) { return $ret; }
        if (isset($data['domainId'])) {
            $ret=$data['domainId'];
        }
        return $ret;
    }
    public function getDomainURLFromSCContext(SalesChannelContext $context) : string
    {
        $ret='';
        if (method_exists($context, 'getSalesChannel')) {
        	$sc=$context->getSalesChannel();
	        if (method_exists($sc, 'getDomains')) {
	        	$ret = $sc->getDomains()->first()->getUrl();
	        }
      	}
      	return $ret;
		}    
    /**
     * returns languageID from context
     * @param SalesChannelContext $context
     * @return string
     */
    public function getDomainFromSCContext(SalesChannelContext $context) : string
    {
        $ret='';
        if (method_exists($context, 'getDomainId')) {
            $ret = $context->getDomainId();
        } else {
            $h=$this->requestStack->getCurrentRequest()->get('sw-domain-id');            
            if ( (!is_null($h)) && (trim($h)!='') ) {
                $ret = $h;
                return $ret;
            }
            $shopDom=$this->requestStack->getCurrentRequest()->get('sw-sales-channel-absolute-base-url');
            if ( (is_null($shopDom)) || (trim($shopDom)!='') ) {
                $shopDom=$this->requestStack->getCurrentRequest()->get('sw-storefront-url');
            }
            $ret='';
            if ($shopDom) {
                $domListObj = ($context->getSalesChannel()->getDomains());
                if (is_object($domListObj)) {
                    $domList = $domListObj->getElements();
                    foreach($domList as $domId => $dom) {
                        if ($dom->getUrl() == $shopDom) {
                            $ret=$domId;
                            break;
                        }
                    }
                }
            }
        }
        return $ret;
    }
    /**
     * returns ID of salesChannel from Context
     * @param Context $context
     * @return string
     */
    private function getSalesChannelFromContext(Context $context) : string
    {
        $ret='';
        $contextSource = $context->getSource();
        if (is_object($contextSource)) {
            var_dump($contextSource);            
        } else {
        }
        return $ret;
    }
    public function getDBData(string $query, array $results, string $key='') : array
    {
        $ta = self::$connection->executeQuery($query)->fetchAll(FetchMode::ASSOCIATIVE);
        $res=array();
        foreach ($ta as $it) {
            if (count($results)==0) {
              $r=$ta;  
            } else {
                $r=[];
                foreach($results as $k) {
                    $r[$k] = $it[$k];
                }
            }
            if ($key=='') { $res[]=$r; } else { $res[$it[$key]]=$r; }
        }
        return $res;
    }
    /**
     * @return null|mixed
     */
    public function extractArgument(array &$params, string $arg)
    {
        if (array_key_exists($arg, $params) === true) {
            $value = $params[$arg];
            $value = is_object($value) ? (array) $value : $value;
            unset($params[$arg]);
            return $value;
        } else {
            return null;
        }
    }
    /**
     * returns the shopware-version as a string if not composer2-InstalledVersions is used (pre shopware 6.3)
     * @return string
     */
    public static function getShopwareVersion_compo1(): string {
        $versions = Versions::VERSIONS;
        if (isset($versions['shopware/core'])) {
            $shopwareVersion = Versions::getVersion('shopware/core');
        } else {
            $shopwareVersion = Versions::getVersion('shopware/platform');
        }
        $shopwareVersion = ltrim($shopwareVersion, 'v');
        $shopwareVersion = substr($shopwareVersion, 0, strpos($shopwareVersion, '@'));
        return $shopwareVersion;
    }
    /**
     * returns the shopware-version as a string
     * @return string
     */
    public static function getShopwareVersion(): string {
        if (class_exists('Composer\InstalledVersions', false) === false) {
            return self::getShopwareVersion_compo1();
        }
        if (InstalledVersions::isInstalled('shopware/core')) {
            $shopwareVersion = InstalledVersions::getVersion('shopware/core');
        } else {
            $shopwareVersion = InstalledVersions::getVersion('shopware/platform');
        }
        $shopwareVersion = ltrim($shopwareVersion, 'v');
        return $shopwareVersion;
    }
    /**
     * compare $version with shopware-version.
     * return true, if compare (<>=)  is correct, false else
     * @param string $version
     * @param string $compare
     * @return bool
     */
     public static function shopwareVersionCompare(string $version, string $compare): bool
     {
         $shopwareVersion = self::getShopwareVersion();
         return version_compare($shopwareVersion, $version, $compare);
     }
    private function setSessionID($request) {
        $session = $request->hasSession() ? $request->getSession() : null;
        if (!is_null($session)) {
            $this->sessionId = $session->get('sessionId') ;
        }
    }
    public function getCurrentSessionId() : string {
        $ret='';
        return $ret;
    }
    private function setPluginVersion() {
        $res=$this->getQueryResult("SELECT * FROM plugin WHERE name='semknoxSearch'");
        if (count($res)) {
            $this->pluginVersion = $res[0]['version'];
        }
    }
    public function getHeaderInfoData() : array {
        $ip = '';
        if (!empty($_SERVER['REMOTE_ADDR'])) { $ip = $_SERVER['REMOTE_ADDR']; }
        $ret= [
            'shopsys' => 'SHOPWARE',
            'shopsysver' => $this->getShopwareVersion(),
            'clientip' => $ip,
            'sessionid'=>$this->sessionId,
            'extver' => $this->pluginVersion
        ];
        return $ret;
    }
    public function add_ending_slash(string $path) : string
    {
        $slash_type = (strpos($path, '\\')===0) ? 'win' : 'unix';
        $last_char = substr($path, strlen($path)-1, 1);
        if ($last_char != '/' and $last_char != '\\') {
            $path .= ($slash_type == 'win') ? '\\' : '/';
        }
        return $path;
    }
    /**
     * returns time of last update from db-semknox-logs.
     * no update running - return 0
     * if last entry = update.finished, return 0
     */
    public function getUpdateRunning() : Int
    {
        $lastentries = $this->getQueryResult("SELECT logtype, status, created_at from semknox_logs WHERE logtype like 'update.%' order by created_at desc LIMIT 3");
        $ret=0;
        foreach ($lastentries as $ent) {
            if ($ent['logtype']!='update.finished') {
                $ret = strtotime($ent['created_at']);
            }
            break;
        }
        return $ret;
    }
    /**
     * returns log-entry of running sitesearch-update-process from semknox-log-db.
     * if there is none running, return []
     */
    public function getLastUpdateStart() : array
    {
        $lastentries = $this->getQueryResult("SELECT logtype, status, logdescr, created_at from semknox_logs WHERE logtype like 'update.start' order by created_at desc LIMIT 3");
        $ret=[];
        foreach ($lastentries as $ent) {
            if (!empty($ent['logdescr'])) {
                $ret = json_decode($ent['logdescr'], true);
                $ret['time'] = strtotime($ent['created_at']);
            }
            break;
        }
        return $ret;
    }
    /**
     * returns log-entry of whole process of sitesearch-update from semknox-log-db.
     * if there is none running, return []
     */
    public function getLastUpdateProcessStart(?int $minCreate=0) : array
    {
        if ($minCreate) {
            $lastentries = $this->getQueryResult("SELECT logtype, status, logdescr, created_at from semknox_logs WHERE logtype like 'update.process.start' AND created_at < '".date("Y-m-d H:i:s.u", $minCreate)."' order by created_at desc LIMIT 3");
        } else {
            $lastentries = $this->getQueryResult("SELECT logtype, status, logdescr, created_at from semknox_logs WHERE logtype like 'update.process.start' order by created_at desc LIMIT 3");
        }
        $ret=[];
        foreach ($lastentries as $ent) {
            $ret['time'] = strtotime($ent['created_at']);
            break;
        }
        return $ret;
    }
    /**
     * returns log-entry of running sitesearch-update-process from semknox-log-db.
     * if there is none running, return []
     */
    public function getLastUpdateFinished() : array
    {
        $lastentries = $this->getQueryResult("SELECT logtype, status, logdescr, created_at from semknox_logs WHERE logtype like 'update.finished' order by created_at desc LIMIT 3");
        $ret=[];
        foreach ($lastentries as $ent) {
            if (!empty($ent['logdescr'])) {
                $ret = json_decode($ent['logdescr'], true);
                $ret['time'] = strtotime($ent['created_at']);
            }
            break;
        }
        return $ret;
    }
    /**
     * returns a subpath of the current log-dir
     * doCreate = 1 creates the new path incl. subdirs, append / to path to create last dir too!
     * @param string $subpath
     * @param int $doCreate
     * @return string
     */
    public function getLogDirSubPath(string $subpath, int $doCreate=0) : string 
    {
        $ret =  $this->add_ending_slash($this->logDir).$subpath;
        if ($doCreate) {
            $p = dirname($ret);
            if (!is_dir($p)) {
                mkdir($p,0777, true);
            }
        }
        return $ret;
    }
    public function getLimit(Request $request, SalesChannelContext $context): int
    {
        $limit = $request->query->getInt('limit', 0);
        if ($request->isMethod(Request::METHOD_POST)) {
            $limit = $request->request->getInt('limit', $limit);
        }
        $limit = 0;
        $limit = $limit > 0 ? $limit : $this->systemConfigService->getInt('core.listing.productsPerPage', $context->getSalesChannel()->getId());
        return $limit <= 0 ? 24 : $limit;
    }
    public function getPage(Request $request): int
    {
        $page = $request->query->getInt('p', 1);
        if ($request->isMethod(Request::METHOD_POST)) {
            $page = $request->request->getInt('p', $page);
        }
        return $page <= 0 ? 1 : $page;
    }
    /**
     * add const filterPrefix to String
     * @param string $str
     * @return string
     */
    public function addFilterPrefix(string $str) : string {
        if (is_null($str)) return $str;
        return self::FilterPrefix.$str;
    }
    /**
     * returns List of filter-properties
     * @param string $queryString
     * @return array
     */
    public function getFilterPropertiesList(string $queryString) : array {
        return explode(self::FilterListSeparator, $queryString);
    }
    /**
     * returns array of feature [1] = value [2]
     * @param string $queryString
     * @return array
     */
    public function getFilterPropertiesEntity(string $queryString) : array {
        return explode(self::FilterPrefix, $queryString);
    }
}
