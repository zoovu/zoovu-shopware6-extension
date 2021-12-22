<?php declare(strict_types=1);
namespace semknox\search\Service;
use Shopware\Storefront\Pagelet\Header\HeaderPageletLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use semknox\search\Framework\SemknoxsearchHelper;
use Shopware\Core\Framework\Struct\ArrayEntity;
use semknox\search\api\SemknoxAjaxParamsCallbackEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
class AddDataToPage implements EventSubscriberInterface
{
    /**
     *
     * @var SemknoxsearchHelper
     */
    private $helper;    
    /**
     * @var EventDispatcherInterface
     */
    private $eventDispatcher;
    public function __construct(SemknoxsearchHelper $helper, EventDispatcherInterface $eventDispatcher)
    {
		    $this->helper = $helper;
		    $this->eventDispatcher = $eventDispatcher;
    }
    public static function getSubscribedEvents(): array
    {
        return [
            HeaderPageletLoadedEvent::class => 'addConfigData'
        ];
    }
    public function addConfigData(HeaderPageletLoadedEvent $event): void
    {
    	$config = [
    		'active' => false ,
    	    'activateAutosuggest' => false,
    		'projectId' => '' ,
    		'apiUrl' => '' ,
    	    'dataPoints'=> [] ,
    		'currentStoreUrl' => '' ,
    		'addParams' => [] ,
    	];
    	$context=$event->getSalesChannelContext();
    	$scID=$this->helper->getSalesChannelFromSCContext($context);
        $langID = $this->helper->getLanguageFromSCContext($context);
        $domainID = $this->helper->getDomainFromSCContext($context);
        $domainUrl = $this->helper->getDomainURLFromSCContext($context);
    	$configdb = $this->helper->allowSalesChannel($scID, $domainID);
    	if ( ($configdb) && (is_array($configdb)) && ($configdb['semknoxActivate'])  ) {
            $config['active'] = $configdb['semknoxActivate'];
            $config['projectId'] = $configdb['semknoxCustomerId'];
            $config['apiUrl'] = $configdb['semknoxBaseUrl'];
            $config['currentStoreUrl'] = $domainUrl;
            if (isset($configdb['semknoxActivateAutosuggest'])) { $config['activateAutosuggest'] = $configdb['semknoxActivateAutosuggest']; }
            $params=[];
            if ($this->eventDispatcher != null) {
                $callBackEvent = new SemknoxAjaxParamsCallbackEvent($params, '');
                $this->eventDispatcher->dispatch($callBackEvent, $callBackEvent::NAME);
                if ( ($callBackEvent->isChanged()) && ($callBackEvent->checkParams() > 0) ) {
                    $params = $callBackEvent->getParams();
                }
            }
            $config['addParams']=$params;
        }
		$event->getPagelet()->addExtension('semknoxConfig', new ArrayEntity($config));
    }
}
