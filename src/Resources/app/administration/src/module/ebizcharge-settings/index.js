import './page/ebizcharge-settings-index';

const { Module } = Shopware;

Module.register('ebizcharge-settings', {
    type: 'plugin',
    name: 'ebizcharge-settings',
    title: 'ebizcharge.settings.title',
    description: 'ebizcharge.settings.description',
    color: '#1f4f9f',
    icon: 'regular-credit-card',

    routes: {
        index: {
            component: 'ebizcharge-settings-index',
            path: 'index',
            meta: {
                parentPath: 'sw.settings.index.plugins',
                privilege: 'system.system_config',
            },
        },
    },

    settingsItem: {
        group: 'plugins',
        to: 'ebizcharge.settings.index',
        icon: 'regular-credit-card',
        privilege: 'system.system_config',
    },
});
