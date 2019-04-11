$(document).ready(function () {
	var form = $('#files_snapshots');
	var format = $('#format');
	var dateFormat = $('#date_format');
    var userFormat = $('#user_format');
	var resultTable = $('table.result');
	var resultBody = $('table.result tbody');
	var loading = $('div.loading');
	form.on('submit', function (event) {
		event.preventDefault();
		$.post(OC.generateUrl('apps/files_snapshots/settings/save'), {
			snapshotFormat: format.val(),
			dateFormat: dateFormat.val(),
			userFormat: userFormat.val()
		});
	});

	var testSettings = _.debounce(function () {
		resultTable.addClass('hidden');
		loading.removeClass('hidden');
		$.post(OC.generateUrl('apps/files_snapshots/settings/test'), {
			snapshotFormat: format.val(),
			dateFormat: dateFormat.val(),
			userFormat: userFormat.val()
		}).then(function (snapshots) {
			resultBody.empty();
			if (snapshots.length < 1) {
				resultBody.append($('<tr class="error"/>').append($('<td colspan="2"/>').text(t('files_snapshots', 'No snapshots found'))));
			} else {
				for (var snap in snapshots) {
					if (snapshots.hasOwnProperty(snap)) {
						var row = $('<tr/>');
						row.append($('<td/>').text(snap));
						row.append($('<td/>').text(snapshots[snap][0]));
                        row.append($('<td/>').text(snapshots[snap][1]));
						resultBody.append(row);
					}
				}
			}
			resultTable.removeClass('hidden');
			loading.addClass('hidden');
		});
	}, 250);

	format.on('input', testSettings);
	dateFormat.on('input', testSettings);
	userFormat.on('input', testSettings);
	testSettings();
});
