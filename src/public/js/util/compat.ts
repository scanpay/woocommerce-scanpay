function safeJsonParse<T>(str: string | null, defaultValue: T): T {
	if (!str) return defaultValue;
	try {
		return JSON.parse(str) as T;
	} catch {
		return defaultValue;
	}
}

/*
	Check if the system is in sync with the backend (wp-scanpay-fetch-ping.php)
	Backend return a unixtime (secs) of the last ping or 0 if no ping has been received.
*/
export function getLastSync(secret: string, force = false): Promise<number> {
	if (!force) {
		const cached = localStorage.getItem('scanpay_lastPing');
		const threshold = Math.floor(Date.now() / 1000) - 300;
		if (cached && parseInt(cached, 10) > threshold) {
			return Promise.resolve(parseInt(cached, 10));
		}
	}
	return fetch('../wp-scanpay/fetch?x=ping&s=' + secret, { headers: { 'X-Scanpay': 'fetch' } })
		.then(async (res) => {
			const body = await res.text();
			if (res.status !== 200) throw new Error(body);
			return body;
		})
		.then((str) => {
			localStorage.setItem('scanpay_lastPing', str);
			return parseInt(str, 10);
		});
}

/*
	Check if the plugin is up to date by fetching the tag_name of the latest release from GitHub
*/
export function checkVersion(): Promise<string> {
	// Try to get the version from localStorage
	const o = safeJsonParse(localStorage.getItem('scanpay_version'), { version: '', expires: 0 });
	if (o.expires > Date.now()) {
		return Promise.resolve(o.version);
	}
	return fetch('https://api.github.com/repos/scanpay/woocommerce-scanpay/releases/latest')
		.then((res) => {
			if (res.status !== 200) throw new Error(res.statusText);
			return res.json();
		})
		.then(({ tag_name }) => {
			const version = tag_name.substring(1);
			localStorage.setItem('scanpay_version', JSON.stringify({ version, expires: Date.now() + 3600 * 1000 }));
			return version;
		});
}

export function isVersionGreater(version1: string, version2: string): boolean {
	const v1Parts = version1.split('.').map(Number);
	const v2Parts = version2.split('.').map(Number);
	const length = Math.max(v1Parts.length, v2Parts.length);

	for (let i = 0; i < length; i++) {
		const v1 = v1Parts[i] ?? 0;
		const v2 = v2Parts[i] ?? 0;
		if (v1 !== v2) {
			return v1 > v2;
		}
	}
	return false;
}
