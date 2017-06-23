/*
 * Copyright (c) 2015
 *
 * This file is licensed under the Affero General Public License snapshot 3
 * or later.
 *
 * See the COPYING-README file.
 *
 */

(function() {
	OCA.Snapshots = OCA.Snapshots || {};

	/**
	 * @namespace
	 */
	OCA.Snapshots.Util = {
		/**
		 * Initialize the snapshots plugin.
		 *
		 * @param {OCA.Files.FileList} fileList file list to be extended
		 */
		attach: function(fileList) {
			if (fileList.id === 'trashbin' || fileList.id === 'files.public') {
				return;
			}

			fileList.registerTabView(new OCA.Snapshots.SnapshotsTabView('snapshotsTabView', {order: -10}));
		}
	};
})();

OC.Plugins.register('OCA.Files.FileList', OCA.Snapshots.Util);

