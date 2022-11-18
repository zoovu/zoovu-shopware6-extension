<?php declare(strict_types=1);
namespace semknox\search\Command;
class semknoxFUData
{
    /**
     * @var string
     */
    private $lastSalesChannelId;
    /**
     * @var string
     */
    private $lastLanguageId;
    /**
     * @var string
     */
    private $lastDomainId;
    /**
     * @var string
     */
    private $lastProvider;
    /**
     * @var int|null
     */
    private $nextOffset;
    /**
     * @var int|null
     */
    private $limit;
    /**
     * @var bool
     */
    private $finished;
    public function __construct(?string $lastSalesChannelId, ?string $lastLanguageId, ?string $lastDomainId, ?string $lastProvider, ?int $nextOffset, ?int $limit, bool $finished)
    {
        $this->lastSalesChannelId = $lastSalesChannelId;
        $this->lastDomainId = $lastDomainId;
        $this->lastLanguageId = $lastLanguageId;
        $this->lastProvider = $lastProvider;
        $this->nextOffset = $nextOffset;
        $this->finished = $finished;
        $this->limit = $limit;
    }
    public function getLastSalesChannelId(): ?string
    {
        return $this->lastSalesChannelId;
    }
    public function getLastLanguageId(): ?string
    {
        return $this->lastLanguageId;
    }
    public function getLastDomainId(): ?string
    {
        return $this->lastDomainId;
    }
    public function getLastProvider(): ?string
    {
        return $this->lastProvider;
    }
    public function getNextOffset(): ?int
    {
        return $this->nextOffset;
    }
    public function isFinished(): bool
    {
        return $this->finished;
    }
    public function getLimit() : ?int
    {
        if (is_null($this->limit)) { return 200; }
        if ($this->limit < 20) { return 200; }
        return $this->limit;
    }
}
