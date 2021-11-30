import template from './semknox-search-log-entry-info.html.twig';

const { Component } = Shopware;

Component.register('semknox-search-log-entry-info', {
    template,

    props: {
        logEntry: {
            type: Object,
            required: true
        }
    },

    data() {
        return {
            activeTab: 'raw'
        };
    },

    computed: {
        displayString() {
            return JSON.stringify(JSON.parse(this.logEntry.logDescr), null, 2);
        }
    },

    methods: {

        onClose() {
            this.$emit('close');
        }
    }
});
