#3.3.15
- bugfixing starttime

#3.3.14
- added function to get local time from Server-Config to get correct local Time of Server for cronjobs
- changed time from UTC (or current time of shopware) to local time of the server to start cronjob
- changed starting-procedure of cronjob
- changed timeout of curl-op to 100s
- only check restart-interval on updates that have a interval of less than half a day
- added local-time-function to logging
- changed laststart-token to update.process.start to get correct starting point of whole update-process, not only last sc
- bugfixing on bug-reporting updatecheck:finished

#3.3.13
- recognize unrecoverable errors in block-struct
- finish update-process on unrecoverable errors 

#3.3.12
- added pid of running process to log-info
- bugfixing for parallel uploads: generated a new cancel function to avoid restarting uploads that have already started

#3.3.11
- added default values for config-data if no boolean-value was selected

#3.3.10
- bugfix update on saleschannel with update disabled

#3.3.9
- bugfix on limit-changes in config
- added UTC-hint for backend-config of start-time

#3.3.8
- bugfix call decorated ajax on searchcontroller with wrong paramsorting

#3.3.7
- set filtervalues on autoremove-function of shopware to remove not-set values
- bugfix filter-panel added value propertyName
- bugfix fix property-name of filtervalues with control-characters {}()/\@

#3.3.6
added ecom-param to autosuggest-json

#3.3.5
configurable change of media-url-domain to shop-domain in update-process

#3.3.4
redirect on one product in search result (with config)
fix for arrays in custom entities of properties
no empty paths in categories breadcrumb
log errors to database on sending blockdata 

#3.3.3
added logging to struct/product on error in attributes-handling
removed currency from product-search-association (only available in saleschannel-context
changed limit-handling, so the limit is taken from the current run and cant be changed during operation of a whole update-run

#3.3.2
added new function to enable/disable content-upload
fix backend-compile-error on new shopwareVersion-Checking
fix error-message on wrong filtervalues from server

#3.3.1
added media-association to include media-urls for category-upload

# 3.3.0
parallel upload-functionality
saleschanneldomainID fix for empty return-values

#3.2.9
removed getReference and changed order of version-getter-functions

#3.2.7
bugfix, if another plugin uses the SalesChannelCmsPageLoader and the request->controller or any necessary variable is not set.

#3.2.5
changes to fix some issues with missing resultsets, unset sessions in checkout-process in other plugins and possible re-indexing after update-procedures

#3.2.3
added MasterProductnumber to variants as first part of masterdata at variant-childs

#3.2.1 
added upload of category-data to SiteSearch360 at the end of the upload-process

#3.1.4
fix: switched name to value on filter-request

#3.1.3
fix: changed underscore to tilde on propertyfilter

#3.1.2 
fix sortkey  if ($key=='topseller') if key is not set in SiteSearchProductListingFeatureSubscriber

#3.1.1
- added Resultmanager-Handling 

#3.0.2
- added no-log-Parameter

#3.0.1
- added CategoryListing to siteSearch360

#2.9.3
- changed API-endpoint to api-shopware.sitesearch360.com
- added Autosuggest-JS-Function from siteSearch360 via config-param
- fix to add noindex, follow to search-result-page

#2.9.2
- added Callback on search/suggest-calls 
- fixed compatibility for shopware 6.3/6.2

#2.9.1
- added config-param to send master-product of variants or not
- added variant-option to features on upload 

#2.8.8
- extended logging on export
- removed start-signal on cancelling the update-procedure

#2.8.7
- changed time-handling for update-start
- fixed bug for shopware6.4+php8 on static/nonstatic calls

#2.8.6
- removed handler for scheduled tasks in shopware 6.1.- services
- changed Version-Check to composer:InstalledVersions to be more compatible with composer2

#2.8.5
- added validation of api-credentials
- added translation for en in config-tool
- bugfixing active=null on variants lead to no update

#2.8.4
- added decoded json from ss360-query to resultset ( to be found in template: listing.extensions.semknoxResultData.jsonDecoded = null|array )
- added Color-Filter 
- bugfixing for price-filter with min/max-values = 0 have been hidden

#2.8.3
- added support for prices by price-rules
- bugfixing template filter: output standard-filter-values on cat-pages and if no semknox-search available
- added function to start cronjob at selected hours+interval


# 2.8.2
- exclude closeoutProducts, if shop-config is set

# 2.8.1
- bugfixing empty search-parameter (i.e. on switching language on search-page)

# 2.8.0
- changes to fix domain-issues on duplicate language-use. config-param = domain-guid
- bugfixing check correct filteroptions from semknox
- removed order=score from semknox-search-request

# 2.7.0
- changes for compatibility to shopware 6.4
- added customFields and customSearchKeywords to attributes in upload-process
- added callback-Event for json-uploads: "semknox.update.data.callback", use getJson() to get the current Json-Value and setJson($json) to store it for uploading
- new backend-link-structure to be max. compatible with 6.2-6.4

# 2.6.9
- admin: changed CustomerID to ProjectID
- admin: moved credentials-component to main-part to prevent bugs on displaying data
- admin: added text to onboarding-button
- admin: hide onboarding-button, if there are any credentials
- admin: hide saleschannel without a language set
- admin: added title-text for cronjob-info-badges

# 2.6.8
- bugfixing - FullUpdateCommand on reset

# 2.6.7
- in upload - remove breadcrumbs with invisible categories

# 2.6.6
- loading-state in Onboarding-onSubmit

# 2.6.5 
- bugfixing ProductListingFeatureSubscriber handleResult

# 2.6.4
- bugfixing Admin

# 2.6.3
- bugfixing Shopware 6.2
- bugfixing Shopware 6.3.5.2 - security-update - in ProductSearchRoute-

# 2.6.1
- removed switch for stage/productive
- adding own backend-module for config/log etc
- adding own config-module
- projectID added to every request
- fixed Bug on semknox-search-result=null on SiteSearchProduktListingFeatureSubscriber