$(document).ready(function () {
	var form = $('#files_snapshots');
	var format = $('#format');
	var dateFormat = $('#date_format');
	form.on('submit', function (event) {
		event.preventDefault();
		$.post(OC.generateUrl('apps/files_snapshots/settings/save'), {
			snapshotFormat: format.val(),
			dateFormat: dateFormat.val()
		});
	});
});
