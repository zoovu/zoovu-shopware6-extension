import Plugin from 'src/plugin-system/plugin.class';

export default class JsAutosuggestPlugin extends Plugin {
    static options = {
        /**
         * use ss360 or not
         * @type boolean
         */
				active: false,
        /**
         * use js-autosuggest or not
         * @type boolean
         */
				activateAutosuggest: false,
        /**
         * projectId
         * @type string
         */
        projectId: '',
        /**
         * apiUrl
         * @type string
         */
				apiUrl: 'https://api-shopware-v3.semknox.com/',        
        /**
         * dataPoints
         * @type array
         */
				dataPoints: {},
        /**
         * current Store URL
         * @type string
         */
				currentStoreUrl: '',
				/**
				 * parameters to add on call
				 * @type array
				 */
				addParams: {},
    };	
	
    init() {
    		if (!this.options.active) { return; }
    		if (!this.options.activateAutosuggest) { return; }
    		if ( this.options.currentStoreUrl == null ){ this.options.currentStoreUrl=''; }
    		
        var siteId = this.options.projectId; // the project id
        var options = this.options;

        if (!siteId || siteId.length === 0 ) { return; }

        var ap = '';
        for (var key in this.options.addParams) {
        	ap += '&'+key+'='+this.options.addParams[key];
        }
        
        // remove listener by cloning Search-Element
        var elem = document.querySelectorAll('input[name="search"]')[0];
				elem.replaceWith(elem.cloneNode(true));

        
        window.ss360Config = {
            siteId: siteId,
            ecom: true,
            baseUrl: this.options.apiUrl+'search?projectId=' + siteId +ap,
            suggestBaseUrl: this.options.apiUrl+'search/suggestions?projectId=' + siteId + ap,            
            suggestions: {
                dataPoints: this.options.dataPoints
            },
            searchBox: {
                selector: 'input[name="search"]', // search box css selector
                searchButton: 'form.header-search-form .header-search-btn', // search button css selector (makes the search suggestions extend over the full search form width)
                preventFormParentSubmit: false // prevents the search plugin from preventing search form submit
            },
            results: {
                ignoreEnter: true // search plugin will ignore enter keys (won't submit search on enter)
            },
            callbacks: {
                preSearch: function (query) { // handle query suggestions

                    var searchForm = document.querySelectorAll('form.header-search-form')[0];

                    var searchBox = document.querySelectorAll('form.header-search-form input[name=search]')[0]; 

                    searchBox.value = query;
                    searchForm.submit();
                    return false; // prevent search
                },
                suggestLine: function (suggestLine, key, index, suggest) {
                    var replaceUrl = options.currentStoreUrl;
                    replaceUrl = replaceUrl.substr(0, replaceUrl.lastIndexOf("/"));

                    // shop specific url-slug
                    var specificShopUrl = '';
                    if(suggest.dataPoints != undefined){
                        for (var i = 0; i < suggest.dataPoints.length; i++) {
                            if(suggest.dataPoints[i].key == 'shop-specific-url'){
                                suggestLine = suggestLine.replace(/href="(.*?)"/, function(m, $1) {
                                    return 'href="' +replaceUrl + suggest.dataPoints[i].value + '"';
                                });
                            }
                        } 
                    }

										return suggestLine;
										
                    // shop specific master URL - we don't use replace-masterurls right now...
                    if( ! options.replaceMasterStoreUrlInSS360Result || options.masterStoreUrl=="EMPTY" ) return suggestLine;

                    var replaceUrl = options.currentStoreUrl;
                    replaceUrl = replaceUrl.substr(0, replaceUrl.lastIndexOf("/") +1);

                    suggestLine = suggestLine.replace(/options.masterStoreUrl/g, replaceUrl);
                    return suggestLine;
                }
            }
        };	
        // prevent autosuggest from Shopware
        //document.querySelectorAll('form.header-search-form input[name=search]')[0].oninput = null;
        var e = document.createElement('script');
        e.src = 'https://cdn.sitesearch360.com/v13/sitesearch360-v13.min.js';
        document.getElementsByTagName('body')[0].appendChild(e);
    }

}