import template from './semknox-search-locale-field.html.twig';
import './semknox-search-locale-field.scss';

const { Component } = Shopware;
const { debounce } = Shopware.Utils;

Component.extend('semknox-search-locale-field', 'sw-text-field', {
    template,

    data() {
        return {
            error: null
        };
    },

    methods: {
        onInput: debounce(function onInput(event) {
            this.checkValue(event.target.value);
        }, 350),

        onBlur(event, removeFocusClass) {
            removeFocusClass();
            this.checkValue(event.target.value);
        },

        checkValue(value) {
            const localeCodeRegex = /^[a-z]{2}_[A-Z]{2}$/;

            this.$emit('change', value || '');

            if (!value || localeCodeRegex.exec(value)) {
                this.preventSave(false);
                this.error = null;
                return;
            }

            this.preventSave(true);
            this.error = {
                code: 1,
                detail: this.$tc('semknox-search.settingForm.locale-field.error.detail')
            };
        },

        preventSave(mode) {
            this.$emit('preventSave', mode);
        }
    }
});
