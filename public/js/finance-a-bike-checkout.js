"use strict";

(() => {
    const script = document.getElementById('c2EcomCheckoutScript');

    let fabID;

    function onCheckoutUpdated() {
        const container = document.getElementById(fabID);

        if (!container) {
            return;
        }

        switch (script.dataset.state) {
            default:
            case 'init':
                script.addEventListener('load', (event) => {
                    event.target.dataset.state = 'loaded';
                    onCheckoutUpdated();
                });

                script.addEventListener('error', (event) => {
                    event.target.dataset.state = 'failed';
                    onCheckoutUpdated();
                });

                script.dataset.state = 'loading';
                script.src = script.dataset.src;
                break;

            case 'loading':
                    script.addEventListener('load', (event) => {
                        onCheckoutUpdated();
                    });

                    script.addEventListener('error', (event) => {
                        onCheckoutUpdated();
                    });

                break;

            case 'loaded':
                if (typeof C2EcomCheckout !== 'undefined') {
                    const amount = parseFloat(container?.dataset?.total);

                    C2EcomCheckout.init();

                    if (isNaN(amount) === false) {
                        C2EcomCheckout.refreshAmount(amount);
                    }
                } else {
                    // not loaded correctly
                }
                break;

            case 'error':
                 // failed loading at all
                break;
        }
    }

    if (typeof fabConfig !== 'undefined') {
        fabID = fabConfig?.id || '';

        if (fabID) {
            const container = document.getElementById(fabID);

            if (container && script && script.dataset.state === 'init') {
                script.addEventListener('load', (event) => {
                    event.target.dataset.state = 'loaded';
                });

                script.addEventListener('error', (event) => {
                    event.target.dataset.state = 'failed';
                });

                script.dataset.state = 'loading';
                script.src = script.dataset.src;
            }
        }
    }

    if (typeof jQuery !== 'undefined' && fabID) {
        jQuery(document.body)
            .on('updated_checkout', onCheckoutUpdated);
    }
})();
