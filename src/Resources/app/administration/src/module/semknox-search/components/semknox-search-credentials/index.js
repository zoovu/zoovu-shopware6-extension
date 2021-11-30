import template from './semknox-search-credentials.html.twig';

const { Component } = Shopware;

Component.register('semknox-search-credentials', {
    template,

    inject: [
        'acl'
    ],

    mixins: [
        'notification',
    ],

    props: {
    		ScLangConfigData: {
            type: Object,
            required: true    			
    		},
    		CurrentCustomerId: {
    			type: String,
    			required: true
    		},
    		CurrentApiKey: {
    			type: String,
    			required: true
    		},
    		semknoxConfigData: {
            type: Object,
            required: true    			
    		},
        selectedSalesChannelId: {
        		type: String,
            required: true
        },
        selectedLanguageId: {
        		type: String,
            required: true
        },
        apiTargetFilled: {
            type: Boolean,
            required: true
        },
        apiKeyFilled: {
            type: Boolean,
            required: true
        },
        isLoading: {
            type: Boolean,
            required: true
        },
        isPrefsLoading: {
            type: Boolean,
            required: true
        },
        customerIdFilled: {
            type: Boolean,
            required: true
        },
        getSelectedLanguageId: {
            required: true
        },
        getScLangConfigData: {
            required: true
        },
        customerIdErrorState: {
            required: true
        },
        shopData: {
        	required: true
        },
        showOnBoardingContainer: {
        	required: true
        },        
    		
    	
        apiKeyErrorState: {
            required: true
        },
        apiTargetErrorState: {
            required: true
        }
    },

		data() {
			return {
			   	onBoardingErrorMsg: '',
    			isErrorInOnboarding: false
    	};
		},

    computed: {
    		
				activateOnBoardingButton() {
					if (this.customerIdFilled && this.apiKeyFilled) {
						return false;
					}		 					
					return !this.showOnBoardingContainer;
				} ,   	
				
				siteSearchOnBoardingContainerContent() {
					return 'TESTTESTTEST '+this.onBoardingContent;
				}	,
				
		
    },

    created() {
    	//console.log('created creds!!');
    	//console.log(this.$route);
    },
    

    methods: {
        createScript() {
            const id = 'siteSearchOnBoarding-js';
            if (document.getElementById(id)) {
            	var elem = document.getElementById(id); elem.parentNode.removeChild(elem);
            }            	
 						if (window.ss360ShopPluginConfig) {
	            if (!document.getElementById(id)) {
	                const scriptUrl = 'https://cdn.sitesearch360.com/shop-extension/sitesearch360-shop-accounting-extension.min.js';
	                const script = document.createElement('script');
	                script.id = id;
	                script.type = 'text/javascript';
	                script.src = scriptUrl;
	                script.async = true;
	                script.onload = function() {
					    			if (document.querySelector('.ss360__button')) {
					    				document.querySelector('.ss360__button').classList.add('sw-button');
					    			}
					    			if (document.querySelector('.ss360__row--text')) {
					    				document.querySelector('.ss360__row--text').classList.add('sw-field');
					    				document.querySelector('.ss360__row--text').classList.add('sw-block-field');
					    				document.querySelector('.ss360__row--text').classList.add('sw-contextual-field');
					    				document.querySelector('.ss360__row--text').classList.add('sw-field--text');
					    			}
					    			if (document.querySelector('.ss360__row--text .ss360__label')) {
					    				var element = document.querySelector('.ss360__row--text .ss360__label');
											var parent = element.parentNode;
											var wrapper = document.createElement('div');
											parent.replaceChild(wrapper, element);
											wrapper.appendChild(element);					    				
					    				wrapper.classList.add('sw-field__label');
					    			}
					    			if (document.querySelector('.ss360__row--text .ss360__input--text')) {
					    				var element = document.querySelector('.ss360__row--text .ss360__input--text');
											var parent = element.parentNode;
											var wrapper = document.createElement('div');
											parent.replaceChild(wrapper, element);
											wrapper.appendChild(element);					    				
					    				wrapper.classList.add('sw-block-field__block');
					    			}

					    			if (document.querySelector('.ss360__row--email')) {
					    				document.querySelector('.ss360__row--email').classList.add('sw-field');
					    				document.querySelector('.ss360__row--email').classList.add('sw-block-field');
					    				document.querySelector('.ss360__row--email').classList.add('sw-contextual-field');
					    				document.querySelector('.ss360__row--email').classList.add('sw-field--text');
					    			}
					    			if (document.querySelector('.ss360__row--email .ss360__label')) {
					    				var element = document.querySelector('.ss360__row--email .ss360__label');
											var parent = element.parentNode;
											var wrapper = document.createElement('div');
											parent.replaceChild(wrapper, element);
											wrapper.appendChild(element);					    				
					    				wrapper.classList.add('sw-field__label');
					    			}
					    			if (document.querySelector('.ss360__row--email .ss360__input--email')) {
					    				var element = document.querySelector('.ss360__row--email .ss360__input--email');
											var parent = element.parentNode;
											var wrapper = document.createElement('div');
											parent.replaceChild(wrapper, element);
											wrapper.appendChild(element);					    				
					    				wrapper.classList.add('sw-block-field__block');
					    			}

	                };
	              document.head.appendChild(script);
	            }
	          }
        },
    	
    		activateOnBoarding() {
     			if (document.querySelector('#siteSearchOnBoardingContainer')) {
     				//alert('selector found');
    				document.querySelector('#siteSearchOnBoardingContainer').innerHTML='';
    			}
					window.ss360ShopPluginConfig = {
						contentBlock: '#siteSearchOnBoardingContainer',
						storeView: [{
						lang: this.shopData.selectedShopLang, name: this.shopData.selectdDomainTitle, domain: this.shopData.selectedShopUrl
						}],
						companyName: this.shopData.shopName,
						email: this.shopData.shopEmail,
						shopSystem: 'Shopware',
						shopSystemVersion: this.shopData.shopwareVersion,
						extensionVersion: this.shopData.pluginVersion,
						success: function(data) {
							this.$set(this.ScLangConfigData, 'semknoxSearch.config.semknoxC01CustomerId', data.data.data[0].projectId);
							this.$set(this.ScLangConfigData, 'semknoxSearch.config.semknoxC01ApiKey', data.data.data[0].apiKey);
							//this.ScLangConfigData['semknoxSearch.config.semknoxC01CustomerId']=data.data.data[0].projectId;
		 					//this.ScLangConfigData['semknoxSearch.config.semknoxC01ApiKey']=data.data.data[0].apiKey;
		 					this.customerIdErrorState=false;
		 					this.apiKeyErrorState=false;
		 					this.showOnBoardingContainer=false;
		 					this.isLoading=false;this.isPrefsLoading=false;
							this.customerIdFilled = !!this.ScLangConfigData['semknoxSearch.config.semknoxC01CustomerId'];
							this.apiKeyFilled = !!this.ScLangConfigData['semknoxSearch.config.semknoxC01ApiKey'];		 					
							this.$emit(this.ScLangConfigData);
		 					this.forceUpdate();		 	
		 					console.log(this.hasError);
							}.bind(this),
						error: function(status, statusText, event) { 
							console.error(status, statusText, event); 
							this.onBoardingErrorMsg= status;
    					this.isErrorInOnboarding= true;
		 					this.showOnBoardingContainer=true;
		 					this.isLoading=false;this.isPrefsLoading=false;
    					this.forceUpdate();
							}.bind(this),
						onSubmit: function() { 
		 					this.isLoading=true;this.isPrefsLoading=true;
							}.bind(this)
					};
//					console.log(window.ss360ShopPluginConfig);
					this.onBoardingErrorMsg= '';
 					this.isErrorInOnboarding= false;
 					

					
					this.createScript();
    			this.showOnBoardingContainer = true;

    		},
        checkTextFieldInheritance(value) {
            if (typeof value !== 'string') {
                return true;
            }

            return value.length <= 0;
        },

        checkBoolFieldInheritance(value) {
            return typeof value !== 'boolean';
        },

				forceUpdate() {
            this.$forceUpdate();
        },
        
				aclAllowed(module) {
					const aclService = Shopware.Service('acl');
					if (aclService) {		
						console.log('acl');
						return aclService.can(module);
					} else {
						console.log('nonacl');
						return true;
					}
				},
				
				
				
				onInputCustomerID(lid) {
					this.CurrentCustomerId = lid;
					this.$set(this.ScLangConfigData, 'semknoxSearch.config.semknoxC01CustomerId', lid);
//					this.ScLangConfigData['semknoxSearch.config.semknoxC01CustomerId'] = lid;
		 					this.customerIdErrorState=false;
		 					this.apiKeyErrorState=false;
		 					this.showOnBoardingContainer=false;
		 					this.isLoading=false;this.isPrefsLoading=false;
							this.customerIdFilled = !!this.ScLangConfigData['semknoxSearch.config.semknoxC01CustomerId'];
							this.apiKeyFilled = !!this.ScLangConfigData['semknoxSearch.config.semknoxC01ApiKey'];		 					
							this.$emit(this.ScLangConfigData);
							this.$emit(this.CurrentCustomerId);
		 					this.forceUpdate();		 	
					//alert(lid);
				},
		
				onInputApiKey(lid) {
					this.CurrentApiKey = lid;
					this.$set(this.ScLangConfigData, 'semknoxSearch.config.semknoxC01ApiKey', lid);
					//this.ScLangConfigData['semknoxSearch.config.semknoxC01ApiKey'] = lid;
		 					this.customerIdErrorState=false;
		 					this.apiKeyErrorState=false;
		 					this.showOnBoardingContainer=false;
		 					this.isLoading=false;this.isPrefsLoading=false;
							this.customerIdFilled = !!this.ScLangConfigData['semknoxSearch.config.semknoxC01CustomerId'];
							this.apiKeyFilled = !!this.ScLangConfigData['semknoxSearch.config.semknoxC01ApiKey'];		 					
							this.$emit(this.ScLangConfigData);
							this.$emit(this.CurrentApiKey);
		 					this.forceUpdate();		 	
					//alert(lid);
				},
        
    }
});
