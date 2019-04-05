import {VersionModel} from './versionmodel'

export const VersionCollection = OC.Backbone.Collection.extend({
	model: VersionModel,

	/**
	 * @var OCA.Files.FileInfoModel
	 */
	_fileInfo: null,

	_endReached: false,
	_currentIndex: 0,

	url: function () {
		var url = OC.generateUrl('/apps/files_snapshots/versions');
		var query = {
			source: this._fileInfo.getFullPath(),
			start: this._currentIndex
		};
		return url + '?' + OC.buildQueryString(query);
	},

	setFileInfo: function (fileInfo) {
		this._fileInfo = fileInfo;
		// reset
		this._endReached = false;
		this._currentIndex = 0;
	},

	getFileInfo: function () {
		return this._fileInfo;
	},

	hasMoreResults: function () {
		return !this._endReached;
	},

	fetch: function (options) {
		console.log("fetch");
		if (!options || options.remove) {
			this._currentIndex = 0;
		}
		return OC.Backbone.Collection.prototype.fetch.apply(this, arguments);
	},

	/**
	 * Fetch the next set of results
	 */
	fetchNext: function () {
		if (!this.hasMoreResults()) {
			return null;
		}
		if (this._currentIndex === 0) {
			return this.fetch();
		}
		return this.fetch({remove: false});
	},

	reset: function () {
		this._currentIndex = 0;
		OC.Backbone.Collection.prototype.reset.apply(this, arguments);
	},

	parse: function (result) {
		var fullPath = this._fileInfo.getFullPath();
		var results = result.versions.map(function (version) {
			var revision = parseInt(version.mtime, 10);
			return {
				id: version.version,
				name: OC.basename(fullPath),
				fullPath: fullPath,
				timestamp: revision,
				size: version.size,
				mimetype: version.mimetype
			};
		}.bind(this));
		this._endReached = result.endReached;
		this._currentIndex += results.length;
		return results;
	}
});

