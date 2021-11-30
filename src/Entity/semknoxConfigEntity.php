<?php declare(strict_types=1);
namespace semknox\search\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;
use Shopware\Core\System\SalesChannel\SalesChannelEntity; 
use Shopware\Core\System\Language\LanguageDefinition;
use Shopware\Core\System\Language\LanguageEntity;
class semknoxConfigEntity extends Entity
{
    use EntityIdTrait;
    /**
     * @var string
     */
    protected $configurationKey;
    /**
     * @var string
     */
    protected $configurationValue;
    /**
     * @var string
     */
    protected $salesChannelId;    
    /**
     * @var string
     */
    protected $languageId;
    /**
     * @var string
     */
    protected $domainId;
    /**
     * @var SalesChannelEntity|null
     */
    protected $salesChannel;
    /**
     * @var LanguageEntity|null
     */
    protected $language;
    public function getConfigurationKey(): string
    {
        return $this->configurationKey;
    }
    public function setConfigurationKey(string $configurationKey): void
    {
        $this->configurationKey = $configurationKey;
    }
    public function getConfigurationValue(): string
    {
        return $this->configurationValue;
    }
    public function setConfigurationValue(string $configurationValue): void
    {
        $this->configurationValue = $configurationValue;
    }
    public function getSalesChannelId(): string
    {
        return $this->salesChannelId;
    }
    public function setSalesChannelId(string $salesChannelId): void
    {
        $this->salesChannelId = $salesChannelId;
    }
    public function getLanguageId(): string
    {
        return $this->languageId;
    }
    public function setLanguageId(string $languageId): void
    {
        $this->languageId = $languageId;
    }
    public function getDomainId(): string
    {
        return $this->domainId;
    }
    public function setDomainId(string $domainId): void
    {
        $this->domainId = $domainId;
    }
    public function getSalesChannel(): ?SalesChannelEntity
    {
        return $this->salesChannel;
    }
    public function setSalesChannel(?SalesChannelEntity $salesChannel): void
    {
        $this->salesChannel = $salesChannel;
    }
    public function getLanguage(): ?LanguageEntity
    {
        return $this->language;
    }
    public function setLanguage(?LanguageEntity $language): void
    {
        $this->language = $language;
    }
}
