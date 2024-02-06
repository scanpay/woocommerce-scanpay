/*
    JavaScript for the meta box on the order page.
*/

(() => {
    const urlParams = new URLSearchParams(window.location.search);
    const orderid = urlParams.get('id');
    const page = urlParams.get('page');
    if (!orderid || (page !== 'wc-orders' && page !== 'wc-orders--shop_subscription')) return;
    let rev = 0;
    let currency;
    let box;

    /*
        get(): fetch wrapper with caching (v1.0)
    */
    function get(url, caching = 0) {
        const reqCache = (caching) ? JSON.parse(localStorage.getItem('scanpay_cache')) || {} : {};
        const now = Math.floor(Date.now() / 1000);
        const opts = (url.startsWith('http')) ? {} : { headers: { 'X-Scanpay': 'fetch' } };

        if (caching && reqCache[url] && now < reqCache[url].next) {
            return new Promise((resolve, reject) => {
                if (reqCache[url].err) return reject(reqCache[url].err);
                resolve(reqCache[url].o)
            });
        }
        return fetch(url, opts )
            .then((res) => {
                if (res.status !== 200) {
                    if (caching) {
                        reqCache[url] = { err: res.statusText, next: now + caching };
                        localStorage.setItem('scanpay_cache', JSON.stringify(reqCache));
                    }
                    throw new Error(res.statusText);
                }
                return res.json();
            })
            .then((o) => {
                if (caching) {
                    reqCache[url] = { o: o, next: now + caching };
                    if (o.error) reqCache[url] = { err: o.error, next: now + caching };
                    localStorage.setItem('scanpay_cache', JSON.stringify(reqCache));
                }
                if (o.error) throw new Error(o.error);
                return o;
            });
    }

    function showWarning(msg, type = 'error') {
        const div = document.createElement('div');
        div.className = 'wcsp-meta-alert wcsp-meta-alert-' + type;
        div.innerHTML = msg;
        box.prepend(div);
    }

    /*
        Check mtime and plugin version
    */
    function compatibilityCheck() {
        const secret = document.getElementById('wcsp-meta').dataset.secret;
        get('../wp-scanpay/fetch?x=ping&s=' + secret, 120)
            .then(({ mtime }) => {
                const dmins = Math.floor((Math.floor(Date.now() / 1000) - mtime) / 60);
                if (mtime === 0 || dmins < 10) return;
                let ts = dmins + ' minutes';
                if (dmins > 120) ts = Math.floor(dmins / 60) + ' hours'
                showWarning('Your scanpay extension is out of sync: ' + ts + ' since last synchronization.');
            });

        get('https://api.github.com/repos/scanpay/woocommerce-scanpay/releases/latest', 600)
            .then(({ tag_name }) => {
                if (tag_name !== 'v' + wcSettings.admin.scanpay) {
                    showWarning(
                        `Your scanpay plugin is <b class="scanpay-outdated">outdated</b>.
                        Please update to ${tag_name} (<a href="//github.com/scanpay/woocommerce-scanpay/releases"
                        target="_blank">changelog</a>)`
                    );
                }
            });
    }

    function buildDataArray(o) {
        const data = [
            ['Authorized', currency.format(o.authorized)],
            ['Captured', currency.format(o.captured)]
        ];
        if (o.voided > 0) {
            data.push(
                ['Voided', currency.format(o.voided)],
                ['Net payment', currency.format(o.captured)]
            );
        } else if (o.refunded > 0) {
            data.push(
                ['Refunded', currency.format(o.refunded)],
                ['Net payment', currency.format(o.captured - o.refunded)]
            );
        } else {
            data.push(['Net payment', currency.format(o.captured)]);
        }
        return data;
    }

    function buildTable(arr) {
        const ul = document.createElement('ul');
        ul.id = 'wcsp-meta-ul';
        ul.className = 'wcsp-meta-ul';
        for (const x of arr) {
            const li = document.createElement('li');
            li.className = 'wcsp-meta-li';

            const title = document.createElement('div');
            title.className = 'wcsp-meta-li-title';
            title.textContent = x[0] + ':';
            li.appendChild(title);

            const value = document.createElement('div');
            value.className = 'wcsp-meta-li-value';
            value.textContent = x[1];
            li.appendChild(value);
            ul.appendChild(li);
        }
        return ul;
    }

    let abortCtrl;
    function loadOrderMeta() {
        const target = document.getElementById('wcsp-meta');
        const secret = target.dataset.secret;
        abortCtrl = new AbortController();
        const url = '../wp-scanpay/fetch?x=meta&s=' + secret + ' &oid=' + orderid + '&rev=' + rev;
        fetch(url, { signal: abortCtrl.signal, headers: { 'X-Scanpay': 'fetch' } })
            .then(res => res.json())
            .then((meta) => {
                box = target.cloneNode(false);
                const dataset = document.getElementById('wcsp-meta').dataset;

                if (meta.error) {
                    if (meta.error === 'not found') {
                        if (!dataset.payid) return showWarning('No payment details found for this order.');
                        const dtime = 30 - Math.floor((Date.now() / 1000 - dataset.ptime) / 60);
                        if (dtime > 0) {
                            showWarning(`The order has not been paid yet. The payment link expires in ${dtime} minutes.`);
                        } else {
                            showWarning('The payment link has expired. No payment received.');
                        }
                        box.appendChild(buildTable([['Pay ID', dataset.payid]]));
                    } else if (meta.error === 'invalid shopid') {
                        showWarning('Invalid or missing API key. Please check your plugin settings or contact support.');
                    }
                    return;
                }

                const link = 'https://dashboard.scanpay.dk/' + meta.shopid + '/' + meta.id;
                const iso = wcSettings.currency.decimalSeparator === ',' ? 'da-DK' : 'en-US';
                currency = new Intl.NumberFormat(
                    iso, { style: 'currency', currency: meta.currency }
                );
                box.appendChild(buildTable(buildDataArray(meta)));
                let btns = '';
                if (meta.captured === '0') {
                    btns = `<a target="_blank" href="${link}" class="wcsp-meta-acts-refund">Void payment</a>`;
                } else if (parseFloat(meta.refunded) < parseFloat(meta.authorized)) {
                    btns = `<a target="_blank" href="${link}/refund" class="wcsp-meta-acts-refund">Refund</a>`;
                }

                box.innerHTML += `<div class="wcsp-meta-acts">
                    <div class="wcsp-meta-acts-left">
                        <a target="_blank" href="${link}" class="wcsp-meta-acts-link"></a>
                    </div>
                    ${btns}
                </div>`;
                rev = meta.rev;

                if (dataset.status === 'completed' || dataset.status === 'refunded') {
                    const total = parseFloat(meta.captured - meta.refunded);
                    if (parseFloat(dataset.total) !== total) {
                        showWarning('The order total (<b><i>' + currency.format(dataset.total) +
                        '</i></b>) does not match the net payment.');
                    }
                }
                compatibilityCheck();
            })
            .then(() => {
                target.parentNode.replaceChild(box, target);
            })
            .catch(({ name }) => {
                if (name === 'AbortError') return;
                showWarning('Error: could not load payment details.');
            });
    }

    function loadSubs() {
        const data = document.getElementById('wcsp-meta').dataset;
        const subid = data.subid;
        const target = document.getElementById('wcsp-meta');
        const secret = target.dataset.secret;
        abortCtrl = new AbortController();

        const url = '../wp-scanpay/fetch?x=sub&s=' + secret + ' &subid=' + subid + '&rev=' + rev;
        fetch(url, { signal: abortCtrl.signal, headers: { 'X-Scanpay': 'fetch' } })
            .then(res => res.json())
            .then((sub) => {
                const dato = new Date(sub.method_exp * 1000);
                box = target.cloneNode(false);
                let status = 'success';
                if (sub.retries === 0) status = 'error';
                if ((sub.method_exp * 1000) < Date.now()) status = 'expired';

                box.appendChild(buildTable([
                    ['Subscription ID', sub.subid],
                    ['Method', sub.method],
                    ['Expiration', dato.toISOString().split('T')[0]],
                    ['Status', status],
                ]));
                // compatibilityCheck();
            })
            .then(() => {
                target.parentNode.replaceChild(box, target);
            })
            .catch(({ name }) => {
                if (name === 'AbortError') return;
                showWarning('Error: could not load subscription details.');
            });
    }

    function load() {
        if (page === 'wc-orders') return loadOrderMeta();
        if (page === 'wc-orders--shop_subscription') return loadSubs();
    }
    load();

    document.addEventListener("visibilitychange", () => {
        if (document.visibilityState == "visible") return load();
        abortCtrl.abort();
    });
})();