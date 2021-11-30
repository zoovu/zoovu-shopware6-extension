<?php declare(strict_types=1);
namespace semknox\search\api;
use Symfony\Contracts\EventDispatcher\Event;
class SemknoxUpdateDataCallbackEvent extends Event
{
    public const NAME = 'semknox.update.data.callback';
    /**
     * @var string[]
     */
    private $jsonPayload;
    private $changed = 0;
    /**
     * @param string[] $paths
     */
    public function __construct(string $json)
    {
        $this->jsonPayload = $json;
    }
    public function setJson(string $json): void
    {
        $this->jsonPayload = $json;
        $this->changed = 1;
    }
    /**
     * @return string
     */
    public function getJson(): string
    {
        return $this->jsonPayload;
    }
    /**
     * 
     * @return bool
     */
    public function getChanged() : bool
    {
        return $this->changed;
    }
    /**
     * checks the current stored json-value
     * right now only checking, whether there is a value or not
     * returning > 0 if correct, <=0 on error
     * @return int
     */
    public function checkJson() : int
    {
        if (is_null($this->jsonPayload)) { return -1; }
        if (trim($this->jsonPayload) == '') { return -1; }
        return 1;
    }
}
