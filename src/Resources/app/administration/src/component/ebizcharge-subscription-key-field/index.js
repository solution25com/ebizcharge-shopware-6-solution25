import template from './ebizcharge-subscription-key-field.html.twig';

const { Component } = Shopware;

Component.register('ebizcharge-subscription-key-field', {
    template,

    props: {
        value: {
            type: String,
            required: false,
            default: '',
        },
        label: {
            type: String,
            required: false,
            default: '',
        },
        helpText: {
            type: String,
            required: false,
            default: '',
        },
        error: {
            type: Object,
            required: false,
            default: null,
        },
        disabled: {
            type: Boolean,
            required: false,
            default: false,
        },
        required: {
            type: Boolean,
            required: false,
            default: false,
        },
    },

    emits: ['update:value'],

    data() {
        return {
            localValue: this.maskValue(this.value),
        };
    },

    computed: {
        displayValue() {
            return this.localValue;
        },
    },

    watch: {
        value(value) {
            this.localValue = this.maskValue(value);
        },
    },

    methods: {
        onInput(value) {
            this.localValue = value;

            if (value === this.maskValue(this.value)) {
                return;
            }

            this.$emit('update:value', value);
        },

        maskValue(value) {
            const rawValue = `${value ?? ''}`.trim();

            if (rawValue === '') {
                return '';
            }

            if (rawValue.length <= 8) {
                return rawValue;
            }

            return `${rawValue.slice(0, 4)}${'*'.repeat(rawValue.length - 8)}${rawValue.slice(-4)}`;
        },
    },
});
