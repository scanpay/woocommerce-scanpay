/*
    Settings JS
*/


// Show or Hide Subscriptions Settings
const subs_checkbox = document.getElementById('woocommerce_scanpay_subscriptions_enabled');
subs_checkbox.addEventListener('change', () => {
    if (subs_checkbox.checked) {
        document.querySelector('.scanpay--admin--table').classList.remove('scanpay--admin--no-subs');
    } else {
        document.querySelector('.scanpay--admin--table').classList.add('scanpay--admin--no-subs');
    }
})

