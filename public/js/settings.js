/*
    Settings page JS (loaded with defer)
*/

(() => {
    const alert = document.getElementById('wcsp-set-alert');

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
        return fetch(url, opts)
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


    function showWarning(title, msg, id = false) {
        const html = '<h4>' + title + '</h4>' + msg;
        if (id) {
            const oldWarn = document.getElementById('wcsp-set-alert-' + id);
            if (oldWarn) return oldWarn.innerHTML = html
        }
        const div = document.createElement('div');
        div.id = 'wcsp-set-alert-' + id;
        div.className = 'wcsp-set-alert';
        div.innerHTML = html;
        alert.appendChild(div);
    }

    // 1) Check for new version (cache result for 5 minutes)
    get('https://api.github.com/repos/scanpay/woocommerce-scanpay/releases/latest', 300)
        .then(({ tag_name }) => {
            const version = 'v' + window.wcSettings.admin.scanpay;
            if (tag_name !== version) {
                showWarning(
                    'There is a new version of the Scanpay plugin available',
                    `Your scanpay extension <i>(${version})</i> is <b class="scanpay-outdated">outdated</b>.
                    Please update to ${tag_name} (<a href="//github.com/scanpay/woocommerce-scanpay/releases"
                    target="_blank">changelog</a>)`
                );
            }
        });

    // 2) Stop if no shopid (no API key)
    if (alert.dataset.shopid === '0') {
        const html = `<span class="wcsp-set-api-info">
            You can find your Scanpay API key <a target="_blank" href="https://dashboard.scanpay.dk/settings/api">here</a>.
        </span>`;
        return document.getElementById('woocommerce_scanpay_apikey').parentNode.parentNode.innerHTML += html;
    }

    // 3) Check last ping and warn if >5 mins old (no caching in settings)
    function checkMtime() {
        get('../wp-scanpay/fetch?x=ping&s=' + alert.dataset.secret)
            .then(({ mtime }) => {
                if (mtime === 0) {
                    return showWarning(
                        'Not synchronized: No pings received',
                        'Please click <i>\'send ping\'</i> to initiate the synchronization with the scanpay backend.',
                        'sync'
                    );
                }
                const dsecs = Math.floor(Date.now() / 1000) - mtime;
                if (dsecs < 400) {
                    const oldWarn = document.getElementById('wcsp-set-alert-sync');
                    if (oldWarn) oldWarn.remove();
                    document.getElementById('wcsp-set-nav-mtime').innerHTML = '<b>Synchronized:</b> ' + dsecs + ' seconds ago.';
                } else if (dsecs < 604800) {
                    const dmins = Math.floor(dsecs / 60);
                    showWarning(
                        'Warning: Your system may be out of sync',
                        'More than ' + dmins + ' minutes have passed since the last received ping. ' +
                        `Please check your <i>API key</i> and click <i>'send ping'</i>.`,
                        'sync'
                    );
                } else {
                    showWarning(
                        'ERROR: Your system is out of sync',
                        'A long time has passed since the last received ping. ' +
                        `Please check your <i>API key</i> and click <i>'send ping'</i>.`,
                        'sync'
                    );
                }
            });
    }
    checkMtime();

    // checkPing when the tab is visible again
    document.addEventListener("visibilitychange", () => {
        if (document.visibilityState === "visible") checkMtime();
    });
})();