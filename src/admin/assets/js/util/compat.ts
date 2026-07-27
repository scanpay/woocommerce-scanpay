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
export function getLastSync(secret: string, endpoint: string, force = false): Promise<number> {
	if (!force) {
		const cached = localStorage.getItem('scanpay_lastPing');
		const threshold = Math.floor(Date.now() / 1000) - 300;
		if (cached && parseInt(cached, 10) > threshold) {
			return Promise.resolve(parseInt(cached, 10));
		}
	}
	// The base is a parameter for the reason `secret` already is: this helper is shared
	// and knows nothing about which screen called it, and #wcsp-set-alert exists on
	// exactly one of them. The fallback is the old relative path, which only resolves
	// with pretty permalinks — it keeps a cached older bundle working.
	const ep = endpoint || '../wp-scanpay/fetch';
	return fetch(`${ep}?x=ping`, { headers: { 'X-Scanpay': secret } })
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
const VERSION_TTL = 3600 * 1000; // 1h, matching GitHub's rate-limit window.

function cacheVersion(version: string): void {
	localStorage.setItem('scanpay_version', JSON.stringify({ version, expires: Date.now() + VERSION_TTL }));
}

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
			cacheVersion(version);
			return version;
		})
		.catch((err) => {
			// Cache the failure too. GitHub allows 60 unauthenticated requests per hour
			// per IP; caching only on success meant a rate-limited admin re-hit the API
			// on every single page load, and never got back under the limit. An empty
			// version never satisfies isVersionGreater(), so no banner is shown for it.
			// Rethrown so callers still handle (and swallow) the failure themselves.
			cacheVersion('');
			throw err;
		});
}

/**
 * Split a dotted version into numbers.
 *
 * parseInt (not Number) so a pre-release suffix is stripped rather than poisoning
 * the segment: Number('1-rc1') is NaN, parseInt('1-rc1', 10) is 1. Anything still
 * unparseable -- an unsubstituted '{{ VERSION }}' in unbuilt src/ -- becomes 0.
 * Every NaN comparison below returns false, so the update banner would silently
 * never fire.
 */
function parseVersion(version: string): number[] {
	return version.split('.').map((part) => {
		const n = parseInt(part, 10);
		return Number.isNaN(n) ? 0 : n;
	});
}

export function isVersionGreater(version1: string, version2: string): boolean {
	const v1Parts = parseVersion(version1);
	const v2Parts = parseVersion(version2);
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
