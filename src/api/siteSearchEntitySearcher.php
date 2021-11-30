<?php declare(strict_types=1);
namespace semknox\search\api;
use semknox\search\api\Client;
use semknox\search\api\Searchbody;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearcherInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Grouping\FieldGrouping;
use Shopware\Core\Framework\DataAbstractionLayer\Search\IdSearchResult;
use semknox\search\Framework\SemknoxsearchHelper;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Struct\ArrayEntity;
class siteSearchEntitySearcher 
{
    /**
     * @var Client
     */
    private $client;
    /**
     * @var SemknoxsearchHelper
     */
    private $helper;
    public function __construct(
        Client $client,
        SemknoxsearchHelper $helper
    ) {
        $this->client = $client;
        $this->helper = $helper;
    }
    public function search(EntityDefinition $definition, Criteria $criteria, Context $context): IdSearchResult
    {
        $salesChannelID='';$controller='';$langID='';
        if ($criteria->hasExtension('semknoxData')) {
            $semkData = $criteria->getExtension('semknoxData');
            $salesChannelID=$semkData->get('salesChannelID');
            $langID = $semkData->get('languageID');
            $domainID = $semkData->get('domainID');
            $controller=$semkData->get('controller');
        }
        if ( (trim($salesChannelID)=='') || (trim($langID)=='') || (trim($controller)=='') )  {
            return $this->decorated->search($definition, $criteria, $context);
        }
        $mainConfig=$this->helper->allowSearch($definition, $context, $salesChannelID, $domainID, $controller);
        if (is_null($mainConfig)) {           
            return $this->decorated->search($definition, $criteria, $context);
        }
        $searchMode = 0;
        if ($controller == 'Shopware\Storefront\Controller\SearchController::suggest') {
            $searchMode = 11;
        }
        $this->helper->setSalesChannelID($salesChannelID);
        $search = $this->createSearch($criteria, $definition, $context);
        $search->setSearchMode(0);
        $this->client->init([
            'baseUrl' => $mainConfig['semknoxBaseUrl'],
            'apiKey' => $mainConfig['semknoxApiKey'],
            'customerId' => $mainConfig['semknoxCustomerId'],
            'session' => 'userQuerySession',            
            'scId' => $salesChannelID,
            'headerInfoData' => $this->helper->getHeaderInfoData()
        ]);
        try {
            if ($searchMode == 0) {
                $result = $this->client->search([
                    'index' => $this->helper->getIndexName($definition, $context->getLanguageId()),
                    'type' => $definition->getEntityName(),
                    'track_total_hits' => true,
                    'body' => $search,
                    'scID' => $salesChannelID,
                ]);
            } else {
                $result = $this->client->getSuggests([
                    'index' => $this->helper->getIndexName($definition, $context->getLanguageId()),
                    'type' => $definition->getEntityName(),
                    'track_total_hits' => true,
                    'body' => $search,
                    'scID' => $salesChannelID,
                ]);               
            }
        } catch (\Throwable $e) {
            $this->helper->logOrThrowException($e);
            return $this->decorated->search($definition, $criteria, $context);
        }
        return $this->hydrate($criteria, $context, $result);
    }
    protected function createSearch(Criteria $criteria, EntityDefinition $definition, Context $context): Searchbody
    {
        $search = new Searchbody();
        $this->helper->addTerm($criteria, $search, $context);
        $search->setSize($criteria->getLimit());
        $search->setFrom($criteria->getOffset());
        $this->helper->addPostFilters($definition, $criteria, $search, $context);
        $this->helper->addSortings($definition, $criteria, $search, $context);
        return $search;
    }
    private function hydrate(Criteria $criteria, Context $context, Searchbody $result): IdSearchResult
    {
        $criteria->addExtension('semknoxResultData', new ArrayEntity(
            [
                'metaData' => $result->getSearchMetadata(),
                'sortData' => $result->getSortData(),
                'filterData' => $result->getFilterData(),
                'jsonDecoded' => $result->getDecodedJson()
            ]
            ));
        if ($result->getRedirect()!='') {
            $res =  new IdSearchResult(0, [], $criteria, $context);
            $res->addExtension('semknoxResultData', new ArrayEntity(
                [
                    'metaData' => $result->getSearchMetadata(),
                    'sortData' => [],
                    'filterData' => [],
                    'customResults' => [],
                    'redirect' => $result->getRedirect(),
                    'jsonDecoded' => $result->getDecodedJson()
                ]
                ));
            return $res;
        }
        if ($result->getUseInternalSearch()) {
            $res =  new IdSearchResult(0, [], $criteria, $context);
            $res->addExtension('semknoxResultData', new ArrayEntity(
                [
                    'metaData' => $result->getSearchMetadata(),
                    'sortData' => [],
                    'filterData' => [],
                    'customResults' => [],
                    'useInternalSearch' => $result->getUseInternalSearch(),
                    'jsonDecoded' => $result->getDecodedJson()
                ]
                ));
            return $res;
        }
        $hits = $result->getResultListCalc();
        if (count($hits) <= 0) {
            return new IdSearchResult(0, [], $criteria, $context);
        }
        $data = [];
        foreach ($hits as $hit) {
            $id = $hit['id'];
            $data[$id] = [
                'primaryKey' => $id,
                'data' => [
                    'id' => $id,
                    'datapoints' => $hit['datapoints'],
                ],
            ];
        }
        $total = (int) $result->getTotalResultsCalc();
        /*
        if ($criteria->getGroupFields()) {
            $total = (int) $result['aggregations']['total-count']['value'];
        }        
        */
        $res = new IdSearchResult($total, $data, $criteria, $context);
        $res->addExtension('semknoxResultData', new ArrayEntity(
            [
                'metaData' => $result->getSearchMetadata(),
                'sortData' => $result->getSortData(),
                'filterData' => $result->getFilterData(),
                'customResults' => $result->getCustomResults(),
                'jsonDecoded' => $result->getDecodedJson()
            ]
            ));
        return $res;
    }
    private function extractHits(array $result): array
    {
        $records = [];
        $hits = $result['hits']['hits'];
        foreach ($hits as $hit) {
            if (!isset($hit['inner_hits'])) {
                $records[] = $hit;
                continue;
            }
            $nested = $this->extractHits($hit['inner_hits']['inner']);
            foreach ($nested as $inner) {
                $records[] = $inner;
            }
        }
        return $records;
    }
}
