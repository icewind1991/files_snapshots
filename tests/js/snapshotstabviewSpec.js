/*
 * Copyright (c) 2015
 *
 * This file is licensed under the Affero General Public License snapshot 3
 * or later.
 *
 * See the COPYING-README file.
 *
 */
describe('OCA.Snapshots.SnapshotsTabView', function() {
	var SnapshotCollection = OCA.Snapshots.SnapshotCollection;
	var SnapshotModel = OCA.Snapshots.SnapshotModel;
	var SnapshotsTabView = OCA.Snapshots.SnapshotsTabView;

	var fetchStub, fileInfoModel, tabView, testSnapshots, clock;

	beforeEach(function() {
		clock = sinon.useFakeTimers(Date.UTC(2015, 6, 17, 1, 2, 0, 3));
		var time1 = Date.UTC(2015, 6, 17, 1, 2, 0, 3) / 1000;
		var time2 = Date.UTC(2015, 6, 15, 1, 2, 0, 3) / 1000;

		var snapshot1 = new SnapshotModel({
			id: time1,
			timestamp: time1,
			name: 'some file.txt',
			size: 140,
			fullPath: '/subdir/some file.txt',
			mimetype: 'text/plain'
		});
		var snapshot2 = new SnapshotModel({
			id: time2,
			timestamp: time2,
			name: 'some file.txt',
			size: 150,
			fullPath: '/subdir/some file.txt',
			mimetype: 'text/plain'
		});

		testSnapshots = [snapshot1, snapshot2];

		fetchStub = sinon.stub(SnapshotCollection.prototype, 'fetch');
		fileInfoModel = new OCA.Files.FileInfoModel({
			id: 123,
			name: 'test.txt',
			permissions: OC.PERMISSION_READ | OC.PERMISSION_UPDATE
		});
		tabView = new SnapshotsTabView();
		tabView.render();
	});

	afterEach(function() {
		fetchStub.restore();
		tabView.remove();
		clock.restore();
	});

	describe('rendering', function() {
		it('reloads matching snapshots when setting file info model', function() {
			tabView.setFileInfo(fileInfoModel);
			expect(fetchStub.calledOnce).toEqual(true);
		});

		it('renders loading icon while fetching snapshots', function() {
			tabView.setFileInfo(fileInfoModel);
			tabView.collection.trigger('request');

			expect(tabView.$el.find('.loading').length).toEqual(1);
			expect(tabView.$el.find('.snapshots li').length).toEqual(0);
		});

		it('renders snapshots', function() {

			tabView.setFileInfo(fileInfoModel);
			tabView.collection.set(testSnapshots);

			var snapshot1 = testSnapshots[0];
			var snapshot2 = testSnapshots[1];
			var $snapshots = tabView.$el.find('.snapshots>li');
			expect($snapshots.length).toEqual(2);
			var $item = $snapshots.eq(0);
			expect($item.find('.downloadSnapshot').attr('href')).toEqual(snapshot1.getDownloadUrl());
			expect($item.find('.snapshotdate').text()).toEqual('seconds ago');
			expect($item.find('.size').text()).toEqual('< 1 KB');
			expect($item.find('.revertSnapshot').length).toEqual(1);
			expect($item.find('.preview').attr('src')).toEqual('http://localhost/core/img/filetypes/text.svg');

			$item = $snapshots.eq(1);
			expect($item.find('.downloadSnapshot').attr('href')).toEqual(snapshot2.getDownloadUrl());
			expect($item.find('.snapshotdate').text()).toEqual('2 days ago');
			expect($item.find('.size').text()).toEqual('< 1 KB');
			expect($item.find('.revertSnapshot').length).toEqual(1);
			expect($item.find('.preview').attr('src')).toEqual('http://localhost/core/img/filetypes/text.svg');
		});

		it('does not render revert button when no update permissions', function() {

			fileInfoModel.set('permissions', OC.PERMISSION_READ);
			tabView.setFileInfo(fileInfoModel);
			tabView.collection.set(testSnapshots);

			var snapshot1 = testSnapshots[0];
			var snapshot2 = testSnapshots[1];
			var $snapshots = tabView.$el.find('.snapshots>li');
			expect($snapshots.length).toEqual(2);
			var $item = $snapshots.eq(0);
			expect($item.find('.downloadSnapshot').attr('href')).toEqual(snapshot1.getDownloadUrl());
			expect($item.find('.snapshotdate').text()).toEqual('seconds ago');
			expect($item.find('.revertSnapshot').length).toEqual(0);
			expect($item.find('.preview').attr('src')).toEqual('http://localhost/core/img/filetypes/text.svg');

			$item = $snapshots.eq(1);
			expect($item.find('.downloadSnapshot').attr('href')).toEqual(snapshot2.getDownloadUrl());
			expect($item.find('.snapshotdate').text()).toEqual('2 days ago');
			expect($item.find('.revertSnapshot').length).toEqual(0);
			expect($item.find('.preview').attr('src')).toEqual('http://localhost/core/img/filetypes/text.svg');
		});
	});

	describe('More snapshots', function() {
		var hasMoreResultsStub;

		beforeEach(function() {
			tabView.setFileInfo(fileInfoModel);
			fetchStub.reset();
			tabView.collection.set(testSnapshots);
			hasMoreResultsStub = sinon.stub(SnapshotCollection.prototype, 'hasMoreResults');
		});
		afterEach(function() {
			hasMoreResultsStub.restore();
		});

		it('shows "More snapshots" button when more snapshots are available', function() {
			hasMoreResultsStub.returns(true);
			tabView.collection.trigger('sync');

			expect(tabView.$el.find('.showMoreSnapshots').hasClass('hidden')).toEqual(false);
		});
		it('does not show "More snapshots" button when more snapshots are available', function() {
			hasMoreResultsStub.returns(false);
			tabView.collection.trigger('sync');

			expect(tabView.$el.find('.showMoreSnapshots').hasClass('hidden')).toEqual(true);
		});
		it('fetches and appends the next page when clicking the "More" button', function() {
			hasMoreResultsStub.returns(true);

			expect(fetchStub.notCalled).toEqual(true);

			tabView.$el.find('.showMoreSnapshots').click();

			expect(fetchStub.calledOnce).toEqual(true);
		});
		it('appends snapshot to the list when added to collection', function() {
			var time3 = Date.UTC(2015, 6, 10, 1, 0, 0, 0) / 1000;

			var snapshot3 = new SnapshotModel({
				id: time3,
				timestamp: time3,
				name: 'some file.txt',
				size: 54,
				fullPath: '/subdir/some file.txt',
				mimetype: 'text/plain'
			});

			tabView.collection.add(snapshot3);

			expect(tabView.$el.find('.snapshots>li').length).toEqual(3);

			var $item = tabView.$el.find('.snapshots>li').eq(2);
			expect($item.find('.downloadSnapshot').attr('href')).toEqual(snapshot3.getDownloadUrl());
			expect($item.find('.snapshotdate').text()).toEqual('7 days ago');
			expect($item.find('.revertSnapshot').length).toEqual(1);
			expect($item.find('.preview').attr('src')).toEqual('http://localhost/core/img/filetypes/text.svg');
		});
	});

	describe('Reverting', function() {
		var revertStub;

		beforeEach(function() {
			revertStub = sinon.stub(SnapshotModel.prototype, 'revert');
			tabView.setFileInfo(fileInfoModel);
			tabView.collection.set(testSnapshots);
		});
		
		afterEach(function() {
			revertStub.restore();
		});

		it('tells the model to revert when clicking "Revert"', function() {
			tabView.$el.find('.revertSnapshot').eq(1).click();

			expect(revertStub.calledOnce).toEqual(true);
		});
		it('triggers busy state during revert', function() {
			var busyStub = sinon.stub();
			fileInfoModel.on('busy', busyStub);

			tabView.$el.find('.revertSnapshot').eq(1).click();

			expect(busyStub.calledOnce).toEqual(true);
			expect(busyStub.calledWith(fileInfoModel, true)).toEqual(true);

			busyStub.reset();
			revertStub.getCall(0).args[0].success();

			expect(busyStub.calledOnce).toEqual(true);
			expect(busyStub.calledWith(fileInfoModel, false)).toEqual(true);
		});
		it('updates the file info model with the information from the reverted revision', function() {
			var changeStub = sinon.stub();
			fileInfoModel.on('change', changeStub);

			tabView.$el.find('.revertSnapshot').eq(1).click();

			expect(changeStub.notCalled).toEqual(true);

			revertStub.getCall(0).args[0].success();

			expect(changeStub.calledOnce).toEqual(true);
			var changes = changeStub.getCall(0).args[0].changed;
			expect(changes.size).toEqual(150);
			expect(changes.mtime).toEqual(testSnapshots[1].get('timestamp') * 1000);
			expect(changes.etag).toBeDefined();
		});
		it('shows notification on revert error', function() {
			var notificationStub = sinon.stub(OC.Notification, 'show');

			tabView.$el.find('.revertSnapshot').eq(1).click();

			revertStub.getCall(0).args[0].error();

			expect(notificationStub.calledOnce).toEqual(true);

			notificationStub.restore();
		});
	});
});
