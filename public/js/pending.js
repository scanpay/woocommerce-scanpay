/*
    Pending ping
*/

const pendingSync = document.getElementById('scanpay--widget');
if (pendingSync) {
    const orderID = pendingSync.dataset.order;
    const rev = pendingSync.dataset.rev;

    fetch('../wc-api/scanpay_order_rev/?order=' + orderID + '&rev=' + rev)
        .then((res) => res.json())
        .then((json) => {
            if (json.data.rev > rev) {
                location.reload();
            }
        });
}

/*
    Check rev when window/tab gains focus.
*/

document.addEventListener("visibilitychange", (evt) => {
    console.log(evt);
    if (document.visibilityState == "visible") {
      console.log("tab is active")
    } else {
      console.log("tab is inactive")
    }
});
