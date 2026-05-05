/**
 * Instant win rule: public display type + inject into LFW AJAX payloads.
 *
 * Augments "Add rule" and bulk "Save" requests with nera_* fields (LFW only
 * serializes its own keys from each table row).
 */
(function () {
	'use strict';

	/**
	 * Matches LFW Lottery product fields: Sequential either as automatic `_lty_ticket_number_type` "2"
	 * or user-selection `_lty_tickets_per_tab_display_type` "1".
	 * Exposed for jQuery handlers (unsaved-state / publish guard).
	 *
	 * @return {boolean}
	 */
	window.neraIwtDomHasSequentialTicketPattern = function () {
		var genEl = document.getElementById('_lty_ticket_generation_type');
		var gen = genEl ? String(genEl.value || '') : '';
		if (gen === '1') {
			var nt = document.getElementById('_lty_ticket_number_type');
			return !!(nt && String(nt.value || '') === '2');
		}
		if (gen === '2') {
			var tt = document.getElementById('_lty_tickets_per_tab_display_type');
			return !!(tt && String(tt.value || '') === '1');
		}
		return false;
	};

	/**
	 * Clear LFW “unsaved instant winner rules” flag when Ticket Number Pattern in the DOM is no
	 * longer Sequential — runs before LTY’s submit handler (capture phase).
	 *
	 * @return {void}
	 */
	window.neraIwtClearInstantWinUnsavedIfDomTicketPatternNotSequential = function () {
		document.querySelectorAll('.lty-unsaved-instant-winner-rules').forEach(function (hidden) {
			hidden.value = '';
		});
		document.querySelectorAll('.lty-save-instant-winners-rules').forEach(function (btn) {
			btn.disabled = true;
		});
	};

	document.addEventListener(
		'submit',
		function (e) {
			var form = e.target;
			if (!form || form.nodeName !== 'FORM' || form.id !== 'post') {
				return;
			}
			var pt = document.getElementById('product-type');
			if (!pt || String(pt.value || '') !== 'lottery') {
				return;
			}
			if (typeof window.neraIwtDomHasSequentialTicketPattern !== 'function') {
				return;
			}
			if (window.neraIwtDomHasSequentialTicketPattern()) {
				return;
			}
			window.neraIwtClearInstantWinUnsavedIfDomTicketPatternNotSequential();
		},
		true
	);

	/**
	 * @return {string}
	 */
	function neraIwtAddRuleModalRuleType() {
		var modal = document.getElementById('lty_lottery_instant_winners_rule_modal');
		if (!modal) {
			return 'instant';
		}
		var sel = modal.querySelector('.nera-iwt-public-rule-type');
		return sel ? String(sel.value || 'instant') : 'instant';
	}

	// Capture phase: runs before LFW/jQuery delegated handlers so the request never fires.
	document.addEventListener(
		'click',
		function (e) {
			var t = e.target;
			if (!t || typeof t.closest !== 'function') {
				return;
			}
			if (!t.closest('.lty-add-instant-winner-rule')) {
				return;
			}
			if (!window.neraIwtDomHasSequentialTicketPattern()) {
				return;
			}
			var ruleType = neraIwtAddRuleModalRuleType();
			if (ruleType === 'instant') {
				return;
			}
			e.preventDefault();
			e.stopPropagation();
			if (typeof e.stopImmediatePropagation === 'function') {
				e.stopImmediatePropagation();
			}
			var msg =
				typeof window.neraIwtAdmin !== 'undefined' &&
				window.neraIwtAdmin &&
				window.neraIwtAdmin.sequentialTicketConflictMsg
					? window.neraIwtAdmin.sequentialTicketConflictMsg
					: 'Change Ticket Number Pattern from Sequential before using this Rule Type.';
			window.alert(msg);
		},
		true
	);
})();

