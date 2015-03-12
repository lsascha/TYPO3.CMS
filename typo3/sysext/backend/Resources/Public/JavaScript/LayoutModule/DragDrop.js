/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

/**
 * this JS code does the drag+drop logic for the Layout module (Web => Page)
 * based on jQuery UI
 */
define(['jquery', 'jquery-ui/sortable'], function ($) {

	var DragDrop = {
		contentIdentifier: '.t3-page-ce',
		dragIdentifier: '.t3-row-header',
		dropZoneAvailableIdentifier: '.t3-page-ce-dropzone-available',
		dropPossibleClass: 't3-page-ce-dropzone-possible',
		sortableItemsIdentifier: '.t3js-page-ce-sortable',
		columnIdentifier: '.t3-page-column',
		columnHolderIdentifier: 'table.t3-page-columns',
		addContentIdentifier: '.t3js-page-new-ce',
		langClassPrefix: '.t3js-sortable-lang-'
	};

	/**
	 * initializes Drag+Drop for all content elements on the page
	 */
	DragDrop.initialize = function() {
		$('td[data-language-uid]').each(function() {
			var connectWithClassName = DragDrop.langClassPrefix + $(this).data('language-uid');
			$(connectWithClassName).sortable({
				items: DragDrop.sortableItemsIdentifier,
				connectWith: connectWithClassName,
				handle: DragDrop.dragIdentifier,
				distance: 20,
				cursor: 'move',
				helper: 'clone',
				placeholder: DragDrop.dropPossibleClass,
				tolerance: 'pointer',
				start: function(e, ui) {
					DragDrop.onSortStart($(this), ui);
				},
				stop: function(e, ui) {
					DragDrop.onSortStop($(this), ui);
				},
				change: function(e, ui) {
					DragDrop.onSortChange($(this), ui);
				},
				update: function(e, ui) {
					if (this === ui.item.parent()[0]) {
						DragDrop.onSortUpdate($(this), ui)
					}
				}
			}).disableSelection();
		});
	};

	/**
	 * Called when an item is about to be moved
	 */
	DragDrop.onSortStart = function($container, ui) {
		var $item = $(ui.item),
			$helper = $(ui.helper),
			$placeholder = $(ui.placeholder);

		$placeholder.height($item.height() - $helper.find(DragDrop.addContentIdentifier).height());
		DragDrop.changeDropzoneVisibility($container, $item);

		// show all dropzones, except the own
		$helper.find(DragDrop.dropZoneAvailableIdentifier).removeClass('active');
		$container.parents(DragDrop.columnHolderIdentifier).find(DragDrop.addContentIdentifier).hide();
	};

	/**
	 * Called when the sorting stopped
	 */
	DragDrop.onSortStop = function($container, ui) {
		var $allColumns = $container.parents(DragDrop.columnHolderIdentifier);
		$allColumns.find(DragDrop.addContentIdentifier).show();
		$allColumns.find(DragDrop.dropZoneAvailableIdentifier + '.active').removeClass('active');
	};

	/**
	 * Called when the index of the element in the sortable list has changed
	 */
	DragDrop.onSortChange = function($container, ui) {
		var $placeholder = $(ui.placeholder);
		DragDrop.changeDropzoneVisibility($container, $placeholder);
	};

	DragDrop.changeDropzoneVisibility = function($container, $subject) {
		var $prev = $subject.prev(':visible'),
			droppableClassName = DragDrop.langClassPrefix + $container.data('language-uid');

		if ($prev.length === 0) {
			$prev = $subject.prevUntil(':visible').last().prev();
		}
		$container.parents(DragDrop.columnHolderIdentifier).find(droppableClassName).find(DragDrop.contentIdentifier + ':not(.ui-sortable-helper)').not($prev).find(DragDrop.dropZoneAvailableIdentifier).addClass('active');
		$prev.find(DragDrop.dropZoneAvailableIdentifier + '.active').removeClass('active');
	};

	/**
	 * Called when the new position of the element gets stored
	 */
	DragDrop.onSortUpdate = function($container, ui) {
		var $selectedItem = $(ui.item),
			contentElementUid = parseInt($selectedItem.data('uid'));

		// send an AJAX requst via the AjaxDataHandler
		if (contentElementUid > 0) {
			var parameters = {};
			// add the information about a possible column position change
			parameters['data'] = {tt_content: {}};
			parameters['data']['tt_content'][contentElementUid] = {colPos: parseInt($container.data('colpos'))};
		}

		var targetContentElementUid = $selectedItem.prev().data('uid');
		// the item was moved to the top of the colPos, so the page ID is used here
		if (typeof targetContentElementUid === 'undefined') {
			// the actual page is needed
			targetContentElementUid = parseInt($container.find(DragDrop.contentIdentifier).first().data('page'));
		} else {
			// the negative value of the content element after where it should be moved
			targetContentElementUid = parseInt(targetContentElementUid) * -1;
		}

		parameters['cmd'] = {tt_content: {}};
		parameters['cmd']['tt_content'][contentElementUid] = {move: targetContentElementUid};
		// fire the request, and show a message if it has failed
		require(['TYPO3/CMS/Backend/AjaxDataHandler'], function(DataHandler) {
			DataHandler.process(parameters).done(function(result) {
				if (result.hasErrors) {
					$container.sortable('cancel');
				}
			});
		});
	};

	/**
	 * initialize function
	 */
	return function() {
		DragDrop.initialize();
		return DragDrop;
	}();
});