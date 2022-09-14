<?php declare(strict_types=1);
namespace semknox\search\Service;
use Psr\Cache\CacheItemPoolInterface;
use Shopware\Core\Framework\Context;
use semknox\search\Exception\AlreadyLockedException;
use semknox\search\Exception\ProductProviderNotFound;
use semknox\search\Struct\semknoxGenerationResult;
use semknox\search\Provider\ProductProviderInterface;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainCollection;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use function sprintf;
use semknox\search\api\semknoxBaseApi;
use semknox\search\Framework\SemknoxsearchHelper;
use semknox\search\Struct\ProductResult;
use Psr\Log\LoggerInterface;
use Shopware\Core\Content\Rule\RuleCollection;
use Shopware\Core\Checkout\Cart\AbstractRuleLoader;
class semknoxExporter implements semknoxExporterInterface
{
    /**
     * @var SystemConfigService
     */
    private $systemConfigService;
    /**
     * @var ProductProviderInterface[]
     */
    private $productProvider; 
    /**
     * @var LoggerInterface
     */
    private $logger;
    private $logDir='';
    /**
     * 
     * @var SemknoxsearchHelper
     */
    private $semknoxSearchHelper;
    /**
     * @var CacheItemPoolInterface
     */
    private $cache;
    /**
     * @var int
     */
    private $batchSize;
    /**
     * @var EventDispatcherInterface
     */
    private $eventDispatcher;
    /**
     * AbstractRuleLoader
     */
    private $ruleLoader;
    /**
     *  @var RuleCollection
     */
    private $rules = null;
    public function __construct(
        SystemConfigService $systemConfigService,
        iterable $productProvider,
        CacheItemPoolInterface $cache,
        SemknoxsearchHelper $helper,
        LoggerInterface $logger,
        string $rootDir, 
        EventDispatcherInterface $eventDispatcher,
        AbstractRuleLoader $loader
        ) {
            $this->systemConfigService = $systemConfigService;
            $this->productProvider = $productProvider;
            $this->cache = $cache;
            $this->semknoxSearchHelper = $helper;
            $this->logger = $logger;
            $this->batchSize = 2000; 
            $this->logDir = $rootDir;
            $this->eventDispatcher = $eventDispatcher;
            $this->ruleLoader = $loader;
    }
    private function createLogPath($path) : int 
    {
        $ret = 0;
        if (empty($path)) { return $ret; }
        try {
            if (!is_dir($path)) {
                mkdir($path, 0775, true);
            }
            $ret=1;
        } catch (\Throwable $t)
        {
            $ret=-1;
        }
        return $ret;        
    }
    /**
     * {@inheritdoc}
     */
    public function generate(SalesChannelContext $salesChannelContext, bool $force = false, ?string $lastProvider = null, ?int $offset = null, ?int $batchSize = 500): semknoxGenerationResult
    {
        $this->setRulesToSalesChannelContext($salesChannelContext);
        $this->batchSize=$batchSize;
        if ($this->batchSize < 20) { $this->batchSize = 20; }
        if (is_null($offset)) { $offset=0; }
        $this->semknoxSearchHelper->logData(1, "Semknox-Generate: ".$salesChannelContext->getSalesChannel()->getId()."--".$salesChannelContext->getSalesChannel()->getLanguageId()."--".$this->semknoxSearchHelper->getDomainFromSCContextExt($salesChannelContext)."--".$offset);
        $scID=$salesChannelContext->getSalesChannel()->getId();
        $langID=$salesChannelContext->getSalesChannel()->getLanguageId();
        $domainID=$this->semknoxSearchHelper->getDomainFromSCContextExt($salesChannelContext);
        if ($force === false && $this->isLocked($salesChannelContext)) {
            throw new AlreadyLockedException($salesChannelContext);
        }
        if ($lastProvider === '') { $lastProvider = null; }
        /** @var ProductProviderInterface $productProvider */
        $productProvider = $this->getProductProvider($lastProvider);
        $this->createLogPath($this->semknoxSearchHelper->add_ending_slash($this->logDir).'semknox');
        $mylogdir = $this->semknoxSearchHelper->add_ending_slash($this->semknoxSearchHelper->add_ending_slash($this->logDir).'semknox');
        $productResult = $productProvider->getProductData($salesChannelContext, $this->batchSize, $offset, $mylogdir);
        if ( ($offset==0) && ($productResult->getNextOffset()==0) ) {
            return new semknoxGenerationResult(
                false,
                $lastProvider,
                $productResult->getNextOffset(),
                $salesChannelContext->getSalesChannel()->getId(),
                $salesChannelContext->getSalesChannel()->getLanguageId(),
                $domainID
            );
        }
        $host = $this->getHost($salesChannelContext);
        $finish=false;
        if ($productResult->getNextOffset() !== null) {
            $lastProvider = $productProvider->getName();
        } else {
            $finish = true;
            $nextProvider = $this->getNextProductProvider($productProvider->getName());
            $lastProvider = $nextProvider ? $nextProvider->getName() : null;
        }
        $res=['finish'=>$finish, 'offset'=>$productResult->getNextOffset(), 'channelID'=>$salesChannelContext->getSalesChannel()->getId(), 'langID'=>$salesChannelContext->getSalesChannel()->getLanguageId(), 'domainID'=>$domainID];
        $this->semknoxSearchHelper->logData(10, 'generateupdate', ['genData'=>$res]);
        $this->ExportProducts($productResult, $offset, $finish, $scID, $langID, $domainID);
        return new semknoxGenerationResult(
            $finish,
            $lastProvider,
            $productResult->getNextOffset(),
            $salesChannelContext->getSalesChannel()->getId(),
            $salesChannelContext->getSalesChannel()->getLanguageId(),
            $domainID
            );
    }
    public function generateCategoriesData(SalesChannelContext $salesChannelContext, ?string $lastProvider = null, ?int $offset = null, ?int $batchSize = 500): array
    {
        $ret=$this->semknoxSearchHelper->apiResult_asArray();
        $catData=[];
        /** @var ProductProviderInterface $productProvider */
        $productProvider = $this->getProductProvider('product');
        if ($lastProvider === '') { $lastProvider = null; }
        /** @var ProductProviderInterface $productProvider */
        $productProvider = $this->getProductProvider($lastProvider);
        $host = $this->getHost($salesChannelContext);
        $this->createLogPath($this->semknoxSearchHelper->add_ending_slash($this->logDir).'semknox');
        $catData = $productProvider->getCategoryData($salesChannelContext);
        $scID=$salesChannelContext->getSalesChannel()->getId();
        $langID=$salesChannelContext->getSalesChannel()->getLanguageId();
        $domainID=$this->semknoxSearchHelper->getDomainFromSCContextExt($salesChannelContext);
        $ret = $this->ExportCategories($catData, $scID, $langID, $domainID);
        $ret = $this->semknoxSearchHelper->apiResult_asArray($ret);
        return $ret;
    }
    private function lock(SalesChannelContext $salesChannelContext): bool
    {
        $cacheKey = $this->generateCacheKeyForSalesChannel($salesChannelContext);
        if ($this->cache->hasItem($cacheKey)) {
            return false;
        }
        $lifeTime = (int) $this->systemConfigService->get('core.sitemap.sitemapRefreshTime');
        $lock = $this->cache->getItem($cacheKey);
        $lock->set(sprintf('Locked: %s', (new \DateTime('NOW', new \DateTimeZone('UTC')))->format(\DateTime::ATOM)))
        ->expiresAfter($lifeTime);
        return $this->cache->save($lock);
    }
    private function unlock(SalesChannelContext $salesChannelContext): void
    {
        $this->cache->deleteItem($this->generateCacheKeyForSalesChannel($salesChannelContext));
    }
    private function isLocked(SalesChannelContext $salesChannelContext): bool
    {
        return $this->cache->hasItem($this->generateCacheKeyForSalesChannel($salesChannelContext));
    }
    private function generateCacheKeyForSalesChannel(SalesChannelContext $salesChannelContext): string
    {
        return sprintf('semknox-fuexporter-running-%s-%s', $salesChannelContext->getSalesChannel()->getId(), $salesChannelContext->getSalesChannel()->getLanguageId());
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
    private function getProductProvider(?string $provider): ?ProductProviderInterface
    {
        if ($provider === null) {
            return $this->getNextProductProvider($provider);
        }
        foreach ($this->productProvider as $productProvider) {
            if ($productProvider->getName() === $provider) {
                return $productProvider;
            }
        }
        throw new ProductProviderNotFound($provider);
    }
    private function getNextProductProvider(?string $lastProvider): ?ProductProviderInterface
    {
        if ($lastProvider === null) {
            foreach ($this->productProvider as $productProvider) {
                return $productProvider;
            }
        }
        $getNext = false;
        foreach ($this->productProvider as $productProvider) {
            if ($getNext === true) {
                return $productProvider;
            }
            if ($productProvider->getName() === $lastProvider) {
                $getNext = true;
            }
        }
        return null;
    }
    private function ExportCategories(array $catData, string $scID, string $langID, string $domainID) : array
    {
        $ret = $this->semknoxSearchHelper->apiResult_asArray();
        $ret['status'] = 1;
        $mainConfig = $this->semknoxSearchHelper->getMainConfigParams($scID, $domainID);
        if ( (is_array($catData)) && (!empty($catData)) ) {
            $ret['status'] = -1;
            try {
                $api = new semknoxBaseApi($mainConfig['semknoxBaseUrl'], $mainConfig['semknoxCustomerId'],
                    $mainConfig['semknoxApiKey'], "updateSessionID");
                $api->addHeaderInfoData($this->semknoxSearchHelper->getHeaderInfoData());
                $api->setLogPath($this->semknoxSearchHelper->add_ending_slash($this->logDir) . 'semknox');
                $this->semknoxSearchHelper->logData(1, 'siteSearch360: start sending catData -');
                $ret = -21;
                sleep(1);
                $res = $api->sendCatDatav3($catData);
                $logt = $res['status'];
                if (isset($res['resultText'])) {
                    $logt .= '##' . $res['resultText'];
                }
                $this->semknoxSearchHelper->logData(10, 'update.send.cat', ['updateSendData' => $res]);
                if ($res['status'] < 0) {
                    $ret = -22;
                    return $this->semknoxSearchHelper->apiResult_asArray($res);
                } else {
                    $ret = $this->semknoxSearchHelper->apiResult_asArray($res);
                }
            } catch (\Throwable $t) {
                $this->logData(1, 'ExportCategories.ERROR', ['msg' => $t->getMessage()], 500);
                $ret['status'] = -1;
                $ret['resultText'] = $t->getMessage();
            }
        }
        return $ret;
    }
    /**
     * sends the update-command to sitesearch360 for finishing updateprocess for one saleschannel
     * @param SalesChannelContext $salesChannelContext
     * @return int
     */
    public function finishUpdate(SalesChannelContext $salesChannelContext) : array {
        $ret=$this->semknoxSearchHelper->apiResult_asArray();
        $ret['status']=0;
        $scID=$salesChannelContext->getSalesChannel()->getId();
        $langID=$salesChannelContext->getSalesChannel()->getLanguageId();
        $domainID=$this->semknoxSearchHelper->getDomainFromSCContextExt($salesChannelContext);
        try {
            $mainConfig = $this->semknoxSearchHelper->getMainConfigParams($scID,$domainID);
            $api = new semknoxBaseApi($mainConfig['semknoxBaseUrl'], $mainConfig['semknoxCustomerId'], $mainConfig['semknoxApiKey'], "updateSessionID");
            $api->addHeaderInfoData($this->semknoxSearchHelper->getHeaderInfoData());
            $api->setLogPath($this->semknoxSearchHelper->add_ending_slash($this->logDir).'semknox');
            $this->semknoxSearchHelper->logData(1, 'siteSearch360: finish update -');
            $ret['status']=-21;
            $res = $api->finishBatchUpload();
            $logt=$res['status'];if (isset($res['resultText'])) { $logt.='##'.$res['resultText']; }
            $this->semknoxSearchHelper->logData(10, 'update.send.p3', ['updateSendData'=>$res]);
            $ret=$this->semknoxSearchHelper->apiResult_asArray($res);
        } catch (\Throwable $t) {
            $this->logData(1, 'finishUpdate.ERROR', ['msg' => $t->getMessage()], 500);
            $ret['resultText'] = $t->getMessage();
            return $ret;
        }
        return $ret;
    }
    /**
     * sending data to sitesearch-server
     * if offset > 0 no starting-signal will be sent
     * is finish <> true no finish-signal will b sent
     * return 1 if everything is o.k. < 0 else
     * @param ProductResult $productList
     * @param int $offset
     * @param bool $finish
     */
    private function ExportProducts(ProductResult $productList, int $offset, bool $finish, string $scID, string $langID, string $domainID) : int
    {
        $ret=1;
        $mainConfig = $this->semknoxSearchHelper->getMainConfigParams($scID,$domainID);
        if (!$productList->hasProducts()) {
            return $ret;
        }
        $api = new semknoxBaseApi($mainConfig['semknoxBaseUrl'], $mainConfig['semknoxCustomerId'], $mainConfig['semknoxApiKey'], "updateSessionID");
        $api->addHeaderInfoData($this->semknoxSearchHelper->getHeaderInfoData());
        $r=$api->setLogPath($this->semknoxSearchHelper->add_ending_slash($this->logDir).'semknox');
        if ( (is_null($offset)) || ($offset === 0) ) {
            $this->semknoxSearchHelper->logData(10, 'log.update.LogStart', ['dir'=>$this->logDir, 'status'=>$r]);
            $api->resetJsonLog();
            $this->semknoxSearchHelper->logData(1, 'siteSearch360: start update: '.$mainConfig['semknoxCustomerId']."(".$mainConfig['semknoxLang'].")");
            $ret=-1;
            $res = $api->startBatchUpload();
            $logt=$res['status'];if (isset($res['resultText'])) { $logt.='##'.$res['resultText']; }
            $this->semknoxSearchHelper->logData(100, 'update.send.p1', ['updateSendData'=>$res]);
            if ($res['status'] < 0 ) { $ret = -2; return $ret; } else { $ret = 1; }
            sleep(2);
        }
        $this->semknoxSearchHelper->logData(1, 'siteSearch360: send update');
        $ret=-11;
        $res = $api->sendBatchDataBlocks($productList, $this->eventDispatcher);
        $logt=$res['status'];if (isset($res['resultText'])) { $logt.='##'.$res['resultText']; }if (isset($res['message']) && ($res['message']!=$res['resultText'])) { $logt.='##'.$res['message']; }
        $res['offset']=$offset;
        $this->semknoxSearchHelper->logData(10, 'update.send.p2', ['updateSendData'=>$res]);
        if ($res['status'] < 0) { $ret=-12; return $ret; } else { $ret = 1; }
        return $ret;
    }
    public function resetUpload(string $scID, string $langID, string $domainID) : int
    {
        $ret = 0;
        $mainConfig = $this->semknoxSearchHelper->getMainConfigParams($scID,$domainID);
        $api = new semknoxBaseApi($mainConfig['semknoxBaseUrl'], $mainConfig['semknoxCustomerId'], $mainConfig['semknoxApiKey'], "updateSessionID");
        $api->addHeaderInfoData($this->semknoxSearchHelper->getHeaderInfoData());
        $this->semknoxSearchHelper->logData(1, 'Semknox: update cancel/reset');
        $ret=-21;
        $res=['status'=>-1, 'resultText'=>'reset last update'];
        $this->semknoxSearchHelper->logData(10, 'update.send.p3', ['updateSendData'=>$res]);
        if ($res['status'] < 0) { $ret=-22; return $ret; } else { $ret = 1; }
        return $ret;
    }
    /**
     * sets the rules of the salesChannelContext
     * @param SalesChannelContext $context
     */
    public function setRulesToSalesChannelContext(SalesChannelContext $context) {
        $rules = $this->loadRules($context->getContext());
        $context->setRuleIds($rules->getIds());
    }
    private function loadRules(Context $context): RuleCollection
    {
        if ($this->rules !== null) {
            return $this->rules;
        }
        return $this->rules = $this->ruleLoader->load($context);
    }
}
