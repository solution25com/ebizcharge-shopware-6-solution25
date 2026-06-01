const Plugin = window.PluginBaseClass;

export default class EbizChargeSavedCardsPlugin extends Plugin {
    static options = {
        frameSelector: '.ebiz-add-card-frame',
        reloadUrl: '',
        reloadDelay: 3000,
    };

    init() {
        this.frame = this.el.querySelector(this.options.frameSelector);

        if (!this.frame) {
            return;
        }

        this.hasLoadedHostedForm = false;
        this.reloadQueued = false;
        this._registerEvents();
    }

    _registerEvents() {
        this.frame.addEventListener('load', this._onFrameLoad.bind(this));
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
