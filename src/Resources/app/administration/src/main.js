import './component/ebizcharge-api-test';
import './module/sw-order/component/sw-order-state-select-v2';
import './module/sw-order/view/sw-order-detail-general';
import EbizChargeAdminService from './service/ebizcharge-admin.service';

Shopware.Service().register('ebizchargeAdminService', () => {
    return new EbizChargeAdminService(
        Shopware.Application.getContainer('init').httpClient,
        Shopware.Service('loginService')
    );
});
