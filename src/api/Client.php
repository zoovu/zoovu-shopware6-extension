<?php
declare(strict_types = 1);
namespace semknox\search\api;
use Psr\Log\LoggerInterface;
use semknox\search\Exception\ApiConnectException;
/**
 * Class Client
 *
 * Client für SemknoxSearch
 * selektiert anhand der Config die API und führt Suche darüber aus
 * gibt @Searchbody in Search-Methode zurück mit Ergebnissen
 */
class Client
{
    const VERSION = '3.0.1';
    /**
     * @var string
     */
    private $environment;
    /**
     * @var LoggerInterface
     */
    private $logger;
    private $salesChannelID;
    private $baseUrl;
    private $apiKey;
    private $customerId;
    private $session;
    private $api;
    private $headerInfoData = ['shopsys'=>'SHOPWARE', 'shopsysver'=>'', 'extver'=>'', 'clientip'=>'', 'sessionid'=>''];  /** information which should be send by header like shopware-version etc. */
    /**
     * Client constructor
     *
     */    
    public function __construct(
        string $environment,
        LoggerInterface $logger
     )
    {
        $this->environment = $environment;
        $this->logger = $logger;
    }
    public function init(array $options) : void
    {
        $this->baseUrl = $this->extractArgument($options, 'baseUrl');
        $this->apiKey = $this->extractArgument($options, 'apiKey');
        $this->customerId = $this->extractArgument($options, 'customerId');
        $this->session = $this->extractArgument($options, 'session');
        $this->salesChannelID = $this->extractArgument($options, 'scId');
        $this->headerInfoData = $this->extractArgument($options, 'headerInfoData');
    }
    private function checkConfigData() : bool {
        $ret = true;
        if ( 
            ($this->apiKey === null)
            || ($this->customerId === null)
            || ($this->baseUrl === null)
            )
        {
            $ret=false;
        }
        return $ret;
    }
    public function search(array $params = []) : Searchbody
    {
        $ret=null;
        $index = $this->extractArgument($params, 'index');
        $type = $this->extractArgument($params, 'type');
        /**
         * SearchBody
         * @var Searchbody $body
         */
        $body = $this->extractArgument($params, 'body');
        if (!$this->checkConfigData()) {return $ret; }
        try {
            $this->api = new semknoxBaseApi($this->baseUrl, $this->customerId,$this->apiKey, $this->session);
            $this->api->addHeaderInfoData($this->headerInfoData);
            $ret = $this->api->QuerySearchResultsByBody($body);
            unset($this->api);
        } catch (\Throwable $e) {
            $this->logOrThrowException($e);
        }
        return $ret;
    }
    public function getSuggests(array $params = []) : Searchbody
    {
        $ret=null;
        $index = $this->extractArgument($params, 'index');
        $type = $this->extractArgument($params, 'type');
        /**
         * SearchBody
         * @var Searchbody $body
         */
        $body = $this->extractArgument($params, 'body');
        if (!$this->checkConfigData()) {return $ret; }
        try {
            $this->api = new semknoxBaseApi($this->baseUrl, $this->customerId,$this->apiKey, $this->session);
            $this->api->addHeaderInfoData($this->headerInfoData);
            $ret = $this->api->getSuggests($body);
            unset($this->api);
        } catch (\Throwable $e) {
            $this->logOrThrowException($e);
        }
        return $ret;
    }
    /**
     * @return null|mixed
     */
    public function extractArgument(array &$params, string $arg)
    {
        if (array_key_exists($arg, $params) === true) {
            $value = $params[$arg];
            unset($params[$arg]);
            return $value;
        } else {
            return null;
        }
    }
    public function logOrThrowException(\Throwable $exception): bool
    {
        if ($this->environment !== 'prod') {
            throw new \RuntimeException($exception->getMessage());
        }
        $this->logger->error($exception->getMessage());
        return false;
    }
}
