import template from './ebizcharge-settings-index.html.twig';
import './ebizcharge-settings-index.scss';

const { Component, Mixin } = Shopware;
const { ShopwareError } = Shopware.Classes;
const { Criteria } = Shopware.Data;

const CONFIG_DOMAIN = 'EbizChargeShopware.config';

const DEFAULT_CONFIG = {
    environmentMode: 'sandbox',
    sandboxBaseUrl: 'https://restapi1.ebizcharge.net/production/v2',
    sandboxSecurityId: '',
    sandboxUserId: '',
    sandboxPassword: '',
    sandboxSubscriptionKey: '',
    productionBaseUrl: 'https://restapi1.ebizcharge.net/production/v2',
    productionSecurityId: '',
    productionUserId: '',
    productionPassword: '',
    productionSubscriptionKey: '',
    processingCommand: 'Sale',
    shipFromZip: '',
    descriptionTemplate: 'Order {{ orderNumber }}',
    enforceAvsCheck: false,
    verificationLookbackDays: 7,
    connectionTimeoutSeconds: 20,
    retryCount: 1,
    webhookBasicUsername: '',
    webhookBasicPassword: '',
    webhookSignatureKey: '',
    paymentFlow: 'redirect',
};

const ALWAYS_REQUIRED_FIELDS = [
    'environmentMode',
    'processingCommand',
    'shipFromZip',
    'verificationLookbackDays',
    'connectionTimeoutSeconds',
    'retryCount',
];

const ENVIRONMENT_REQUIRED_FIELDS = {
    sandbox: [
        'sandboxBaseUrl',
        'sandboxSecurityId',
        'sandboxUserId',
        'sandboxPassword',
        'sandboxSubscriptionKey',
    ],
    production: [
        'productionBaseUrl',
        'productionSecurityId',
        'productionUserId',
        'productionPassword',
        'productionSubscriptionKey',
    ],
};

