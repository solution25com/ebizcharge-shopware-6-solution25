import template from './ebizcharge-api-test.html.twig';

const { Component, Mixin } = Shopware;

Component.register('ebizcharge-api-test', {
    template,
    inject: ['ebizchargeAdminService'],
    mixins: [Mixin.getByName('notification')],

    data() {
        return {
            isLoading: false,
            isSaveSuccessful: false,
        };
    },

    methods: {
        saveFinish() {
            this.isSaveSuccessful = false;
        },

        getCurrentSalesChannelId() {
            let parent = this.$parent;

            while (parent && parent.currentSalesChannelId === undefined) {
                parent = parent.$parent;
            }

            return parent?.currentSalesChannelId ?? null;
        },

        async check() {
            this.isLoading = true;

            try {
                const result = await this.ebizchargeAdminService.testConnection(this.getCurrentSalesChannelId());

                if (result.success) {
                    this.isSaveSuccessful = true;
                    this.createNotificationSuccess({
                        title: 'eBizCharge connection test',
                        message: `${result.message} Fingerprint ${result.credentialFingerprint}.`,
                    });

                    return;
                }

                this.createNotificationError({
                    title: 'eBizCharge connection test',
                    message: result.message,
                });
            } catch (error) {
                this.createNotificationError({
                    title: 'eBizCharge connection test',
                    message: error.message || 'Connection test failed.',
                });
            } finally {
                this.isLoading = false;
            }
        },
    },
});
