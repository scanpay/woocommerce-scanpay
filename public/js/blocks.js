(() => {
    const element = window.wp.element;
    const data = window.wc.wcSettings.getSetting( 'scanpay_data' );
    if (!data) return;

    for (const name in data.methods) {
        const method = data.methods[name];

        const label = () => Object(element.createElement)(
            'span',
            {
                className: 'wc-block-components-payment-method-label'
            },
            method.title
        );

        const icons = [];
        for (const ico in method.icons) {
            icons.push(element.createElement(
                'img',
                {
                    'src': data.url + method.icons[ico] + '.svg',
                    'width': '50',
                    'className': 'wcsp-blocks-ico'
                }
            ));
        }

        const content = () => Object(element.createElement)(
            'div',
            {
                'className': 'payment_method_' + method.id,
                'billing': null
            },
            method.description,
            element.createElement(
                'div',
                {
                    'className': 'wcsp-blocks-cards'
                },
                icons
            )
        );

        window.wc.wcBlocksRegistry.registerPaymentMethod({
            name: name,
            label: Object(element.createElement)(label, null),
            content: Object(element.createElement)(content, null),
            edit: element.createElement('div', {}, method.description),
            canMakePayment: () => true,
            ariaLabel: name,
            supports: {
                features: method.supports,
            }
        });
    }
})();


/*
registerPaymentMethod({
    name: "scanpay",
    label: (<>
        <span className="wcsp-blocks-title">Betalingskort</span>
        <span className="wcsp-blocks-cards">
            {array.map((str) =>
                <img
                    width=""
                    className="wcsp-blocks-ico"
                    src={ settings.scanpay.url + str + '.svg' }/>
            )}
        </span>
    </>),
    content: <>{ settings.scanpay.description }</>,
    edit: <>{ settings.scanpay.description }</>,
    canMakePayment: () => true,
    ariaLabel: settings.scanpay.title,
    supports: {
        features: ['products', 'subscriptions'],
    },
});

registerPaymentMethod({
    name: "scanpay_mobilepay",
    label: (<>
        <span className="wcsp-blocks-title">MobilePay</span>
        <img width="94" height="24" src={ settings.scanpay.url + 'mobilepay.svg' }/>
    </>),
    content: <>Betal med MobilePay</>,
    edit: <>Betal med MobilePay</>,
    canMakePayment: () => true,
    ariaLabel: 'MobilePay',
    supports: {
        features: ['products'],
    },
});

registerPaymentMethod({
    name: "scanpay_applepay",
    label: (<>
        <span className="wcsp-blocks-title">Apple Pay</span>
        <img width="50" height="22" src={ settings.scanpay.url + 'applepay.svg' }/>
    </>),
    content: <>Betal med Apple Pay</>,
    edit: <>Betal med Apple Pay</>,
    canMakePayment: () => true,
    ariaLabel: 'Apple Pay',
    supports: {
        features: ['products'],
    },
});
*/
