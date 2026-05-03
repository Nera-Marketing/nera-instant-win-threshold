/**
 * Instant win rule: public display type + inject into LFW AJAX payloads.
 *
 * Augments "Add rule" and bulk "Save" requests with nera_* fields (LFW only
 * serializes its own keys from each table row).
 */
(function ($) {
	'use strict';

	function updateVisibilityFields($wrap) {
		var $sel = $wrap.find('.nera-iwt-public-rule-type').first();
		if (!$sel.length) {
			return;
		}
		var v = String($sel.val() || 'instant');
		$wrap.toggleClass('nera-iwt--show-schedule', v === 'schedule');
		$wrap.toggleClass('nera-iwt--show-ticket-pct', v === 'ticket_pct');
		// Drop any inline display (e.g. from other scripts) so class-based CSS wins.
		$wrap.find('.nera-iwt-row-schedule, .nera-iwt-row-ticket-pct').css('display', '');
	}

	function refreshAll() {
		$('.nera-iwt-rule-visibility-fields').each(function () {
			updateVisibilityFields($(this));
		});
	}

	function movePopupFieldsToTop() {
		var $cell = $('#lty_lottery_instant_winners_rule_modal .lty-instant-winners-rule-content td').first();
		var $blk = $cell.find('.nera-iwt-rule-visibility-popup-fields');
		if ($cell.length && $blk.length) {
			$blk.prependTo($cell);
		}
	}

	/**
	 * LFW prints `lty_instant_winner_rule_column*` after the Action column.
	 * Move every Rule type header/cell to sit immediately before Action.
	 */
	function moveRuleTypeColumnBeforeAction() {
		$('.lty-instant-winners-rules-wrapper').each(function () {
			var $wrap = $(this);
			var $table = $wrap.find('table.lty-instant-winners-rules-contents').first();
			if (!$table.length) {
				return;
			}

			var $theadRow = $table.find('thead tr').first();
			var $actionTh = $theadRow.find('th.lty-instant-winner-action-column').first();
			if ($actionTh.length) {
				$theadRow.find('th.nera-iwt-public-rule-type-column').each(function () {
					$(this).insertBefore($actionTh);
				});
			}

			$table.find('tbody tr').each(function () {
				var $tr = $(this);
				var $actionTd = $tr.find('td.lty-instant-winner-action-column').first();
				if (!$actionTd.length) {
					return;
				}
				$tr.find('td.nera-iwt-public-rule-type-column').each(function () {
					$(this).insertBefore($actionTd);
				});
			});
		});
	}

	function scheduleColumnReorder() {
		moveRuleTypeColumnBeforeAction();
		window.setTimeout(moveRuleTypeColumnBeforeAction, 0);
		window.setTimeout(moveRuleTypeColumnBeforeAction, 100);
		window.setTimeout(moveRuleTypeColumnBeforeAction, 300);
	}

	/**
	 * Prefer the modal inside jquery-modal’s active blocker so we don’t read a
	 * hidden duplicate node when the DOM has more than one match.
	 */
	function neraIwtGetActiveAddRuleModal() {
		var $b = $('.blocker.jquery-modal.blocker.current');
		if ($b.length) {
			var $inside = $b.find('#lty_lottery_instant_winners_rule_modal').first();
			if ($inside.length) {
				return $inside;
			}
		}
		return $('#lty_lottery_instant_winners_rule_modal').first();
	}

	function neraIwtReadVisibilityFromAddRuleModal($m) {
		if (!$m || !$m.length) {
			return { type: 'instant', sched: '', pct: '0' };
		}
		var type = String($m.find('.nera-iwt-public-rule-type').first().val() || 'instant');
		var sched = type === 'schedule' ? String($m.find('.nera-iwt-schedule-at').first().val() || '') : '';
		var pct = type === 'ticket_pct' ? String($m.find('.nera-iwt-ticket-pct').first().val() || '0') : '0';
		return { type: type, sched: sched, pct: pct };
	}

	function neraIwtIsLtyAddInstantWinnerRuleAjaxData(data) {
		if (data && typeof data === 'object' && data.action === 'lty_add_instant_winner_rule') {
			return true;
		}
		if (typeof data === 'string') {
			return /(^|[&?])action=lty_add_instant_winner_rule(?:&|$)/.test(data);
		}
		return false;
	}

	function neraIwtAppendAddRuleVisibilityQuerySegment(dataStr, v) {
		var q =
			'instant_winner_rule[nera_public_rule_type]=' +
			encodeURIComponent(v.type) +
			'&instant_winner_rule[nera_schedule_at]=' +
			encodeURIComponent(v.sched) +
			'&instant_winner_rule[nera_ticket_pct]=' +
			encodeURIComponent(v.pct);
		return dataStr + (dataStr.length ? '&' : '') + q;
	}

	function neraIwtIsLtySaveInstantWinnersRulesAjaxData(data) {
		if (data && typeof data === 'object' && data.action === 'lty_save_instant_winners_rules') {
			return true;
		}
		if (typeof data === 'string') {
			return /(^|[&?])action=lty_save_instant_winners_rules(?:&|$)/.test(data);
		}
		return false;
	}

	/**
	 * Merge per-row Rule type / schedule / ticket % into LFW bulk-save payload.
	 */
	function neraIwtMergeSaveInstantWinnersRulesPayload(options) {
		var d = options.data;
		if (!d || typeof d !== 'object' || d.action !== 'lty_save_instant_winners_rules') {
			return;
		}
		if (!d.instant_winners_rules || typeof d.instant_winners_rules !== 'object') {
			return;
		}
		var $wrapper = $('.lty-instant-winners-rules-wrapper').first();
		if (!$wrapper.length) {
			return;
		}
		$.each(d.instant_winners_rules, function (ruleId, row) {
			if (!row || typeof row !== 'object') {
				return;
			}
			var rid = String(ruleId);
			var $tr = $wrapper
				.find('.lty-remove-instant-winner-rule')
				.filter(function () {
					return String($(this).data('instant_winner_rule_id')) === rid;
				})
				.closest('tr')
				.first();
			if (!$tr.length) {
				return;
			}
			var $wrap = $tr.find('.nera-iwt-public-rule-type-column .nera-iwt-rule-visibility-fields').first();
			if (!$wrap.length) {
				return;
			}
			var type = String($wrap.find('.nera-iwt-public-rule-type').first().val() || 'instant');
			var sched = type === 'schedule' ? String($wrap.find('.nera-iwt-schedule-at').first().val() || '') : '';
			var pct = type === 'ticket_pct' ? String($wrap.find('.nera-iwt-ticket-pct').first().val() || '0') : '0';
			row.nera_public_rule_type = type;
			row.nera_schedule_at = sched;
			row.nera_ticket_pct = pct;
		});
	}

	function neraIwtAppendBulkSaveVisibilityQuerySegment(dataStr) {
		var $wrapper = $('.lty-instant-winners-rules-wrapper').first();
		if (!$wrapper.length) {
			return dataStr;
		}
		var parts = [];
		$wrapper.find('.lty-remove-instant-winner-rule').each(function () {
			var id = $(this).data('instant_winner_rule_id');
			if (id === undefined || id === null) {
				return;
			}
			var $tr = $(this).closest('tr');
			var $wrap = $tr.find('.nera-iwt-public-rule-type-column .nera-iwt-rule-visibility-fields').first();
			if (!$wrap.length) {
				return;
			}
			var type = String($wrap.find('.nera-iwt-public-rule-type').first().val() || 'instant');
			var sched = type === 'schedule' ? String($wrap.find('.nera-iwt-schedule-at').first().val() || '') : '';
			var pct = type === 'ticket_pct' ? String($wrap.find('.nera-iwt-ticket-pct').first().val() || '0') : '0';
			var base = 'instant_winners_rules[' + id + ']';
			parts.push(
				base +
					'[nera_public_rule_type]=' +
					encodeURIComponent(type) +
					'&' +
					base +
					'[nera_schedule_at]=' +
					encodeURIComponent(sched) +
					'&' +
					base +
					'[nera_ticket_pct]=' +
					encodeURIComponent(pct)
			);
		});
		if (!parts.length) {
			return dataStr;
		}
		return dataStr + (dataStr.length ? '&' : '') + parts.join('&');
	}

	function neraIwtMergeAllInstantWinnerVisibilityPayloads(options) {
		neraIwtMergeInstantWinnerRuleVisibilityPayload(options);
		neraIwtMergeSaveInstantWinnersRulesPayload(options);
	}

	/**
	 * Read Rule type / schedule / ticket % from the Add Rule modal and merge
	 * into the outgoing AJAX payload (object or serialized string).
	 */
	function neraIwtMergeInstantWinnerRuleVisibilityPayload(options) {
		var $m = neraIwtGetActiveAddRuleModal();
		var v = neraIwtReadVisibilityFromAddRuleModal($m);

		var d = options.data;
		if (d && typeof d === 'object' && d.action === 'lty_add_instant_winner_rule') {
			if (!d.instant_winner_rule || typeof d.instant_winner_rule !== 'object') {
				d.instant_winner_rule = {};
			}
			d.instant_winner_rule.nera_public_rule_type = v.type;
			d.instant_winner_rule.nera_schedule_at = v.sched;
			d.instant_winner_rule.nera_ticket_pct = v.pct;
			return;
		}

		if (typeof d === 'string' && neraIwtIsLtyAddInstantWinnerRuleAjaxData(d)) {
			options.data = neraIwtAppendAddRuleVisibilityQuerySegment(d, v);
		}
	}

	/**
	 * jquery-modal triggers `modal:open` on the modal element; bind on the node
	 * and re-bind after LFW replaces the wrapper HTML via AJAX.
	 */
	function bindNeraIwtModalOpenHandlers() {
		var $m = $('#lty_lottery_instant_winners_rule_modal');
		if (!$m.length) {
			return;
		}
		$m.off('modal:open.neraIwt').on('modal:open.neraIwt', function () {
			movePopupFieldsToTop();
			refreshAll();
		});
	}

	$(document).on('change', '.nera-iwt-public-rule-type', function () {
		var $wrap = $(this).closest('.nera-iwt-rule-visibility-fields');
		if ($wrap.length) {
			updateVisibilityFields($wrap);
		}
	});

	$(function () {
		movePopupFieldsToTop();
		scheduleColumnReorder();
		refreshAll();
		bindNeraIwtModalOpenHandlers();
	});

	// Re-bind after Add New Rule link (modal node may have been replaced).
	$(document).on('click', 'a[href="#lty_lottery_instant_winners_rule_modal"]', function () {
		window.setTimeout(bindNeraIwtModalOpenHandlers, 400);
	});

	// After WC shows/hides panels (e.g. product type → lottery), DOM can appear late.
	$(document.body).on('woocommerce-product-type-change', function () {
		scheduleColumnReorder();
		window.setTimeout(function () {
			bindNeraIwtModalOpenHandlers();
			movePopupFieldsToTop();
			refreshAll();
		}, 200);
	});

	// Re-run when switching product data tabs (Instant Win tab may have been hidden).
	$(document.body).on('click', '#woocommerce-product-data ul.wc-tabs a', function () {
		scheduleColumnReorder();
	});

	// LFW toggles .lty-instant-winner-rule-column visibility when display mode changes.
	$(document).on('change', '#lty_instant_winner_display_mode', function () {
		window.setTimeout(refreshAll, 50);
	});

	// Re-run after LFW replaces the rules table via pagination AJAX.
	$(document).ajaxComplete(function (event, xhr, settings) {
		var d = settings.data;
		var isPagination = false;
		if (typeof d === 'string' && d.indexOf('action=lty_instant_winners_rules_pagination_content') !== -1) {
			isPagination = true;
		} else if (d && typeof d === 'object' && d.action === 'lty_instant_winners_rules_pagination_content') {
			isPagination = true;
		}
		if (!isPagination) {
			return;
		}
		setTimeout(function () {
			movePopupFieldsToTop();
			scheduleColumnReorder();
			refreshAll();
			bindNeraIwtModalOpenHandlers();
		}, 0);
	});

	// Merge Nera visibility fields into Add Rule + bulk Save requests.
	$.ajaxPrefilter(function (options) {
		neraIwtMergeAllInstantWinnerVisibilityPayloads(options);
	});

	// After jQuery serializes `data` to a string, append so duplicate keys win in PHP.
	$(document).ajaxSend(function (_event, _jqXHR, settings) {
		if (!settings || !settings.data || typeof settings.data !== 'string') {
			return;
		}
		if (neraIwtIsLtyAddInstantWinnerRuleAjaxData(settings.data)) {
			var v = neraIwtReadVisibilityFromAddRuleModal(neraIwtGetActiveAddRuleModal());
			settings.data = neraIwtAppendAddRuleVisibilityQuerySegment(settings.data, v);
			return;
		}
		if (neraIwtIsLtySaveInstantWinnersRulesAjaxData(settings.data)) {
			settings.data = neraIwtAppendBulkSaveVisibilityQuerySegment(settings.data);
		}
	});
})(jQuery);
