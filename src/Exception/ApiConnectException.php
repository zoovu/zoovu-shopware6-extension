<?php declare(strict_types=1);
namespace semknox\search\Exception;
use Shopware\Core\Framework\ShopwareHttpException;
class ApiConnectException extends ShopwareHttpException
{
    public const CODE = 'SITESEARCH_API_CONNECTION';
    public function __construct(string $err)
    {
        parent::__construct(
            sprintf('SiteSearch360 api connection error: %s', $err)
            );
    }
    public function getErrorCode(): string
    {
        return self::CODE;
    }
}
