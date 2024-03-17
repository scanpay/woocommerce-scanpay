/*
    JavaScript for the meta box on the order page.
*/

(() => {
    let dom = document.getElementById('wcsp-meta');
    const data = dom.dataset || {};
    if ( ! data.secret || ! data.id ) return;
    let rev = 0;
    let currency;

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
        get('../wp-scanpay/fetch?x=ping&s=' + data.secret, 120)
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
                        target="_blank">changelog</a>)`,
                        'info'
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

    let box, abortCtrl;
    function loadOrderMeta() {
        abortCtrl = new AbortController();
        const url = '../wp-scanpay/fetch?x=meta&s=' + data.secret + ' &oid=' + data.id + '&rev=' + rev;
        fetch(url, { signal: abortCtrl.signal, headers: { 'X-Scanpay': 'fetch' } })
            .then(res => res.json())
            .then((meta) => {
                box = dom.cloneNode(false);

                if (meta.error) {
                    if (meta.error === 'not found') {
                        if (!data.payid) return showWarning('No payment details found for this order.');
                        const dtime = 30 - Math.floor((Date.now() / 1000 - data.ptime) / 60);
                        if (dtime > 0) {
                            showWarning(`The order has not been paid yet. The payment link expires in ${dtime} minutes.`);
                        } else {
                            showWarning('The payment link has expired. No payment received.');
                        }
                        box.appendChild(buildTable([['Pay ID', data.payid]]));
                    } else if (meta.error === 'invalid shopid') {
                        showWarning('Invalid or missing API key. Please check your plugin settings or contact support.');
                    }
                    return;
                }

                if ( ! currency ) {
                    const iso = wcSettings.currency.decimalSeparator === ',' ? 'da-DK' : 'en-US';
                    currency = new Intl.NumberFormat(
                        iso, { style: 'currency', currency: meta.currency }
                    );
                }

                box.appendChild(buildTable(buildDataArray(meta)));
                let btns = '';
                const link = 'https://dashboard.scanpay.dk/' + meta.shopid + '/' + meta.id;
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

                if (data.status === 'completed' || data.status === 'refunded') {
                    const total = parseFloat(meta.captured - meta.refunded);
                    if (parseFloat(data.total) !== total) {
                        showWarning('The order total (<b><i>' + currency.format(data.total) +
                        '</i></b>) does not match the net payment.');
                    }
                }
                compatibilityCheck();
            })
            .then(() => {
                dom.parentNode.replaceChild(box, dom);
                dom = document.getElementById('wcsp-meta');
            })
            .catch(({ name }) => {
                if (name === 'AbortError') return;
                showWarning('Error: could not load payment details.');
            });
    }
    loadOrderMeta();

    document.addEventListener("visibilitychange", () => {
        if (document.visibilityState == "visible") return loadOrderMeta();
        abortCtrl.abort();
    });
})();
