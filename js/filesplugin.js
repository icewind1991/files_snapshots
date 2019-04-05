import {VersionsTabView} from './versionstabview';

export class FilesPlugin {
	/**
	 * Initialize the versions plugin.
	 *
	 * @param {OCA.Files.FileList} fileList file list to be extended
	 */
	attach(fileList) {
		if (fileList.id === 'trashbin' || fileList.id === 'files.public') {
			return;
		}

		fileList.registerTabView(new VersionsTabView('snapshotsTabView', {order: -10}));
	}
}

