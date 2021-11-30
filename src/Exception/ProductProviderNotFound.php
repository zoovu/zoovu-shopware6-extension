<?php declare(strict_types=1);
namespace semknox\search\Exception;
use Shopware\Core\Framework\ShopwareHttpException;
class ProductProviderNotFound extends ShopwareHttpException
{
    public function __construct(string $provider)
    {
        parent::__construct('provider "{{ provider }}" not found.', ['provider' => $provider]);
    }
    public function getErrorCode(): string
    {
        return 'SITESEARCH_PRODUCT_PROVIDER_NOT_FOUND';
    }
} 
