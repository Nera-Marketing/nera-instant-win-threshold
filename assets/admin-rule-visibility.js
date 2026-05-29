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
			var admin = window.neraIwtAdmin;
			var msg = '';
			if (admin) {
				if (ruleType === 'schedule' && admin.sequentialTicketConflictMsgSchedule) {
					msg = admin.sequentialTicketConflictMsgSchedule;
				} else if (ruleType === 'ticket_pct' && admin.sequentialTicketConflictMsgTicketPct) {
					msg = admin.sequentialTicketConflictMsgTicketPct;
				}
			}
			if (!msg) {
				msg =
					admin && admin.sequentialTicketConflictMsgTicketPct
						? admin.sequentialTicketConflictMsgTicketPct
						: 'Change Ticket Number Pattern from Sequential before using this Rule Type.';
			}
			window.alert(msg);
		},
		true
	);
})();

/**
 * Instant-win ticket number must fall within the product pool (numeric tickets only; prefix/suffix patterns are not range-checked in admin).
 */
(function () {
	'use strict';

	/**
	 * @return {number}
	 */
	function neraIwtDomEffectiveTicketStart() {
		var genEl = document.getElementById('_lty_ticket_generation_type');
		var gen = genEl ? String(genEl.value || '') : '';
		if (gen === '2') {
			var sn = document.getElementById('_lty_ticket_start_number');
			var v = sn ? parseInt(String(sn.value || '').trim(), 10) : NaN;
			if (isNaN(v)) {
				return 1;
			}
			return v;
		}
		if (gen === '1') {
			var ntEl = document.getElementById('_lty_ticket_number_type');
			var nt = ntEl ? String(ntEl.value || '') : '';
			if (nt === '2') {
				var seq = document.getElementById('_lty_ticket_sequential_start_number');
				var sv = seq ? parseInt(String(seq.value || '').trim(), 10) : NaN;
				if (isNaN(sv)) {
					return 1;
				}
				return sv;
			}
			if (nt === '3') {
				var sh = document.getElementById('_lty_ticket_shuffled_start_number');
				var hv = sh ? parseInt(String(sh.value || '').trim(), 10) : NaN;
				if (isNaN(hv)) {
					return 1;
				}
				return hv;
			}
		}
		return 1;
	}

	/**
	 * @return {number}
	 */
	function neraIwtDomTicketRangeMax() {
		// wp_localize_script casts every scalar to string before JSON encode, so cap is often "999" not 999.
		var cap = 0;
		if (typeof window.neraIwtAdmin !== 'undefined' && window.neraIwtAdmin) {
			var rawCap = window.neraIwtAdmin.maxTicketNumberCap;
			if (rawCap !== undefined && rawCap !== null && rawCap !== '') {
				var parsedCap = parseInt(String(rawCap), 10);
				if (!isNaN(parsedCap) && parsedCap > 0) {
					cap = parsedCap;
				}
			}
		}
		var start = neraIwtDomEffectiveTicketStart();
		var mtEl = document.getElementById('_lty_maximum_tickets');
		var mt = mtEl ? parseInt(String(mtEl.value || '1'), 10) : 1;
		if (isNaN(mt) || mt < 1) {
			mt = 1;
		}
		if (cap > 0) {
			return Math.max(start, cap);
		}
		return Math.max(start, start + mt - 1);
	}

	/**
	 * @param {number} min
	 * @param {number} max
	 * @return {string}
	 */
	function neraIwtTicketRangeMsg(min, max) {
		var tpl =
			typeof window.neraIwtAdmin !== 'undefined' && window.neraIwtAdmin && window.neraIwtAdmin.ticketRangeInvalidMsg
				? window.neraIwtAdmin.ticketRangeInvalidMsg
				: 'Ticket Number must be between {min} and {max} (inclusive).';
		return String(tpl).replace(/\{min\}/g, String(min)).replace(/\{max\}/g, String(max));
	}

	window.neraIwtDomEffectiveTicketStart = neraIwtDomEffectiveTicketStart;
	window.neraIwtDomTicketRangeMax = neraIwtDomTicketRangeMax;
	window.neraIwtTicketRangeAlertMessage = neraIwtTicketRangeMsg;

	function neraIwtGetActiveAddRuleModalForTicket() {
		var $b = document.querySelector('.blocker.jquery-modal.blocker.current');
		if ($b) {
			var inside = $b.querySelector('#lty_lottery_instant_winners_rule_modal');
			if (inside) {
				return inside;
			}
		}
		return document.getElementById('lty_lottery_instant_winners_rule_modal');
	}

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
			var modal = neraIwtGetActiveAddRuleModalForTicket();
			var inp = modal ? modal.querySelector('.lty-ticket-number') : null;
			var raw = inp ? String(inp.value || '').trim() : '';
			if (!raw || !/^\d+$/.test(raw)) {
				return;
			}
			var n = parseInt(raw, 10);
			var min = neraIwtDomEffectiveTicketStart();
			var max = neraIwtDomTicketRangeMax();
			if (min > max) {
				return;
			}
			if (n < min || n > max) {
				e.preventDefault();
				e.stopPropagation();
				if (typeof e.stopImmediatePropagation === 'function') {
					e.stopImmediatePropagation();
				}
				window.alert(neraIwtTicketRangeMsg(min, max));
			}
		},
		true
	);

	document.addEventListener(
		'click',
		function (e) {
			var t = e.target;
			if (!t || typeof t.closest !== 'function') {
				return;
			}
			if (!t.closest('.lty-save-instant-winners-rules')) {
				return;
			}
			var min = neraIwtDomEffectiveTicketStart();
			var max = neraIwtDomTicketRangeMax();
			if (min > max) {
				return;
			}
			var inputs = document.querySelectorAll('.lty-instant-winners-rules-contents .lty-ticket-number');
			var i;
			for (i = 0; i < inputs.length; i++) {
				var raw = String(inputs[i].value || '').trim();
				if (!raw || !/^\d+$/.test(raw)) {
					continue;
				}
				var n = parseInt(raw, 10);
				if (n < min || n > max) {
					e.preventDefault();
					e.stopPropagation();
					if (typeof e.stopImmediatePropagation === 'function') {
						e.stopImmediatePropagation();
					}
					window.alert(neraIwtTicketRangeMsg(min, max));
					return;
				}
			}
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
		'#_lty_ticket_number_type, #_lty_tickets_per_tab_display_type',
		function () {
			neraIwtMaybeClearInstantWinnersUnsavedAfterTicketPatternFix();
		}
	);

	$(document).on('change select2:select', '#_lty_ticket_generation_type', function () {
		var $el = $(this);
		var admin = window.neraIwtAdmin;
		var v = String($el.val() || '');
		var prev = String($el.attr('data-nera-iwt-prev-gen') || '');
		if (prev === '1' && v !== '1' && admin) {
			var hasConflict = parseInt(String(admin.productHasPctOrScheduleRules || '0'), 10) === 1;
			if (hasConflict) {
				window.alert(String(admin.ticketGenConflictMsg || ''));
				$el.val('1');
				return;
			}
		}
		$el.attr('data-nera-iwt-prev-gen', v);
		neraIwtMaybeClearInstantWinnersUnsavedAfterTicketPatternFix();
	});

	function neraIwtRelocateTicketMaxField() {
		var $mount = $('#nera-iwt-ticket-max-field-mount');
		var $prefix = $('#_lty_ticket_prefix').closest('.form-field');
		if ($mount.length && $prefix.length) {
			$mount.children().insertBefore($prefix);
			$mount.remove();
		}
	}

	function neraIwtAppendInstantWinTicketNote() {
		var admin = window.neraIwtAdmin;
		if (!admin || !admin.instantWinTicketRangeNote) {
			return;
		}
		var $w = $('.lty-instant-winner-rules-note-wrapper');
		if (!$w.length || $w.find('.nera-iwt-instant-win-ticket-range-note').length) {
			return;
		}
		$w.append(
			$('<p></p>')
				.addClass('nera-iwt-instant-win-ticket-range-note')
				.text('* ' + String(admin.instantWinTicketRangeNote))
		);
	}

	// ── Prize search ──────────────────────────────────────────────────────────

	/** Currently active search term (persists across pagination page changes). */
	var neraIwtPrizeSearchTerm = '';

	/**
	 * Inject the search UI after the Save button inside the bulk-actions wrapper.
	 * Idempotent: skips if already injected.
	 */
	function neraIwtInjectPrizeSearchUI() {
		var $wrapper = $('.lty-instant-winners-rules-bulk-actions-wrapper');
		if (!$wrapper.length || $wrapper.find('.nera-iwt-prize-search-wrap').length) {
			return;
		}
		var $ui = $(
			'<span class="nera-iwt-prize-search-wrap">' +
				'<input type="text" class="nera-iwt-prize-search" placeholder="Search ticket or prize…" />' +
				'<button type="button" class="nera-iwt-prize-search-clear" title="Clear search" aria-label="Clear search">&#x2715;</button>' +
			'</span>' +
			'<span class="nera-iwt-prize-search-count"></span>'
		);
		$wrapper.find('.lty-save-instant-winners-rules').after($ui);

		// Restore term if page was changed while a search was active.
		if (neraIwtPrizeSearchTerm) {
			$wrapper.find('.nera-iwt-prize-search').val(neraIwtPrizeSearchTerm);
			neraIwtApplyPrizeFilter(neraIwtPrizeSearchTerm);
		}
	}

	/**
	 * Filter visible rows in the rules table by the given term.
	 * Matches against rule ID or prize message textarea content.
	 *
	 * @param {string} term
	 */
	function neraIwtApplyPrizeFilter(term) {
		var $table = $('.lty-instant-winners-rules-contents');
		if (!$table.length) {
			return;
		}
		var $rows = $table.find('tbody tr');
		var total = $rows.length;

		if (!term) {
			$rows.show();
			neraIwtUpdatePrizeSearchCount(0, 0, false);
			$('.nera-iwt-prize-search-clear').hide();
			return;
		}

		var lc = term.toLowerCase();
		var matched = 0;

		$rows.each(function () {
			var $tr = $(this);
			var ticket  = String($tr.find('.lty-ticket-number').val() || '').toLowerCase();
			var message = String($tr.find('.lty-instant-winner-prize-message').val() || '').toLowerCase();
			var group   = String($tr.find('.lty-instant-winner-prize-group-id option:selected').text() || '').toLowerCase();
			var show = ticket.indexOf(lc) !== -1 || message.indexOf(lc) !== -1 || group.indexOf(lc) !== -1;
			$tr.toggle(show);
			if (show) {
				matched++;
			}
		});

		neraIwtUpdatePrizeSearchCount(matched, total, true);
		$('.nera-iwt-prize-search-clear').show();
	}

	/**
	 * Update the result count badge beside the search input.
	 *
	 * @param {number}  matched
	 * @param {number}  total
	 * @param {boolean} active
	 */
	function neraIwtUpdatePrizeSearchCount(matched, total, active) {
		var $count = $('.nera-iwt-prize-search-count');
		if (!active) {
			$count.text('').hide();
			return;
		}
		$count.text(matched + ' / ' + total).show();
	}

	// Debounce helper.
	var neraIwtSearchTimer = null;

	$(document).on('input', '.nera-iwt-prize-search', function () {
		var term = String($(this).val() || '').trim();
		neraIwtPrizeSearchTerm = term;
		if (neraIwtSearchTimer) {
			clearTimeout(neraIwtSearchTimer);
		}
		neraIwtSearchTimer = setTimeout(function () {
			neraIwtApplyPrizeFilter(neraIwtPrizeSearchTerm);
			if (neraIwtPrizeSearchTerm) {
				$('.nera-iwt-prize-search-clear').show();
			} else {
				$('.nera-iwt-prize-search-clear').hide();
			}
		}, 200);
	});

	$(document).on('click', '.nera-iwt-prize-search-clear', function () {
		neraIwtPrizeSearchTerm = '';
		$('.nera-iwt-prize-search').val('');
		neraIwtApplyPrizeFilter('');
		$('.nera-iwt-prize-search-clear').hide();
	});

	// ── End prize search ───────────────────────────────────────────────────────

	$(function () {
		neraIwtResetTicketPatternSequentialBaseline();
		$('#_lty_ticket_generation_type').each(function () {
			var $g = $(this);
			if ($g.attr('data-nera-iwt-prev-gen') === undefined) {
				$g.attr('data-nera-iwt-prev-gen', String($g.val() || ''));
			}
		});
		neraIwtRelocateTicketMaxField();
		neraIwtAppendInstantWinTicketNote();
		movePopupFieldsToTop();
		scheduleColumnReorder();
		refreshAll();
		bindNeraIwtModalOpenHandlers();
		neraIwtInjectPrizeSearchUI();
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
	$(document).ajaxComplete(function (_event, _xhr, settings) {
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
			neraIwtInjectPrizeSearchUI();
			if (neraIwtPrizeSearchTerm) {
				neraIwtApplyPrizeFilter(neraIwtPrizeSearchTerm);
			}
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
