(function () {
	'use strict';

	function translate(text, vars) {
		return window.t ? window.t('files_snapshots', text, vars, undefined, {escape: false}) : text;
	}

	function debounce(fn, delay) {
		var timer = null;
		return function () {
			clearTimeout(timer);
			timer = setTimeout(fn, delay);
		};
	}

	function post(url, data) {
		return fetch(OC.generateUrl(url), {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				requesttoken: OC.requestToken,
			},
			body: JSON.stringify(data),
		}).then(function (response) {
			if (!response.ok) {
				throw new Error(response.status + ' ' + response.statusText);
			}
			return response.json();
		});
	}

	function element(tag, attributes, children) {
		var el = document.createElement(tag);
		Object.keys(attributes || {}).forEach(function (key) {
			if (key === 'text') {
				el.textContent = attributes[key];
			} else {
				el.setAttribute(key, attributes[key]);
			}
		});
		(children || []).forEach(function (child) {
			el.appendChild(child);
		});
		return el;
	}

	function init() {
		var form = document.getElementById('files_snapshots');
		if (!form) {
			return;
		}
		var format = document.getElementById('format');
		var dateFormat = document.getElementById('date_format');
		var resultTable = form.querySelector('table.result');
		var resultBody = form.querySelector('table.result tbody');
		var loading = form.querySelector('div.loading');
		var saveStatus = document.getElementById('save_status');

		function showStatus(el, text, isError) {
			el.textContent = text;
			el.classList.toggle('error', !!isError);
		}

		form.addEventListener('submit', function (event) {
			event.preventDefault();
			showStatus(saveStatus, translate('Saving…'));
			post('apps/files_snapshots/settings/save', {
				snapshotFormat: format.value,
				dateFormat: dateFormat.value,
			}).then(function () {
				showStatus(saveStatus, translate('Saved'));
			}, function (error) {
				showStatus(saveStatus, translate('Failed to save settings: {error}', {error: error.message}), true);
			});
		});

		var testSettings = debounce(function () {
			resultTable.classList.add('hidden');
			loading.classList.remove('hidden');
			resultBody.textContent = '';
			post('apps/files_snapshots/settings/test', {
				snapshotFormat: format.value,
				dateFormat: dateFormat.value,
			}).then(function (snapshots) {
				var names = Object.keys(snapshots);
				if (names.length < 1) {
					resultBody.appendChild(element('tr', {class: 'error'}, [
						element('td', {colspan: '2', text: translate('No snapshots found')}),
					]));
				} else {
					names.forEach(function (name) {
						resultBody.appendChild(element('tr', {}, [
							element('td', {text: name}),
							element('td', {text: snapshots[name]}),
						]));
					});
				}
			}, function (error) {
				resultBody.appendChild(element('tr', {class: 'error'}, [
					element('td', {colspan: '2', text: translate('Failed to list snapshots: {error}', {error: error.message})}),
				]));
			}).then(function () {
				resultTable.classList.remove('hidden');
				loading.classList.add('hidden');
			});
		}, 250);

		format.addEventListener('input', testSettings);
		dateFormat.addEventListener('input', testSettings);
		testSettings();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
