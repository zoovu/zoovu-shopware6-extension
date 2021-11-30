<?php declare(strict_types=1);
namespace semknox\search\Product;
use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Content\Product\SearchKeyword\ProductSearchBuilderInterface;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Routing\Exception\MissingRequestParameterException;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use semknox\search\Framework\SemknoxsearchHelper;
use Symfony\Component\HttpFoundation\Request;
use Shopware\Core\Framework\Struct\ArrayEntity;
class ProductSearchBuilder implements ProductSearchBuilderInterface
{
    /**
     * @var SemknoxsearchHelper
     */
    private $helper;
    /**
     *
     * @var EntityRepositoryInterface
     */
    private $logRepository;
    /**
     * @var ProductDefinition
     */
    private $productDefinition;
    private $systemConfig;
    public function __construct(
        SemknoxsearchHelper $helper,
        ProductDefinition $productDefinition,
        SystemConfigService $systemConfigService,
        EntityRepositoryInterface $logRepo
    ) {
        $this->helper = $helper;
        $this->productDefinition = $productDefinition;
        $this->systemConfig = $systemConfigService;
        $this->logRepository = $logRepo;
        $this->helper->setLogRepository($this->logRepository);
    }
    public function build(Request $request, Criteria $criteria, SalesChannelContext $context): void
    {
        $scID=$this->helper->getSalesChannelFromSCContext($context);
        $langID = $this->helper->getLanguageFromSCContext($context);
        $domainID = $this->helper->getDomainFromSCContext($context);
        $contr=$request->attributes->get('_controller');
        $mainConfig=$this->helper->allowSearch($this->productDefinition, $context->getContext(), $scID, $domainID, $contr);
        /**
        if ($mainConfig===null) {
            $this->decorated->build($request, $criteria, $context);
            return;
        }
        */
        $search = $request->query->get('search');
        if (is_array($search)) {
            $term = implode(' ', $search);
        } else {
            $term = (string) $search;
        }
        $term = trim($term);
        if (empty($term)) {
            throw new MissingRequestParameterException('search');
        }
        $criteria->resetQueries();
        $criteria->setTerm($term);
        $criteria->addExtension('semknoxData', new ArrayEntity(
            [
                'salesChannelID' => $scID,
                'languageID' => $langID,
                'domainID' => $domainID,
                'controller' => $contr               
            ]
         ));
    }
    /**
     * holt die ID des aktuellen SalesChannels aus dem Context
     * @param SalesChannelContext $context
     * @return string
     */
    private function getSalesChannelFromContext(SalesChannelContext $context) : string
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
     * holt die ID des aktuellen SalesChannels aus dem Context
     * @param SalesChannelContext $context
     * @return string
     */
    private function getLanguageFromContext(SalesChannelContext $context) : string
    {
        $ret='';
        $sc = $context->getSalesChannel();
        if (is_object($sc)) {
            $ret = $sc->getLanguageId();
        } else {
        }
        return $ret;
    }
}
