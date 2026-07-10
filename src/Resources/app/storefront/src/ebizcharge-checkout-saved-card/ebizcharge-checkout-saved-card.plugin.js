const Plugin = window.PluginBaseClass;

export default class EbizChargeCheckoutSavedCardPlugin extends Plugin {
    static options = {
        selectSelector: '[data-ebiz-saved-method-select]',
        codeFieldSelector: '[data-ebiz-card-code-field]',
        codeInputSelector: '[data-ebiz-card-code-input]',
    };

    init() {
        this.select = this.el.querySelector(this.options.selectSelector);
        this.codeField = this.el.querySelector(this.options.codeFieldSelector);
        this.codeInput = this.el.querySelector(this.options.codeInputSelector);

        if (!this.select || !this.codeField || !this.codeInput) {
            return;
        }

        this._registerEvents();
        this._sync();
    }

    _registerEvents() {
        this.select.addEventListener('change', this._sync.bind(this));
    }

    _sync() {
        const option = this.select.selectedOptions[0];
        const requiresCardCode = this.select.value !== '' && option?.dataset.requiresCardCode === '1';

        this.codeField.hidden = !requiresCardCode;
        this.codeInput.disabled = !requiresCardCode;
        this.codeInput.required = requiresCardCode;

        if (!requiresCardCode) {
            this.codeInput.value = '';
        }
    }
}
