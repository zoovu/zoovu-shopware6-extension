<?php declare(strict_types=1);
namespace semknox\search\Exception;
use Shopware\Core\Framework\ShopwareHttpException;
class SemknoxsearchIndexingException extends ShopwareHttpException
{
    public const CODE = 'SEMKNOXSEARCH_INDEXING';
    public function __construct(array $items)
    {
        $message = PHP_EOL . implode(PHP_EOL . '#', array_column($items, 'message'));
        parent::__construct(
            sprintf('Following errors occurred while indexing: %s', $message)
        );
    }
    public function getErrorCode(): string
    {
        return self::CODE;
    }
}
