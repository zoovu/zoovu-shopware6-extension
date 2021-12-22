// Import all necessary Storefront plugins
 import JsAutosuggestPlugin from './js-autosuggest-plugin/js-autosuggest-plugin.plugin.js';

 // Register your plugin via the existing PluginManager
 const PluginManager = window.PluginManager;
 PluginManager.register('JsAutosuggestPlugin', JsAutosuggestPlugin, '[data-js-autosuggest-plugin]'); 
 
