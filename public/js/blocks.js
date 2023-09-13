
import { registerPaymentMethod } from '@woocommerce/blocks-registry';
import { getSetting } from '@woocommerce/settings';
const settings = getSetting('paymentMethodData');
if (!settings.scanpay) throw 'Scanpay settings not found';

const array = ['dankort', 'visa', 'mastercard'];

registerPaymentMethod({
    name: "scanpay",
    label: (<>
        <span className="scanpay--blocks--title">Betalingskort</span>
        <span className="scanpay--blocks--cards">
            {array.map((str) =>
                <img
                    width=""
                    className="scanpay--blocks--ico"
                    src={ settings.scanpay.url + str + '.svg' }/>
            )}
        </span>
    </>),
    content: <>{ settings.scanpay.description }</>,
    edit: <>{ settings.scanpay.description }</>,
    canMakePayment: () => true,
    ariaLabel: settings.scanpay.title,
    supports: {
        features: ['products', 'pre-orders'],
    },
});

registerPaymentMethod({
    name: "scanpay_mobilepay",
    label: (<>
        <span className="scanpay--blocks--title">MobilePay</span>
        <img width="94" height="24" src={ settings.scanpay.url + 'mobilepay.svg' }/>
    </>),
    content: <>Betal med MobilePay</>,
    edit: <>Betal med MobilePay</>,
    canMakePayment: () => true,
    ariaLabel: 'MobilePay',
    supports: {
        features: ['products', 'pre-orders'],
    },
});

registerPaymentMethod({
    name: "scanpay_applepay",
    label: (<>
        <span className="scanpay--blocks--title">Apple Pay</span>
        <img width="50" height="22" src={ settings.scanpay.url + 'applepay.svg' }/>
    </>),
    content: <>Betal med Apple Pay</>,
    edit: <>Betal med Apple Pay</>,
    canMakePayment: () => true,
    ariaLabel: 'Apple Pay',
    supports: {
        features: ['products', 'pre-orders'],
    },
});
