/*
    Ping
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

