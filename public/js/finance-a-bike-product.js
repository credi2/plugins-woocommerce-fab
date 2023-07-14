((global) => {
    if (typeof global.fabUpdateProductAmount === 'undefined') {
        global.fabUpdateProductAmount = (idOrSKU, amount)  => {
            if ( typeof global?.C2EcomWizard?.refreshAmount !== 'function') {
                return;
            }

            const labels = document.querySelectorAll(`[data-fab-product-id="${idOrSKU}"], [data-fab-product-sku="${idOrSKU}"]`);

            labels.forEach(label => {
                const labelId = label?.id;

                if (labelId) {
                    global.C2EcomWizard.refreshAmount(labelId, amount);
                }
            });
        };
    }
})(window);
