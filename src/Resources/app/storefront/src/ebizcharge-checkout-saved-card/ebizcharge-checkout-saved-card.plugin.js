const Plugin = window.PluginBaseClass;

export default class EbizChargeCheckoutSavedCardPlugin extends Plugin {
    static options = {
        selectSelector: '[data-ebiz-saved-method-select]',
        customSelectSelector: '[data-ebiz-payment-select]',
        hiddenInputSelector: '[data-ebiz-payment-select-input]',
        toggleSelector: '[data-ebiz-payment-select-toggle]',
        labelSelector: '[data-ebiz-payment-select-label]',
        optionSelector: '[data-ebiz-payment-option]',
        codeFieldSelector: '[data-ebiz-card-code-field]',
        codeInputSelector: '[data-ebiz-card-code-input]',
    };

    init() {
        this.select = this.el.querySelector(this.options.selectSelector);
        this.customSelect = this.el.querySelector(this.options.customSelectSelector);
        this.hiddenInput = this.el.querySelector(this.options.hiddenInputSelector);
        this.toggle = this.el.querySelector(this.options.toggleSelector);
        this.label = this.el.querySelector(this.options.labelSelector);
        this.optionsList = Array.from(this.el.querySelectorAll(this.options.optionSelector));
        this.codeField = this.el.querySelector(this.options.codeFieldSelector);
        this.codeInput = this.el.querySelector(this.options.codeInputSelector);

        if (!this.select || !this.customSelect || !this.hiddenInput || !this.toggle || !this.label ||
            !this.optionsList.length || !this.codeField || !this.codeInput) {
            return;
        }

        this.hiddenInput.name = this.select.name;
        this.hiddenInput.value = this.select.value;
        this.hiddenInput.disabled = false;
        this.select.disabled = true;
        this.el.querySelector(`label[for="${this.select.id}"]`)?.setAttribute('for', this.toggle.id);
        this.el.classList.add('has-ebiz-payment-select');

        this._registerEvents();
        this._selectOption(this._findOption(this.hiddenInput.value), false);
    }

    _registerEvents() {
        this._boundOnToggle = this._onToggle.bind(this);
        this._boundOnDocumentClick = this._onDocumentClick.bind(this);
        this._boundOnKeydown = this._onKeydown.bind(this);

        this.toggle.addEventListener('click', this._boundOnToggle);
        this.optionsList.forEach((option) => {
            option.addEventListener('click', () => this._selectOption(option));
        });
        document.addEventListener('click', this._boundOnDocumentClick);
        document.addEventListener('keydown', this._boundOnKeydown);
    }

    destroy() {
        document.removeEventListener('click', this._boundOnDocumentClick);
        document.removeEventListener('keydown', this._boundOnKeydown);
        super.destroy();
    }

    _onToggle() {
        this._setOpen(!this.customSelect.classList.contains('is-open'));
    }

    _onDocumentClick(event) {
        if (!this.customSelect.contains(event.target)) {
            this._setOpen(false);
        }
    }

    _onKeydown(event) {
        if (event.key === 'Escape' && this.customSelect.classList.contains('is-open')) {
            this._setOpen(false);
            this.toggle.focus();
        }
    }

    _setOpen(isOpen) {
        this.customSelect.classList.toggle('is-open', isOpen);
        this.toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    }

    _findOption(methodId) {
        return this.optionsList.find((option) => option.dataset.methodId === methodId) || this.optionsList[0];
    }

    _selectOption(option, dispatchChange = true) {
        this.hiddenInput.value = option.dataset.methodId || '';
        this.select.value = this.hiddenInput.value;
        this._updateSelectedDisplay(option);

        this.optionsList.forEach((item) => {
            const isSelected = item === option;
            item.classList.toggle('is-selected', isSelected);
            item.setAttribute('aria-selected', isSelected ? 'true' : 'false');
        });

        this._syncCardCode(option);
        this._setOpen(false);

        if (dispatchChange) {
            this.hiddenInput.dispatchEvent(new Event('change', { bubbles: true }));
        }
    }

    _updateSelectedDisplay(option) {
        const label = option.dataset.label || option.textContent.trim();
        const icon = option.querySelector('.ebiz-payment-select__icon');
        const content = option.querySelector('.ebiz-payment-select__option-content, .ebiz-payment-select__option-main');

        this.label.textContent = '';

        if (icon) {
            this.label.appendChild(icon.cloneNode(true));
        }

        if (content) {
            this.label.appendChild(content.cloneNode(true));
        } else {
            this.label.textContent = label;
        }

        this.toggle.setAttribute('aria-label', label);
        this.toggle.setAttribute('title', label);
    }

    _syncCardCode(option) {
        const requiresCardCode = this.hiddenInput.value !== '' && option.dataset.requiresCardCode === '1';

        this.codeField.hidden = !requiresCardCode;
        this.codeInput.disabled = !requiresCardCode;
        this.codeInput.required = requiresCardCode;

        if (!requiresCardCode) {
            this.codeInput.value = '';
        }
    }
}
