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
    const blockMaxTime = 300; 
    const blockMaxRetry = 10; 
    const maxLogDays = 30; 
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
    private $systemConfigService = null;
    /**
     * @var string
     */
    private $prefix = '';
    /**
     * root-Dir of shopware-installation
     * @var string
     */
    private $rootDir = '';
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
    protected static $connection = null;
    /**
     * @var RequestStack
     */
    private $requestStack;
    private $searchEnabled = true;
    private $supportedControllers = array(
        'Shopware\Storefront\Controller\SearchController::search',
        'Shopware\Storefront\Controller\SearchController::pagelet',
        'Shopware\Storefront\Controller\SearchController::suggest',
        'Shopware\Storefront\Controller\SearchController::ajax',
        'Shopware\Storefront\Controller\SearchController::filter',
        'siteSearchCMSController'
    );
    private $supportedControllersInListing = array(
        'Shopware\Storefront\Controller\NavigationController::index',
        'Shopware\Storefront\Controller\CmsController::category'
    );
    private $langTrans = []; 
    private $langTransX = []; 
    private $mainConfigVars = null; 
    private $shopwareConfig = null; 
    private $outputInt = null; 
    public $serverTimeZone = '';
    /**
     * 
     * public $calculator;
     */
    public $logDir = '';
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
    )
    {
        $this->setServerTimeZone();
        $this->requestStack = $requestStack;
        $this->client = $client;
        $this->environment = $environment;
        $this->parser = $parser;
        $this->logger = $logger;
        $this->productDefinition = $pd;
        $this->rootDir = $rootDir;
        $this->logDir = $this->add_ending_slash($rootDir) . 'semknox';
        $this->systemConfigService = $systemConfigService;
        $this->getConnection();
        $this->getSemknoxDBConfig();
        $this->getShopwareConfig();
    }
    private function setServerTimeZone() {
        try {
            $timezone='';
            if (isset($_SERVER['TZ'])) {
                $timezone = $_SERVER['TZ'];
            }
            if ((empty($timezone)) && (file_exists('/etc/timezone'))) {
                $timezone = file_get_contents('/etc/timezone');
            }
            $timezone = trim($timezone);
            $zoneList = timezone_identifiers_list(); # list of (all) valid timezones
            if (in_array($timezone, $zoneList)) {
                $this->serverTimeZone = $timezone;
            }
        } catch (\Throwable $e) {
            $this->serverTimeZone='';
        }
    }
    public function getLocalTimeTC($time = 0, $format='') {
        if ($time == 0) {
            $time = time();
        }
        if ($this->serverTimeZone == '')  {
            if ($format != '') {
                return date($format, $time);
            } else {
                return time();
            }
        }
        $date = \DateTime::createFromFormat('Y-m-d H:i:s', date('Y-m-d H:i:s', $time) );
        $date->setTimeZone(new \DateTimeZone($this->serverTimeZone));
        if ($format != '' ){
            return $date->format($format);
        }
        $d = $date->format('Y-m-d H:i:s');
        return strtotime($d);
    }
    /**
     * returns the mktime according parameter and using localTimeTC
     * @param int|null $hour
     * @param int|null $minute
     * @param int|null $second
     * @param int|null $month
     * @param int|null $day
     * @param int|null $year
     * @return int
     */
    public function getLocalTimeTC_mkTime(?int $hour = null, ?int $minute = null, ?int $second = null, ?int $month = null, ?int $day = null, ?int $year = null) : int
    {
        if (is_null($hour)) { $hour = intval($this->getLocalTimeTC(0,'h')); }
        if (is_null($minute)) { $minute = intval($this->getLocalTimeTC(0,'i')); }
        if (is_null($second)) { $second = intval($this->getLocalTimeTC(0,'s')); }
        if (is_null($month)) { $month = intval($this->getLocalTimeTC(0,'m')); }
        if (is_null($day)) { $day = intval($this->getLocalTimeTC(0,'d')); }
        if (is_null($year)) { $year = intval($this->getLocalTimeTC(0,'Y')); }
        $ret = mktime($hour, $minute, $second, $month , $day , $year );
        return $this->getLocalTimeTC($ret);
    }
    public function logOrThrowException(\Throwable $exception): bool
    {
        if ($this->environment !== 'prod') {
            throw new \RuntimeException($exception->getMessage());
        }
        $this->logger->error($exception->getMessage());
        return false;
    }
    public function setLogRepository(EntityRepositoryInterface $logRepo): void
    {
        $this->logRepository = $logRepo;
    }
    public function setOutputInterface(OutputInterface $oi)
    {
        $this->outputInt = $oi;
    }
    /**
     * logging considering logtypes to internal logs, stdout and/or DB
     * @param int $logType = 0 -> only shopware-logging = 10 -> enable output-logging = 100->DB-logging
     * @param string $entryName
     * @param array $additionalData
     * @param int $doShow
     * @param int $logLevel
     * @param string $logTitle
     */
    public function logData(
        int $logType,
        string $entryName,
        array $additionalData = [],
        int $logLevel = 100,
        string $logTitle = ''
    ): void {
        if ($logType > 1) {
            switch ($logLevel) {
                case 800    :
                    $this->logger->emergency(trim('semknox.' . $entryName . " (" . $logLevel . ") " . $logTitle));
                    break;
                case 700    :
                    $this->logger->alert(trim('semknox.' . $entryName . " (" . $logLevel . ") " . $logTitle));
                    break;
                case 600    :
                    $this->logger->critical(trim('semknox.' . $entryName . " (" . $logLevel . ") " . $logTitle));
                    break;
                case 500    :
                    $this->logger->error(trim('semknox.' . $entryName . " (" . $logLevel . ") " . $logTitle));
                    break;
                case 400    :
                    $this->logger->warning(trim('semknox.' . $entryName . " (" . $logLevel . ") " . $logTitle));
                    break;
                case 300    :
                    $this->logger->notice(trim('semknox.' . $entryName . " (" . $logLevel . ") " . $logTitle));
                    break;
                case 200    :
                    $this->logger->info(trim('semknox.' . $entryName . " (" . $logLevel . ") " . $logTitle));
                    break;
                default        :
                    $this->logger->debug(trim('semknox.' . $entryName . " (" . $logLevel . ") " . $logTitle));
            }
        }
        if ($logType > 0) {
            $outT = $this->getLocalTimeTC( time(), 'Y-m-d H:i:s') . '::' .getmypid(). ' :: ' . $entryName;
            if (is_null($this->outputInt)) {
                echo "\n$outT";
            } else {
                $this->outputInt->writeln($outT);
            }
        }
        if (($this->logRepository === null) || ($logType < 100)) {
            return;
        }
        if ($logTitle == '') {
            $logTitle = $entryName;
        }
        $additionalData['pid'] = getmypid();
        $this->logRepository->create(
            [
                [
                    'logType' => $entryName,
                    'logStatus' => $logLevel,
                    'logTitle' => $logTitle,
                    'logDescr' => json_encode($additionalData)
                ]
            ], \Shopware\Core\Framework\Context::createDefaultContext());
    }
    /** should be replaced by logdata! deprecated!
     * logged in die interne DB von Shopware
     * @param string $entryName
     * @param array $additionalData
     */
    public function log2ShopDB(
        string $entryName,
        array $additionalData = [],
        int $doShow = 0,
        int $logLevel = 100,
        string $logTitle = ''
    ): void {
        $this->logger->debug(trim('semknox.' . $entryName . " (" . $logLevel . ") " . $logTitle));
        if ($doShow) {
            if (is_null($this->outputInt)) {
                echo "\n$entryName";
            }
        }
        if (!is_null($this->outputInt)) {
            $this->outputInt->writeln($entryName);
        }
        if ($this->logRepository === null) {
            return;
        }
        if ($logTitle == '') {
            $logTitle = $entryName;
        }
        $this->logRepository->create(
            [
                [
                    'logType' => $entryName,
                    'logStatus' => $logLevel,
                    'logTitle' => $logTitle,
                    'logDescr' => json_encode($additionalData)
                ]
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
    public function getLanguageCodeByID($id): string
    {
        if ((is_array($this->langTrans)) && (isset($this->langTrans[$id]))) {
            return $this->langTrans[$id];
        }
        $ret = '';
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
    public function getLanguageIDByCode($code): string
    {
        if ((is_array($this->langTransX)) && (isset($this->langTransX[$code]))) {
            return $this->langTransX[$code];
        }
        $ret = '';
        $this->getConnection();
        $q = "SELECT lang.name, lang.id FROM language lang, locale loc WHERE loc.id = lang.locale_id AND loc.code = '$code' ";
        $ta = self::$connection->executeQuery($q)->fetchAll(FetchMode::ASSOCIATIVE);
        foreach ($ta as $it) {
            $id = $this->getDBConfigChannelID($it['id']);
            if (substr($id, 0, 2) == '0x') {
                $ret = substr($id, 2, 10000);
            } else {
                $ret = $id;
            }
        }
        if (!empty($ret)) {
            $this->langTransX[$code] = $ret;
        }
        return $ret;
    }
    public function getQueryResult(string $query): array
    {
        $ret = [];
        $this->getConnection();
        $ta = self::$connection->executeQuery($query)->fetchAll(FetchMode::ASSOCIATIVE);
        foreach ($ta as $it) {
            $ret[] = $it;
        }
        return $ret;
    }
    public function execQuery(string $query): int
    {
        $ret = 0;
        $this->getConnection();
        $ta = self::$connection->executeQuery($query);
        $ret = 1;
        return $ret;
    }
    /**
     * returns the array of semknox-preferences for the whole system
     * @return array
     */
    public function getPreferences(): array
    {
        $ret = [
            'semknoxUpdateCronTime' => 0,
            'semknoxUpdateCronInterval' => 24,
            'semknoxUpdateBlocksize' => 500,
            'semknoxUpdateUseVariantMaster' => false,
            'semknoxUpdateUploadContent' => true,
            'semknoxRedirectOn1' => false,
            'semknoxChangeMediaDomain' => true
        ];
        $h = $this->getMainConfigParams('00000000000000000000000000000000', '00000000000000000000000000000000');
        if ((isset ($h['semknoxUpdateCronTime'])) &&
            ($h['semknoxUpdateCronTime'] > -1) &&
            ($h['semknoxUpdateCronTime'] < 24)) {
            $ret['semknoxUpdateCronTime'] = intval($h['semknoxUpdateCronTime']);
        }
        if ((isset ($h['semknoxUpdateCronInterval'])) &&
            ($h['semknoxUpdateCronInterval'] > 2) &&
            ($h['semknoxUpdateCronInterval'] < 25)) {
            $ret['semknoxUpdateCronInterval'] = intval($h['semknoxUpdateCronInterval']);
        }
        if ((isset ($h['semknoxUpdateBlocksize'])) &&
            ($h['semknoxUpdateBlocksize'] > 20) &&
            ($h['semknoxUpdateBlocksize'] < 200000)) {
            $ret['semknoxUpdateBlocksize'] = intval($h['semknoxUpdateBlocksize']);
        }
        if (isset ($h['semknoxUpdateUseVariantMaster'])) {
            $ret['semknoxUpdateUseVariantMaster'] = $h['semknoxUpdateUseVariantMaster'];
        }
        if (isset ($h['semknoxUpdateUploadContent'])) {
            $ret['semknoxUpdateUploadContent'] = $h['semknoxUpdateUploadContent'];
        }
        if (isset ($h['semknoxRedirectOn1'])) {
            $ret['semknoxRedirectOn1'] = $h['semknoxRedirectOn1'];
        }
        if (isset ($h['semknoxChangeMediaDomain'])) {
            $ret['semknoxChangeMediaDomain'] = $h['semknoxChangeMediaDomain'];
        }
        $ret['semknoxUpdateCronTimeList'] = [];
        $i = $ret['semknoxUpdateCronTime'];
        do {
            $start = $i;
            $i -= $ret['semknoxUpdateCronInterval'];
        } while ($i > -1);
        do {
            $ret['semknoxUpdateCronTimeList'][] = $start;
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
    public function allowSalesChannel($scID, $domainID, $doUpdate = 0)
    {
        $ret = null;
        $ret = $this->getMainConfigParams($scID, $domainID);
        if (is_null($ret)) {
            return null;
        }
        if (!$ret['valid']) {
            return null;
        }
        if ($doUpdate) {
            if ((!$ret['semknoxActivate']) || (!$ret['semknoxActivateUpdate'])) {
                $ret = null;
            }
        } else {
            if (!$ret['semknoxActivate']) {
                $ret = null;
            }
        }
        return $ret;
    }
    public function allowSearchByContext(
        EntityDefinition $definition,
        Context $context,
        string $controller = ''
    ): ?array {
        $scId = $this->getSalesChannelFromSCContext($context);
        if ($scId == '') {
            return null;
        }
        $domainId = $this->getDomainFromSCContext($context);
        return $this->allowSearch($definition, $context, $scId, $domainId, $controller);
    }
    /**
     * function checks, if the use of sitesearch is allowed for saleschannel, language and controller
     * @param SalesChannelContext $context
     * @param Request $request
     * @return boolean
     */
    public function useSiteSearch(SalesChannelContext $context, Request $request, ?EntityDefinition $definition = null)
    {
        $this->setSessionID($request);
        $scID = $this->getSalesChannelFromSCContext($context);
        $domainID = $this->getDomainFromSCContext($context);
        $contr = $request->attributes->get('_controller');
        if (is_null($definition)) {
            $definition = $this->productDefinition;
        }
        $mainConfig = $this->allowSearch($definition, $context->getContext(), $scID, $domainID, $contr);
        if ($mainConfig === null) {
            return false;
        }
        return true;
    }
    public function useSiteSearchInListing(
        SalesChannelContext $context,
        Request $request,
        ?EntityDefinition $definition = null
    ) {
        $this->setSessionID($request);
        $scID = $this->getSalesChannelFromSCContext($context);
        $domainID = $this->getDomainFromSCContext($context);
        $contr = $request->attributes->get('_controller');
        if (is_null($definition)) {
            $definition = $this->productDefinition;
        }
        if (is_null($contr)) {
            return false;
        }
        if (is_null($domainID)) {
            return false;
        }
        if (is_null($scID)) {
            return false;
        }
        $mainConfig = $this->allowCatListing($definition, $context->getContext(), $scID, $domainID, $contr);
        if ($mainConfig === null) {
            return false;
        }
        return true;
    }
    /**
     * Validates if it is allowed do execute the search request over semknoxsearch
     * used in ProductSearchbuilder und SemknoxsearchEntityServer
     */
    public function allowSearch(
        EntityDefinition $definition,
        Context $context,
        string $salesChannelID = '',
        string $domainID = '',
        string $controller = ''
    ): ?array {
        $ret = null;
        if (!$this->searchEnabled) {
            return $ret;
        }
        $ret = $this->getMainConfigParams($salesChannelID, $domainID);
        if (is_null($ret)) {
            return $ret;
        }
        if ((!$ret['valid']) || (!$ret['semknoxActivate'])) {
            $ret = null;
        }
        if (!$this->isSupported($definition, $controller)) {
            $ret = null;
        }
        return $ret;
        return $this->logOrThrowException(new NoIndexedDocumentsException($definition->getEntityName()));
    }
    /**
     * Validates if it is allowed do execute the cat-listing request over sitesearch360
     */
    public function allowCatListing(
        EntityDefinition $definition,
        Context $context,
        string $salesChannelID = '',
        string $domainID = '',
        string $controller = ''
    ): ?array {
        $ret = null;
        if (!$this->searchEnabled) {
            return $ret;
        }
        $ret = $this->getMainConfigParams($salesChannelID, $domainID);
        if (is_null($ret)) {
            return $ret;
        }
        if ((!$ret['valid']) || (!$ret['semknoxActivate']) || (!$ret['semknoxActivateCategoryListing'])) {
            $ret = null;
        }
        if (!$this->isSupportedInListing($definition, $controller)) {
            $ret = null;
        }
        return $ret;
    }
    public function handleIds(
        EntityDefinition $definition,
        Criteria $criteria,
        Searchbody $search,
        Context $context
    ): void {
        return;
    }
    public function addFilters(
        EntityDefinition $definition,
        Criteria $criteria,
        Searchbody $search,
        Context $context
    ): void {
        return;
    }
    /**
     * function used to extract filters
     * @param EntityDefinition $definition
     * @param Criteria $criteria
     * @param Searchbody $search
     * @param Context $context
     */
    public function addPostFilters(
        EntityDefinition $definition,
        Criteria $criteria,
        Searchbody $search,
        Context $context
    ): void {
        $postFilters = $criteria->getPostFilters();
        if (!empty($postFilters)) {
            $pr = null;
            foreach ($postFilters as $filter) {
                foreach ($filter->getFields() as $f) {
                    if ($f == 'product.listingPrices') {
                        $pr = $filter;
                        break;
                    }
                }
                if ($pr === null) {
                    continue;
                }
            }
            if ($pr !== null) {
                $search->addSearchFilter([
                    'type' => 'minmax',
                    'key' => 'price',
                    'value' => null,
                    'minValue' => $pr->getVars()['parameters']['gte'],
                    'maxValue' => $pr->getVars()['parameters']['lte']
                ]);
            }
        }
        $semknoxFilter = $criteria->getExtension('semknoxDataFilter');
        if (($semknoxFilter === null)) {
            return;
        }
        $semknoxData = $semknoxFilter->getVars();
        if ((!is_array($semknoxData['data'])) || (!is_array($semknoxData['data']['filter']))) {
            return;
        }
        foreach ($semknoxData['data']['filter'] as $fi) {
            $filter = ['type' => '', 'key' => '', 'value' => '', 'minValue' => 0, 'maxValue' => 0];
            $filter['key'] = $fi['name'];
            $filter['name'] = $fi['name'];
            $filter['value'] = $fi['value'];
            $filter['valueList'] = $fi['valueList'];
            $filter['type'] = $fi['valType'];
            if (in_array(trim($fi['valType']), ['min', 'max'])) {
                $filter['type'] = 'minmax';
                $filter['minValue'] = $fi['minValue'];
                $filter['maxValue'] = $fi['maxValue'];
            }
            $search->addSearchFilter($filter);
        }
    }
    public function addTerm(Criteria $criteria, Searchbody $search, Context $context): void
    {
        $search->addTerm('');
        if (!$criteria->getTerm()) {
            return;
        }
        $term = $criteria->getTerm();
        if (trim($term) == '') {
            return;
        }
        $search->addTerm($term);
        return;
        $reg = $this->getConfigSemknoxRegEx();
        $regRepl = $this->getConfigSemknoxRegExRepl();
        if (($reg != '') && ($regRepl != '')) {
            try {
                $term = preg_replace($reg, $regRepl, $term);
            } catch (\Throwable $e) {
                $this->logOrThrowException($e);
            }
        }
        $search->addTerm($term);
    }
    public function addQueries(
        EntityDefinition $definition,
        Criteria $criteria,
        Searchbody $search,
        Context $context
    ): void {
        $queries = $criteria->getQueries();
        if (empty($queries)) {
            return;
        }
    }
    public function addSortings(
        EntityDefinition $definition,
        Criteria $criteria,
        Searchbody $search,
        Context $context
    ): void {
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
    public function addAggregations(
        EntityDefinition $definition,
        Criteria $criteria,
        Searchbody $search,
        Context $context
    ): void {
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
    private function getConfigSelectIntValue($v, $def = 0)
    {
        $ret = $v;
        if (is_bool($v)) {
            $v ? $ret = 1 : $ret = 0;
        } else {
            if (trim($v) == '') {
                $ret = $def;
            } else {
                if (substr($v, 0, 6) == '_woso_') {
                    $v = substr($v, 6);
                }
                if (!(ctype_digit($v))) {
                    $ret = $def;
                } else {
                    $ret = intval($v);
                }
            }
        }
        return $ret;
    }
    /**
     * returns semknox-api-url of config-id
     * @param int $id
     * @return string
     */
    private function getBaseURLByID($id)
    {
        $ret = "stage-shopware.semknox.com/";
        switch ($id) {
            case 0  : 
            case 1  :
                $ret = "https://api-shopware.sitesearch360.com/";
                break;
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
        $value = trim($value);
        $ret = $value;
        try {
            $h = json_decode($value, true);
            if ((is_array($h)) && (count($h) == 1) && (isset($h['_value']))) {
                if (is_string($h['_value'])) {
                    $h['_value'] = trim($h['_value']);
                }
                $ret = $h['_value'];
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
        return substr($key, 21, 1000);
    }
    public function getShopwareConfigValue(string $configKey, string $scID = 'null', $def = null)
    {
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
    private function getShopwareConfig()
    {
        if (!is_null($this->shopwareConfig)) {
            return;
        }
        $this->shopwareConfig = [];
        $ta = self::$connection->executeQuery('
            SELECT *
            FROM `system_config`
            WHERE `configuration_key` LIKE "core.%"
        ')->fetchAll(FetchMode::ASSOCIATIVE);
        foreach ($ta as $it) {
            $key = $it['configuration_key'];
            if ($it['sales_channel_id']) {
                $scid = $this->getHexUUID($it['sales_channel_id']);
            } else {
                $scid = 'null';
            }
            $val = $this->getDBConfigValue($it['configuration_value']);
            if (!isset($this->shopwareConfig[$key])) {
                $this->shopwareConfig[$key] = [];
            }
            $this->shopwareConfig[$key][$scid] = $val;
        }
    }
    /**
     * selects data of plugin-configuration direct from database
     */
    private function getSemknoxDBConfig()
    {
        if (!is_null($this->mainConfigVars)) {
            return;
        }
        $this->mainConfigVars = [];
        $ta = self::$connection->executeQuery('
            SELECT *
            FROM `semknox_config`
            WHERE `configuration_key` LIKE "semknoxSearch%"
        ')->fetchAll(FetchMode::ASSOCIATIVE);
        $defFound = 0;
        foreach ($ta as $it) {
            $k1 = '';
            $k2 = '';
            if ($it['sales_channel_id']) {
                $k1 = $this->getHexUUID($it['sales_channel_id']);
            }
            if ($it['domain_id']) {
                $k2 = $this->getHexUUID($it['domain_id']);
            }
            if ($it['language_id']) {
                $k3 = $this->getHexUUID($it['language_id']);
            }
            if (($k1 != '') && ($k2 != '')) {
                $this->mainConfigVars[$k1][$k2][$this->getDBConfigKey($it['configuration_key'])] = $this->getDBConfigValue($it['configuration_value']);
                if (($k3 != '') && (!isset($this->mainConfigVars[$k1][$k2]['lang_id']))) {
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
    private function checkMainSemknoxConfig(&$config)
    {
        foreach ($config as $k1 => &$sce) {
            if ($k1 == '00000000000000000000000000000000') {
                continue;
            }
            foreach ($sce as $k2 => &$lange) {
                $lange['valid'] = false;
                $valid = true;
                $lange['semknoxBaseUrlID'] = 1;
                if ($lange['semknoxBaseUrlID'] > -1) {
                    $lange['semknoxBaseUrl'] = $this->getBaseURLByID($lange['semknoxBaseUrlID']);
                }
                $lange['semknoxCustomerId'] = $lange['semknoxC01CustomerId'];
                unset ($lange['semknoxC01CustomerId']);
                $lange['semknoxApiKey'] = $lange['semknoxC01ApiKey'];
                unset ($lange['semknoxC01ApiKey']);
                $lange['semknoxLang'] = $this->getLanguageCodeById($lange['language_id']);
                if (trim($lange['semknoxBaseUrl']) == '') {
                    $valid = false;
                }
                if (trim($lange['semknoxCustomerId']) == '') {
                    $valid = false;
                }
                if (trim($lange['semknoxApiKey']) == '') {
                    $valid = false;
                }
                if (empty($lange['semknoxUpdateBlocksize'])) {
                    $lange['semknoxUpdateBlocksize'] = 500;
                }
                if ( (empty($lange['semknoxActivateCategoryListing']))  || (!is_bool($lange['semknoxActivateCategoryListing'])) )  {
                    $lange['semknoxActivateCategoryListing'] = false;
                }
                if ( (empty($lange['semknoxActivateSearchTemplate']))  || (!is_bool($lange['semknoxActivateSearchTemplate'])) )  {
                    $lange['semknoxActivateSearchTemplate'] = false;
                }
                if ( (empty($lange['semknoxActivateAutosuggest'])) || (!is_bool($lange['semknoxActivateAutosuggest'])) )  {
                    $lange['semknoxActivateAutosuggest'] = false;
                }
                if ( (empty($lange['semknoxActivateUpdate'])) || (!is_bool($lange['semknoxActivateUpdate'])) ) {
                    $lange['semknoxActivateUpdate'] = false;
                }
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
    public function checkMainConfig(&$config)
    {
        $config['valid'] = false;
        $valid = true;
        if ($config['semknoxBaseUrlID'] > -1) {
            $config['semknoxBaseUrl'] = $this->getBaseURLByID($config['semknoxBaseUrlID']);
        }
        if (trim($config['semknoxBaseUrl']) == '') {
            $valid = false;
        }
        if (trim($config['semknoxCustomerId']) == '') {
            $valid = false;
        }
        if (trim($config['semknoxApiKey']) == '') {
            $valid = false;
        }
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
    public function getMainConfigParams(string $scID, string $domainID)
    {
        $ret = null;
        if ((empty($scID)) || (empty($domainID))) {
            return $ret;
        }
        if ((is_array($this->mainConfigVars)) && (isset($this->mainConfigVars[$scID])) && (isset($this->mainConfigVars[$scID][$domainID]))) {
            $ret = null;
            if ((is_array($this->mainConfigVars[$scID][$domainID]))) {
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
    public function getSalesChannelID(bool $useDefault = true)
    {
        if (trim($this->salesChannelID) != '') {
            return $this->salesChannelID;
        } elseif ($useDefault) {
            return DEFAULTS::SALES_CHANNEL;
        }
        return '';
    }
    public function setSalesChannelID(string $scID): void
    {
        $this->salesChannelID = $scID;
    }
    /**
     * returns ID of salesChannel from Contents
     * @param SalesChannelContext $context
     * @return string
     */
    public function getSalesChannelFromSCContext(SalesChannelContext $context): string
    {
        $ret = '';
        $sc = $context->getSalesChannel();
        if (is_object($sc)) {
            $ret = $sc->getId();
        } else {
        }
        return $ret;
    }
    /**
     * returns languageID from context
     * @param SalesChannelContext $context
     * @return string
     */
    public function getLanguageFromSCContext(SalesChannelContext $context): string
    {
        $ret = '';
        $sc = $context->getSalesChannel();
        if (is_object($sc)) {
            $ret = $sc->getLanguageId();
        } else {
        }
        return $ret;
    }
    public function setDomainToSCContextExt(SalesChannelContext $context, array $data)
    {
        $context->addExtension('semknoxDataDomain', new ArrayEntity(
            [
                'data' => $data
            ]));
    }
    public function getDomainFromSCContextExt(SalesChannelContext $context): string
    {
        $ret = '';
        $domData = $context->getExtension('semknoxDataDomain');
        if (($domData === null)) {
            return $ret;
        }
        $domain = $domData->getVars();
        if ((!is_array($domain)) || (!isset($domain['data']))) {
            return $ret;
        }
        $data = [];
        if (isset($domain['data']['data'])) {
            if (!is_array($domain['data']['data'])) {
                return $ret;
            }
            $data = $domain['data']['data'];
        } else {
            if (isset($domain['data'])) {
                if (!is_array($domain['data'])) {
                    return $ret;
                }
                $data = $domain['data'];
            }
        }
        if (empty($data)) {
            return $ret;
        }
        if (isset($data['domainId'])) {
            $ret = $data['domainId'];
        }
        return $ret;
    }
    public function getDomainURLFromSCContext(SalesChannelContext $context): string
    {
        $ret = '';
        if (method_exists($context, 'getSalesChannel')) {
            $sc = $context->getSalesChannel();
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
    public function getDomainFromSCContext(SalesChannelContext $context): string
    {
        $ret = '';
        if ((method_exists($context, 'getDomainId')) && (!empty($context->getDomainId()))) {
            $ret = $context->getDomainId();
        } else {
            $h = $this->requestStack->getCurrentRequest()->get('sw-domain-id');
            if ((!is_null($h)) && (trim($h) != '')) {
                $ret = $h;
                return $ret;
            }
            $shopDom = $this->requestStack->getCurrentRequest()->get('sw-sales-channel-absolute-base-url');
            if ((is_null($shopDom)) || (trim($shopDom) != '')) {
                $shopDom = $this->requestStack->getCurrentRequest()->get('sw-storefront-url');
            }
            $ret = '';
            if ($shopDom) {
                $domListObj = ($context->getSalesChannel()->getDomains());
                if (is_object($domListObj)) {
                    $domList = $domListObj->getElements();
                    foreach ($domList as $domId => $dom) {
                        if ($dom->getUrl() == $shopDom) {
                            $ret = $domId;
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
    private function getSalesChannelFromContext(Context $context): string
    {
        $ret = '';
        $contextSource = $context->getSource();
        if (is_object($contextSource)) {
            var_dump($contextSource);
        } else {
        }
        return $ret;
    }
    public function getDBData(string $query, array $results, string $key = ''): array
    {
        $ta = self::$connection->executeQuery($query)->fetchAll(FetchMode::ASSOCIATIVE);
        $res = array();
        foreach ($ta as $it) {
            if (count($results) == 0) {
                $r = $ta;
            } else {
                $r = [];
                foreach ($results as $k) {
                    $r[$k] = $it[$k];
                }
            }
            if ($key == '') {
                $res[] = $r;
            } else {
                $res[$it[$key]] = $r;
            }
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
            $value = is_object($value) ? (array)$value : $value;
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
    public static function getShopwareVersion_compo1(): string
    {
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
    public static function getShopwareVersion(): string
    {
        if (class_exists('Composer\InstalledVersions', false) === false) {
            return self::getShopwareVersion_compo1();
        }
        $usecore=0;
        if (InstalledVersions::isInstalled('shopware/core')) {
            $usecore=1;
            $shopwareVersion = InstalledVersions::getVersion('shopware/core');
        } else {
            $shopwareVersion = InstalledVersions::getVersion('shopware/platform');
        }
        if ( ($usecore) && (is_null($shopwareVersion)) ) {
            $shopwareVersion = InstalledVersions::getVersion('shopware/platform');
        }
        if (is_null($shopwareVersion)) { return ''; }
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
    private function setSessionID($request)
    {
        $session = $request->hasSession() ? $request->getSession() : null;
        if (!is_null($session)) {
            $this->sessionId = $session->get('sessionId');
        }
    }
    public function getCurrentSessionId(): string
    {
        $ret = '';
        return $ret;
    }
    private function setPluginVersion()
    {
        $res = $this->getQueryResult("SELECT * FROM plugin WHERE name='semknoxSearch'");
        if (count($res)) {
            $this->pluginVersion = $res[0]['version'];
        }
    }
    public function getHeaderInfoData(): array
    {
        $ip = '';
        if (!empty($_SERVER['REMOTE_ADDR'])) {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        $ret = [
            'shopsys' => 'SHOPWARE',
            'shopsysver' => $this->getShopwareVersion(),
            'clientip' => $ip,
            'sessionid' => $this->sessionId,
            'extver' => $this->pluginVersion
        ];
        return $ret;
    }
    /**
     * returns time of last update from db-semknox-logs.
     * no update running - return 0
     * if last entry = update.finished, return 0
     */
    public function getUpdateRunning(): int
    {
        $lastentries = $this->getQueryResult("SELECT logtype, status, created_at from semknox_logs WHERE logtype like 'update.%' order by created_at desc LIMIT 3");
        $ret = 0;
        foreach ($lastentries as $ent) {
            if ($ent['logtype'] != 'update.finished') {
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
    public function getLastUpdateStart(): array
    {
        $lastentries = $this->getQueryResult("SELECT logtype, status, logdescr, created_at from semknox_logs WHERE logtype like 'update.process.start' order by created_at desc LIMIT 3");
        $ret = [];
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
    public function getLastUpdateProcessStart(?int $minCreate = 0): array
    {
        if ($minCreate) {
            $lastentries = $this->getQueryResult("SELECT logtype, status, logdescr, created_at from semknox_logs WHERE logtype like 'update.process.start' AND created_at < '" . date("Y-m-d H:i:s.u",
                    $minCreate) . "' order by created_at desc LIMIT 3");
        } else {
            $lastentries = $this->getQueryResult("SELECT logtype, status, logdescr, created_at from semknox_logs WHERE logtype like 'update.process.start' order by created_at desc LIMIT 3");
        }
        $ret = [];
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
    public function getLastUpdateFinished(): array
    {
        $lastentries = $this->getQueryResult("SELECT logtype, status, logdescr, created_at from semknox_logs WHERE logtype like 'update.finished' order by created_at desc LIMIT 3");
        $ret = [];
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
    public function getLogDirSubPath(string $subpath, int $doCreate = 0): string
    {
        $ret = $this->add_ending_slash($this->logDir) . $subpath;
        if ($doCreate) {
            $p = dirname($ret);
            if (!is_dir($p)) {
                mkdir($p, 0777, true);
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
        $limit = $limit > 0 ? $limit : $this->systemConfigService->getInt('core.listing.productsPerPage',
            $context->getSalesChannel()->getId());
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
    public function addFilterPrefix(string $str): string
    {
        if (is_null($str)) {
            return $str;
        }
        return self::FilterPrefix . $str;
    }
    /**
     * returns List of filter-properties
     * @param string $queryString
     * @return array
     */
    public function getFilterPropertiesList(string $queryString): array
    {
        return explode(self::FilterListSeparator, $queryString);
    }
    /**
     * returns array of feature [1] = value [2]
     * @param string $queryString
     * @return array
     */
    public function getFilterPropertiesEntity(string $queryString): array
    {
        return explode(self::FilterPrefix, $queryString);
    }
    /**
     * adds an ending slash to the directory-path, if necessary
     * @param string $path
     * @return string
     */
    public function add_ending_slash(string $path): string
    {
        $slash_type = (strpos($path, '\\') === 0) ? 'win' : 'unix';
        $last_char = substr($path, strlen($path) - 1, 1);
        if ($last_char != '/' and $last_char != '\\') {
            $path .= ($slash_type == 'win') ? '\\' : '/';
        }
        return $path;
    }
    /**
     * generate current used blockfile for upload-processes
     * @param integer $maxProducts
     * @param $limit
     * @param SalesChannelContext $salesChannelContext
     * @return void
     */
    public function uploadblocks_generate(int $maxProducts, $limit, SalesChannelContext $salesChannelContext)
    {
        try {
            $blockcount = ($maxProducts / $limit);
            if (is_float($blockcount)) {
                $blockcount = intval($blockcount) + 1;
            } else {
                $blockcount = intval($blockcount);
            }
            $scId = $salesChannelContext->getSalesChannel()->getId();
            $langId = $salesChannelContext->getSalesChannel()->getLanguageId();
            $domainId = $this->getDomainFromSCContextExt($salesChannelContext);
            $blockstruct = $this->uploadblocks_loaddata();
            if (!is_array($blockstruct)) {
                $blockstruct = [
                    'lastSC' => $scId,
                    'lastDomain' => $domainId,
                    'startTime' => time(),
                    'lastchange' => time(),
                    'status' => 0,
                    'scList' => []
                ];
            }
            $blockstruct['scList'][$scId . '#' . $domainId] = [
                'saleschannelId' => $scId,
                'domainId' => $domainId,
                'languageId' => $langId,
                'maxProducts' => $maxProducts,
                'blockcount' => $blockcount,
                'limit' => $limit,
                'status' => 0,
                'lastchange' => 0,
                'blocks' => []
            ];
            $b = [
                'offset' => -1,
                'status' => 0,
                'startTime' => 0,
                'endTime' => 0,
                'errorcount' => 0,
                'retrycount' => 0,
                'error' => '',
                'lastchange' => 0
            ];
            $blockstruct['scList'][$scId . '#' . $domainId]['blocks'][-1] = $b;
            for ($i = 0; $i < $blockcount; $i++) {
                $offset = $i * $limit;
                $b = [
                    'offset' => $offset,
                    'status' => 0,
                    'startTime' => 0,
                    'endTime' => 0,
                    'errorcount' => 0,
                    'retrycount' => 0,
                    'error' => '',
                    'lastchange' => 0
                ];
                $blockstruct['scList'][$scId . '#' . $domainId]['blocks'][$offset] = $b;
            }
            $b = [
                'offset' => 10000000000,
                'status' => 0,
                'startTime' => 0,
                'endTime' => 0,
                'errorcount' => 0,
                'retrycount' => 0,
                'error' => '',
                'lastchange' => 0
            ];
            $blockstruct['scList'][$scId . '#' . $domainId]['blocks'][10000000000] = $b;
            $fn = $this->uploadblocks_getblockfilename();
            $blockstruct['lastchange'] = time();
            $blockstruct['scList'][$scId . '#' . $domainId]['lastchange'] = time();
            $this->uploadblocks_savedata($blockstruct);
        } catch (\Throwable $t) {
            $this->logData(1, 'uploadblocks_generate.ERROR', ['msg' => $t->getMessage()], 500);
        }
    }
    /**
     * return blockfilename of saleschannel/domainId
     * @return string
     */
    public function uploadblocks_getblockfilename(): string
    {
        return $this->add_ending_slash($this->logDir) . 'uplblocks.json';
    }
    /**
     * return datafilename for saleschannelContext
     * @return string
     */
    public function uploadblocks_getproductfilename(SalesChannelContext $salesChannelContext): string
    {
        try {
            $scId = $salesChannelContext->getSalesChannel()->getId();
            $domainId = $this->getDomainFromSCContextExt($salesChannelContext);
            return $this->add_ending_slash($this->logDir) . 'allprods_'.$scId.'_'.$domainId.'.json';
        } catch (\Throwable $t) {
            $this->logData(1, 'uploadblocks_getproductfilename.ERROR', ['msg' => $t->getMessage()], 500);
            return '';
        }
    }
    public function uploadblocks_setBlockStatusBySC(
        SalesChannelContext $salesChannelContext,
        int $offset,
        int $status,
        string $error = '',
        int $overwriteTime = 0
    ): int {
        try {
            $scId = $salesChannelContext->getSalesChannel()->getId();
            $domainId = $this->getDomainFromSCContextExt($salesChannelContext);
            return $this->uploadblocks_setBlockStatus($scId, $domainId, $offset, $status, $error, $overwriteTime);
        } catch (\Throwable $t) {
            $this->logData(1, 'uploadblocks_setBlockStatusBySC.ERROR', ['msg' => $t->getMessage()], 500);
            return -1;
        }
    }
    public function uploadblocks_setBlockStatus(
        string $saleschannelId,
        string $domainId,
        int $offset,
        int $status,
        string $error = '',
        int $overwriteTime = 0
    ): int {
        try {
            $blockstruct = $this->uploadblocks_loaddata();
            if (($saleschannelId=='') && ($domainId=='') && ($offset==-1000)) {
                $blockstruct['status'] = $status;
                $blockstruct['lastchange'] = time();
                $blockstruct['lastSC'] = $saleschannelId;
                $blockstruct['lastDomain'] = $domainId;
            }
            if ((isset($blockstruct['scList'][$saleschannelId . '#' . $domainId]['blocks'][$offset])) &&
                (is_array($blockstruct['scList'][$saleschannelId . '#' . $domainId]['blocks'][$offset]))) {
                $t=time();
                if ($overwriteTime > 0) { $t=$overwriteTime; }
                $blockstruct['scList'][$saleschannelId . '#' . $domainId]['blocks'][$offset]['status'] = $status;
                if (trim($error) != '') {
                    $blockstruct['scList'][$saleschannelId . '#' . $domainId]['blocks'][$offset]['errorcount']++;
                    $blockstruct['scList'][$saleschannelId . '#' . $domainId]['blocks'][$offset]['error'] .= $error . "\n";
                }
                if ($status >= 100) {
                    $blockstruct['scList'][$saleschannelId . '#' . $domainId]['blocks'][$offset]['endTime'] = $t;
                }
                if ($status == 1) {
                    $blockstruct['scList'][$saleschannelId . '#' . $domainId]['blocks'][$offset]['startTime'] = $t;
                }
                $blockstruct['scList'][$saleschannelId . '#' . $domainId]['blocks'][$offset]['lastchange'] = $t;
                $blockstruct['scList'][$saleschannelId . '#' . $domainId]['lastchange'] = time();
                if ( ($offset==10000000000) &&  ($status >= 100) ) {
                    $blockstruct['scList'][$saleschannelId . '#' . $domainId]['status'] = 100;
                }
                if ( ($offset==-1) &&  ($status = 100) ) {
                    $blockstruct['scList'][$saleschannelId . '#' . $domainId]['status'] = 1;
                }
                $blockstruct['lastchange'] = time();
                $blockstruct['lastSC'] = $saleschannelId;
                $blockstruct['lastDomain'] = $domainId;
            }
            $this->uploadblocks_savedata($blockstruct);
            return 0;
        } catch (\Throwable $t) {
            $this->logData(1, 'uploadblocks_setBlockStatus.ERROR', ['msg' => $t->getMessage()], 500);
            return -1;
        }
    }
    public function uploadblocks_startNextBlock(): ?array
    {
        $ret = null;
        try {
            $blockstruct = $this->uploadblocks_loaddata();
            if (is_null($blockstruct)) { return null; }
            $blockstruct = $this->uploadblocks_checkSingleBlocks($blockstruct);
            $lastSC = null;
            foreach ($blockstruct['scList'] as &$scItem) {
                $cfinished=0;
                if ($scItem['status'] < -999) { continue; }
                if ($scItem['status'] >= 100 ) { $lastSC = $scItem; continue; }
                foreach ($scItem['blocks'] as $k => &$block) {
                    $finish = false;
                    if ($k == -1) { continue; }
                    if ($k == 10000000000) {
                        if ($cfinished == (count($scItem['blocks']) -2) ) {
                            $finish = true;
                        } else {
                            continue;
                        }
                    }
                    if ($block['status'] >= 100) {
                        $cfinished++;
                    }
                    if ( ($block['status'] <= 0) && ($block['status'] > -1000) ) {
                        $block['status'] = 1;
                        $block['startTime'] = time();
                        $blockstruct['lastchange'] = time();
                        $blockstruct['lastSC'] = $scItem['saleschannelId'];
                        $blockstruct['lastDomain'] = $scItem['domainId'];
                        $block['lastchange'] = time();
                        $ret = [
                            'usData' => [
                                'scID' => $scItem['saleschannelId'],
                                'langID' => $scItem['languageId'],
                                'domainID' => $scItem['domainId'],
                                'provider' => 'product',
                                'offset' => $block['offset'],
                                'finished' => $finish,
                                'limit' => $scItem['limit'],
                                'startnext' => false
                            ]
                        ];
                    }
                    unset($block);
                    if (!is_null($ret)) {
                        break;
                    }
                }
                unset($scItem);
                if (!is_null($ret)) {
                    break;
                }
            }
            if ( (is_null($ret)) && (!is_null($lastSC)) && ($blockstruct['status'] <= 100) ) {
                $ret = [
                    'usData' => [
                        'scID' => $lastSC['saleschannelId'],
                        'langID' => $lastSC['languageId'],
                        'domainID' => $lastSC['domainId'],
                        'provider' => 'product',
                        'offset' => 0,
                        'finished' => true,
                        'limit' => $lastSC['limit'],
                        'startnext' => true
                    ]
                ];
            }
            $this->uploadblocks_savedata($blockstruct);
        } catch (\Throwable $t) {
            var_dump($t->getMessage());
            $this->logData(1, 'uploadblocks_startNextBlock.ERROR', ['msg' => $t->getMessage()], 500);
            return null;
        }
        return $ret;
    }
    /**
     * checking all upload-blocks, if everything is ready, stop upload by setting Blockstatus to 100
     * @return void|null
     */
    public function uploadblocks_CheckAndSetBlockStatus() {
        try {
            $blockstruct = $this->uploadblocks_loaddata();
            if (is_null($blockstruct)) { return; }
            $scFinishedCount = 0;
            $scErrorCount = 0;
            foreach ($blockstruct['scList'] as &$scItem) {
                foreach ($scItem['blocks'] as $block) {
                    if ($block['status'] <= -1000) {
                        $scItem['status'] = -1000;
                    }
                }
                if ($scItem['status'] >= 100 ) { $scFinishedCount++; }
                if ($scItem['status'] <= -1000) { $scErrorCount++; }
            }
            $this->uploadblocks_savedata($blockstruct);
            if ($scFinishedCount == count($blockstruct['scList'])) {
                $this->logData(100, 'updatecheck:finished');
                $this->uploadblocks_setBlockStatus('','', -1000, 100);
            } else {
                if (($scFinishedCount + $scErrorCount) == count($blockstruct['scList'])) {
                    $this->logData(100, 'updatecheck:errors');
                    $this->logData(100, 'updatecheck:finished');
                    $this->uploadblocks_setBlockStatus('', '', -1000, -1000);
                }
            }
        } catch (\Throwable $t) {
            var_dump($t->getMessage());
            $this->logData(1, 'uploadblocks_CheckAndSetBlockStatus.ERROR', ['msg' => $t->getMessage()], 500);
            return null;
        }
    }
    public function uploadblocks_getSCStatus(string $scId, string $domainId, string $langId): ?int
    {
        $ret = null;
        try {
            $blockstruct = $this->uploadblocks_loaddata();
            if (is_null($blockstruct)) { return $ret; }
            $this->uploadblocks_savedata($blockstruct);
            if ($scId=='') {
                if (isset($blockstruct['status'])) {
                    return $blockstruct['status'];
                }
            } else {
                if (isset($blockstruct['scList'][$scId.'#'.$domainId])) {
                    $scItem = $blockstruct['scList'][$scId.'#'.$domainId];
                    return $scItem['status'];
                }
            }
        } catch (\Throwable $t) {
            var_dump($t->getMessage());
            $this->logData(1, 'uploadblocks_getSCStatus.ERROR', ['msg' => $t->getMessage()], 500);
            return null;
        }
        return $ret;
    }
    /**
     * returns the offset of the next block by saleschannelcontext
     * @param SalesChannelContext $salesChannelContext
     * @return int
     */
    public function uploadblocks_startnextOffsetBySC(SalesChannelContext $salesChannelContext): int
    {
        try {
            $ret = -10000;
            $saleschannelId = $salesChannelContext->getSalesChannel()->getId();
            $domainId = $this->getDomainFromSCContextExt($salesChannelContext);
            $blockstruct = $this->uploadblocks_loaddata();
            foreach ($blockstruct['scList'][$saleschannelId . '#' . $domainId]['blocks'] as $k => &$block) {
                if ( ($k==-1) || ($k==10000000000) ) { continue; }
                if ($block['status'] <= 0) {
                    $block['status'] = 1;
                    $block['startTime'] = time();
                    $blockstruct['lastchange'] = time();
                    $blockstruct['lastSC'] = $saleschannelId;
                    $blockstruct['lastDomain'] = $domainId;
                    $block['lastchange'] = time();
                    $ret = $block['offset'];
                }
                unset($block);
                if ($ret >= 0) {
                    break;
                }
            }
            $this->uploadblocks_savedata($blockstruct);
        } catch (\Throwable $t) {
            $this->logData(1, 'uploadblocks_startnextOffsetBySC.ERROR', ['msg' => $t->getMessage()], 500);
            return -1000;
        }
        return $ret;
    }
    /**
     * returns the structure of the blockfile-data, using lockfile-mechanism
     * @param int $dolock
     * @return array|null
     */
    private function uploadblocks_loaddata(int $dolock = 1): ?array
    {
        $blockstruct = null;
        $blockfilename = $this->uploadblocks_getblockfilename();
        $lockfilename = $blockfilename . '.lock';
        try {
            $dostop = 0;
            srand(intval(time() / (60 * 60 * 24)));
            $waittime = 0;
            do {
                if (file_exists($lockfilename)) {
                    $wt = rand(100, 1000);
                    $waittime += $wt;
                    usleep($wt);
                } else {
                    $dostop = 1;
                }
            } while (($dostop == 0) && ($waittime < 1000000));
            if ($dostop == 0) {
                return null;
            }
            if ($dolock) {
                if ($dolock) {
                    touch($lockfilename);
                }
                if (file_exists($blockfilename)) {
                    $blockstruct = json_decode(file_get_contents($blockfilename), true);
                } else {
                    if ($dolock) {
                        unlink($lockfilename);
                    }
                }
            }
        } catch (\Throwable $t) {
            $this->logData(1, 'uploadblocks_loaddata.ERROR', ['msg' => $t->getMessage()], 500);
            if (file_exists($lockfilename)) {
                unlink($lockfilename);
            }
            return null;
        }
        return $blockstruct;
    }
    /**
     * saves the block-structure and removes the lockfile, if necessary
     * returns 1 if o.k. < 0 on error
     * @param array $blockstruct
     * @return int
     */
    private function uploadblocks_savedata(array $blockstruct): int
    {
        $ret = 0;
        $blockfilename = $this->uploadblocks_getblockfilename();
        $lockfilename = $blockfilename . '.lock';
        try {
            file_put_contents($blockfilename, \json_encode($blockstruct));
            if (file_exists($lockfilename)) {
                unlink($lockfilename);
            }
            $ret = 1;
        } catch (\Throwable $t) {
            $this->logData(1, 'uploadblocks_savedata.ERROR', ['msg' => $t->getMessage()], 500);
            if (file_exists($lockfilename)) {
                unlink($lockfilename);
            }
            return -1;
        }
        return $ret;
    }
    /**
     * deletes block-file and if existing lockfile for blockhandling
     * returns 1 if o.k., < 0 on error
     * @return int
     */
    public function uploadblocks_resetFile(): int
    {
        $this->cleanLogDB();
        $ret = 0;
        $blockfilename = $this->uploadblocks_getblockfilename();
        $lockfilename = $blockfilename . '.lock';
        try {
            if (file_exists($blockfilename)) {
                unlink($blockfilename);
            }
            if (file_exists($lockfilename)) {
                unlink($lockfilename);
            }
            $ret = 1;
        } catch (\Throwable $t) {
            $this->logData(1, 'uploadblocks_resetFile.ERROR', ['msg' => $t->getMessage()], 500);
            if (file_exists($lockfilename)) {
                unlink($lockfilename);
            }
            return -1;
        }
        return $ret;
    }
    public function uploadblocks_resetProductDataFile(SalesChannelContext $salesChannelContext): int
    {
        $ret=0;
        try {
            $dataFilename = $this->uploadblocks_getproductfilename($salesChannelContext);
            if ($dataFilename != '') {
                if (file_exists($dataFilename)) { unlink($dataFilename); }
            }
        } catch (\Throwable $t) {
            $this->logData(1, 'uploadblocks_resetProductDataFile.ERROR', ['msg' => $t->getMessage()], 500);
            return -1;
        }
        return $ret;
    }
    public function uploadblocks_resetAllProductDataFiles(): int
    {
        $ret=0;
        try {
            $filter = $this->add_ending_slash($this->logDir) . 'allprods_*';
            $dfiles = glob($filter);
            foreach ($dfiles as $dfile) {
                if (file_exists($dfile)) { unlink($dfile); }
            }
        } catch (\Throwable $t) {
            $this->logData(1, 'uploadblocks_resetAllProductDataFiles.ERROR', ['msg' => $t->getMessage()], 500);
            return -1;
        }
        return $ret;
    }
    /**
     * returns 1 if datafile of saleschannelcontext exists, 0 or <0 if an error occurred
     * @param SalesChannelContext $salesChannelContext
     * @return int
     */
    public function uploadblocks_existsProductDataFile(SalesChannelContext $salesChannelContext): int
    {
        $ret=0;
        try {
            $dataFilename = $this->uploadblocks_getproductfilename($salesChannelContext);
            if ($dataFilename != '') {
                if (file_exists($dataFilename)) { $ret = 1; }
            } else {
                return -1;
            }
        } catch (\Throwable $t) {
            $this->logData(1, 'uploadblocks_resetProductDataFile.ERROR', ['msg' => $t->getMessage()], 500);
            return -1;
        }
        return $ret;
    }
    /**
     * checks current blocks for runtime-errors and resets the block, if waiting-time is over
     * @return array
     */
    public function uploadblocks_checkSingleBlocks(?array $blockstruct) : ?array {
        $ret = $blockstruct;
        try {
            if (is_array($blockstruct)) {
                foreach ($blockstruct['scList'] as &$scItem) {
                    $scItemComplete = 1;
                    foreach ($scItem['blocks'] as $k => &$block) {
                        if ($block['status'] < 100) { $scItemComplete = 0; }
                        if ($k == -1) { continue; }
                        if ( ($block['status'] > 0) && ($block['status'] < 100) && ((time() - $block['lastchange']) > self::blockMaxTime) ) {
                            if ($block['retrycount'] < self::blockMaxRetry) {
                                $block['retrycount']++;
                                $block['status'] = 0;
                            } else {
                                $block['status'] = -1000;
                                $scItem['status'] = -1000;
                                $this->logData(1, 'uploadblocks_checkSingleBlocks.ERROR.Block.'.$block['offset'], ['msg' => 'max. retries reached for block '.$block['offset']], 500);
                            }
                        }
                        unset($block);
                    }
                    if ( ($scItemComplete) && ($scItem['status'] < 100) ) {
                        $scItem['status'] = 100;
                    }
                    unset($scItem);
                }
                $ret = $blockstruct;
            }
        } catch (\Throwable $t) {
            $this->logData(1, 'uploadblocks_checkSingleBlocks.ERROR', ['msg' => $t->getMessage()], 500);
        }
        return $ret;
    }
    /**
     * checks status of whole update-process, lockfiles etc.
     * @return void
     */
    public function uploadblocks_checkStatus() {
        try {
            $blockfilename = $this->uploadblocks_getblockfilename();
            $lockfilename = $blockfilename . '.lock';
            if (!file_exists($lockfilename)) { return; }
            $filemtime = @filemtime($lockfilename);  
            if (!$filemtime or (time() - $filemtime >= self::blockMaxTime)) {
                unlink($lockfilename);
                $this->logData(1, 'uploadblocks_checkStatus.Warning.lockfile.released', ['msg' => 'released lockfile after '.self::blockMaxTime.' seconds'], 400);
            }
        } catch (\Throwable $t) {
            $this->logData(1, 'uploadblocks_checkStatus.ERROR', ['msg' => $t->getMessage()], 500);
        }
    }
    /**
     * returns an array containing at least status- and resultText-fields
     * @param array|null $res
     * @param int $artype
     * @return array
     */
    public function apiResult_asArray(?array $res = null, int $artype = 1): array {
        $ret = ['status' => -1, 'resultText' => ''];
        if ( (is_null($res)) || (!is_array($res)) ) {
            return $ret;
        }
        if (!isset($res['status'])) { $res['status'] = -1; }
        if (!isset($res['resultText'])) { $res['resultText'] = ''; }
        return $res;
    }
    public function cleanLogDB() {
        try {
            $dt = new \DateTime();
            $dt->sub(new \DateInterval('P'.self::maxLogDays.'D'));
            $query = "SELECT logtype, status, created_at from semknox_logs WHERE logtype like 'update.finished' AND created_at < '".$dt->format('Y-m-d H:i:s')."' order by created_at DESC LIMIT 1";
            $lastentries = $this->getQueryResult($query);
            if (!empty($lastentries)) {
                $lastfin = $lastentries[0];
                $q2 = "DELETE FROM semknox_logs WHERE created_at < '".$lastfin['created_at']."'";
                $this->execQuery($q2);
            }
        } catch (\Throwable $t) {
            $this->logData(1, 'cleanLogDB.ERROR', ['msg' => $t->getMessage()], 500);
        }
    }
}
