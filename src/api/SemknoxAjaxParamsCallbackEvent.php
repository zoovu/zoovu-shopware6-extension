<?php declare(strict_types=1);
namespace semknox\search\api;
use Symfony\Contracts\EventDispatcher\Event;
class SemknoxAjaxParamsCallbackEvent extends Event
{
    public const NAME = 'semknox.ajax.paramdata.callback';
    /**
     * @var string[]
     */
    private $params;
    private $callType;
    private $changed = 0;
    /**
     * @param string[] $paths
     */
    public function __construct(array $params, string $callType)
    {
        $this->params = $params;
        $this->callType = $callType;
    }
    public function setParams(array $params): void
    {
        $this->params = $params;
        $this->changed = 1;
    }
    /**
     * @return array
     */
    public function getParams(): array
    {
        return $this->params;
    }
    /**
     * @return string
     */
    public function getCallType(): string
    {
        return $this->callType;
    }
    /**
     *
     * @return bool
     */
    public function isChanged() : bool
    {
        return ($this->changed > 0) ? true : false;
    }
    /**
     * checks the current stored params-value
     * right now only checking, whether params is an array, no content-check
     * returning > 0 if correct, <=0 on error
     * @return int
     */
    public function checkParams() : int
    {
        if (is_null($this->params)) { return -1; }
        if (!is_array($this->params)) { return -1; }
        return 1;
    }
}
