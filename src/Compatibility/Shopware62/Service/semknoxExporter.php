<?php declare(strict_types=1);
namespace semknox\search\Compatibility\Shopware62\Service;
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
use Shopware\Core\Checkout\Cart\CartRuleLoader;
use semknox\search\Service\semknoxExporterInterface;
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
     * CartRuleLoader
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
        CartRuleLoader $loader
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
        $this->createLogPath($this->add_ending_slash($this->logDir).'semknox');
        $mylogdir = $this->add_ending_slash($this->add_ending_slash($this->logDir).'semknox');
        $productResult = $productProvider->getProductData($salesChannelContext, $this->batchSize, $offset, $mylogdir);
        $host = $this->getHost($salesChannelContext);
        $finish=false;
        if ($productResult->getNextOffset() !== null) {
            $lastProvider = $productProvider->getName();
        } else {
            $finish = true;
            $nextProvider = $this->getNextProductProvider($productProvider->getName());
            $lastProvider = $nextProvider ? $nextProvider->getName() : null;
        }
        if ($finish) {
            $this->unlock($salesChannelContext);
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
            if ($finish) {
                $api = new semknoxBaseApi($mainConfig['semknoxBaseUrl'], $mainConfig['semknoxCustomerId'], $mainConfig['semknoxApiKey'], "updateSessionID");
                $api->addHeaderInfoData($this->semknoxSearchHelper->getHeaderInfoData());
                $api->setLogPath($this->add_ending_slash($this->logDir).'semknox');
                $this->semknoxSearchHelper->logData(1, 'Semknox: beende Vollupdate');
                $ret=-21;
                sleep(2);
                $res = $api->finishBatchUpload();
                $logt=$res['status'];if (isset($res['resultText'])) { $logt.='##'.$res['resultText']; }
                $this->semknoxSearchHelper->logData(10, 'update.send.p3', ['updateSendData'=>$res]);
                if ($res['status'] < 0) { $ret=-22; return $ret; } else { $ret = 1; }                
            }
            return $ret; 
        }
        $api = new semknoxBaseApi($mainConfig['semknoxBaseUrl'], $mainConfig['semknoxCustomerId'], $mainConfig['semknoxApiKey'], "updateSessionID");
        $api->addHeaderInfoData($this->semknoxSearchHelper->getHeaderInfoData());
        $r=$api->setLogPath($this->add_ending_slash($this->logDir).'semknox');
        if ( (is_null($offset)) || ($offset === 0) ) {
            $this->semknoxSearchHelper->logData(10, 'log.update.LogStart', ['dir'=>$this->logDir, 'status'=>$r]);
            $api->resetJsonLog();
            $this->semknoxSearchHelper->logData(1, 'Semknox: starte Vollupdate: '.$mainConfig['semknoxCustomerId']."(".$mainConfig['semknoxLang'].")");
            $ret=-1;
            $res = $api->startBatchUpload();
            $logt=$res['status'];if (isset($res['resultText'])) { $logt.='##'.$res['resultText']; }
            $this->semknoxSearchHelper->logData(10, 'update.send.p1', ['updateSendData'=>$res]);
            if ($res['status'] < 0 ) { $ret = -2; return $ret; } else { $ret = 1; }
            sleep(2);
        }
        $this->semknoxSearchHelper->logData(1, 'Semknox: sende Vollupdate');
        $ret=-11;
        $res = $api->sendBatchDataBlocks($productList, $this->eventDispatcher);
        $logt=$res['status'];if (isset($res['resultText'])) { $logt.='##'.$res['resultText']; }if (isset($res['message']) && ($res['message']!=$res['resultText'])) { $logt.='##'.$res['message']; }
        $res['offset']=$offset;
        $this->semknoxSearchHelper->logData(10, 'update.send.p2', ['updateSendData'=>$res]);
        if ($res['status'] < 0) { $ret=-12; return $ret; } else { $ret = 1; }
        if ($finish) {
            $this->semknoxSearchHelper->logData(1, 'Semknox: beende Vollupdate');
            $ret=-21;
            sleep(2);
            $res = $api->finishBatchUpload();
            $logt=$res['status'];if (isset($res['resultText'])) { $logt.='##'.$res['resultText']; }
            $this->semknoxSearchHelper->logData(10, 'update.send.p3', ['updateSendData'=>$res]);
            if ($res['status'] < 0) { $ret=-22; return $ret; } else { $ret = 1; }
        }
        return $ret;
    }
    public function resetUpload(string $scID, string $langID, string $domainID) : int
    {
        $ret = 0;
        $mainConfig = $this->semknoxSearchHelper->getMainConfigParams($scID,$domainID);
        $api = new semknoxBaseApi($mainConfig['semknoxBaseUrl'], $mainConfig['semknoxCustomerId'], $mainConfig['semknoxApiKey'], "updateSessionID");
        $api->addHeaderInfoData($this->semknoxSearchHelper->getHeaderInfoData());
        $this->semknoxSearchHelper->logData(1, 'Semknox: Vollupdate Abbruch/Reset');
        $ret=-21;
        $logt=$res['status'];if (isset($res['resultText'])) { $logt.='##'.$res['resultText']; }
        $this->semknoxSearchHelper->logData(10, 'update.send.p3', ['updateSendData'=>$res]);
        if ($res['status'] < 0) { $ret=-22; return $ret; } else { $ret = 1; }
        return $ret;
    }
    /**
     * sets the rules of the salesChannelContext
     * @param SalesChannelContext $context
     */
    public function setRulesToSalesChannelContext(SalesChannelContext $context) {
    		return;
        $rules = $this->loadRules($context->getContext());
        $context->setRuleIds($rules->getIds());
    }
    private function loadRules(Context $context): RuleCollection
    {
        if ($this->rules !== null) {
            return $this->rules;
        }
        return $this->rules = $this->ruleLoader->loadRules($context);
    }
}
