const Plugin = window.PluginBaseClass;

export default class EbizChargeSavedCardsPlugin extends Plugin {
    static options = {
        frameSelector: '.ebiz-add-card-frame',
        bankToggleSelector: '.ebiz-bank-methods',
        bankPanelSelector: '[data-ebiz-bank-methods-panel]',
        bankToggleLabelSelector: '[data-ebiz-bank-toggle-label]',
        defaultFormSelector: '[data-ebiz-saved-method-default-form]',
        deleteFormSelector: '[data-ebiz-saved-method-delete-form]',
        addLinkSelector: '[data-ebiz-saved-method-add-link]',
        cancelAddLinkSelector: '[data-ebiz-saved-method-cancel-add-link]',
        deleteConfirmModalSelector: '[data-ebiz-delete-confirm-modal]',
        deleteConfirmMessageSelector: '[data-ebiz-delete-confirm-message]',
        deleteConfirmSubmitSelector: '[data-ebiz-delete-confirm-submit]',
        loadingOverlayDelay: 250,
        reloadUrl: '',
        reloadDelay: 3000,
    };

    init() {
        this.frame = this.el.querySelector(this.options.frameSelector);
        this.bankToggle = this.el.querySelector(this.options.bankToggleSelector);
        this.bankPanel = this.el.querySelector(this.options.bankPanelSelector);
        this.bankToggleLabel = this.el.querySelector(this.options.bankToggleLabelSelector);
        this.defaultForms = Array.from(this.el.querySelectorAll(this.options.defaultFormSelector));
        this.deleteForms = Array.from(this.el.querySelectorAll(this.options.deleteFormSelector));
        this.addLinks = Array.from(this.el.querySelectorAll(this.options.addLinkSelector));
        this.cancelAddLinks = Array.from(this.el.querySelectorAll(this.options.cancelAddLinkSelector));
        this.deleteConfirmModal = this.el.querySelector(this.options.deleteConfirmModalSelector);
        this.deleteConfirmMessage = this.el.querySelector(this.options.deleteConfirmMessageSelector);
        this.deleteConfirmSubmit = this.el.querySelector(this.options.deleteConfirmSubmitSelector);

        if (!this.frame && (!this.bankToggle || !this.bankPanel) && !this.defaultForms.length && !this.deleteForms.length && !this.addLinks.length && !this.cancelAddLinks.length) {
            return;
        }

        this.hasLoadedHostedForm = false;
        this.reloadQueued = false;
        this.pendingDeleteForm = null;
        this.deleteConfirmModalInstance = null;

        if (this.deleteConfirmModal && window.bootstrap?.Modal) {
            this.deleteConfirmModalInstance = window.bootstrap.Modal.getOrCreateInstance(this.deleteConfirmModal);
        }

        this._registerEvents();
    }

    _registerEvents() {
        if (this.frame) {
            this.frame.addEventListener('load', this._onFrameLoad.bind(this));
        }

        if (this.bankToggle && this.bankPanel) {
            this.bankToggle.addEventListener('toggle', this._onBankToggle.bind(this));
        }

        this.defaultForms.forEach((form) => {
            form.addEventListener('submit', () => {
                this._scheduleLoadingOverlay(
                    form.dataset.ebizLoadingMessage || 'Updating your saved payment methods...'
                );
            });
        });

        this.deleteForms.forEach((form) => {
            form.addEventListener('submit', (event) => {
                event.preventDefault();
                this._showDeleteConfirmModal(form);
            });
        });

        this.addLinks.forEach((link) => {
            link.addEventListener('click', () => {
                this._scheduleLoadingOverlay(
                    link.dataset.ebizLoadingMessage || 'Loading add payment method form...'
                );
            });
        });

        this.cancelAddLinks.forEach((link) => {
            link.addEventListener('click', () => {
                this._scheduleLoadingOverlay(
                    link.dataset.ebizLoadingMessage || 'Loading saved payment methods...'
                );
            });
        });

        if (this.deleteConfirmSubmit) {
            this.deleteConfirmSubmit.addEventListener('click', () => this._submitConfirmedDelete());
        }

        if (this.deleteConfirmModal) {
            this.deleteConfirmModal.addEventListener('hidden.bs.modal', () => {
                this.pendingDeleteForm = null;
            });
        }
    }

    _showDeleteConfirmModal(form) {
        if (!this.deleteConfirmModalInstance || !form) {
            return;
        }

        this.pendingDeleteForm = form;

        if (this.deleteConfirmMessage) {
            this.deleteConfirmMessage.textContent = form.dataset.ebizConfirmMessage || '';
        }

        this.deleteConfirmModalInstance.show();
    }

    _closeDeleteConfirmModal() {
        this.deleteConfirmModalInstance?.hide();
        this.pendingDeleteForm = null;
    }

    _submitConfirmedDelete() {
        const form = this.pendingDeleteForm;

        if (!form) {
            this._closeDeleteConfirmModal();

            return;
        }

        const loadingMessage = form.dataset.ebizLoadingMessage || 'Removing saved payment method...';

        this.pendingDeleteForm = null;
        this.deleteConfirmModalInstance?.hide();
        this._showLoadingOverlay(loadingMessage);
        form.submit();
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

    _scheduleLoadingOverlay(message) {
        if (this.loadingOverlay || this.loadingOverlayTimer) {
            return;
        }

        this.loadingOverlayTimer = window.setTimeout(() => {
            this.loadingOverlayTimer = null;
            this._showLoadingOverlay(message);
        }, this.options.loadingOverlayDelay);
    }

    _showLoadingOverlay(message) {
        if (this.loadingOverlay) {
            return;
        }

        this.loadingOverlay = document.createElement('div');
        this.loadingOverlay.className = 'ebiz-saved-methods-loading-overlay';
        this.loadingOverlay.setAttribute('role', 'status');
        this.loadingOverlay.setAttribute('aria-live', 'polite');

        const box = document.createElement('div');
        box.className = 'ebiz-saved-methods-loading-overlay__box';

        const spinner = document.createElement('div');
        spinner.className = 'ebiz-saved-methods-loading-overlay__spinner';
        spinner.setAttribute('aria-hidden', 'true');

        const text = document.createElement('div');
        text.className = 'ebiz-saved-methods-loading-overlay__text';
        text.textContent = message;

        box.appendChild(spinner);
        box.appendChild(text);
        this.loadingOverlay.appendChild(box);
        document.body.appendChild(this.loadingOverlay);
    }
}
