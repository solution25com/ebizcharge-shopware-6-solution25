const Plugin = window.PluginBaseClass;

export default class EbizChargeSavedCardsPlugin extends Plugin {
    static options = {
        frameSelector: '.ebiz-add-card-frame',
        bankToggleSelector: '.ebiz-bank-methods',
        bankPanelSelector: '[data-ebiz-bank-methods-panel]',
        bankToggleLabelSelector: '[data-ebiz-bank-toggle-label]',
        reloadUrl: '',
        reloadDelay: 3000,
    };

    init() {
        this.frame = this.el.querySelector(this.options.frameSelector);
        this.bankToggle = this.el.querySelector(this.options.bankToggleSelector);
        this.bankPanel = this.el.querySelector(this.options.bankPanelSelector);
        this.bankToggleLabel = this.el.querySelector(this.options.bankToggleLabelSelector);

        if (!this.frame && (!this.bankToggle || !this.bankPanel)) {
            return;
        }

        this.hasLoadedHostedForm = false;
        this.reloadQueued = false;
        this._registerEvents();
    }

    _registerEvents() {
        if (this.frame) {
            this.frame.addEventListener('load', this._onFrameLoad.bind(this));
        }

        if (this.bankToggle && this.bankPanel) {
            this.bankToggle.addEventListener('toggle', this._onBankToggle.bind(this));
        }
    }

    _onBankToggle() {
        this.bankPanel.hidden = !this.bankToggle.open;

        if (this.bankToggleLabel) {
            this.bankToggleLabel.textContent = this.bankToggle.open
                ? this.bankToggleLabel.dataset.hideLabel
                : this.bankToggleLabel.dataset.showLabel;
        }
    }

    _onFrameLoad() {
        if (!this.hasLoadedHostedForm) {
            this.hasLoadedHostedForm = true;

            return;
        }

        if (this.reloadQueued) {
            return;
        }

        this.reloadQueued = true;

        window.setTimeout(() => {
            const url = new URL(this.options.reloadUrl || window.location.href, window.location.origin);
            window.location.href = url.toString();
        }, this.options.reloadDelay);
    }
}
