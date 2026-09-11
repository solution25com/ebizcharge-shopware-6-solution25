const Plugin = window.PluginBaseClass;

export default class EbizChargeEmbeddedCheckoutPlugin extends Plugin {
    static options = {
        formUrlEndpoint: '',
        returnPath: '',
        frameSelector: '[data-ebiz-embedded-frame]',
        frameWrapSelector: '[data-ebiz-embedded-frame-wrap]',
        statusSelector: '[data-ebiz-embedded-status]',
        lookupKeySelector: '[data-ebiz-embedded-lookup-key]',
        savedMethodSelectSelector: '[data-ebiz-payment-select-input]',
        savedMethodNativeSelectSelector: '[data-ebiz-saved-method-select]',
        confirmFormId: 'confirmOrderForm',
    };

    init() {
        this.frame = this.el.querySelector(this.options.frameSelector);
        this.frameWrap = this.el.querySelector(this.options.frameWrapSelector);
        this.statusEl = this.el.querySelector(this.options.statusSelector);
        this.lookupKeyInput = this.el.querySelector(this.options.lookupKeySelector);
        this.confirmForm = document.forms[this.options.confirmFormId] || document.getElementById(this.options.confirmFormId);
        this.savedMethodSelect = document.querySelector(this.options.savedMethodSelectSelector)
            || document.querySelector(this.options.savedMethodNativeSelectSelector);

        if (!this.frame || !this.frameWrap || !this.confirmForm || !this.options.formUrlEndpoint) {
            return;
        }

        this.paymentApproved = false;
        this.frame.addEventListener('load', this._onFrameLoad.bind(this));

        if (this.savedMethodSelect) {
            this.savedMethodSelect.addEventListener('change', this._onSavedMethodChange.bind(this));
        }

        this._sync();
    }

    _sync() {
        const usingHostedForm = !this.savedMethodSelect || this.savedMethodSelect.value === '';

        if (usingHostedForm) {
            this._enableEmbedded();
            return;
        }

        this._disableEmbedded();
    }

    _onSavedMethodChange() {
        this._sync();
    }

    _enableEmbedded() {
        this._togglePlaceOrderButton(false);

        if (!this.frame.src) {
            this._loadFormUrl();
            return;
        }

        this.frameWrap.hidden = false;
    }

    _disableEmbedded() {
        this.frameWrap.hidden = true;
        this._togglePlaceOrderButton(true);
        this._setStatus('');
    }

    _loadFormUrl() {
        this._setStatus(this._text('loading'));

        fetch(this.options.formUrlEndpoint, {
            method: 'GET',
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        })
            .then((response) => response.json().then((data) => ({ ok: response.ok, data })))
            .then(({ ok, data }) => {
                if (!ok || !data || !data.url) {
                    this._setStatus(this._text('error'));
                    this._togglePlaceOrderButton(true);
                    return;
                }

                if (this.lookupKeyInput && data.lookupKey) {
                    this.lookupKeyInput.value = data.lookupKey;
                }

                this.frame.src = data.url;
                this.frameWrap.hidden = false;
                this._setStatus('');
            })
            .catch(() => {
                this._setStatus(this._text('error'));
                this._togglePlaceOrderButton(true);
            });
    }

    _onFrameLoad() {
        const returnInfo = this._readReturnOutcome();
        if (returnInfo === null) {
            return;
        }

        if (returnInfo.outcome === 'approved') {
            this._onApproved();
            return;
        }

        this._onDeclinedOrError(returnInfo.outcome === 'declined' ? this._text('declined') : this._text('error'));
    }

    _readReturnOutcome() {
        try {
            const frameLocation = this.frame.contentWindow.location;
            const href = frameLocation.href;

            if (!href || href.indexOf(this.options.returnPath) === -1) {
                return null;
            }

            const params = new URLSearchParams(frameLocation.search);
            return { outcome: (params.get('ebizchargeResult') || 'unknown').toLowerCase() };
        } catch {
            return null;
        }
    }

    _onApproved() {
        if (this.paymentApproved) {
            return;
        }

        this.paymentApproved = true;
        this.frameWrap.hidden = true;
        this._setStatus(this._text('approved'));
        this._completeOrder();
    }

    _onDeclinedOrError(message) {
        this._setStatus(message);
        this.frame.src = '';
        this.paymentApproved = false;
        window.setTimeout(() => this._loadFormUrl(), 1500);
    }

    _completeOrder() {
        if (typeof this.confirmForm.checkValidity === 'function' && !this.confirmForm.checkValidity()) {
            this._setStatus(this._text('completeManually'));
            this._togglePlaceOrderButton(true);
            if (typeof this.confirmForm.reportValidity === 'function') {
                this.confirmForm.reportValidity();
            }

            return;
        }

        if (typeof this.confirmForm.requestSubmit === 'function') {
            this.confirmForm.requestSubmit();
            return;
        }

        this.confirmForm.submit();
    }

    _togglePlaceOrderButton(visible) {
        const buttons = document.querySelectorAll(
            `#${this.options.confirmFormId} button[type="submit"], button[form="${this.options.confirmFormId}"][type="submit"]`
        );

        buttons.forEach((button) => {
            button.hidden = !visible;
            button.disabled = !visible;
        });
    }

    _setStatus(message) {
        if (!this.statusEl) {
            return;
        }

        this.statusEl.hidden = message === '';
        this.statusEl.textContent = message;
    }

    _text(key) {
        const messages = {
            loading: 'Loading secure payment form...',
            approved: 'Payment received. Completing your order...',
            declined: 'The payment was declined. Please try another card.',
            error: 'The payment form could not be loaded. Please try again.',
            completeManually: 'Payment received. Please accept the terms and place the order to finish.',
        };
        const attr = this.statusEl ? this.statusEl.getAttribute(`data-text-${key}`) : null;

        return attr || messages[key] || '';
    }
}
