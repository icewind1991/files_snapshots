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
		var autodetect = document.getElementById('autodetect');
		var autodetectPatterns = document.getElementById('autodetect_patterns');
		var mountBody = form.querySelector('table.mount-formats tbody');
		var resultTable = form.querySelector('table.result');
		var resultBody = form.querySelector('table.result tbody');
		var loading = form.querySelector('div.loading');
		var saveStatus = document.getElementById('save_status');

		function getMountFormats() {
			var result = [];
			mountBody.querySelectorAll('tr.mount-format').forEach(function (row) {
				var source = row.querySelector('.mount-source').value.trim();
				var mountFormat = row.querySelector('.mount-format-input').value.trim();
				if (source || mountFormat) {
					result.push({source: source, format: mountFormat});
				}
			});
			return result;
		}

		function getSettings() {
			return {
				snapshotFormat: format.value,
				dateFormat: dateFormat.value,
				mountFormats: getMountFormats(),
				autodetect: autodetect.checked,
				autodetectPatterns: autodetectPatterns.value,
			};
		}

		function showStatus(el, text, isError) {
			el.textContent = text;
			el.classList.toggle('error', !!isError);
		}

		form.addEventListener('submit', function (event) {
			event.preventDefault();
			showStatus(saveStatus, translate('Saving…'));
			post('apps/files_snapshots/settings/save', getSettings()).then(function (saved) {
				autodetectPatterns.value = saved.autodetectPatterns.join('\n');
				showStatus(saveStatus, translate('Saved'));
			}, function (error) {
				showStatus(saveStatus, translate('Failed to save settings: {error}', {error: error.message}), true);
			});
		});

		function testFormat(snapshotFormat) {
			return post('apps/files_snapshots/settings/test', {
				snapshotFormat: snapshotFormat,
				dateFormat: dateFormat.value,
			});
		}

		var testSettings = debounce(function () {
			resultTable.classList.add('hidden');
			loading.classList.remove('hidden');
			resultBody.textContent = '';
			testFormat(format.value).then(function (snapshots) {
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

		function testMountRow(row) {
			var status = row.querySelector('.mount-status');
			var mountFormat = row.querySelector('.mount-format-input').value.trim();
			showStatus(status, '');
			if (!mountFormat) {
				return;
			}
			testFormat(mountFormat).then(function (snapshots) {
				var count = Object.keys(snapshots).length;
				if (count < 1) {
					showStatus(status, translate('No snapshots found'), true);
				} else {
					showStatus(status, window.n
						? window.n('files_snapshots', '%n snapshot found', '%n snapshots found', count)
						: count + ' snapshots found');
				}
			}, function (error) {
				showStatus(status, error.message, true);
			});
		}

		var testMountRows = debounce(function () {
			mountBody.querySelectorAll('tr.mount-format').forEach(testMountRow);
		}, 250);

		function addMountRow() {
			var row = element('tr', {class: 'mount-format'}, [
				element('td', {}, [element('input', {type: 'text', class: 'mount-source', placeholder: '/mnt/external'})]),
				element('td', {}, [element('input', {type: 'text', class: 'mount-format-input', placeholder: '/mnt/external/.zfs/snapshot/%snapshot%'})]),
				element('td', {class: 'mount-status'}),
				element('td', {}, [element('button', {type: 'button', class: 'icon-delete remove-mount-format', title: translate('Remove')})]),
			]);
			mountBody.appendChild(row);
			return row;
		}

		form.querySelector('.add-mount-format').addEventListener('click', function () {
			addMountRow().querySelector('.mount-source').focus();
		});
		mountBody.addEventListener('click', function (event) {
			var button = event.target.closest('.remove-mount-format');
			if (button) {
				button.closest('tr').remove();
			}
		});
		mountBody.addEventListener('input', function (event) {
			if (event.target.classList.contains('mount-format-input')) {
				testMountRows();
			}
		});

		format.addEventListener('input', testSettings);
		dateFormat.addEventListener('input', testSettings);
		dateFormat.addEventListener('input', testMountRows);
		testSettings();
		testMountRows();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
