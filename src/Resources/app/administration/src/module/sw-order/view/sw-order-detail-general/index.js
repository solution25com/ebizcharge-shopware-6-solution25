import template from './sw-order-detail-general.html.twig';

const { Component, Mixin } = Shopware;

Component.override('sw-order-detail-general', {
    template,

    mixins: [Mixin.getByName('notification')],

    data() {
        return {
            isEbizChargePayment: false,
            isPayByLink: false,
            ebizTransactionId: '',
            ebizProviderTransactionId: '',
            isSendingLink: false,
        };
    },

    watch: {
        order: {
            immediate: true,
            handler(order) {
                if (!order) return;
                this.detectPayByLink(order);
            },
        },
    },

    methods: {
        detectPayByLink(order) {
            const transaction = order?.transactions?.last();
            if (!transaction) {
                return;
            }

            const handler = transaction.paymentMethod?.handlerIdentifier ?? '';
            this.isEbizChargePayment = handler.includes('EbizChargeShopware\\Checkout\\Payment\\Handler\\');
            this.isPayByLink = handler.includes('PayByLinkPaymentHandler');
            this.ebizTransactionId = transaction.id ?? '';

            if (this.isEbizChargePayment) {
                this.loadEbizTransactionDetails();
            } else {
                this.ebizProviderTransactionId = '';
            }
        },

        async loadEbizTransactionDetails() {
            if (!this.ebizTransactionId) {
                return;
            }

            try {
                const response = await Shopware.Service('repositoryFactory').httpClient.post(
                    '/_action/ebizcharge/payment-transaction',
                    { orderTransactionId: this.ebizTransactionId },
                    {
                        headers: {
                            'Content-Type': 'application/json',
                            Authorization: `Bearer ${Shopware.Context.api.authToken.access}`,
                        },
                    }
                );

                this.ebizProviderTransactionId = response.data?.providerRefNum ?? '';
            } catch {
                this.ebizProviderTransactionId = '';
            }
        },

        async onSendPaymentLink() {
            if (!this.ebizTransactionId) return;

            this.isSendingLink = true;

            try {
                await Shopware.Service('repositoryFactory').httpClient.post(
                    '/_action/ebizcharge/payment-link/re-send',
                    { orderTransactionId: this.ebizTransactionId },
                    {
                        headers: {
                            'Content-Type': 'application/json',
                            Authorization: `Bearer ${Shopware.Context.api.authToken.access}`,
                        },
                    }
                );

                this.createNotificationSuccess({
                    message: this.$tc('ebizcharge.payByLink.successMessage'),
                });
            } catch {
                this.createNotificationError({
                    message: this.$tc('ebizcharge.payByLink.errorMessage'),
                });
            } finally {
                this.isSendingLink = false;
            }
        },
    },
});
