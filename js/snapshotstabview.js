/*
 * Copyright (c) 2015
 *
 * This file is licensed under the Affero General Public License snapshot 3
 * or later.
 *
 * See the COPYING-README file.
 *
 */

/* @global Handlebars */

(function() {
	var TEMPLATE_ITEM =
		'<li data-revision="{{id}}">' +
		'<div>' +
		'<div class="preview-container">' +
		'<img class="preview" src="{{previewUrl}}" width="44" height="44"/>' +
		'</div>' +
		'<div class="snapshot-container">' +
		'<div>' +
		'<a href="{{downloadUrl}}" class="downloadSnapshot"><img src="{{downloadIconUrl}}" />' +
		'<span class="snapshotdate has-tooltip live-relative-timestamp" data-timestamp="{{millisecondsTimestamp}}" title="{{formattedTimestamp}}">{{relativeTimestamp}}</span>' +
		'</a>' +
		'</div>' +
		'{{#hasDetails}}' +
		'<div class="snapshot-details">' +
		'<span class="size has-tooltip" title="{{altSize}}">{{humanReadableSize}}</span>' +
		'</div>' +
		'{{/hasDetails}}' +
		'</div>' +
		'{{#canRevert}}' +
		'<a href="#" class="revertSnapshot" title="{{revertLabel}}"><img src="{{revertIconUrl}}" /></a>' +
		'{{/canRevert}}' +
		'</div>' +
		'</li>';

	var TEMPLATE =
		'<ul class="snapshots"></ul>' +
		'<div class="clear-float"></div>' +
		'<div class="empty hidden">' +
		'<div class="emptycontent">' +
		'<div class="icon-history"></div>' +
		'<p>{{emptyResultLabel}}</p>' +
		'</div></div>' +
		'<input type="button" class="showMoreSnapshots hidden" value="{{moreSnapshotsLabel}}"' +
		' name="show-more-snapshots" id="show-more-snapshots" />' +
		'<div class="loading hidden" style="height: 50px"></div>';

	/**
	 * @memberof OCA.Snapshots
	 */
	var SnapshotsTabView = OCA.Files.DetailTabView.extend(
		/** @lends OCA.Snapshots.SnapshotsTabView.prototype */ {
		id: 'snapshotsTabView',
		className: 'tab snapshotsTabView',

		_template: null,

		$snapshotsContainer: null,

		events: {
			'click .revertSnapshot': '_onClickRevertSnapshot',
			'click .showMoreSnapshots': '_onClickShowMoreSnapshots'
		},

		initialize: function() {
			OCA.Files.DetailTabView.prototype.initialize.apply(this, arguments);
			this.collection = new OCA.Snapshots.SnapshotCollection();
			this.collection.on('request', this._onRequest, this);
			this.collection.on('sync', this._onEndRequest, this);
			this.collection.on('update', this._onUpdate, this);
			this.collection.on('error', this._onError, this);
			this.collection.on('add', this._onAddModel, this);
		},

		getLabel: function() {
			return t('files_snapshots', 'Snapshots');
		},

		nextPage: function() {
			if (this._loading || !this.collection.hasMoreResults()) {
				return;
			}

			if (this.collection.getFileInfo() && this.collection.getFileInfo().isDirectory()) {
				return;
			}
			this.collection.fetchNext();
		},

		_onClickShowMoreSnapshots: function(ev) {
			ev.preventDefault();
			this.nextPage();
		},

		_onClickRevertSnapshot: function(ev) {
			var self = this;
			var $target = $(ev.target);
			var fileInfoModel = this.collection.getFileInfo();
			var revision;
			if (!$target.is('li')) {
				$target = $target.closest('li');
			}

			ev.preventDefault();
			revision = $target.attr('data-revision');

			this.$el.find('.snapshots, .showMoreSnapshots').addClass('hidden');

			var snapshotModel = this.collection.get(revision);
			snapshotModel.revert({
				success: function() {
					// reset and re-fetch the updated collection
					self.$snapshotsContainer.empty();
					self.collection.setFileInfo(fileInfoModel);
					self.collection.reset([], {silent: true});
					self.collection.fetchNext();

					self.$el.find('.snapshots').removeClass('hidden');

					// update original model
					fileInfoModel.trigger('busy', fileInfoModel, false);
					fileInfoModel.set({
						size: snapshotModel.get('size'),
						mtime: snapshotModel.get('timestamp') * 1000,
						// temp dummy, until we can do a PROPFIND
						etag: snapshotModel.get('id') + snapshotModel.get('timestamp')
					});
				},

				error: function() {
					fileInfoModel.trigger('busy', fileInfoModel, false);
					self.$el.find('.snapshots').removeClass('hidden');
					self._toggleLoading(false);
					OC.Notification.show(t('files_snapshot', 'Failed to revert {file} to revision {timestamp}.', 
						{
							file: snapshotModel.getFullPath(),
							timestamp: OC.Util.formatDate(snapshotModel.get('timestamp') * 1000)
						}),
						{
							type: 'error'
						}
					);
				}
			});

			// spinner
			this._toggleLoading(true);
			fileInfoModel.trigger('busy', fileInfoModel, true);
		},

		_toggleLoading: function(state) {
			this._loading = state;
			this.$el.find('.loading').toggleClass('hidden', !state);
		},

		_onRequest: function() {
			this._toggleLoading(true);
			this.$el.find('.showMoreSnapshots').addClass('hidden');
		},

		_onEndRequest: function() {
			this._toggleLoading(false);
			this.$el.find('.empty').toggleClass('hidden', !!this.collection.length);
			this.$el.find('.showMoreSnapshots').toggleClass('hidden', !this.collection.hasMoreResults());
		},

		_onAddModel: function(model) {
			var $el = $(this.itemTemplate(this._formatItem(model)));
			this.$snapshotsContainer.append($el);

			var preview = $el.find('.preview')[0];
			this._lazyLoadPreview({
				url: model.getPreviewUrl(),
				mime: model.get('mimetype'),
				callback: function(url) {
					preview.src = url;
				}
			});
			$el.find('.has-tooltip').tooltip();
		},

		template: function(data) {
			if (!this._template) {
				this._template = Handlebars.compile(TEMPLATE);
			}

			return this._template(data);
		},

		itemTemplate: function(data) {
			if (!this._itemTemplate) {
				this._itemTemplate = Handlebars.compile(TEMPLATE_ITEM);
			}

			return this._itemTemplate(data);
		},

		setFileInfo: function(fileInfo) {
			if (fileInfo) {
				this.render();
				this.collection.setFileInfo(fileInfo);
				this.collection.reset([], {silent: true});
				this.nextPage();
			} else {
				this.render();
				this.collection.reset();
			}
		},

		_formatItem: function(snapshot) {
			var timestamp = snapshot.get('timestamp') * 1000;
			var size = snapshot.has('size') ? snapshot.get('size') : 0;
			return _.extend({
				millisecondsTimestamp: timestamp,
				formattedTimestamp: OC.Util.formatDate(timestamp),
				relativeTimestamp: OC.Util.relativeModifiedDate(timestamp),
				humanReadableSize: OC.Util.humanFileSize(size, true),
				altSize: n('files', '%n byte', '%n bytes', size),
				hasDetails: snapshot.has('size'),
				downloadUrl: snapshot.getDownloadUrl(),
				downloadIconUrl: OC.imagePath('core', 'actions/download'),
				revertIconUrl: OC.imagePath('core', 'actions/history'),
				revertLabel: t('files_snapshots', 'Restore'),
				canRevert: (this.collection.getFileInfo().get('permissions') & OC.PERMISSION_UPDATE) !== 0
			}, snapshot.attributes);
		},

		/**
		 * Renders this details view
		 */
		render: function() {
			this.$el.html(this.template({
				emptyResultLabel: t('files_snapshots', 'No earlier snapshots available'),
				moreSnapshotsLabel: t('files_snapshots', 'More snapshots …')
			}));
			this.$el.find('.has-tooltip').tooltip();
			this.$snapshotsContainer = this.$el.find('ul.snapshots');
			this.delegateEvents();
		},

		/**
		 * Returns true for files, false for folders.
		 *
		 * @return {bool} true for files, false for folders
		 */
		canDisplay: function(fileInfo) {
			if (!fileInfo) {
				return false;
			}
			return !fileInfo.isDirectory();
		},

		/**
		 * Lazy load a file's preview.
		 *
		 * @param path path of the file
		 * @param mime mime type
		 * @param callback callback function to call when the image was loaded
		 * @param etag file etag (for caching)
		 */
		_lazyLoadPreview : function(options) {
			var url = options.url;
			var mime = options.mime;
			var ready = options.callback;

			// get mime icon url
			var iconURL = OC.MimeType.getIconUrl(mime);
			ready(iconURL); // set mimeicon URL

			var img = new Image();
			img.onload = function(){
				// if loading the preview image failed (no preview for the mimetype) then img.width will < 5
				if (img.width > 5) {
					ready(url, img);
				} else if (options.error) {
					options.error();
				}
			};
			if (options.error) {
				img.onerror = options.error;
			}
			img.src = url;
		}
	});

	OCA.Snapshots = OCA.Snapshots || {};

	OCA.Snapshots.SnapshotsTabView = SnapshotsTabView;
})();
