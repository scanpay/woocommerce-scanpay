(() => {
    const { createElement } = window.wp.element;
    const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
    const data = window.wc.wcSettings.getSetting( 'scanpay_data' );
    const canMakePayment = () => true;
    if (!data) return;

    for (const name in data.methods) {
        const method = data.methods[name];
        const icons = method.icons.map((ico) => {
            return createElement(
                'img',
                {
                    src: `${data.url}${ico}.svg`,
                    width: '50',
                    className: 'wcsp-blocks-ico'
                }
            );
        });

        const content = createElement(
            'div',
            {
                className: `payment_method_${name}`
            },
            method.description,
            createElement(
                'div',
                {
                    className: 'wcsp-blocks-cards'
                },
                icons
            )
        );

        registerPaymentMethod({
            name, // also used for paymentMethodId
            ariaLabel: name,
            label: createElement(
                'span',
                {
                    className: 'wc-block-components-payment-method-label'
                },
                method.title
            ),
            content,
            edit: content,
            canMakePayment,
            supports: {
                features: method.supports,
            }
        });
    }
})();
