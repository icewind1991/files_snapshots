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
		var locationsTable = form.querySelector('table.locations');
		var locationsBody = form.querySelector('table.locations tbody');
		var loading = form.querySelector('div.loading');
		var saveStatus = document.getElementById('save_status');
		var testPath = document.getElementById('test_path');
		var testPathResult = document.getElementById('test_path_result');

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

		function snapshotPath(location) {
			var path = location.format.replace(/\/+$/, '');
			if (location.relativePath) {
				path += '/' + location.relativePath;
			}
			return path;
		}

		function renderSnapshotList(cell, snapshots) {
			cell.textContent = '';
			var names = Object.keys(snapshots);
			if (names.length < 1) {
				cell.appendChild(element('span', {class: 'error', text: translate('No snapshots found')}));
				return;
			}
			var table = element('table', {class: 'snapshot-list'});
			names.forEach(function (name) {
				table.appendChild(element('tr', {}, [
					element('td', {text: name}),
					element('td', {text: snapshots[name]}),
				]));
			});
			cell.appendChild(table);
		}

		function renderLocation(location) {
			var label = element('td', {}, [
				element('span', {class: 'location-type', text: location.type === 'external' ? translate('External storage') : ''}),
				element('span', {text: location.label}),
			]);
			var snapshotsCell = element('td');
			var toggleCell = element('td');
			var row = element('tr', {class: 'location' + (location.found ? '' : ' not-found')}, [
				label,
				element('td', {class: 'path', text: location.source}),
				snapshotsCell,
				toggleCell,
			]);
			if (!location.found) {
				snapshotsCell.appendChild(element('span', {class: 'error', text: translate('No snapshots found')}));
				return [row];
			}
			snapshotsCell.appendChild(element('div', {class: 'path', text: snapshotPath(location)}));
			snapshotsCell.appendChild(element('div', {class: 'location-details', text: (location.autodetected ? translate('auto-detected') + ', ' : '')
				+ (window.n ? window.n('files_snapshots', '%n snapshot', '%n snapshots', location.snapshotCount) : location.snapshotCount + ' snapshots')}));
			if (location.snapshotCount < 1) {
				return [row];
			}

			var detailsCell = element('td', {colspan: '4'});
			var detailsRow = element('tr', {class: 'location-snapshots hidden'}, [detailsCell]);
			var toggle = element('button', {type: 'button', class: 'toggle-snapshots', 'aria-expanded': 'false', text: translate('Show')});
			var loaded = false;
			toggle.addEventListener('click', function () {
				var expanded = detailsRow.classList.toggle('hidden') === false;
				toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
				toggle.textContent = expanded ? translate('Hide') : translate('Show');
				if (expanded && !loaded) {
					loaded = true;
					detailsCell.textContent = translate('Loading…');
					// resolve the location again, so the snapshots are listed exactly like they are used for versions
					post('apps/files_snapshots/settings/test-path', Object.assign(getSettings(), {path: location.source})).then(function (result) {
						renderSnapshotList(detailsCell, result.found ? result.snapshots : {});
					}, function (error) {
						loaded = false;
						detailsCell.textContent = '';
						detailsCell.appendChild(element('span', {class: 'error', text: error.message}));
					});
				}
			});
			toggleCell.appendChild(toggle);
			return [row, detailsRow];
		}

		var refreshId = 0;
		var refreshLocations = debounce(function () {
			var id = ++refreshId;
			locationsTable.classList.add('hidden');
			loading.classList.remove('hidden');
			post('apps/files_snapshots/settings/locations', getSettings()).then(function (locations) {
				if (id !== refreshId) {
					return;
				}
				locationsBody.textContent = '';
				if (locations.length < 1) {
					locationsBody.appendChild(element('tr', {}, [
						element('td', {colspan: '4', class: 'error', text: translate('No snapshot locations configured')}),
					]));
				}
				locations.forEach(function (location) {
					renderLocation(location).forEach(function (row) {
						locationsBody.appendChild(row);
					});
				});
			}, function (error) {
				if (id !== refreshId) {
					return;
				}
				locationsBody.textContent = '';
				locationsBody.appendChild(element('tr', {}, [
					element('td', {colspan: '4', class: 'error', text: translate('Failed to list snapshots: {error}', {error: error.message})}),
				]));
			}).then(function () {
				if (id === refreshId) {
					locationsTable.classList.remove('hidden');
					loading.classList.add('hidden');
				}
			});
		}, 500);

		function testMountRow(row) {
			var status = row.querySelector('.mount-status');
			var mountFormat = row.querySelector('.mount-format-input').value.trim();
			showStatus(status, '');
			if (!mountFormat) {
				return;
			}
			if (mountFormat.indexOf('%user%') !== -1) {
				showStatus(status, translate('See discovered snapshots below'));
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
				refreshLocations();
			}
		});
		mountBody.addEventListener('input', function (event) {
			if (event.target.classList.contains('mount-format-input')) {
				testMountRows();
			}
			refreshLocations();
		});

		function runTestPath() {
			var path = testPath.value.trim();
			if (!path) {
				return;
			}
			var data = getSettings();
			data.path = path;
			showStatus(testPathResult, translate('Testing…'));
			post('apps/files_snapshots/settings/test-path', data).then(function (result) {
				if (!result.found) {
					showStatus(testPathResult, translate('No snapshot location found for this path'), true);
					return;
				}
				var lines = [
					translate('Path inside snapshots: {path}', {path: result.relativePath || '/'}),
					translate('Snapshots: {count}, containing this path: {withFile}, distinct versions: {versions}', {
						count: result.snapshotCount,
						withFile: Object.keys(result.snapshots).length,
						versions: result.versionCount,
					}),
				];
				if (result.example) {
					lines.push(translate('Example: {path}', {path: result.example}));
				}
				showStatus(testPathResult, lines.join('\n'));
			}, function (error) {
				showStatus(testPathResult, error.message, true);
			});
		}

		document.getElementById('test_path_button').addEventListener('click', runTestPath);
		testPath.addEventListener('keydown', function (event) {
			if (event.key === 'Enter') {
				event.preventDefault();
				runTestPath();
			}
		});

		format.addEventListener('input', refreshLocations);
		dateFormat.addEventListener('input', refreshLocations);
		dateFormat.addEventListener('input', testMountRows);
		autodetect.addEventListener('change', refreshLocations);
		autodetectPatterns.addEventListener('input', refreshLocations);
		refreshLocations();
		testMountRows();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
