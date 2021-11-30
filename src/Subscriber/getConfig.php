<?php declare(strict_types=1);
namespace semknox\search\Subscriber;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use semknox\search\Framework\SemknoxsearchHelper;
class getConfig
{
    /**
     * @var SystemConfigService
     */
    private $systemConfigService;
    private $semknoxSearchHelper;
    public function __construct(
        SystemConfigService $systemConfigService, 
        SemknoxsearchHelper $helper)
    {
        $this->systemConfigService = $systemConfigService;
        $this->semknoxSearchHelper = $helper;
        echo "constructgetConfig";
        $this->semknoxSearchHelper->setConfigService($systemConfigService);
    }
}
