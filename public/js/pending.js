/*
    JavaScript for the meta box on the order page.
*/

(() => {
    const data = document.getElementById('scanpay--data');
    if (!data) return;
    const orderID = data.dataset.order;
    const rev = data.dataset.rev;
    let busy;
    let controller;

    // LocalStorage is used to avoid unnecessary requests to the server.
    let lastPing = localStorage.getItem('scanpay-last-ping') || 0;
    let fib = localStorage.getItem('scanpay-fib') || 12;

    // TODO: contionously check lastPing
    const pingThreshold = (Date.now() / 1000 - 300);
    if (lastPing < pingThreshold) {
        fetch('../wc-api/scanpay_last_ping/')
            .then((res) => {
                if (res.status !== 200) throw 'failed';
                return res.json();
            })
            .then((json) => {
                lastPing = json.data.last;
                localStorage.setItem('scanpay-last-ping', lastPing);
            })
            .catch(err => console.log(err))
            .finally(() => {
                if (lastPing < pingThreshold) {
                    console.error('Scanpay: last ping is too old. Please check your server time.');
                }
            });
    }

    function lookup_rev() {
        if (busy) return;
        controller = new AbortController();
        busy = true;
        fetch('../wc-api/scanpay_get_rev/?order=' + orderID + '&rev=' + rev + '&fib=' + fib, {
            signal: controller.signal
        })
            .then((res) => {
                if (res.status === 200) return res.json();
                if (res.status === 504) {
                    localStorage.setItem('scanpay-fib', --fib);
                    console.log(fib);
                }
                throw 'failed';
            })
            .then((json) => {
                if (json.data.rev > rev) location.reload();
            })
            .catch(err => console.log(err))
            .finally(() => {
                busy = false;
            });
    }
    if (data.dataset.pending === 'true') lookup_rev();

    document.addEventListener("visibilitychange", () => {
        // lookup_rev when the tab is visible again (e.g. after refund in dashboard)
        // Abort if user minimizes the window or switches to another tab.
        if (document.visibilityState == "visible") {
            lookup_rev();
        } else if (busy) {
            controller.abort();
        }
    });

    /*
    document.addEventListener("mouseleave", (evt) => {
        // console.log(evt);
    });
    */
})();
