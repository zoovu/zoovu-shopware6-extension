<?php declare(strict_types=1);
namespace semknox\search\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
/**
 * @method void              add(CustomEntity $entity)
 * @method void              set(string $key, CustomEntity $entity)
 * @method CustomEntity[]    getIterator()
 * @method CustomEntity[]    getElements()
 * @method CustomEntity|null get(string $key)
 * @method CustomEntity|null first()
 * @method CustomEntity|null last()
 */
class semknoxLogEntityCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return semknoxLogEntity::class;
    }
}
