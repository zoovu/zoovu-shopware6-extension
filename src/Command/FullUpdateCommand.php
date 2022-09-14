<?php declare(strict_types=1);
/**
 * startroutine for updating from the cli
 * example:
 *  *\/1 * * * * php /var/www/shopware/bin/console plugin-commands:semknoxFullUpdate >> /var/www/shopware/var/log/semknox/cron.log
 */
namespace semknox\search\Command;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextFactory;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextService;
use Shopware\Core\System\SalesChannel\SalesChannelCollection;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use semknox\search\Exception\AlreadyLockedException;
use semknox\search\Framework\SemknoxsearchHelper;
use semknox\search\Service\semknoxExporterInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainEntity;
class FullUpdateCommand extends Command
{
    protected static $defaultName = 'plugin-commands:semknoxFullUpdate';
    /**
     * @var EntityRepositoryInterface
     */
    private $salesChannelRepository;
    /**
     * @var SalesChannelContextFactory
     */
    private $salesChannelContextFactory;
    /**
     *
     * @var EntityRepositoryInterface
     */
    private $logRepository;
    /**
     * @var LoggerInterface
     */
    private $logger;
    /**
     * @var SystemConfigService
     */
    private $systemConfigService;
    /**
     *
     * @var SemknoxsearchHelper
     */
    private $helper;
    private $isNewSC = 1;
    private $firstStart = 1;
    private $updateTimeMinRange = 5; 
    /**
     * @var semknoxExporterInterface
     */
    private $semknoxExporter;
    private $cronFile='';
    private $preferences = [];
    protected function configure(): void
    {
        $this->setName(self::$defaultName);
    }
    public function __construct(
        EntityRepositoryInterface $salesChannelRepository,
        $salesChannelContextFactory,
        LoggerInterface $logger,
        SystemConfigService $systemConfigService,
        SemknoxsearchHelper $helper,
        semknoxExporterInterface $semknoxExporter,
        EntityRepositoryInterface $logRepo        
        ) {
            parent::__construct();
            $this->salesChannelRepository = $salesChannelRepository;
            $this->salesChannelContextFactory = $salesChannelContextFactory;
            $this->logger = $logger;
            $this->systemConfigService = $systemConfigService;
            $this->helper = $helper;
            $this->semknoxExporter = $semknoxExporter;
            $this->logRepository = $logRepo;
            $this->preferences = $this->helper->getPreferences();
    }
    /**
     * function returns 1, if we should restart the update (maybe by error) or 0 if not
     * lastupdate is the timestamp of last starttime, startdata currently not needed
     * @return number
     */
    private function checkRestart($lastUpdate, $startData) {
        $doRestart=0;
        if ($lastUpdate > 43000) { $doRestart=1; }
        $restartFile = $this->helper->getLogDirSubPath('restartcron.js',1);
        if (file_exists($restartFile)) {
            $doRestart=1;
            unlink($restartFile);
        }
        return $doRestart;
    }
    private function resetRestartFile() {
        $restartFile = $this->helper->getLogDirSubPath('restartcron.js',1);
        if (file_exists($restartFile)) {
            unlink($restartFile);
        }        
    }
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->cronFile = $this->helper->getLogDirSubPath('lastcron.js',1);
        touch($this->cronFile);
        $this->helper->setOutputInterface($output);
        $isupdateRunning = $this->getUpdateRunning();
        $startData = $this->getLastStartRunning();
        if ($isupdateRunning != 0) {
            $lastUpdate = time()-$isupdateRunning;            
            $this->helper->setLogRepository($this->logRepository);
            if ($this->checkRestart($lastUpdate, $startData)) {                
                $scID='';$langID='';$domainID='';
                if ( (!empty($startData)) && (isset($startData['usData'])) && (isset($startData['usData']['scID']))  && (isset($startData['usData']['langID']))   && (isset($startData['usData']['domainID'])) ) {
                    $scID = $startData['usData']['scID'];
                    $langID = $startData['usData']['langID'];
                    $domainID = $startData['usData']['domainID'];
                    $this->semknoxExporter->resetUpload($scID, $langID, $domainID);
                }
                $this->helper->uploadblocks_resetFile();
                $this->helper->uploadblocks_resetAllProductDataFiles();
                $additional=['usData' => ['error'=>'update canceled - no action for '.$lastUpdate.' seconds', 'scID'=>$scID, 'langID'=>$langID, 'domainID'=>$domainID, 'provider'=> '', 'offset'=>0, 'cancel'=>'yes', 'lastUpdate'=>$lastUpdate]];
                $this->helper->logData(100, 'update.finished', $additional);
            } else {
                $runData = $this->helper->uploadblocks_startNextBlock();
                if ( (!empty($runData)) && (isset($runData['usData'])) && (isset($runData['usData']['scID']))  && (isset($runData['usData']['langID']))  && (isset($startData['usData']['domainID'])) ) {
                    $scID = $runData['usData']['scID'];
                    $langID = $runData['usData']['langID'];
                    $domainID = $runData['usData']['domainID'];
                    $provider = $runData['usData']['provider'];
                    $offset = $runData['usData']['offset'];
                    $finished = $runData['usData']['finished'];
                    $this->firstStart=0;
                    $newMessage = new semknoxFUData($scID, $langID, $domainID, $provider, $offset, $finished);
                    $this->resetRestartFile();
                    $this->generateData($newMessage);
                }
                return 0;
            }
        } else {
            if ($this->checkRestart(0,$startData)==0) {
                $nextStart = $this->getNextStartTime();
                if ($nextStart > time()) { return 0; }
                if ( (!empty($startData)) && (is_array($startData)) && (isset($startData['time'])) ) {
                    $lastBaseStarttime = $this->getLastBaseStartTime($startData);
                    if ($lastBaseStarttime > 0) {
                        $lastInterval = time() - $lastBaseStarttime;
                        if ( $lastInterval < ( (intval($this->preferences['semknoxUpdateCronInterval'])*60*60) - ($this->updateTimeMinRange*60) ) ) {
                            return 0;
                        }
                    }
                }
                $this->helper->uploadblocks_resetFile();
                $this->helper->uploadblocks_resetAllProductDataFiles();
            }
        }
        $this->resetRestartFile();
        $this->helper->uploadblocks_resetFile();
        $this->helper->uploadblocks_resetAllProductDataFiles();
        $this->generateData(new semknoxFUData(null, null, null, null, null, false));
        return 0;
    }
    /**
     * returning the "base"-Starttime of last update-process.
     * cut the min/secs from the last process, only use the hours for base-starttime.
     * @param array $startData
     * @return number
     */
    private function getLastBaseStartTime($startData) {
        $ret=0;
        if ( (is_array($startData)) && (isset($startData['time'])) ) {
            $nh=intval(date('H', $startData['time']));
            $ret = mktime($nh, 0, 0, intval(date("n", $startData['time'])) , intval(date("j", $startData['time'])) , intval(date("Y", $startData['time'])) );
        }
        return $ret;
    }
    /**
     * generating timestamp of next starting time by semknoxUpdateCronTimeList.
     * Depending on the hour in the list and current timestamp, returning the Timestamp of next Starttime. A 
     * @return number
     */
    private function getNextStartTime() {
        $ret=0;
        $nh=intval(date('H'));
        $nm=intval(date('i'));
        foreach ( $this->preferences['semknoxUpdateCronTimeList'] as $h) {
            if ( ($h == $nh) && ($nm <= ($this->updateTimeMinRange)) ) {
                $ret=$h;break;
            }
            if ($h > $nh) {
                $ret=$h;break;
            }
        }
        $nextday=0;
        if ($ret==0) {
            $nextday=1;
            $ret=$this->preferences['semknoxUpdateCronTimeList'][0];
        }
        $ret = mktime($ret, 0, 0, intval(date("n")) , intval(date("j")) , intval(date("Y")) );
        if ($nextday==1) {
            $date = new \DateTime('');
            $date->setTimestamp($ret);
            $date->modify('+1 day');
            $ret = $date->getTimestamp();
        }
        return $ret;
    }
   /**
     * returns time of last update from db-semknox-logs.
     * no update running - return 0
     * if last entry = update.finished, return 0
     */
    public function getUpdateRunning() : Int
    {
        $lastentries = $this->helper->getQueryResult("SELECT logtype, status, created_at from semknox_logs WHERE logtype like 'update.%' order by created_at desc LIMIT 3");
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
    public function getLastStartRunning() : array
    {
        $lastentries = $this->helper->getQueryResult("SELECT logtype, status, logdescr, created_at from semknox_logs WHERE logtype like 'update.start' order by created_at desc LIMIT 3");
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
    public function getCurrentRunning() : array
    {
        $lastentries = $this->helper->getQueryResult("SELECT logtype, status, logdescr, created_at from semknox_logs WHERE logtype like 'update.%' order by created_at desc LIMIT 1");
        $ret=[];
        foreach ($lastentries as $ent) {
            if ($ent['logtype']=='update.nextBlockFin') {
                if (!empty($ent['logdescr'])) {
                    $ret = json_decode($ent['logdescr'], true);
                    $ret['time'] = strtotime($ent['created_at']);
                }
            }
            break;
        }
        return $ret;
    }
    public function getCurrentRunningFile() : array
    {
        $lastentries = $this->helper->getQueryResult("SELECT logtype, status, logdescr, created_at from semknox_logs WHERE logtype like 'update.%' order by created_at desc LIMIT 1");
        $ret=[];
        foreach ($lastentries as $ent) {
            if ($ent['logtype']=='update.nextBlockFin') {
                if (!empty($ent['logdescr'])) {
                    $ret = json_decode($ent['logdescr'], true);
                    $ret['time'] = strtotime($ent['created_at']);
                }
            }
            break;
        }
        return $ret;
    }
    private function generateData(semknoxFUData $message): void
    {
        $this->helper->setLogRepository($this->logRepository);
        $this->helper->uploadblocks_checkStatus();
        $salesChannelContext = $this->getSalesChannelContext($message);
        if (!($salesChannelContext instanceof SalesChannelContext)) {
            $this->helper->logData(100, 'update.finished');
            $this->helper->uploadblocks_setBlockStatus('','', -1000, 100);
            return;
        }
        $mainConfig = $this->helper->allowSalesChannel($salesChannelContext->getSalesChannel()->getId(), $this->helper->getDomainFromSCContextExt($salesChannelContext), 1);
        if (is_null($mainConfig)) {
            $newMessage = new semknoxFUData($salesChannelContext->getSalesChannel()->getId(), $salesChannelContext->getSalesChannel()->getLanguageId(),  $this->helper->getDomainFromSCContextExt($salesChannelContext), "", 0, true);
            $this->generateData($newMessage);
        } else {
            try {
                if ( ($message->isFinished()) && ($message->getNextOffset()==10000000000) ) {
                    $ret = $this->semknoxExporter->finishUpdate($salesChannelContext);
                    if ($ret['status'] > 0) {
                        $this->helper->uploadblocks_setBlockStatusBySC($salesChannelContext, 10000000000, 2);
                        $this->helper->logData(100, 'update.sentUpdateInit', []);
                    } else {
                        $this->helper->uploadblocks_setBlockStatusBySC($salesChannelContext, 10000000000, 0, 'unknown error: '.$ret['resultText']);
                        $this->helper->logData(100, 'update.sentUpdateInit.Error', $ret);
                        return;
                    }
                    $catResult = $this->semknoxExporter->generateCategoriesData($salesChannelContext, $message->getLastProvider(), $message->getNextOffset(), $this->preferences['semknoxUpdateBlocksize']);
                    if ($catResult['status'] > 0) {
                        $this->helper->uploadblocks_setBlockStatusBySC($salesChannelContext, 10000000000, 100);
                        $this->helper->logData(100, 'update.sentCatData', []);
                    } else {
                        $this->helper->uploadblocks_setBlockStatusBySC($salesChannelContext, 10000000000, 0, 'error: '.$catResult['resultText']);
                        $this->helper->logData(100, 'update.sentCatData.Error', $catResult);
                    }
                    return;
                }
                if ($this->firstStart) {
                    $this->helper->logData(100, 'update.process.start',[]);
                    $this->firstStart=0;
                }
                $additional=['usData' => ['scID'=>$salesChannelContext->getSalesChannel()->getId(), 'langID'=>$salesChannelContext->getSalesChannel()->getLanguageId(), 'domainID' => $this->helper->getDomainFromSCContextExt($salesChannelContext), 'provider'=> $message->getLastProvider(), 'offset'=>$message->getNextOffset()]];
                if ($this->isNewSC) {
                    $this->helper->logData(100, 'update.start',$additional);
                }
                $this->helper->logData(100, 'update.nextBlockStart',$additional);
                $result = $this->semknoxExporter->generate($salesChannelContext, true, $message->getLastProvider(), $message->getNextOffset(), $this->preferences['semknoxUpdateBlocksize']);
                $additional=['usData' => ['scID'=>$result->getLastSalesChannelId(), 'langID'=>$result->getLastLanguageId(), 'domainID' => $this->helper->getDomainFromSCContextExt($salesChannelContext), 'provider'=> $result->getProvider(), 'offset'=>$result->getOffset(), 'finished'=>$result->isFinish()]];
                $this->helper->logData(100, 'update.nextBlockFin',$additional);
            } catch (AlreadyLockedException $exception) {
                $this->helper->logData(10, sprintf('ERROR: %s', $exception->getMessage()), [], 500 );
            }
        }
    }
    /**
     * function checks, whether the salesChannel has Domains and it's domains have languages associated
     * result false, if not, else true
     * alternative is to check the database directly as in SalesChannelContextFactory.php to check if there is any languageId 
     * @param SalesChannelEntity $salesChannel
     * @return bool
     */
    private function checkSCHasLanguage($salesChannel) : bool {
        $dom = $salesChannel->getDomains();
        if (count($dom) <= 0) {
            return false;
        }
        $c=0;
        foreach ($salesChannel->getDomains() as $domain) {
            if (!empty($domain->getLanguageId())) { $c++; }
        }
        if ($c > 0) {	return true; } else { return false; }
    }
    private function getSalesChannelContext(semknoxFUData $message)
    {
        if ( (($message->isFinished() === false) && ($message->getLastSalesChannelId() !== null) ) ||
             (($message->isFinished() === true) && ($message->getNextOffset()==10000000000)) ) {
            $this->helper->logData(1, 'woso_continue with last used saleschannel ' . $message->getLastSalesChannelId() . ' ' . $message->getLastLanguageId() . ' '. $message->getLastDomainId());
            $this->isNewSC=0;
            try {
                $sc = $this->salesChannelContextFactory->create('', $message->getLastSalesChannelId(), [SalesChannelContextService::LANGUAGE_ID => $message->getLastLanguageId()]);
                $this->helper->setDomainToSCContextExt($sc, ['domainId'=>$message->getLastDomainId(), 'languageId' => $message->getLastLanguageId()]);
                return $sc;
            } catch (\Exception $exception) {
                $this->helper->logData(10, sprintf('ERROR: %s', $exception->getMessage()), [], 500 );
            }
        }
        $this->isNewSC = 1;
        $context = Context::createDefaultContext();
        $criteria = new Criteria();
        $criteria->addAssociation('domains');
        /** @var SalesChannelCollection $salesChannels */
        $salesChannels = $this->salesChannelRepository->search($criteria, $context)->getEntities();
        $useNextChannel = false;
        $useNextDomain = false;
        if ($message->getLastSalesChannelId() === null) {
            $useNextChannel=true;$useNextDomain=true;
        }
        /** @var SalesChannelEntity $salesChannel */
        foreach ($salesChannels as $salesChannel) {
            if ($salesChannel->getId() === $message->getLastSalesChannelId()) { $useNextChannel = true; }
            if ($useNextChannel) {
                /** @var SalesChannelDomainEntity $domain */                
                foreach ($salesChannel->getDomains() as $domain) {
                    if ($useNextDomain) {
                        try {
                            $sc = $this->salesChannelContextFactory->create('', $salesChannel->getId(), [SalesChannelContextService::LANGUAGE_ID => $domain->getLanguageId()]);
                            $this->helper->setDomainToSCContextExt($sc, ['domainId'=>$domain->getId() , 'languageId' => $domain->getLanguageId()]);
                            return $sc;
                        } catch (\Exception $exception) {
                            $this->helper->logData(10, sprintf('ERROR gssc4: %s', $exception->getMessage()), [], 500 );
                        }                       
                    }
                    if ($domain->getId() === $message->getLastDomainId()) { $useNextDomain = true; }                    
                }
            }
        }
        return null;
    }
    private function getSalesChannelContext_old(semknoxFUData $message)
    {
        if ( ($message->isFinished() === false) && ($message->getLastSalesChannelId() !== null) ) {
            $this->helper->logData(1, 'woso_continue with last used saleschannel ' . $message->getLastSalesChannelId() . ' ' . $message->getLastLanguageId() . ' '. $message->getLastDomainId());
            $this->isNewSC=0;
            try {
                $sc = $this->salesChannelContextFactory->create('', $message->getLastSalesChannelId(), [SalesChannelContextService::LANGUAGE_ID => $message->getLastLanguageId()]);
                $this->helper->setDomainToSCContextExt($sc, ['domainId'=>$message->getLastDomainId(), 'languageId' => $message->getLastLanguageId()]);
                return $sc;
            } catch (\Exception $exception) {
                $this->helper->logData(10, sprintf('ERROR: %s', $exception->getMessage()), [], 500 );
            }
        }
        $this->isNewSC = 1;
        $context = Context::createDefaultContext();
        $criteria = new Criteria();
        $criteria->addAssociation('domains');
        /** @var SalesChannelCollection $salesChannels */
        $salesChannels = $this->salesChannelRepository->search($criteria, $context)->getEntities();
        if ($message->getLastSalesChannelId() === null) {
            $salesChannel = $salesChannels->first();
            try {
                $sc = $this->salesChannelContextFactory->create('', $salesChannel->getId(), [SalesChannelContextService::LANGUAGE_ID => $salesChannel->getLanguageId()]);
                $this->helper->setDomainToSCContextExt($sc, ['domainId'=>$message->getLastDomainId(), 'languageId' => $message->getLastLanguageId()]);
                return $sc;
            } catch (\Exception $exception) {
                $this->helper->logData(10, sprintf('ERROR gssc2: %s', $exception->getMessage()), [], 500 );
            }
        }
        $useNextChannel = false;
        $useNextLanguage = false;
        foreach ($salesChannels as $salesChannel) {
            if ($this->checkSCHasLanguage($salesChannel) === false) {
                if ($salesChannel->getId() == $message->getLastSalesChannelId()) {
                    $useNextChannel = true;
                }
                continue;
            }
            if ($useNextChannel === true || $useNextLanguage === true) {
                try {
                    return $this->salesChannelContextFactory->create('', $salesChannel->getId(), [SalesChannelContextService::LANGUAGE_ID => $salesChannel->getLanguageId()]);
                } catch (\Exception $exception) {
                    $this->helper->logData(10, sprintf('ERROR gssc3: %s', $exception->getMessage()), [], 500 );
                }
            }
            if ($salesChannel->getId() !== $message->getLastSalesChannelId()) {
                continue;
            }
            if (\count($salesChannel->getDomains()) === 0) {
                $useNextChannel = true;
                continue;
            }
            foreach ($salesChannel->getDomains() as $domain) {
                if ($useNextLanguage === true) {
                    if ( ($salesChannel->getId() === $message->getLastSalesChannelId()) && ($domain->getLanguageId() === $message->getLastLanguageId()) ) {
                        continue;
                    }
                    try {
                        return $this->salesChannelContextFactory->create('', $salesChannel->getId(), [SalesChannelContextService::LANGUAGE_ID => $domain->getLanguageId()]);
                    } catch (\Exception $exception) {
                        $this->helper->logData(10, sprintf('ERROR gssc4: %s', $exception->getMessage()), [], 500 );
                    }
                }
                if ($domain->getLanguageId() !== $message->getLastLanguageId()) {
                    continue;
                }
                $useNextLanguage = true;
            }
        }
        return null;
    }
}
