/*
	Show a warning message in the meta box.
	Prevent duplicate messages.
*/

export function showError(msg: string) {
	showWarning(msg, 'error');
}

export function showWarning(msg: string, type: string = 'error') {
	const div = document.createElement('div');
	div.className = 'wcsp-meta-alert wcsp-meta-alert-' + type;
	div.innerHTML = msg;
	(document.getElementById('wcsp-meta-head') as HTMLElement).appendChild(div);
}
