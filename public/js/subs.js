/*
    JavaScript for the Subscriptions meta box
*/

(() => {
    const dom = document.getElementById('wcsp-meta');
    const data = dom.dataset || {};
    if ( ! data.secret || ! data.subid ) return;
    let rev = 0;

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
    function loadSubs() {
        abortCtrl = new AbortController();
        const url = '../wp-scanpay/fetch?x=sub&s=' + data.secret + ' &subid=' + data.subid + '&rev=' + rev;
        fetch(url, { signal: abortCtrl.signal, headers: { 'X-Scanpay': 'fetch' } })
            .then(res => res.json())
            .then((sub) => {
                const dato = new Date(sub.method_exp * 1000);
                box = dom.cloneNode(false);
                let status = 'success';
                if (sub.retries === 0) status = 'error';
                if ((sub.method_exp * 1000) < Date.now()) status = 'expired';

                box.appendChild(buildTable([
                    ['Subscription ID', sub.subid],
                    ['Method', sub.method],
                    ['Expiration', dato.toISOString().split('T')[0]],
                    ['Status', status],
                ]));
                compatibilityCheck();
            })
            .then(() => {
                dom.parentNode.replaceChild(box, dom);
            })
            .catch(({ name }) => {
                if (name === 'AbortError') return;
                showWarning('Error: could not load subscription details.');
            });
    }
    loadSubs();
})();
