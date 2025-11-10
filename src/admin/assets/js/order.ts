/**
 * 	order.js: Show the meta box in the WooCommerce order page (admin).
 */

const dom = document.getElementById('wcsp-meta')!;
const domOrderData = window.ScanpayOrderData as OrderData;


interface ParsedResult {
	list: { label: string; value: number }[];
	errors: string[];
}

function parseOrderData(order: OrderData): ParsedResult {
	const errors: string[] = [];
	const list: { label: string; value: number }[] = [];

	if (!order.meta) {
		errors.push("Missing meta data");
		return { list, errors };
	}

	const keys = ["authorized", "captured", "refunded"] as const;
	for (const key of keys) {
		const raw = order.meta[key];
		if (typeof raw !== "string" || raw.trim() === "") {
			errors.push(`Missing field: ${key}`);
			continue;
		}

		const val = Number(raw);
		if (Number.isNaN(val)) {
			errors.push(`Invalid numeric value: ${key}=${raw}`);
			continue;
		}

		list.push({ label: key, value: val });
	}

	// sanity checks
	if (list.length && list.some(i => i.value < 0)) {
		errors.push("Negative amount detected");
	}

	return { list, errors };
}

function render(wco: OrderData) {
	const result = parseOrderData(wco);

	if (result.errors.length) {
		console.warn("Order warnings:", result.errors);
	}

	let li = '';
	for (const { label, value } of result.list) {
		li += `<li class="wcsp-meta-li">
				<div class="wcsp-meta-li-title">${label}</div>
				<div class="wcsp-meta-li-value">${value}</div>
			</li>`
	}
	dom.innerHTML = `
		<div id="wcsp-meta-head"></div>
		<ul id="wcsp-meta-ul" class="wcsp-meta-ul">${li}</ul>
		<div id="wcsp-meta-foot"></div>`;
}
render(domOrderData);
