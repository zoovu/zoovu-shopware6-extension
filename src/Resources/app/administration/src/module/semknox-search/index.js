import './acl'; 
import './page/semknox-search-index';
import './page/semknox-search-logs';
import './page/semknox-search-cron';
import './extension/sw-plugin'; 
import './components/semknox-search-locale-field';
//import './components/semknox-search-credentials';
import './components/semknox-search-log-entry-info';

//import './extension/sw-settings-index';

import deDE from './snippet/de-DE.json';
import enGB from './snippet/en-GB.json';

const { Module } = Shopware;

Module.register('semknox-search', {
    type: 'plugin',
    name: 'Search',
    title: 'semknox-search.general.mainMenuItemGeneral',
    description: 'semknox-search.general.descriptionTextModule',
    color: '#9AA8B5',
    icon: 'default-action-settings',

    snippets: {
        'de-DE': deDE,
        'en-GB': enGB
    },

    routes: {
        index: {
            component: 'semknox-search-index',
            path: 'index',
        },
        logs: {
            component: 'semknox-search-logs',
            path: 'logs',
        },
        cron: {
            component: 'semknox-search-cron',
            path: 'cron',
        },
        config: {
            component: 'semknox-search-config',
            path: 'config',
        }
    },
    
    navigation: [
    {
    		id: 'semknoxSearchIndex',
        label: 'semknox-search.header',
        color: '#62ff80',
        path: 'semknox.search.index',
        icon: 'default-action-search',
        parent: 'sw-settings',
        position: 100
    }
    /*,
		{
				id: 'semknoxSearchLogs',
        label: 'semknox-search.general.mainMenuItemLogs',
        color: '#62ff80',
        path: 'semknox.search.logs',
        icon: 'default-basic-stack-block',
        position: 110,
        parent: 'sw-settings',
    },
		{
				id: 'semknoxSearchCron',
        label: 'semknox-search.general.mainMenuItemCron',
        color: '#62ff80',
        path: 'semknox.search.cron',
        icon: 'default-basic-stack-block',
        position: 120,
        parent: 'sw-settings',
    } */   
    ]    
});
