const { Component, Defaults } = Shopware;
const { Criteria } = Shopware.Data;
const { hasOwnProperty } = Shopware.Utils.object;
const { Application } = Shopware;

import template from './semknox-search-index.html.twig';
import './semknox-search.scss';

Shopware.Component.register('semknox-search-index', {
	template: template,
	
	inject: [
        'repositoryFactory',
        'acl',
        'systemConfigApiService',
        'pluginService',
        'semknoxSearchCredService'
  ], 	 
      
	mixins: [
		'notification'
	],   
	
	data() {
		return {
			applicationRoot : null,
            isLoading: false,
            isPrefsLoading: false,
            isSaveSuccessful: false,
            isPrefsSaveSuccessful: false,
            isTestSuccessful: false,
            isTestSandboxSuccessful: false,
            customerIdFilled: false,
            apiKeyFilled: false,
            credsError : false,
            apiTargetFilled: false,
            clientIdSandboxFilled: false,
            clientSecretSandboxFilled: false,
            sandboxChecked: false,
            salesChannels: [],
            salesChannel: null,
            domains: [],
            languageId: 120,
            locales: [],
            selectedLanguageId: null,
            selectedDomainId: null,
            selectedDomainData: null,
            config: null,
            semknoxConfigData: {},
            semknoxPrefConfigData: {},
            ScLangConfigData: {},
            ScLangPrefConfigData: {},
            ScLangConfigDataSet: 0,
            ScLangPrefConfigDataSet: 0,
            ScLangRepoData: {},
            ScLangPrefRepoData: {},
            savingDisabled: false,
            savingPrefsDisabled: false,
            messageBlankErrorState: null,
            configParamsCount: 4,            
            configParamsSaved: 0,
            configPrefsParamsSaved: 0,
            configPrefsCronIntervals: [ 
            														{'val' : 3, 'title': '3h'},
            														{'val' : 4, 'title': '4h'},
            														{'val' : 6, 'title': '6h'},
            														{'val' : 12, 'title': '12h'},
            														{'val' : 24, 'title': '24h'},
            													],
            configPrefsCronHours: [ 
            														{'val' : 0, 'title': '0:00'},
            														{'val' : 1, 'title': '1:00'},
            														{'val' : 2, 'title': '2:00'},
            														{'val' : 3, 'title': '3:00'},
            														{'val' : 4, 'title': '4:00'},
            														{'val' : 5, 'title': '5:00'},
            														{'val' : 6, 'title': '6:00'},
            														{'val' : 7, 'title': '7:00'},
            														{'val' : 8, 'title': '8:00'},
            														{'val' : 9, 'title': '9:00'},
            														{'val' : 10, 'title': '10:00'},
            														{'val' : 11, 'title': '11:00'},
            														{'val' : 12, 'title': '12:00'},
            														{'val' : 13, 'title': '13:00'},
            														{'val' : 14, 'title': '14:00'},
            														{'val' : 15, 'title': '15:00'},
            														{'val' : 16, 'title': '16:00'},
            														{'val' : 17, 'title': '17:00'},
            														{'val' : 18, 'title': '18:00'},
            														{'val' : 19, 'title': '19:00'},
            														{'val' : 20, 'title': '20:00'},
            														{'val' : 21, 'title': '21:00'},
            														{'val' : 22, 'title': '22:00'},
            														{'val' : 23, 'title': '23:00'},
            													],
            shopData: {},
            showOnBoarding: false,
            CurrentCustomerId: null,
            CurrentApiKey: null,
			   		onBoardingErrorMsg: '',
    				isErrorInOnboarding: false,
    				showOnBoardingContainer: false
  	};
	}, 

	metaInfo() {
		return {
			title: this.$createTitle()
		};
	}, 	
	
	computed: {
		pathToConfig () {
			return { name: 'semknox.search.index' };
		},
		pathToLogs () {
			return { name: 'semknox.search.logs' };
		},
		pathToCron () {
			return { name: 'semknox.search.cron' };
		},
		
		showSPBCard() {
			return true;
		},

		showPlusCard() {
			return true;
		}, 
		
		getPrefsCronHours() {
			return this.configPrefsCronHours;
		},
		
		getPrefsCronInterval() {
			return this.configPrefsCronIntervals;
		},
		
		getSelectedLanguageId() {
			return this.selectedLanguageId;
		},
        		
		getSelectedDomainId() {
			return this.selectedDomainId;
		},
        		
		getScLangConfigData() {
			return this.ScLangConfigData;
		},
        		
		hasError() {
			return false;
			//no error on customerId/apiKey to clear these fields in setup
			return ( !(this.customerIdFilled && this.apiKeyFilled) );
		},		
		
		hasPrefsError() {
			return false;
		},		
		
		salesChannelRepository() {
			return this.repositoryFactory.create('sales_channel');
		},
		
		localeRepository() {
			return this.repositoryFactory.create('locale');
		},

		customerIdErrorState() {
			if ( (this.customerIdFilled) ) {
				return null;
			}
			return this.messageBlankErrorState;
		}, 		
		
		apiKeyErrorState() {
			if ( (this.apiKeyFilled) ) {
				return null;
			}

			return this.messageBlankErrorState;
		},

		apiTargetErrorState() {
			if (this.apiTargetFilled) {
				return null;
			}

			return this.messageBlankErrorState;
		},

		clientIdSandboxErrorState() {
			if (!this.sandboxChecked || this.clientIdSandboxFilled) {
				return null;
			}

			return this.messageBlankErrorState;
		},

		clientSecretSandboxErrorState() {
			if (!this.sandboxChecked || this.clientSecretSandboxFilled) {
				return null;
			}

			return this.messageBlankErrorState;
		},

		getSalesChannelDomains() {
			return this.domains;
		},
		
		activateOnBoardingButton() {
			if (this.customerIdFilled && this.apiKeyFilled) {
				return false;
			}		 					
			return !this.showOnBoardingContainer;
		} ,   	
				
		
	},

	watch: {
		ScLangConfigData: {
			handler() {
				this.checkRequiredFields();
			},
			deep: true
		},
		CurrentCustomerId: {
			handler() {
				this.checkRequiredFields();
			}
			
		},
		CurrentApiKey: {
			handler() {
				this.checkRequiredFields();
			}
			
		}
	}, 	
	
	created() {
		this.createdComponent();
		this.getLocaleList();
    this.repository = this.repositoryFactory.create('semknox_config');
    this.getShopData();
    this.getPrefs();

    //this.getConfig();
	}, 	
	
	methods: {
		getShopData() {
			//function to retrieve all data to get API-accessdata
			//get data from Config-Store
			this.systemConfigApiService.getValues('core').then(res => {
					if (hasOwnProperty(res,'core.basicInformation.email')) {
						this.shopData.shopEmail = res['core.basicInformation.email'];
					}
					if (hasOwnProperty(res,'core.store.licenseHost')) {
						this.shopData.shopUrl = res['core.store.licenseHost'];
					}
					if (hasOwnProperty(res,'core.basicInformation.shopName')) {					
						this.shopData.shopName = res['core.basicInformation.shopName'];
					}
					if (hasOwnProperty(res,'core.update.previousVersion')) {					
						this.shopData.shopwareVersion = res['core.update.previousVersion'];
					}
					
					//console.log(this.shopData);
				});
			//get data from Plugin-Store
			const pluginCriteria = new Criteria();
      pluginCriteria
				.addFilter(
					Criteria.equalsAny('plugin.name', ['semknoxSearch'])
				)
				.setLimit(1);

      this.repositoryFactory.create('plugin')
				.search(pluginCriteria, Shopware.Context.api)
				.then((result) => {
					if (result.total < 1) {
						return;
					}
					result.forEach((plugin) => {
						this.shopData.pluginVersion = plugin.version;
					});
				});
			
				
			//console.log(this.shopData);
			
		},
		checkRequiredFields() {
				if (this.selectedDomainId === null) {
					this.customerIdFilled = false;
					this.apiKeyFilled = false;
					this.apiTargetFilled = false;
				} else {
					this.customerIdFilled = !!this.ScLangConfigData['semknoxSearch.config.semknoxC01CustomerId'];
					this.apiKeyFilled = !!this.ScLangConfigData['semknoxSearch.config.semknoxC01ApiKey'];
					this.apiTargetFilled = !!this.ScLangConfigData['semknoxSearch.config.semknoxBaseUrlID'];
				}			
		},
		checkRequiredPrefFields() {
		},
		getPrefs() {
			this.isLoading = true;
			this.ScLangPrefConfigDataSet = 0;
			this.ScLangPrefConfigData = {};
			this.semknoxPrefConfigData = {};
			this.ScLangRepoData = {};
			const criteria = new Criteria();
			criteria.addFilter(Criteria.equalsAny('salesChannelId', [
				'00000000000000000000000000000000',
			]));
			criteria.addFilter(Criteria.equalsAny('domainId', [
				'00000000000000000000000000000000',
			]));
			this.repository.search(criteria, Shopware.Context.api).then(res => {
				res.forEach((cnf) => {
					const obj = JSON.parse(cnf.configurationValue);
					this.semknoxPrefConfigData[cnf.configurationKey] = obj._value;
					this.$set(this.ScLangPrefConfigData, cnf.configurationKey, obj._value);
					this.ScLangPrefRepoData[cnf.configurationKey] = cnf;
				});
				//set default-values
				if (!this.semknoxPrefConfigData.hasOwnProperty('semknoxSearch.config.semknoxUpdateBlocksize')) {
					this.semknoxPrefConfigData['semknoxSearch.config.semknoxUpdateBlocksize'] = 500;
					this.ScLangPrefConfigData['semknoxSearch.config.semknoxUpdateBlocksize'] = 500;
				}
				if (!this.semknoxPrefConfigData.hasOwnProperty('semknoxSearch.config.semknoxUpdateCronTime')) {
					this.semknoxPrefConfigData['semknoxSearch.config.semknoxUpdateCronTime'] = 4;
					this.ScLangPrefConfigData['semknoxSearch.config.semknoxUpdateCronTime'] = 4;
				}
				if (!this.semknoxPrefConfigData.hasOwnProperty('semknoxSearch.config.semknoxUpdateCronInterval')) {
					this.semknoxPrefConfigData['semknoxSearch.config.semknoxUpdateCronInterval'] = 24;
					this.ScLangPrefConfigData['semknoxSearch.config.semknoxUpdateCronInterval'] = 24;
				}
				if (!this.semknoxPrefConfigData.hasOwnProperty('semknoxSearch.config.semknoxUpdateUseVariantMaster')) {
					this.semknoxPrefConfigData['semknoxSearch.config.semknoxUpdateUseVariantMaster'] = false;
					this.ScLangPrefConfigData['semknoxSearch.config.semknoxUpdateUseVariantMaster'] = false;
				}
				if (!this.semknoxPrefConfigData.hasOwnProperty('semknoxSearch.config.semknoxUpdateUploadContent')) {
					this.semknoxPrefConfigData['semknoxSearch.config.semknoxUpdateUploadContent'] = true;
					this.ScLangPrefConfigData['semknoxSearch.config.semknoxUpdateUploadContent'] = true;
				}
				if (!this.semknoxPrefConfigData.hasOwnProperty('semknoxSearch.config.semknoxRedirectOn1')) {
					this.semknoxPrefConfigData['semknoxSearch.config.semknoxRedirectOn1'] = false;
					this.ScLangPrefConfigData['semknoxSearch.config.semknoxRedirectOn1'] = false;
				}
				if (!this.semknoxPrefConfigData.hasOwnProperty('semknoxSearch.config.semknoxChangeMediaDomain')) {
					this.semknoxPrefConfigData['semknoxSearch.config.semknoxChangeMediaDomain'] = true;
					this.ScLangPrefConfigData['semknoxSearch.config.semknoxChangeMediaDomain'] = true;
				}

				this.checkRequiredPrefFields();
				//this.semknoxConfigData = res;
				this.$emit(this.ScLangPrefConfigData);
				this.ScLangPrefConfigDataSet = 1;
				//console.log('repo-ergebnis:');console.log(this.semknoxConfigData);
			}).finally(() => {
				this.isLoading = false;
			});

			
		},
    getConfig() {
			this.isLoading = true;
			this.ScLangConfigDataSet = 0;
			this.semknoxConfigData = {};
			this.ScLangConfigData = {};
			this.ScLangRepoData = {};
			
			const criteria = new Criteria();
			criteria.addFilter(Criteria.equalsAny('salesChannelId', [
				this.selectedSalesChannelId,
			]));
			criteria.addFilter(Criteria.equalsAny('domainId', [
				this.selectedDomainId,
			]));
			this.repository.search(criteria, Shopware.Context.api).then(res => {
				res.forEach((cnf) => {
					const obj = JSON.parse(cnf.configurationValue);
					this.semknoxConfigData[cnf.configurationKey] = obj._value;
					//this.ScLangConfigData[cnf.configurationKey] = obj._value;
					this.$set(this.ScLangConfigData, cnf.configurationKey, obj._value);
					this.ScLangRepoData[cnf.configurationKey] = cnf;
				});
				//set default-values
				if (!this.semknoxConfigData.hasOwnProperty('semknoxSearch.config.semknoxUpdateBlocksize')) {
					this.semknoxConfigData['semknoxSearch.config.semknoxUpdateBlocksize'] = 500;
					this.ScLangConfigData['semknoxSearch.config.semknoxUpdateBlocksize'] = 500;
				}
				if (!this.semknoxConfigData.hasOwnProperty('semknoxSearch.config.semknoxUpdateUseVariantMaster')) {
					this.semknoxConfigData['semknoxSearch.config.semknoxUpdateUseVariantMaster'] = false;
					this.ScLangConfigData['semknoxSearch.config.semknoxUpdateUseVariantMaster'] = false;
				}
				if (!this.semknoxConfigData.hasOwnProperty('semknoxSearch.config.semknoxUpdateUploadContent')) {
					this.semknoxConfigData['semknoxSearch.config.semknoxUpdateUploadContent'] = true;
					this.ScLangConfigData['semknoxSearch.config.semknoxUpdateUploadContent'] = true;
				}
				if (!this.semknoxConfigData.hasOwnProperty('semknoxSearch.config.semknoxRedirectOn1')) {
					this.semknoxConfigData['semknoxSearch.config.semknoxRedirectOn1'] = false;
					this.ScLangConfigData['semknoxSearch.config.semknoxRedirectOn1'] = false;
				}
				if (!this.semknoxConfigData.hasOwnProperty('semknoxSearch.config.semknoxChangeMediaDomain')) {
					this.semknoxConfigData['semknoxSearch.config.semknoxChangeMediaDomain'] = true;
					this.ScLangConfigData['semknoxSearch.config.semknoxChangeMediaDomain'] = true;
				}
				if (!this.semknoxConfigData.hasOwnProperty('semknoxSearch.config.semknoxActivateCategoryListing')) {
					this.semknoxConfigData['semknoxSearch.config.semknoxActivateCategoryListing'] = false;
					this.ScLangConfigData['semknoxSearch.config.semknoxActivateCategoryListing'] = false;
				}
				if (!this.semknoxConfigData.hasOwnProperty('semknoxSearch.config.semknoxActivateSearchTemplate')) {
					this.semknoxConfigData['semknoxSearch.config.semknoxActivateSearchTemplate'] = false;
					this.ScLangConfigData['semknoxSearch.config.semknoxActivateSearchTemplate'] = false;
				}
				this.checkRequiredFields();
				//this.semknoxConfigData = res;
				this.CurrentCustomerId = this.ScLangConfigData['semknoxSearch.config.semknoxC01CustomerId'];
				this.CurrentApiKey = this.ScLangConfigData['semknoxSearch.config.semknoxC01ApiKey'];
				this.$emit(this.ScLangConfigData);
				this.ScLangConfigDataSet = 1;
				//console.log('repo-ergebnis:');console.log(this.semknoxConfigData);
			}).finally(() => {
				this.isLoading = false;
			});

    },
		
		createdComponent() {
			this.isLoading = true;

			const criteria = new Criteria();
			criteria.addFilter(Criteria.equalsAny('typeId', [
				Defaults.storefrontSalesChannelTypeId,
				Defaults.apiSalesChannelTypeId
			]));
			criteria.addAssociation('domains');
			criteria.addAssociation('languages');

			this.salesChannelRepository.search(criteria, Shopware.Context.api).then(res => {
				/*add for all saleschannel, not needed now
				res.add({
					id: null,
					translated: {
						name: this.$tc('sw-sales-channel-switch.labelDefaultOption')
					}
				});
				*/
				res = res.filter(item => item.languages.length > 0)
				this.salesChannels = res;
			}).finally(() => {
				this.isLoading = false;
			});

		},
		

    getApplicationRootReference() {
        if (!this.applicationRoot) {
            this.applicationRoot = Application.getApplicationRoot();
        }

        return this.applicationRoot;
    },
		
		spawnNotification(msgTitle='', msg='', msgType='error', autoclose=true) {
				// msgType= success, info, warning, error
				switch (msgType) {
					case 'error' : this.createNotificationError({title: msgTitle, message: msg, autoclose:autoclose});break;
					case 'success' : this.createNotificationSuccess({title: msgTitle, message: msg, autoclose:autoclose});break;
					case 'info' : this.createNotificationInfo({title: msgTitle, message: msg, autoclose:autoclose});break;
					case 'warning' : this.createNotificationWarning({title: msgTitle, message: msg, autoclose:autoclose});break;
				}
				/*
        this.getApplicationRootReference().$store.dispatch('notification/createGrowlNotification', {
            title: msgTitle,
            message: msg,
            autoClose: autoclose,
            variant: msgType,
            actions: []
        });
        */
		},
		
		async checkCreds(projectId, apiKey) {
			this.credsError = false;
			if ( (projectId == '') || (apiKey == '') ) { return 0; }			
			var ret = -1;
			try {
				await this.semknoxSearchCredService.checkCreds(apiKey, projectId).then(value => { 
						if (value.status == 'success') { ret = 1; }
				})
			} catch (e) {
				ret = -11;
			}
			if (ret < 0) { this.credsError = true; }
			return ret;
		}, 
		
		async onSave() {
			if (this.hasError) {
				this.isSaveSuccessful=false;
				return;
			}
			//use every single Config-Element to save seperately
//			this.saveSingle('semknoxSearch.config.semknoxBaseUrlID');
		  this.ScLangConfigData['semknoxSearch.config.semknoxC01CustomerId'] = this.ScLangConfigData['semknoxSearch.config.semknoxC01CustomerId'].trim();
		  this.ScLangConfigData['semknoxSearch.config.semknoxC01ApiKey'] = this.ScLangConfigData['semknoxSearch.config.semknoxC01ApiKey'].trim();
		  var chkCreds = await this.checkCreds(this.ScLangConfigData['semknoxSearch.config.semknoxC01CustomerId'], this.ScLangConfigData['semknoxSearch.config.semknoxC01ApiKey']);
		  if (chkCreds < 0) {
		  	this.isSaveSuccessful=false;
		  	this.spawnNotification(this.$t('semknox-search.settingForm.notifications.save.title'), this.$t('semknox-search.settingForm.notifications.save.msgErrorCreds'), 'warning');
		  	return;
		  }
		  this.configParamsSaved=0;
		  this.configParamsCount=5;
			this.saveSingle('semknoxSearch.config.semknoxC01CustomerId');
			this.saveSingle('semknoxSearch.config.semknoxC01ApiKey');
			this.saveSingle('semknoxSearch.config.semknoxActivate');
			this.saveSingle('semknoxSearch.config.semknoxActivateUpdate');
			this.saveSingle('semknoxSearch.config.semknoxActivateAutosuggest');
			/*
			this.saveSingle('semknoxSearch.config.semknoxUpdateBlocksize');
			this.saveSingle('semknoxSearch.config.semknoxUpdateUseVariantMaster');
			*/
			this.saveSingle('semknoxSearch.config.semknoxActivateCategoryListing');
			this.saveSingle('semknoxSearch.config.semknoxActivateSearchTemplate');
		},
		
		onSavePrefs() {
			if (this.hasPrefsError) {
				return;
			}						
//			this.saveSingle('semknoxSearch.config.semknoxBaseUrlID');
		  this.ScLangPrefConfigData['semknoxSearch.config.semknoxC01CustomerId'] = '00000000000000000000000000000000';
		  this.ScLangPrefConfigData['semknoxSearch.config.semknoxC01ApiKey'] = '0000000000000000000000000000000';
		  this.configPrefsParamsSaved=0;
		  this.configParamsCount=3;
			this.saveSinglePref('semknoxSearch.config.semknoxUpdateCronTime');
			this.saveSinglePref('semknoxSearch.config.semknoxUpdateCronInterval');
			this.saveSinglePref('semknoxSearch.config.semknoxUpdateBlocksize');
			this.saveSinglePref('semknoxSearch.config.semknoxUpdateUseVariantMaster');
			this.saveSinglePref('semknoxSearch.config.semknoxUpdateUploadContent');
			this.saveSinglePref('semknoxSearch.config.semknoxRedirectOn1');
			this.saveSinglePref('semknoxSearch.config.semknoxChangeMediaDomain');
		},
		
		saveSingle(configId) {
			var isNew=false;
			if (!this.ScLangRepoData.hasOwnProperty(configId)) {
				//create a new entry
				//console.log('create new');
				this.ScLangRepoData[configId] = this.repository.create();				
				this.ScLangRepoData[configId].configurationKey = configId;
				this.ScLangRepoData[configId].domainId = this.selectedDomainId;
				this.ScLangRepoData[configId].languageId = this.selectedLanguageId;
				this.ScLangRepoData[configId].salesChannelId = this.selectedSalesChannelId;
				isNew=true;
				console.log(this.ScLangRepoData[configId]);
			} 
			if (this.ScLangRepoData.hasOwnProperty(configId)) {
				//console.log(this.ScLangRepoData[configId]);
				//console.log('repoSaveSingle: '+configId);
				const newVal=JSON.stringify({_value : this.ScLangConfigData[configId]});
				//console.log('newval: '+newVal);
				this.ScLangRepoData[configId].configurationValue=newVal;
				this.isLoading = true;
      	this.repository
          .save(this.ScLangRepoData[configId], Shopware.Context.api)
          .then((res) => {
          	//console.log('Save Done: ');
          	//console.log(res);
						//this.getBundle();
						this.isLoading = false;
						if (isNew) {
							delete(this.ScLangRepoData[configId]._isNew);
						}
						this.configParamsSaved++;
						if (this.configParamsSaved == this.configParamsCount) {
							this.isSaveSuccessful = true;
							this.spawnNotification(this.$t('semknox-search.settingForm.notifications.save.title'), this.$t('semknox-search.settingForm.notifications.save.msgSuccess'), 'success');
						}
						//this.processSuccess = true;
					}).catch((exception) => {
						console.log('Save Error: '+exception);
           	this.isLoading = false;           	
						this.createNotificationError({
							title: this.$t('semknox-search.settingForm.messageWebhookError'),
							message: exception
						});
					});			
				
			}
			
		},

		saveSinglePref(configId) {
			var isNew=false;
			if (!this.ScLangPrefRepoData.hasOwnProperty(configId)) {
				//create a new entry
				//console.log('create new');
				this.ScLangPrefRepoData[configId] = this.repository.create();				
				this.ScLangPrefRepoData[configId].configurationKey = configId;
				this.ScLangPrefRepoData[configId].domainId = '00000000000000000000000000000000';
				this.ScLangPrefRepoData[configId].languageId = '00000000000000000000000000000000';
				this.ScLangPrefRepoData[configId].salesChannelId = '00000000000000000000000000000000';
				isNew=true;
				console.log(this.ScLangPrefRepoData[configId]);
			} 
			if (this.ScLangPrefRepoData.hasOwnProperty(configId)) {
				//console.log(this.ScLangRepoData[configId]);
				//console.log('repoSaveSingle: '+configId);
				const newVal=JSON.stringify({_value : this.ScLangPrefConfigData[configId]});
				//console.log('newval: '+newVal);
				this.ScLangPrefRepoData[configId].configurationValue=newVal;
				this.isPrefLoading = true;
      	this.repository
          .save(this.ScLangPrefRepoData[configId], Shopware.Context.api)
          .then((res) => {
          	//console.log('Save Done: ');
          	//console.log(res);
						//this.getBundle();
						this.isPrefLoading = false;
						if (isNew) {
							delete(this.ScLangPrefRepoData[configId]._isNew);
						}
						this.configPrefsParamsSaved++;
						if (this.configPrefsParamsSaved == this.configParamsCount) {
							this.isPrefsSaveSuccessful = true;
							this.spawnNotification(this.$t('semknox-search.settingForm.notifications.save.title'), this.$t('semknox-search.settingForm.notifications.save.msgSuccess'), 'success');
							
						}
						//this.processSuccess = true;
					}).catch((exception) => {
						console.log('Save Error: '+exception);
           	this.isPrefsLoading = false;           	
						this.createNotificationError({
							title: this.$t('semknox-search.settingForm.messageWebhookError'),
							message: exception
						});
					});			
				
			}
			
		},
		getLocaleList() {
			//console.log('localesList');
			this.isLoading = true;
			const criteria = new Criteria();
			criteria.setLimit(500);
		
			this.localeRepository.search(criteria, Shopware.Context.api).then(res => {
				res.forEach((loc) => {
					this.locales[loc.id] = {
						id : loc.id,
						name: loc.name,
						code: loc.code,
						territory: loc.territory 
					};
				});
				
				//console.log(this.locales);
				
			}).finally(() => {
				this.isLoading = false;
			});
		},		
		
		getRepoLocaleById(locId) {
			const criteria = new Criteria();
			criteria.addFilter(Criteria.equalsAny('id', [
				locId
			]));
			var ret={};
			this.localeRepository.search(criteria, Shopware.Context.api).then(res => {
				ret={
					id : locId,
					name: res.first().name,
					code: res.first().code,
					territory: res.first().territory 
				};
			}).finally(() => {
				this.isLoading = false;
			});
			return ret;
		},
		
		getLocaleById(locId) {			
			return this.locales[locId];
		},
		
		getDomainList(salesChannel) {
			this.domains = [];
			if (salesChannel === null) {
				this.selectedLanguageId = null;
				this.selectedDomainId = null;
			} else {
				this.salesChannels.forEach((sc) => {
					if (sc.id == salesChannel) {
						
						sc.domains.forEach((dom) => {							
							var langName='';
							var localeId='';
							var localeData={};
							var domUrl=dom.url;
							var scName='';
							var langId='';
							sc.languages.forEach((lang) => {
								if (lang.id == dom.languageId) {
									langName=lang.name;
									langId=lang.id;
									localeId=lang.localeId;
									localeData=this.getLocaleById(lang.localeId);
									scName=sc.name;
								}
							});
							
							this.domains.push({
								id: dom.id,
								name: domUrl+'  '+langName,
								url: domUrl,
								scName: scName,
								langName : langName,
								langId : langId,
								localeId: localeId,
								locData: localeData,
								translated: {
									name: langName
								}
							});
						});
						
//						console.log(this.languages);
					}
				});
			}
		},

		preventSave(mode) {
			if (!mode) {
				this.savingDisabled = false;
				return;
			}

			this.savingDisabled = true;
		},
		
		onInputDomain(lid) {
			this.setDomainId = lid;
			this.selectedDomainId = lid;
			this.selectedLanguageId = null;
			
			
			this.domains.forEach((dom) => {
				if (dom.id == lid) {
					this.selectedLanguageId = dom.langId;
					this.selectedDomainData = dom;
					this.shopData.selectedShopUrl = dom.url;
					this.shopData.selectedShopLang = dom.locData.code;
					var h=dom.locData.code.split("-");
					if (h.length>1) {
						this.shopData.selectedShopLang = h[0];
					}
					this.shopData.selectdDomainTitle = dom.scName+'_'+this.shopData.selectedShopLang
				}
			});
//			console.log(this.shopData);
			this.ScLangConfigData = {};
			this.getConfig();
		},
		
		onInputSalesChannel(lid) {
//			console.log('onInputSC: '+lid);
			this.selectedSalesChannelId = lid;
			this.selectedLanguageId=null;
			this.$refs.configComponent.selectedSalesChannelId = lid;
			this.ScLangConfigData = {};
			this.getDomainList(lid);
		},

		aclAllowed(module) {
			const aclService = Shopware.Service('acl');
			if (aclService) {		
				//console.log('acl');
				return aclService.can(module);
			} else {
				//console.log('nonacl');
				return true;
			}
		},
		
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
    			this.showOnBoardingContainer = true;
    			
     			if (document.querySelector('#siteSearchOnBoardingContainer')) {
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
		 					this.isLoading=false;
							this.customerIdFilled = !!this.ScLangConfigData['semknoxSearch.config.semknoxC01CustomerId'];
							this.apiKeyFilled = !!this.ScLangConfigData['semknoxSearch.config.semknoxC01ApiKey'];		 					
							this.$emit(this.ScLangConfigData);
		 					this.forceUpdate();		 	
		 					//console.log(this.hasError);
							}.bind(this),
						error: function(status, statusText, event) { 
							console.error(status, statusText, event); 
							this.onBoardingErrorMsg= status;
    					this.isErrorInOnboarding= true;
		 					this.showOnBoardingContainer=true;
		 					this.isLoading=false;
    					this.forceUpdate();
							}.bind(this),
						onSubmit: function() { 
		 					this.isLoading=true;
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
	},
        


});
