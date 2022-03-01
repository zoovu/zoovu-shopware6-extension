<?php declare(strict_types=1);
namespace semknox\search\Content;
use Shopware\Core\Content\Cms\Aggregate\CmsSlot\CmsSlotCollection;
use Shopware\Core\Content\Cms\Aggregate\CmsSlot\CmsSlotEntity;
use Shopware\Core\Content\Cms\DataResolver\CriteriaCollection;
use Shopware\Core\Content\Cms\DataResolver\Element\CmsElementResolverInterface;
use Shopware\Core\Content\Cms\DataResolver\Element\ElementDataCollection;
use Shopware\Core\Content\Cms\DataResolver\ResolverContext\ResolverContext;
use Shopware\Core\Framework\DataAbstractionLayer\DefinitionInstanceRegistry;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Exception\InconsistentCriteriaIdsException;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\Struct\ArrayEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use semknox\search\Framework\SemknoxsearchHelper;
use semknox\search\Product\SemknoxProductListingLoader;
class SiteSearchCmsSlotsDataResolver
{
    /**
     * @var CmsElementResolverInterface[]
     */
    private $resolvers;
    /**
     * @var array
     */
    private $repositories;
    /**
     * @var DefinitionInstanceRegistry
     */
    private $definitionRegistry;
    /**
     * @var SemknoxProductListingLoader
     */
    private $productListingLoader;
    /**
     * @var SemknoxsearchHelper
     */
    private $helper;
    /**
     * Criteria from CategoryRoute
     * @var Criteria
     */
    private $impCriteria;
    /**
     *
     * @var SiteSearchProductListingCmsElementResolver
     */
    private $listingElementResolver;
    /**
     * @param CmsElementResolverInterface[] $resolvers
     */
    public function __construct(
        iterable $resolvers, 
        array $repositories, 
        DefinitionInstanceRegistry $definitionRegistry, 
        SemknoxProductListingLoader $productListingLoader,
        SiteSearchProductListingCmsElementResolver $listingelementResolver,
        SemknoxsearchHelper $helper
    )
    {
        $this->definitionRegistry = $definitionRegistry;
        foreach ($repositories as $entityName => $repository) {
            $this->repositories[$entityName] = $repository;
        }
        foreach ($resolvers as $resolver) {
            $this->resolvers[$resolver->getType()] = $resolver;
        }
        $this->productListingLoader = $productListingLoader;
        $this->helper = $helper;
        $this->listingElementResolver = $listingelementResolver;
    }
    public function resolve(CmsSlotCollection $slots, ResolverContext $resolverContext, Criteria $impCriteria): CmsSlotCollection
    {
        $slotCriteriaList = [];
        $this->impCriteria = $impCriteria;
        /*
         * Collect criteria objects for each slot from resolver
         *
         * @var CmsSlotEntity
         */
        foreach ($slots as $slot) {
            $resolver = $this->resolvers[$slot->getType()] ?? null;
            if (!$resolver) {
                continue;
            }
            $collection = $resolver->collect($slot, $resolverContext);
            if ($collection === null) {
                continue;
            }
            $slotCriteriaList[$slot->getUniqueIdentifier()] = $collection;
        }
        [$directReads, $searches] = $this->optimizeCriteriaObjects($slotCriteriaList);
        $entities = $this->fetchByIdentifier($directReads, $resolverContext->getSalesChannelContext());
        $searchResults = $this->fetchByCriteria($searches, $resolverContext->getSalesChannelContext());
        foreach ($slots as $slotId => $slot) {
            $resolver = $this->resolvers[$slot->getType()] ?? null;
            if (!$resolver) {
                continue;
            }
            $result = new ElementDataCollection();
            $this->mapSearchResults($result, $slot, $slotCriteriaList, $searchResults);
            $this->mapEntities($result, $slot, $slotCriteriaList, $entities);
			if ($slot->getType()!=='product-listing') {
                $resolver->enrich($slot, $resolverContext, $result);
			} else {
			    $this->listingElementResolver->setCriteria($this->impCriteria);
			    $this->listingElementResolver->enrich($slot, $resolverContext, $result);
			}
            $slots->set($slotId, $slot);
        }
        return $slots;
    }
    /**
     * @param string[][] $directReads
     *
     * @throws InconsistentCriteriaIdsException
     *
     * @return EntitySearchResult[]
     */
    private function fetchByIdentifier(array $directReads, SalesChannelContext $context): array
    {
        $entities = [];
        foreach ($directReads as $definitionClass => $ids) {
            $definition = $this->definitionRegistry->get($definitionClass);
            $repository = $this->getSalesChannelApiRepository($definition);
            if ($repository) {
                $entities[$definitionClass] = $repository->search(new Criteria($ids), $context);
            } else {
                $repository = $this->getApiRepository($definition);
                $entities[$definitionClass] = $repository->search(new Criteria($ids), $context->getContext());
            }
        }
        return $entities;
    }
    private function fetchByCriteria(array $searches, SalesChannelContext $context): array
    {
        $searchResults = [];
        /** @var Criteria[] $criteriaObjects */
        foreach ($searches as $definitionClass => $criteriaObjects) {
            foreach ($criteriaObjects as $criteriaHash => $criteria) {
                $definition = $this->definitionRegistry->get($definitionClass);
                $repository = $this->getSalesChannelApiRepository($definition);
                if ($repository) {
                    $result = $repository->search($criteria, $context);
                } else {
                    if ($definition->getEntityName()=='category') {
                        if ($this->impCriteria->hasExtension('semknoxData')) {
                            $criteria->addExtension('semknoxData', $this->impCriteria->getExtension('semknoxData'));
                            $semkData = $criteria->getExtension('semknoxData');
                            if (isset($semkData['term'])) {
                                $criteria->setTerm($semkData['term']);
                            }
                            $result = $this->productListingLoader->load($criteria, $context);
                        } else {
                            $repository = $this->getApiRepository($definition);
                            $result = $repository->search($criteria, $context->getContext());                            
                        }
                    } else {
                        $repository = $this->getApiRepository($definition);
                        $result = $repository->search($criteria, $context->getContext());
                    }
                }
                $searchResults[$criteriaHash] = $result;
            }
        }
        /* maybe not necessary, loading of data done in semknoxProductListingLoader*/
        if (count($searches)==0) {
					if ($this->impCriteria->hasExtension('semknoxData')) {
						$criteria = $this->impCriteria;
						$semkData = $criteria->getExtension('semknoxData');
						if (isset($semkData['term'])) {
							$criteria->setTerm($semkData['term']);
						}
        	}
        }
        /**/
        return $searchResults;
    }
    /**
     * @param CriteriaCollection[] $criteriaCollections
     */
    private function optimizeCriteriaObjects(array $criteriaCollections): array
    {
        $directReads = [];
        $searches = [];
        $criteriaCollection = $this->flattenCriteriaCollections($criteriaCollections);
        foreach ($criteriaCollection as $definition => $criteriaObjects) {
            $directReads[$definition] = [[]];
            $searches[$definition] = [];
            /** @var Criteria $criteria */
            foreach ($criteriaObjects as $criteria) {
                if ($this->canBeMerged($criteria)) {
                    $directReads[$definition][] = $criteria->getIds();
                } else {
                    $criteriaHash = $this->hash($criteria);
                    $criteria->addExtension('criteriaHash', new ArrayEntity(['hash' => $criteriaHash]));
                    $searches[$definition][$criteriaHash] = $criteria;
                }
            }
        }
        foreach ($directReads as $definition => $idLists) {
            $directReads[$definition] = array_merge(...$idLists);
        }
        return [
            array_filter($directReads),
            array_filter($searches),
        ];
    }
    private function canBeMerged(Criteria $criteria): bool
    {
        if ($criteria->getOffset() !== null || $criteria->getLimit() !== null) {
            return false;
        }
        if (\count($criteria->getSorting())) {
            return false;
        }
        if (\count($criteria->getQueries())) {
            return false;
        }
        if ($criteria->getAssociations()) {
            return false;
        }
        if ($criteria->getAggregations()) {
            return false;
        }
        $filters = array_merge(
            $criteria->getFilters(),
            $criteria->getPostFilters()
        );
        if (!empty($filters)) {
            return false;
        }
        if (empty($filters) && empty($criteria->getIds())) {
            return false;
        }
        return true;
    }
    private function getApiRepository(EntityDefinition $definition): EntityRepositoryInterface
    {
        return $this->definitionRegistry->getRepository($definition->getEntityName());
    }
    /**
     * @return mixed|null
     */
    private function getSalesChannelApiRepository(EntityDefinition $definition)
    {
        return $this->repositories[$definition->getEntityName()] ?? null;
    }
    private function flattenCriteriaCollections(array $criteriaCollections): array
    {
        $flattened = [];
        $criteriaCollections = array_values($criteriaCollections);
        foreach ($criteriaCollections as $collections) {
            foreach ($collections as $definition => $criteriaObjects) {
                $flattened[$definition] = array_merge($flattened[$definition] ?? [], array_values($criteriaObjects));
            }
        }
        return $flattened;
    }
    /**
     * @param CriteriaCollection[] $criteriaObjects
     * @param EntitySearchResult[] $searchResults
     */
    private function mapSearchResults(ElementDataCollection $result, CmsSlotEntity $slot, array $criteriaObjects, array $searchResults): void
    {
        if (!isset($criteriaObjects[$slot->getUniqueIdentifier()])) {
            return;
        }
        foreach ($criteriaObjects[$slot->getUniqueIdentifier()] as $criterias) {
            foreach ($criterias as $key => $criteria) {
                if (!$criteria->hasExtension('criteriaHash')) {
                    continue;
                }
                /** @var ArrayEntity $hashArrayEntity */
                $hashArrayEntity = $criteria->getExtension('criteriaHash');
                $hash = $hashArrayEntity->get('hash');
                if (!isset($searchResults[$hash])) {
                    continue;
                }
                $result->add($key, $searchResults[$hash]);
            }
        }
    }
    /**
     * @param CriteriaCollection[] $criteriaObjects
     * @param EntitySearchResult[] $entities
     */
    private function mapEntities(ElementDataCollection $result, CmsSlotEntity $slot, array $criteriaObjects, array $entities): void
    {
        if (!isset($criteriaObjects[$slot->getUniqueIdentifier()])) {
            return;
        }
        foreach ($criteriaObjects[$slot->getUniqueIdentifier()] as $definition => $criterias) {
            foreach ($criterias as $key => $criteria) {
                if (!$this->canBeMerged($criteria)) {
                    continue;
                }
                if (!isset($entities[$definition])) {
                    continue;
                }
                $ids = $criteria->getIds();
                $filtered = $entities[$definition]->filter(function (Entity $entity) use ($ids) {
                    return \in_array($entity->getUniqueIdentifier(), $ids, true);
                });
                $result->add($key, $filtered);
            }
        }
    }
    private function hash(Criteria $criteria): string
    {
        return md5(serialize($criteria));
    }
}
