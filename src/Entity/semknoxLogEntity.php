<?php declare(strict_types=1);
namespace semknox\search\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;
class semknoxLogEntity extends Entity
{
    use EntityIdTrait;
    /**
     * @var string
     */
    protected $logType;
    /**
     * @var int
     */
    protected $status;
    /**
     * @var string
     */
    protected $logTitle;    
    /**
     * @var string
     */
    protected $logDescr;
    public function getLogType(): string
    {
        return $this->logType;
    }
    public function setLogType(string $logType): void
    {
        $this->logType = $logType;
    }
    public function getLogTitle(): string
    {
        return $this->logTitle;
    }
    public function setLogTitle(string $logTitle): void
    {
        $this->logTitle = $logTitle;
    }
    public function getLogDescr(): string
    {
        return $this->logDescr;
    }
    public function setLogDescr(string $logDescr): void
    {
        $this->logDescr = $logDescr;
    }
    public function getLogStatus(): int
    {
        return $this->status;
    }
    public function setLogStatus(int $logStatus): void
    {
        $this->status = $logStatus;
    }
}
