## Site Search 360 Shopware 6 Extension


***
Callbacks:



    public static function getSubscribedEvents(): array
    {
        // Return the events to listen to as array like this:  <event to listen to> => <method to execute>
        return [
            'semknox.update.data.callback' => 'onUploadJson',
            'semknox.call.params.callback' => 'onCallParams'
        ];
    }
   
    //change JSON-Data for upload to siteSearch360
    public function onUploadJson(SemknoxUpdateDataCallbackEvent $event)
    {          
        $json=$event->getJson();
        //change json here
        $event->setJson($json);
    }
   
    //change params on calls to search/suggest (i.e. add a param)
    public function onCallParams(SemknoxCallParamsCallbackEvent $event)
    {
                //get Params for next call
        $params=$event->getParams();
        //get Type of call (search|suggest)
        $callType=$event->getCallType();
        //change params-array
        $params['userGroup'] = "SETUSERGROUPHERE";
        //set params
        $event->setParams($params);
    }
   