(function ($) {
	'use strict';

	/**
	 * Last known DOM sequential state (for detecting Sequential → non-Sequential transitions only).
	 *
	 * @type {boolean|null}
	 */
	var neraIwtPrevTicketPatternSequentialDom = null;

	function neraIwtReadDomSequentialTicketPattern() {
		return typeof window.neraIwtDomHasSequentialTicketPattern === 'function'
			? window.neraIwtDomHasSequentialTicketPattern()
			: false;
	}

	/**
	 * LTY blocks "Update" while `.lty-unsaved-instant-winner-rules` is set. After the user fixes
	 * Ticket Number Pattern in the DOM (Sequential → Shuffle/Random), clear that flag so they
	 * can save the product without reloading. Only runs on that transition.
	 *
	 * @return {void}
	 */
	function neraIwtMaybeClearInstantWinnersUnsavedAfterTicketPatternFix() {
		var nowSeq = neraIwtReadDomSequentialTicketPattern();
		if (neraIwtPrevTicketPatternSequentialDom === true && nowSeq === false) {
			if (typeof window.neraIwtClearInstantWinUnsavedIfDomTicketPatternNotSequential === 'function') {
				window.neraIwtClearInstantWinUnsavedIfDomTicketPatternNotSequential();
			}
		}
		neraIwtPrevTicketPatternSequentialDom = nowSeq;
	}

	function neraIwtResetTicketPatternSequentialBaseline() {
		neraIwtPrevTicketPatternSequentialDom = neraIwtReadDomSequentialTicketPattern();
	}

	function neraIwtClampTicketPctValue(raw) {
		var n = parseInt(String(raw === undefined || raw === null ? '0' : raw), 10);
		if (isNaN(n)) {
			n = 0;
		}
		return String(Math.max(0, Math.min(100, n)));
	}

	function neraIwtNormalizeRowParts(type, sched, schedEnd, pct) {
		var p = neraIwtClampTicketPctValue(pct);
		if (type !== 'schedule') {
			return { type: type, sched: sched, schedEnd: schedEnd, pct: p };
		}
		var atv = String(sched || '');
		var endv = String(schedEnd || '');
		if (!atv) {
			endv = '';
		} else if (endv && endv < atv) {
			endv = atv;
		}
		return { type: type, sched: atv, schedEnd: endv, pct: p };
	}

	function neraIwtClampScheduleEndAgainstAt($at, $end) {
		var atv = String($at.val() || '');
		var endv = String($end.val() || '');
		if (!atv || !endv) {
			return;
		}
		if (endv < atv) {
			$end.val(atv);
		}
	}

	/**
	 * Schedule End is disabled until Schedule at has a value; min mirrors Schedule at.
	 */
	function neraIwtSyncScheduleEndControl($wrap) {
		var $at = $wrap.find('.nera-iwt-schedule-at').first();
		var $end = $wrap.find('.nera-iwt-schedule-end').first();
		if (!$at.length || !$end.length) {
			return;
		}
		var v = String($wrap.find('.nera-iwt-public-rule-type').first().val() || 'instant');
		var atVal = String($at.val() || '');
		if (v !== 'schedule' || !atVal) {
			$end.prop('disabled', true).removeAttr('min').val('');
			$wrap.find('.nera-iwt-schedule-end-clear').prop('disabled', true);
			return;
		}
		$end.prop('disabled', false);
		$wrap.find('.nera-iwt-schedule-end-clear').prop('disabled', false);
		$end.attr('min', atVal);
		neraIwtClampScheduleEndAgainstAt($at, $end);
	}

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
		neraIwtSyncScheduleEndControl($wrap);
	}

	function refreshAll() {
		$('.nera-iwt-rule-visibility-fields').each(function () {
			updateVisibilityFields($(this));
		});
	}

	/**
	 * LFW binds click/change on `.lty-instant-winner-rule` with preventDefault(), which breaks
	 * native datetime-local and select UI. Our fields omit that class; mirror LFW dirty-state here.
	 */
	function neraIwtMarkInstantWinnersRulesDirty() {
		$('.lty-save-instant-winners-rules').prop('disabled', false);
		$('.lty-unsaved-instant-winner-rules').val('1');
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
			return neraIwtNormalizeRowParts('instant', '', '', '0');
		}
		var type = String($m.find('.nera-iwt-public-rule-type').first().val() || 'instant');
		var sched = type === 'schedule' ? String($m.find('.nera-iwt-schedule-at').first().val() || '') : '';
		var schedEnd = type === 'schedule' ? String($m.find('.nera-iwt-schedule-end').first().val() || '') : '';
		var pct = type === 'ticket_pct' ? String($m.find('.nera-iwt-ticket-pct').first().val() || '0') : '0';
		return neraIwtNormalizeRowParts(type, sched, schedEnd, pct);
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
			'&instant_winner_rule[nera_schedule_end]=' +
			encodeURIComponent(v.schedEnd || '') +
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
			var schedEnd = type === 'schedule' ? String($wrap.find('.nera-iwt-schedule-end').first().val() || '') : '';
			var pct = type === 'ticket_pct' ? String($wrap.find('.nera-iwt-ticket-pct').first().val() || '0') : '0';
			var pl = neraIwtNormalizeRowParts(type, sched, schedEnd, pct);
			row.nera_public_rule_type = pl.type;
			row.nera_schedule_at = pl.sched;
			row.nera_schedule_end = pl.schedEnd;
			row.nera_ticket_pct = pl.pct;
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
			var schedEnd = type === 'schedule' ? String($wrap.find('.nera-iwt-schedule-end').first().val() || '') : '';
			var pct = type === 'ticket_pct' ? String($wrap.find('.nera-iwt-ticket-pct').first().val() || '0') : '0';
			var pl = neraIwtNormalizeRowParts(type, sched, schedEnd, pct);
			var base = 'instant_winners_rules[' + id + ']';
			parts.push(
				base +
					'[nera_public_rule_type]=' +
					encodeURIComponent(pl.type) +
					'&' +
					base +
					'[nera_schedule_at]=' +
					encodeURIComponent(pl.sched) +
					'&' +
					base +
					'[nera_schedule_end]=' +
					encodeURIComponent(pl.schedEnd) +
					'&' +
					base +
					'[nera_ticket_pct]=' +
					encodeURIComponent(pl.pct)
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
			d.instant_winner_rule.nera_schedule_end = v.schedEnd || '';
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
		neraIwtMarkInstantWinnersRulesDirty();
	});

	$(document).on('input change', '.nera-iwt-schedule-at', function () {
		var $wrap = $(this).closest('.nera-iwt-rule-visibility-fields');
		if ($wrap.length) {
			neraIwtSyncScheduleEndControl($wrap);
		}
		neraIwtMarkInstantWinnersRulesDirty();
	});

	$(document).on('change blur', '.nera-iwt-schedule-end', function () {
		var $wrap = $(this).closest('.nera-iwt-rule-visibility-fields');
		if (!$wrap.length) {
			return;
		}
		neraIwtClampScheduleEndAgainstAt($wrap.find('.nera-iwt-schedule-at').first(), $(this));
		neraIwtMarkInstantWinnersRulesDirty();
	});

	$(document).on('click', '.nera-iwt-schedule-end-clear', function (e) {
		e.preventDefault();
		var $btn = $(this);
		var $end = $btn.closest('.nera-iwt-schedule-end-field').find('.nera-iwt-schedule-end').first();
		if (!$end.length || $end.prop('disabled')) {
			return;
		}
		$end.val('');
		var $wrap = $end.closest('.nera-iwt-rule-visibility-fields');
		if ($wrap.length) {
			neraIwtClampScheduleEndAgainstAt($wrap.find('.nera-iwt-schedule-at').first(), $end);
		}
		$end.trigger('change');
		neraIwtMarkInstantWinnersRulesDirty();
	});

	$(document).on('input change blur', '.nera-iwt-ticket-pct', function () {
		var $el = $(this);
		var c = neraIwtClampTicketPctValue($el.val());
		if (String($el.val()) !== c) {
			$el.val(c);
		}
		neraIwtMarkInstantWinnersRulesDirty();
	});

	$(document).on(
		'change select2:select',
		'#_lty_ticket_number_type, #_lty_tickets_per_tab_display_type, #_lty_ticket_generation_type',
		function () {
			neraIwtMaybeClearInstantWinnersUnsavedAfterTicketPatternFix();
		}
	);

	$(function () {
		neraIwtResetTicketPatternSequentialBaseline();
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
			neraIwtResetTicketPatternSequentialBaseline();
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
