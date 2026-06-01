import EbizChargeSavedCardsPlugin from './ebizcharge-saved-cards/ebizcharge-saved-cards.plugin';

const PluginManager = window.PluginManager;

PluginManager.register(
    'EbizChargeSavedCardsPlugin',
    EbizChargeSavedCardsPlugin,
    '[data-ebiz-charge-saved-cards-plugin]'
);
