const { Component, Defaults } = Shopware;
const { Criteria } = Shopware.Data;
const { hasOwnProperty } = Shopware.Utils.object;

import template from './semknox-search-cron.html.twig';

Shopware.Component.register('semknox-search-cron', {
	template: template,
	
	inject: [
        'repositoryFactory',
        'SemknoxSearchCronService'
  ], 	 
      
	mixins: [
		'notification'
	],   
	
	data() {
        return {
            repository: null,
            logs: null,
            displayedLog: null,
            
            lastPingTime: 0,
            currentUpdateRunning: 0,
            lastUpdateTime: 0,
            showAdditionalData: 0,
            
            showResetInfo: 0,
            showResetError: 0,
            showConfigInfo: 0,
            
            stopPolling: 0,
            
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
        
        modalNameFromLogEntry() {
            const eventName = this.displayedLog.logDescr;
						return 'semknox-search-log-entry-info';
        }
        
    },

    created() {
        this.createdComponent(); 
            	
        this.repository = this.repositoryFactory.create('semknox_logs');
				const logCriteria = new Criteria();
	      logCriteria
					.addSorting(
						Criteria.sort('createdAt', 'DESC')
					)

        this.repository
            .search(logCriteria, Shopware.Context.api)
            .then((result) => {
                this.logs = result;
            });
    }, 
    
    beforeDestroy() {
    	this.stopPolling=1;
    },
    
    methods: {
        createdComponent() {
        	this.getCronData();
        	this.startGetDataPeriodicaly();
        },     	
				onResetData() {
            this.SemknoxSearchCronService.setResetCron().then(response => {
              if (response.fileStatus.code === 1) {
              	this.showResetInfo=1;
              } else {
              	this.showResetError=1;
              }
						});
				},
				
				getCronData() {
            this.SemknoxSearchCronService.cronData().then(response => {
              console.log(response);
            	if (response.fileStatus.code) {
            		this.lastPingTime = response.fileStatus.tDiff;
            	} else {
            		this.lastPingTime = 0;
            		this.showConfigInfo = 1;
            	}
            	if (response.dbData.currentUpdateActionTime) {
            		this.currentUpdateRunning = response.dbData.timeToLastUpdateStart;
            	} else {
            		this.currentUpdateRunning = 0;            		
            	}
            	if (response.dbData.lastUpdateDuration) {
            		this.lastUpdateTime = response.dbData.lastUpdateDuration;
            	} else {
            		this.lastUpdateTime = 0;            		
            	}
            	
            });					
				},
				
				getCronDataTime() {
					this.getCronData();
					console.log('stopPoll: '+this.stopPolling);
					if (this.stopPolling == 1) { return; }
					setTimeout(() => {
						this.getCronDataTime();
					}, 5000);
				},
				
				startGetDataPeriodicaly() {
					setTimeout(() => {
						this.getCronDataTime();
					}, 5000);
				}
		
    	
    }
    	
});
