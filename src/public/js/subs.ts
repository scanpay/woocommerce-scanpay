/*
    JavaScript for the Subscriptions meta box
*/

import { showWarning, buildTable, pluginVersionCheck, pluginSyncCheck } from './util/meta';

const wco = (document.getElementById('wcsp-meta') as HTMLElement).dataset as {
	secret?: string;
	subid?: string;
};
const secret = wco.secret as string;

function loadSubs() {
	if (!wco.subid) return; // TODO: lookup subid with AJAX request

	fetch('../wp-scanpay/fetch?x=sub&s=' + secret + ' &subid=' + wco.subid + '&rev=0', {
		headers: { 'X-Scanpay': 'fetch' },
	})
		.then((res) => res.json())
		.then((sub) => {
			const dato = new Date(sub.method_exp * 1000);
			let status = 'success';
			if (sub.retries === 0) status = 'error';
			if (sub.method_exp * 1000 < Date.now()) status = 'expired';

			buildTable([
				['Subscription ID', sub.subid],
				['Method', sub.method],
				['Expiration', dato.toISOString().split('T')[0]],
				['Status', status],
			]);
		})
		.catch(() => {
			showWarning('Error: could not load subscription details.');
		});
}
loadSubs();
pluginSyncCheck(secret);
pluginVersionCheck();