Component.register('ebizcharge-settings-index', {
    template,

    inject: [
        'repositoryFactory',
        'shopwareExtensionService',
        'systemConfigApiService',
    ],

    mixins: [
        Mixin.getByName('notification'),
    ],

    data() {
        return {
            configValues: this.buildDefaultConfig(),
            currentSalesChannelId: null,
            extension: null,
            fieldErrors: {},
            isLoading: false,
            storefrontDomainUrl: null,
        };
    },

    computed: {
        environmentOptions() {
            return [
                { value: 'sandbox', label: this.$tc('ebizcharge.settings.sandbox') },
                { value: 'production', label: this.$tc('ebizcharge.settings.production') },
            ];
        },

        processingCommandOptions() {
            return [
                { value: 'Sale', label: 'Sale' },
                { value: 'AuthOnly', label: 'AuthOnly' },
            ];
        },

        paymentFlowOptions() {
            return [
                {
                    value: 'redirect',
                    label: this.$tc('ebizcharge.settings.paymentFlowRedirect'),
                },
                {
                    value: 'embedded',
                    label: this.$tc('ebizcharge.settings.paymentFlowEmbedded'),
                },
            ];
        },

        myExtensions() {
            return Shopware.Store.get('shopwareExtensions').myExtensions.data;
        },

        defaultThemeAsset() {
            return Shopware.Filter.getByName('asset')(
                'administration/administration/static/img/theme/default_theme_preview.webp'
            );
        },

        image() {
            if (this.extension?.icon) {
                return this.extension.icon;
            }

            if (this.extension?.iconRaw) {
                return `data:image/png;base64, ${this.extension.iconRaw}`;
            }

            return this.defaultThemeAsset;
        },

        validationMessages() {
            const grouped = {};

            Object.values(this.fieldErrors).forEach((error) => {
                if (!grouped[error.detail]) {
                    grouped[error.detail] = 0;
                }

                grouped[error.detail] += 1;
            });

            return Object.entries(grouped).map(([detail, count]) => `${count}x "${detail}"`);
        },

        storefrontBaseUrl() {
            return this.storefrontDomainUrl || window.location.origin;
        },

        webhookEndpointUrl() {
            return `${this.storefrontBaseUrl}/ebizcharge/webhook`;
        },
    },

    created() {
        this.createdComponent();
    },

    methods: {
        async createdComponent() {
            if (!this.myExtensions.length) {
                await this.shopwareExtensionService.updateExtensionData();
            }

            this.extension = this.myExtensions.find((extension) => {
                return extension.name === 'EbizChargeShopware';
            }) || null;

            await Promise.all([
                this.loadConfig(),
                this.loadStorefrontDomainUrl(),
            ]);
        },

        async onSalesChannelChanged(salesChannelId) {
            this.currentSalesChannelId = salesChannelId;
            await Promise.all([
                this.loadConfig(),
                this.loadStorefrontDomainUrl(),
            ]);
        },

        async loadStorefrontDomainUrl() {
            this.storefrontDomainUrl = null;

            if (!this.currentSalesChannelId) {
                return;
            }

            try {
                const repository = this.repositoryFactory.create('sales_channel_domain');
                const criteria = new Criteria(1, 1);
                criteria.addFilter(Criteria.equals('salesChannelId', this.currentSalesChannelId));
                criteria.addSorting(Criteria.sort('createdAt', 'ASC'));

                const domains = await repository.search(criteria);
                const domain = domains.first();

                this.storefrontDomainUrl = this.normalizeStorefrontDomainUrl(domain?.url);
            } catch {
                this.storefrontDomainUrl = null;
            }
        },

        async loadConfig() {
            this.isLoading = true;
            this.fieldErrors = {};

            try {
                const values = await this.systemConfigApiService.getValues(
                    CONFIG_DOMAIN,
                    this.currentSalesChannelId,
                    { inherit: true }
                );

                this.configValues = {
                    ...this.buildDefaultConfig(),
                    ...values,
                };
            } catch (error) {
                this.createNotificationError({
                    title: this.$tc('global.default.error'),
                    message: error?.response?.data?.errors?.[0]?.detail
                        || this.$tc('ebizcharge.configValidation.loadError'),
                });
            } finally {
                this.isLoading = false;
            }
        },

        async onSave() {
            if (!this.validateConfig(true)) {
                return;
            }

            this.isLoading = true;

            try {
                const payload = {};
                payload[this.currentSalesChannelId] = this.normalizeConfigForSave(this.configValues);

                await this.systemConfigApiService.batchSave(payload);
                this.fieldErrors = {};
            } catch {
                this.createNotificationError({
                    title: this.$tc('global.default.error'),
                    message: this.$tc('ebizcharge.configValidation.saveError'),
                    autoClose: false,
                });
            } finally {
                this.isLoading = false;
            }
        },

        validateConfig(showNotification) {
            const errors = {};

            this.getRequiredFields().forEach((fieldName) => {
                if (this.hasValue(this.configValues[this.configKey(fieldName)])) {
                    return;
                }

                errors[fieldName] = {
                    code: 'EBIZCHARGE__CONFIG_REQUIRED_FIELD',
                    detail: this.$tc('ebizcharge.configValidation.requiredField'),
                };
            });

            this.fieldErrors = errors;

            if (showNotification && Object.keys(errors).length > 0) {
                this.createNotificationError({
                    title: this.$tc('global.default.error'),
                    message: this.$tc('ebizcharge.configValidation.saveError'),
                    autoClose: false,
                });
            }

            return Object.keys(errors).length === 0;
        },

        getFieldError(fieldName) {
            if (!this.fieldErrors[fieldName]) {
                return null;
            }

            return new ShopwareError(this.fieldErrors[fieldName]);
        },

        getRequiredFields() {
            const environment = this.configValues[this.configKey('environmentMode')] === 'production'
                ? 'production'
                : 'sandbox';

            return [
                ...ALWAYS_REQUIRED_FIELDS,
                ...ENVIRONMENT_REQUIRED_FIELDS[environment],
            ];
        },

        buildDefaultConfig() {
            const config = {};

            Object.entries(DEFAULT_CONFIG).forEach(([fieldName, value]) => {
                config[this.configKey(fieldName)] = value;
            });

            return config;
        },

        normalizeConfigForSave(config) {
            return Object.keys(DEFAULT_CONFIG).reduce((normalized, fieldName) => {
                normalized[this.configKey(fieldName)] = config[this.configKey(fieldName)];

                return normalized;
            }, {});
        },

        hasValue(value) {
            if (typeof value === 'string') {
                return value.trim() !== '';
            }

            return value !== null && value !== undefined;
        },

        async onCopyWebhookEndpointUrl() {
            try {
                await this.copyTextToClipboard(this.webhookEndpointUrl);

                this.createNotificationSuccess({
                    message: this.$tc('ebizcharge.settings.webhookEndpointCopied'),
                });
            } catch {
                this.createNotificationError({
                    message: this.$tc('ebizcharge.settings.webhookEndpointCopyError'),
                });
            }
        },

        async copyTextToClipboard(text) {
            if (navigator.clipboard?.writeText) {
                await navigator.clipboard.writeText(text);
                return;
            }

            const textArea = document.createElement('textarea');
            textArea.value = text;
            textArea.setAttribute('readonly', 'readonly');
            textArea.style.position = 'fixed';
            textArea.style.top = '-1000px';
            textArea.style.left = '-1000px';

            document.body.appendChild(textArea);
            textArea.select();
            document.execCommand('copy');
            document.body.removeChild(textArea);
        },

        configKey(fieldName) {
            return `${CONFIG_DOMAIN}.${fieldName}`;
        },

        normalizeStorefrontDomainUrl(url) {
            if (typeof url !== 'string' || url.trim() === '') {
                return null;
            }

            return url.trim().replace(/\/+$/, '');
        },
    },
});
