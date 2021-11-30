<?php declare(strict_types=1);
namespace semknox\search\Exception;
use Shopware\Core\Framework\ShopwareHttpException;
class ServerNotAvailableException extends ShopwareHttpException
{
    public const CODE = 'SITESEARCH_SERVER_NOT_AVAILABLE';
    public function __construct()
    {
        parent::__construct('SiteSearch360 server is not available');
    }
    public function getErrorCode(): string
    {
        return self::CODE;
    }
}
