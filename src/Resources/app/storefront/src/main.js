import EbizChargeSavedCardsPlugin from './ebizcharge-saved-cards/ebizcharge-saved-cards.plugin';
import EbizChargeCheckoutSavedCardPlugin from './ebizcharge-checkout-saved-card/ebizcharge-checkout-saved-card.plugin';

const PluginManager = window.PluginManager;

PluginManager.register(
    'EbizChargeSavedCardsPlugin',
    EbizChargeSavedCardsPlugin,
    '[data-ebiz-charge-saved-cards-plugin]'
);

PluginManager.register(
    'EbizChargeCheckoutSavedCardPlugin',
    EbizChargeCheckoutSavedCardPlugin,
    '[data-ebiz-saved-card-checkout]'
);
