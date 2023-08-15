/*
    Pending ping
*/

(() => {
    const data = document.getElementById('scanpay--data');
    if (!data) return;
    let busy;
    let controller;
    const orderID = data.dataset.order;
    const rev = data.dataset.rev;

    function ping(fib) {
        if (busy) return;
        controller = new AbortController();
        busy = true;

        fetch('../wc-api/scanpay_order_rev/?order=' + orderID + '&rev=' + rev + '&fib=' + fib, {
            signal: controller.signal
        })
            .then((res) => res.json())
            .then((json) => {
                if (json.data.rev > rev) location.reload();
            })
            .catch(() => {
                // TODO: handle error and set a max fib on timeout
                // mby save it to localstorage and use it as a starting point
            })
            .finally(() => {
                busy = false;
            });
    }
    if (data.dataset.pending === 'true') ping(8);

    document.addEventListener("visibilitychange", () => {
        if (document.visibilityState == "visible") {
            ping(6);
        } else if (busy) {
            controller.abort();
        }
    });
})();
