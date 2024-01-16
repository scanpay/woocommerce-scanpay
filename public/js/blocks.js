(() => {
    const { createElement, Fragment } = window.wp.element;
    const data = window.wc.wcSettings.getSetting('scanpay_data');
    const canMakePayment = () => true;
    if (!data) return;

    for (const name in data.methods) {
        const method = data.methods[name];
        const content = createElement(Fragment, null, method.description);
        const label = createElement('span', { className: 'wc-block-components-payment-method-label wcsp-label' },
            createElement('span', { className: 'wcsp-title' }, method.title),
            createElement('span', { className: 'wcsp-icons wcsp-icons-' + name }, method.icons.map(
                icon => createElement('img', {
                    src: data.url + icon + '.svg',
                    className: 'wcsp-icon wcsp-icon-' + icon,
                }))
            )
        );

        window.wc.wcBlocksRegistry.registerPaymentMethod({
            name,
            ariaLabel: name,
            label,
            content,
            edit: content,
            canMakePayment,
            supports: {
                features: method.supports,
            }
        });
    }
})();
