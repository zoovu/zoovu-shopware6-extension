## Site Search 360 Shopware 6 Extension
***

INSTALLATION SHOPWARE 6 EXTENSION:

For each sales channel (language or vertical/store view), all settings must first be entered in the Shopware plugin configuration. This includes especially ProjectID, ApiKey and language.

It is important here that the values must not be set in the field for "All sales channels", but separately for each sales channel!

To do this, use the combo box in the upper part of the configuration. First of all, the extension should be activated in general and also the upload via the points 
"Activate SiteSearch360 search for subshop" and 
and
"Activate update of data for Subshop".

For each language different configurations can be stored, important are the values "SiteSearch360-CustomerID", "SiteSearch360-API-Key" and "SiteSearch360-Language".

The latter contains the ISO value of the language as expected by Shopware, e.g. de-DE for German. If multiple languages are to be set, the additional fields "Configuration Language 02-05" can be filled with the corresponding configuration parameters of Site Search 360 and the language ISO code.

Now the plugin is configured and ready for use. For the upload of the data, a cronjob must be created in the system. Per call of the cronjob a data package of about 500 records is processed and uploaded. Therefore, this cronjob should be triggered every minute or every 2 minutes. The entry in the Linux crontab then looks like this:

    */1 * * php /[SHOPWARE DIRECTORY]/bin/console plugin-commands:semknoxFullUpdate >> /[SHOPWARE DIRECTORY]/var/log/semknox/cron.log

This will trigger the cronjob every minute and keep a continuous log file under Shopware's own /var/log directory. Once the cronjob has run once and all data has been uploaded, the upload pauses for 24h so that one upload per day takes place.

With the new support for version 6.5 of Shopware, the extension also allows to do incremental updates which are triggered via the same cronjob which is used for the full updates. You may configure the incremental updates in the corresponding settings section of the extension. Incremental updates are triggered via two different events called *product.written* and *checkoutOrderPlaced*. Please note, that no incremental updates are triggered if a fullupdate is running. Please see additional information in the QA section below.

***
Questions:

Q: When gets a product uploaded to the API?

A: The upload process for products takes place in 2 stages:

1. Collect the currently available products
2. JSON creation of the individual product data

Stage 1: the criteria for a product to get uploaded in this stage are:

* all products that belong to the respective sales channel
* it needs to be active
* and those, that are not on sale (Abverkauf), or - if they are on sale - still have the required minimum sales quantity in stock.

These filters are applied directly by the plugin via a SQL query

Stage 2: After the actual JSON data was created per product, at the end it is evaluated whether an URL is available for the product and whether it is valid. If not, the product will not be uploaded.


Q: How to speed up the product upload?

A: You may use the parallel upload function by configuring the cronjob like this:


    */1 * * php /[SHOPWARE DIRECTORY]/bin/console plugin-commands:semknoxFullUpdate >> /[SHOPWARE DIRECTORY]/var/log/semknox/cron.log
    */1 * * (sleep 20; php /[SHOPWARE DIRECTORY]/bin/console plugin-commands:semknoxFullUpdate >> /[SHOPWARE DIRECTORY]/var/log/semknox/cron.log)
    */1 * * (sleep 40; php /[SHOPWARE DIRECTORY]/bin/console plugin-commands:semknoxFullUpdate >> /[SHOPWARE DIRECTORY]/var/log/semknox/cron.log)


Q: How can I tune which for which product update events an incremental update is triggered?

A: There are some options to react only to certain update event types for incremental updates:

* ordered - is set during checkout
* price, stock, isCloseout, productNumber - is set on eachproduct.written event and can be used to restrict incremental updates to eg. only price changes


Q: Is it possible to stop incremental updates if product data gets imported via Shopware's Admin API?

A: Yes, you may pause the incremental update process by placing a lock file in */[SHOPWARE_INSTALL]/var/log/semknox/singledata.lock*. The process would then be:

* set the lock file in */[SHOPWARE_INSTALL]/var/log/semknox/singledata.lock*
* run the external product data update against the Shopware Admin API
* delete the lock file in */[SHOPWARE_INSTALL]/var/log/semknox/singledata.lock*
* run a full update to upload all data to the SEMKNOX API

***
CALLBACKS:



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
   