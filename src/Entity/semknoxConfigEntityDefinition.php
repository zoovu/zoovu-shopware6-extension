<?php
declare(strict_types = 1);
namespace semknox\search\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\LongTextField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IntField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Shopware\Core\System\Language\LanguageDefinition;
use Shopware\Core\System\SalesChannel\SalesChannelDefinition;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToOneAssociationField;
class semknoxConfigEntityDefinition extends EntityDefinition
{ 
    public const ENTITY_NAME = 'semknox_config';
    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }    
    public function getCollectionClass(): string
    {
        return semknoxConfigEntityCollection::class;
    }
    public function getEntityClass(): string
    {
        return semknoxConfigEntity::class;
    }
    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new Required(), new PrimaryKey()),
            (new StringField('configuration_key', 'configurationKey'))->addFlags(new Required()),
            (new LongTextField('configuration_value', 'configurationValue'))->addFlags(new Required()),
            (new FkField('sales_channel_id', 'salesChannelId', SalesChannelDefinition::class))->addFlags(new Required()),
            (new FkField('language_id', 'languageId', LanguageDefinition::class))->addFlags(new Required()),
            (new FkField('domain_id', 'domainId', SalesChannelDomainDefinition::class))->addFlags(new Required()),
            (new ManyToOneAssociationField('salesChannel', 'sales_channel_id', SalesChannelDefinition::class)),
        ]);        
    }
}
