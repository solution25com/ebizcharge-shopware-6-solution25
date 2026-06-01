const ApiService = Shopware.Classes.ApiService;

class EbizChargeAdminService extends ApiService {
    constructor(httpClient, loginService, apiEndpoint = '_action/ebizcharge') {
        super(httpClient, loginService, apiEndpoint);
        this.name = 'ebizchargeAdminService';
    }

    testConnection(salesChannelId) {
        return this.httpClient
            .post(`${this.getApiBasePath()}/test-connection`, { salesChannelId }, {
                headers: this.getBasicHeaders(),
            })
            .then((response) => response.data);
    }
}

export default EbizChargeAdminService;
