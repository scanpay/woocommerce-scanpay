/*
    Settings JS
*/

(() => {
    let lastPing = parseInt(localStorage.getItem('scanpay-last-ping'), 10);

    // Check last ping and warn if >5 mins old
    function checkPing() {
        const pingThreshold = (Date.now() / 1000 - 300);
        if (lastPing < pingThreshold) {
            fetch('../wc-api/scanpay_last_ping/').then(r => r.json()).then((r) => {
                const parent = document.querySelector('#scanpay--admin--alert--parent');
                lastPing = r.data.last;
                localStorage.setItem('scanpay-last-ping', lastPing);
                console.log(lastPing);
                if (!lastPing) {
                    parent.className = 'scanpay--admin--alert--no-pings';
                } else if (lastPing < pingThreshold) {
                    const mins = Math.round((Date.now() / 1000 - lastPing) / 60);
                    document.querySelector('#scanpay-ping').textContent = mins;
                    parent.className = 'scanpay--admin--alert--last-ping';
                } else {
                    parent.className = '';
                }
            }).catch(e => console.log(e));
        }
    }
    checkPing();

    // Show or Hide Subscriptions Settings
    const subs_checkbox = document.getElementById('woocommerce_scanpay_subscriptions_enabled');
    subs_checkbox.addEventListener('change', () => {
        if (subs_checkbox.checked) {
            document.querySelector('.scanpay--admin--table').classList.remove('scanpay--admin--no-subs');
        } else {
            document.querySelector('.scanpay--admin--table').classList.add('scanpay--admin--no-subs');
        }
    })

    // checkPing when the tab is visible again
    document.addEventListener("visibilitychange", () => {
        if (document.visibilityState == "visible") checkPing();
    });
})();
