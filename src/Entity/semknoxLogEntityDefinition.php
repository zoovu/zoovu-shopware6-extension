<?php
declare(strict_types = 1);
namespace semknox\search\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\LongTextField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IntField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
class semknoxLogEntityDefinition extends EntityDefinition
{ 
    public const ENTITY_NAME = 'semknox_logs';
    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }    
    public function getCollectionClass(): string
    {
        return semknoxLogEntityCollection::class;
    }
    public function getEntityClass(): string
    {
        return semknoxLogEntity::class;
    }
    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new Required(), new PrimaryKey()),
            (new StringField('logtype', 'logType'))->addFlags(new Required()),
            (new IntField('status', 'logStatus')),
            (new StringField('logtitle', 'logTitle')),
            (new LongTextField('logdescr', 'logDescr'))->addFlags(new Required()),
        ]);        
    }
}
