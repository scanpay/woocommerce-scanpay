/*
    JavaScript for the meta box on the order page.
*/

(() => {
    const urlParams = new URLSearchParams(window.location.search);
    const orderid = urlParams.get('id');
    let rev = 0;
    let busy = true;
    let currency;
    let box;

    /*
        request(): fetch wrapper with caching (v1.0)
    */
    function request(url, caching = 0) {
        const reqCache = (caching) ? JSON.parse(localStorage.getItem('scanpay_cache')) || {} : {};
        const now = Math.floor(Date.now() / 1000);

        if (caching && reqCache[url] && now < reqCache[url].next) {
            return new Promise((resolve, reject) => {
                if (reqCache[url].err) return reject(reqCache[url].err);
                resolve(reqCache[url].o)
            });
        }
        return fetch(url)
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
        div.className = 'scanpay--alert scanpay--alert-' + type;
        div.innerHTML = msg;
        document.getElementById('scanpay-meta').prepend(div);
    }

    function compatibilityCheck() {
        // Check last ping and warn if >10 mins old (cache result for 2 mins)
        request('../wc-api/scanpay_ajax_ping_mtime/', 120)
            .then((o) => {
                const dmins = Math.floor((Math.floor(Date.now() / 1000) - o.mtime) / 60);
                if (o.mtime === 0 || dmins < 10) return;
                let ts = dmins + ' minutes';
                if (dmins > 120) ts = Math.floor(dmins / 60) + ' hours'
                showWarning('Your scanpay extension is out of sync: ' + ts + ' since last synchronization.');

            }).catch((e) => {
                // showWarning(e);
            });

        // Check if the extension is up-to-date (cache result for 10 minutes)
        request('https://api.github.com/repos/scanpay/woocommerce-scanpay/releases/latest', 600)
            .then((o) => {
                console.log(o);
                const version = wcSettings.admin.scanpay;
                const release = o.tag_name.substring(1);
                if (release !== version) {
                    showWarning(
                        `Your scanpay plugin is <b class="scanpay-outdated">outdated</b>.
                        Please update to <i>${release}</i> (<a href="//github.com/scanpay/woocommerce-scanpay/releases"
                        target="_blank">changelog</a>)`
                    );
                }
            }).catch((e) => {});
    }

    function buildDataArray(o) {
        // const methodArr = o.method.split(' ');
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
        ul.id = 'sp--widget--ul';
        ul.className = 'sp--widget--ul';
        for (const x of arr) {
            const li = document.createElement('li');
            li.className = 'sp--widget--li';

            const title = document.createElement('div');
            title.className = 'sp--widget--li--title';
            title.textContent = x[0] + ':';
            li.appendChild(title);

            const value = document.createElement('div');
            value.className = 'sp--widget--li--value';
            value.textContent = x[1];
            li.appendChild(value);
            ul.appendChild(li);
        }
        return ul;
    }

    fetch('../wc-api/scanpay_ajax_meta/?order_id=' + orderid + '&rev=0')
        .then(r => r.json())
        .then((meta) => {
            const target = document.getElementById('scanpay-meta');
            const dataset = target.dataset;
            box = target.cloneNode(false);

            if (!dataset.payid) return showWarning('No payment details found for this order.');
            if (!meta.success) {
                if (meta.data.error === 'invalid shopid') {
                    return showWarning('Invalid or missing API key. Please check your plugin settings or contact support.');
                }
                const dtime = 30 - Math.floor((Date.now() / 1000 - dataset.ptime) / 60);
                if (dtime > 0) {
                    showWarning(`The order has not been paid yet. The payment link expires in ${dtime} minutes.`);
                } else {
                    showWarning('The payment link has expired. No payment received.');
                }
                return box.appendChild(buildTable([['Pay ID', dataset.payid]]));
            }

            const link = 'https://dashboard.scanpay.dk/' + meta.data.shopid + '/' + meta.data.id;
            const iso = wcSettings.currency.decimalSeparator === ',' ? 'da-DK' : 'en-US';
            currency = new Intl.NumberFormat(
                iso, { style: 'currency', currency: meta.data.currency }
            );
            box.appendChild(buildTable(buildDataArray(meta.data)));

            let btns = '';
            if (meta.data.captured === '0') {
                btns = `<a target="_blank" href="${link}" class="sp-meta-acts-refund">Void payment</a>`;
            } else if (meta.data.refunded < meta.data.authorized) {
                btns = `<a target="_blank" href="${link}/refund" class="sp-meta-acts-refund">Refund</a>`;
            }

            box.innerHTML += `<div class="sp-meta-acts">
                <div class="sp-meta-acts-left">
                    <a target="_blank" href="${link}" class="sp-meta-acts-link"></a>
                </div>
                ${btns}
            </div>`;

            busy = false;
            compatibilityCheck();
        })
        .catch(e => console.log(e));



    let controller;
    document.addEventListener("visibilitychange", () => {
        // Check for rev updates when user comes back from dashboard
        if (document.visibilityState == "visible") {
            controller = new AbortController();

            fetch(ajaxMetaUrl + '&rev=' + rev, { signal: controller.signal })
                .then((r) => r.json())
                .then(build)
                .catch((e) => console.log(e));
        } else if (controller) {
            controller.abort();
        }
    });


})();