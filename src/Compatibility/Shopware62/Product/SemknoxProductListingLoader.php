<?php declare(strict_types=1);
namespace semknox\search\Compatibility\Shopware62\Product;
use Doctrine\DBAL\Connection;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Product\SalesChannel\ProductAvailableFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\AggregationResultCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Grouping\FieldGrouping;
use Shopware\Core\Framework\DataAbstractionLayer\Search\IdSearchResult;
use Shopware\Core\Framework\Struct\ArrayEntity;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepositoryInterface;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Core\Content\Product\SalesChannel\Listing\ProductListingLoader;
use semknox\search\api\siteSearchEntitySearcher;
use Shopware\Core\Content\Product\ProductDefinition;
class SemknoxProductListingLoader
{
    /**
     * @var SalesChannelRepositoryInterface
     */
    private $repository;
    /**
     * @var SystemConfigService
     */
    private $systemConfigService;
    /**
     * @var Connection
     */
    private $connection;
    /**
     * @var ProductListingLoader
     */
    private $decorated;
    /**
     *
     * @var siteSearchEntitySearcher
     */
    private $entitySearcher;
    /**
     * 
     * @var ProductDefinition
     */
    private $prodDefinition;
    public function __construct(
        SalesChannelRepositoryInterface $repository,
        SystemConfigService $systemConfigService,
        Connection $connection,
        ProductListingLoader $productListingLoader,
        siteSearchEntitySearcher $es,
        ProductDefinition $pd
        ) {
            $this->repository = $repository;
            $this->systemConfigService = $systemConfigService;
            $this->connection = $connection;
            $this->decorated = $productListingLoader;
            $this->entitySearcher = $es;
            $this->prodDefinition = $pd;
        }
        public function load(Criteria $origin, SalesChannelContext $context): EntitySearchResult
        {
            $criteria = clone $origin;
            $ids = $this->entitySearcher->search($this->prodDefinition, $criteria, $context->getContext());
            $ext=$ids->getExtension('semknoxResultData');
            if ($ext) {
                $semknoxData = $ext->getVars();
                if (isset($semknoxData['data']['redirect'])) {
                    $redir = $semknoxData['data']['redirect'];
                }
            }
            if (empty($ids->getIds())) {
                $res = new EntitySearchResult(
                    0,
                    new ProductCollection(),
                    new AggregationResultCollection(),
                    $origin,
                    $context->getContext()
                    );
                $this->addExtensions($ids, $res);
                return $res;
            }
            $aggregations = new AggregationResultCollection(); 
            $variantIds = $ids->getIds();
            $mapping = array_combine($ids->getIds(), $ids->getIds());
/*            
            if (!$this->hasOptionFilter($criteria)) {
                list($variantIds, $mapping) = $this->resolvePreviews($ids->getIds(), $context);
            }
*/            
            $read = $criteria->cloneForRead($variantIds);
            $entities = $this->repository->search($read, $context);
            $this->addExtensions($ids, $entities, $mapping);
            $res = new EntitySearchResult(
                $ids->getTotal(),
                $entities->getEntities(),
                $aggregations,
                $origin,
                $context->getContext()
                );
            $this->addExtensions($ids, $res);
            return $res;
        }
        private function addExtensions(IdSearchResult $ids, EntitySearchResult $entities): void
            {
            foreach ($ids->getExtensions() as $name => $extension) {
                $entities->addExtension($name, $extension);
            }
        }
}
