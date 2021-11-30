<?php
namespace semknox\search\Controller\Routes;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Shopware\Core\Framework\Routing\Annotation\RouteScope;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use semknox\search\Framework\SemknoxsearchHelper;
/**
 * @RouteScope(scopes={"api"})
 */
class CronController extends AbstractController
{
    private $cronFile='';
    private $restartFile='';
    /**
     *
     * @var SemknoxsearchHelper
     */
    private $semknoxSearchHelper;
    public function __construct(
        SemknoxsearchHelper $helper
     ) {
         $this->semknoxSearchHelper = $helper;
         $this->cronFile = $this->semknoxSearchHelper->getLogDirSubPath('lastcron.js');
         $this->restartFile = $this->semknoxSearchHelper->getLogDirSubPath('restartcron.js',1);
    }
    /**
     * @Route("/api/semknox_search/crondata", name="api.semknox_searchv", methods={"GET"})     
     */
    public function info(Request $request): JsonResponse
    {
        error_reporting(0);
        ini_set('display_errors', 'off');
        $response = new JsonResponse([
            'fileStatus' => $this->getCronFileStatus(),
            'localTimeCode' => time(),
            'dbData' => $this->getLogData()
        ]);
        return $response;
    }
    /**
     * @Route("/api/v{version}/semknox_search/crondata", name="api.semknox_search", methods={"GET"})
     */
    public function info_pre64(Request $request): JsonResponse
    {
        return $this->info($request);   
    }
    /**
     * @Route("/api/semknox_search/cronsetrestart", name="api.semknox_search_restart", methods={"GET"})     
     */
    public function setRestart(Request $request): JsonResponse
    {
        error_reporting(0);
        ini_set('display_errors', 'off');
        touch($this->restartFile);
        $response = new JsonResponse([
            'fileStatus' => $this->getRestartFileStatus(),
            'localTimeCode' => time()
        ]);
        return $response;
    }
    /**
     * @Route("/api/v{version}/semknox_search/cronsetrestart", name="api.semknox_search_restartv", methods={"GET"})
     */
    public function setRestart_pre64(Request $request): JsonResponse
    {
        return $this->setRestart($request);
    }
    private function getLogData() {
        $ret=[
                'currentUpdateActionTime'=>0, 'timeToLastUpdateAction'=>0, 'lastStart'=>[], 
                'timeToLastUpdateStart'=>0, 'lastFinished'=>[], 'timeToLastUpdateFinished'=>0, 
                'lastUpdateDuration' => 0
        ];
        $minCreate = 0;
        $ret['currentUpdateActionTime'] = $this->semknoxSearchHelper->getUpdateRunning();
        if ($ret['currentUpdateActionTime']) {
            $ret['timeToLastUpdateAction'] = time() - $ret['currentUpdateActionTime'];
            $ret['lastStart'] = $this->semknoxSearchHelper->getLastUpdateProcessStart();
            if (!empty($ret['lastStart'])) {
                $ret['timeToLastUpdateStart'] = time() - $ret['lastStart']['time'];
                $minCreate=$ret['lastStart']['time'];
                $ret['lastStart'] = $this->semknoxSearchHelper->getLastUpdateProcessStart($minCreate);
            }
        } else {
            $ret['lastStart'] = $this->semknoxSearchHelper->getLastUpdateProcessStart();
            if (!empty($ret['lastStart'])) {
                $ret['timeToLastUpdateStart'] = time() - $ret['lastStart']['time'];
            }            
        }
        $ret['lastFinished'] = $this->semknoxSearchHelper->getLastUpdateFinished();
        if (!empty($ret['lastFinished'])) {
            $ret['timeToLastUpdateFinished'] = time() - $ret['lastFinished']['time'];
            $ret['lastUpdateDuration'] = $ret['lastFinished']['time'] -$ret['lastStart']['time'];
        }
        return $ret;
    }
    private function getCronFileStatus() {
        $ret=['code' => 0, 'lastChange' => 0, 'tDiff' => 0];
        if (file_exists($this->cronFile)) {
            $ret['code'] = 1;
            $ret['lastChange'] = filemtime($this->cronFile);
            $ret['tDiff'] = time() -$ret['lastChange'];            
        }
        return $ret;
    }
    private function getRestartFileStatus() {
        $ret=['code' => 0, 'lastChange' => 0, 'tDiff' => 0];
        if (file_exists($this->restartFile)) {
            $ret['code'] = 1;
            $ret['lastChange'] = filemtime($this->restartFile);
            $ret['tDiff'] = time() -$ret['lastChange'];
        }
        return $ret;
    }
}
