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

    // Show an alert box in the meta box
    function showMetaAlertBox(msg) {
        const div = document.createElement('div');
        div.className = 'scanpay--alert scanpay--alert-error';
        div.textContent = msg;
        document.querySelector('#scanpay-info > .inside').prepend(div);
    }

    // Check last ping and warn if >10 mins old
    const pingThreshold = (Date.now() / 1000 - 600);
    let lastPing = localStorage.getItem('scanpay-last-ping') | 0;

    if (lastPing < pingThreshold) {
        fetch('../wc-api/scanpay_last_ping/').then(r => r.json()).then((r) => {
            lastPing = r.data.mtime;
            localStorage.setItem('scanpay-last-ping', lastPing);
            if (!lastPing) {
                showMetaAlertBox('The system is not synchronized. Please check your plugin settings or contact support.');
            } else if (lastPing < pingThreshold) {
                const mins = Math.round((Date.now() / 1000 - lastPing) / 60);
                const t = (mins < 120) ? mins + ' minutes' : Math.round(mins / 60) + ' hours';
                showMetaAlertBox(t + ' since last synchronization. Please check your plugin settings or contact support.');
            }
        }).catch(e => console.log(e));
    }

    function lookupRev() {
        if (busy) return;
        controller = new AbortController();
        busy = true;
        fetch('../wc-api/scanpay_get_rev/?order=' + orderID + '&rev=' + rev, { signal: controller.signal })
            .then(r => r.json())
            .then((json) => {
                if (json.data.rev > rev) location.reload();
            })
            .catch(e => console.log(e))
            .finally(() => {
                busy = false;
            });
    }
    if (data.dataset.pending === 'true') lookupRev();

    document.addEventListener("visibilitychange", () => {
        // lookupRev when the tab is visible again (e.g. after refund in dashboard)
        // Abort if user minimizes the window or switches to another tab.
        if (document.visibilityState == "visible") {
            lookupRev();
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
