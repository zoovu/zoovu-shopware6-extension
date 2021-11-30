const { Component, Defaults } = Shopware;
const { Criteria } = Shopware.Data;
const { hasOwnProperty } = Shopware.Utils.object;

import template from './semknox-search-logs.html.twig';

Shopware.Component.register('semknox-search-logs', {
	template: template,
	
	inject: [
        'repositoryFactory',
  ], 	 
      
	mixins: [
		'notification'
	],   
	
	data() {
        return {
            repository: null,
            logs: null,
            displayedLog: null
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
    	
        columns() {
            return [
            {
                property: 'createdAt',
                dataIndex: 'createdAt',
                label: 'semknox-search.logs.list.createdAt',
                allowResize: true,
            }, {
                property: 'logTitle',
                dataIndex: 'logTitle',
                label: this.$tc('semknox-search.logs.list.logTitle'),
                allowResize: true,
                primary: true
            }, {
                property: 'logStatus',
                dataIndex: 'logStatus',
                label: this.$tc('semknox-search.logs.list.logState'),
                allowResize: true
            }, {
                property: 'logType',
                dataIndex: 'logType',
                label: this.$tc('semknox-search.logs.list.logType'),
                allowResize: true
            }, {
                property: 'logDescr',
                dataIndex: 'logDescr',
                label: this.$tc('semknox-search.logs.list.logDescr'),
                allowResize: true
            }
            ];
        },
        
        modalNameFromLogEntry() {
            const eventName = this.displayedLog.logDescr;
						return 'semknox-search-log-entry-info';
        }
        
    },

    created() {
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
    
    methods: {
        showInfoModal(entryContents) {
            this.displayedLog = entryContents;
        },
        closeInfoModal() {
            this.displayedLog = null;
        },

    	
    	
    }
    	
});