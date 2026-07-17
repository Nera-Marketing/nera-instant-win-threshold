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

	// Held-back prizes carry NO ticket number until activation (Option B), but LFW's Add-rule
	// handler hard-blocks an empty "Ticket Number". When Held-back is the chosen rule type, drop a
	// throwaway value into the field so LFW's client-side "not empty" guard passes; the outgoing
	// payload is then forced back to an empty number for held rules (see neraIwtMerge…Payload), so
	// the prize is always created with no number and the placeholder never reaches the server.
	// Registered after the range-check handler above, which has already seen the still-empty field.
	document.addEventListener(
		'click',
		function (e) {
			var t = e.target;
			if (!t || typeof t.closest !== 'function') {
				return;
			}
			var addBtn = t.closest('.lty-add-instant-winner-rule');
			if (!addBtn) {
				return;
			}
			// Resolve the modal from the clicked button itself (like LFW's own handler), NOT a
			// global lookup: several stale #lty_lottery_instant_winners_rule_modal nodes can exist
			// in the DOM (observed #modals=3) and a global finder may target the wrong (inactive) one.
			var modal = addBtn.closest('.lty-lottery-instant-winners-rule-modal-wrapper')
				|| addBtn.closest('#lty_lottery_instant_winners_rule_modal')
				|| document;
			var sel = modal.querySelector('.nera-iwt-public-rule-type');
			if (!sel || 'held' !== String(sel.value || '')) {
				return;
			}
			var ticket = modal.querySelector('.lty-ticket-number');
			if (ticket && '' === String(ticket.value || '').trim()) {
				ticket.value = '1'; // placeholder only — blanked in the payload for held rules.
				window.setTimeout(function () {
					ticket.value = '';
				}, 0);
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
		$wrap.toggleClass('nera-iwt--show-held', v === 'held');
		// The action icons live in the Action column (moved out of .nera-iwt-held-controls),
		// so gate their held-ness here by class instead of the .nera-iwt--show-held ancestor.
		$wrap.closest('tr').find('.nera-iwt-held-actions').toggleClass('nera-iwt-held-actions--held', v === 'held');
		// Drop any inline display (e.g. from other scripts) so class-based CSS wins.
		$wrap.find('.nera-iwt-row-schedule, .nera-iwt-row-ticket-pct').css('display', '');
		// Held-back: the winning number is managed by Activate / Deactivate, so lock LFW's own
		// "Ticket Number" input on this row — it must not be edited or mistaken for the override
		// field (that lives beside the Activate button).
		var $row = $wrap.closest('tr');
		if ($row.length) {
			var $lfwTicket = $row.find('.lty-ticket-number').first();
			if ($lfwTicket.length) {
				$lfwTicket.prop('readonly', v === 'held').toggleClass('nera-iwt-lfw-ticket-locked', v === 'held');
				if (v === 'held') {
					// Held & not yet activated → the ticket number must be blank (Option B).
					// An already-activated held prize keeps its assigned number.
					var $hc = $row.find('.nera-iwt-held-controls').first();
					if (!($hc.length && 'active' === $hc.attr('data-held-state'))) {
						$lfwTicket.val('');
					}
				}
			}
		}
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
			if ('held' === v.type) {
				// Option B: a held prize is created with NO number (assigned later on activation).
				// Empty collides with nothing (see the empty-number override in ticket-generation-override).
				d.instant_winner_rule.ticket_number = '';
			}
			return;
		}

		if (typeof d === 'string' && neraIwtIsLtyAddInstantWinnerRuleAjaxData(d)) {
			var out = neraIwtAppendAddRuleVisibilityQuerySegment(d, v);
			if ('held' === v.type) {
				// Append an empty ticket_number to override any earlier value (PHP: last one wins).
				out += '&instant_winner_rule[ticket_number]=';
			}
			options.data = out;
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
		neraIwtAppendStatusLegend($w);
	}

	/**
	 * Append the prize status-colour legend below the ticket-range note.
	 *
	 * @param {jQuery} $w Note wrapper.
	 */
	function neraIwtAppendStatusLegend($w) {
		if (!$w || !$w.length || $w.find('.nera-iwt-status-legend').length) {
			return;
		}
		// Single merged status legend — one coloured dot beside each prize ID (all rule types).
		var items = [
			{ s: 'locked', t: 'Not available yet — not winnable, no winner. A held prize that isn’t activated, or a %/schedule prize whose gate hasn’t been reached.' },
			{ s: 'available', t: 'Available — winnable now, no winner yet (instant, a live held prize, % reached, or inside a schedule window).' },
			{ s: 'won', t: 'Won — a winner has been assigned.' },
			{ s: 'unplaceable', t: 'Needs attention — a held prize can’t be placed; no unsold number is left to hold it.' }
		];
		var $legend = $('<div class="nera-iwt-status-legend"></div>');
		$legend.append(
			$('<p class="nera-iwt-status-legend-title"></p>').text('Prize status (dot beside each ID):')
		);
		var $ul = $('<ul class="nera-iwt-status-legend-list"></ul>');
		$.each(items, function (i, item) {
			var $li = $('<li></li>');
			$li.append($('<span class="nera-iwt-status-dot" aria-hidden="true"></span>').addClass('nera-iwt-dot-' + item.s));
			$li.append(document.createTextNode(' ' + item.t));
			$ul.append($li);
		});
		$legend.append($ul);
		$w.append($legend);
	}

	// ── Prize search ──────────────────────────────────────────────────────────

	/** Currently active search term (persists across pagination page changes). */
	var neraIwtPrizeSearchTerm = '';

	/** Currently active status-filter slug ('' = all). Persists across pagination. */
	var neraIwtPrizeStatusFilter = '';

	/**
	 * Inject the search UI after the Save button inside the bulk-actions wrapper.
	 * Idempotent: skips if already injected.
	 */
	function neraIwtInjectPrizeSearchUI() {
		var $wrapper = $('.lty-instant-winners-rules-bulk-actions-wrapper');
		if (!$wrapper.length || $wrapper.find('.nera-iwt-prize-search-wrap').length) {
			return;
		}
		// Unified status filter: values match the merged dot status (data-nera-status),
		// covering every rule type. Labels mirror the single status legend.
		var statusOpts = '<option value="">All statuses</option>' +
			'<option value="locked">Not available yet</option>' +
			'<option value="available">Available</option>' +
			'<option value="won">Won</option>' +
			'<option value="unplaceable">Needs attention</option>';
		var $ui = $(
			'<span class="nera-iwt-prize-status-wrap">' +
				'<select class="nera-iwt-prize-status-filter" aria-label="Filter by prize status">' + statusOpts + '</select>' +
			'</span>' +
			'<span class="nera-iwt-prize-search-wrap">' +
				'<input type="text" class="nera-iwt-prize-search" placeholder="Search ticket or prize…" />' +
				'<button type="button" class="nera-iwt-prize-search-clear" title="Clear search" aria-label="Clear search">&#x2715;</button>' +
			'</span>' +
			'<span class="nera-iwt-prize-search-count"></span>'
		);
		$wrapper.find('.lty-save-instant-winners-rules').after($ui);

		// Restore term/status if the page was changed while a filter was active.
		if (neraIwtPrizeStatusFilter) {
			$wrapper.find('.nera-iwt-prize-status-filter').val(neraIwtPrizeStatusFilter);
		}
		if (neraIwtPrizeSearchTerm) {
			$wrapper.find('.nera-iwt-prize-search').val(neraIwtPrizeSearchTerm);
		}
		if (neraIwtPrizeSearchTerm || neraIwtPrizeStatusFilter) {
			neraIwtApplyPrizeFilter(neraIwtPrizeSearchTerm);
		}
	}

	// ----- "Hold all in group" toolbar -------------------------------------
	function neraIwtHtmlEscForToolbar(s) {
		return String(s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}

	function neraIwtRefreshHoldGroupOptions() {
		var $sel = $('.nera-iwt-hold-group-select');
		if (!$sel.length) {
			return;
		}
		var seen = {};
		var opts = '';
		$('.lty-instant-winners-rules-contents .lty-instant-winner-prize-group-id').each(function () {
			var v = String($(this).val() || '');
			if (!v || seen[v]) {
				return;
			}
			seen[v] = 1;
			var t = String($(this).find('option:selected').text() || v);
			opts += '<option value="' + neraIwtHtmlEscForToolbar(v) + '">' + neraIwtHtmlEscForToolbar(t) + '</option>';
		});
		var prev = String($sel.val() || '');
		$sel.html(opts);
		if (prev && seen[prev]) {
			$sel.val(prev);
		}
	}

	function neraIwtInjectHoldGroupUI() {
		var admin = window.neraIwtAdmin || {};
		if (!admin.heldEnabled) {
			return; // Held-back type disabled → no group-hold control.
		}
		if ($('.nera-iwt-hold-group-wrap').length) {
			return; // already injected
		}
		// Preferred home: its own block right below the merged "Prize status" legend.
		// Fall back to beside Save if the legend isn't in the DOM yet (renders reliably).
		var $legend = $('.nera-iwt-status-legend').first();
		var belowLegend = $legend.length > 0;
		var $anchor = belowLegend
			? $legend
			: $('.lty-instant-winners-rules-bulk-actions-wrapper .lty-save-instant-winners-rules').first();
		if (!$anchor.length) {
			return;
		}
		var $ui = $(
			'<div class="nera-iwt-hold-group-wrap' + (belowLegend ? ' nera-iwt-hold-group-wrap--block' : '') + '">' +
				'<select class="nera-iwt-hold-group-select"></select>' +
				'<button type="button" class="button nera-iwt-hold-group-apply">' +
					neraIwtHtmlEscForToolbar(admin.holdGroupButton || 'Hold all in group') +
				'</button>' +
				'<span class="nera-iwt-hold-group-msg"></span>' +
			'</div>'
		);
		$anchor.after($ui);
		neraIwtRefreshHoldGroupOptions();
	}

	// ----- "Held-back settings" block (auto% + warn%) ----------------------
	function neraIwtInjectHeldSettings() {
		var admin = window.neraIwtAdmin || {};
		if (!admin.heldEnabled) {
			return;
		}
		var $wrap = $('.lty-instant-winners-rules-wrapper').first();
		if (!$wrap.length || $wrap.find('.nera-iwt-held-settings').length) {
			return;
		}
		var s = admin.heldSettings || {};
		var autoDef = parseInt(s.autoDefault, 10) || 90;
		var warnDef = Math.max(1, autoDef - 10);
		var $ui = $(
			'<div class="nera-iwt-held-settings">' +
				'<strong>' + neraIwtHtmlEscForToolbar(s.title || 'Held-back settings') + '</strong>' +
				'<label>' + neraIwtHtmlEscForToolbar(s.warnLabel || 'Warn at % sold') +
					' <input type="number" min="1" max="100" step="1" class="nera-iwt-warn-pct" name="nera_iwt_held_warn_pct" value="' +
					neraIwtHtmlEscForToolbar(s.warnStored || '') + '" placeholder="' + warnDef + '" /></label>' +
				'<label>' + neraIwtHtmlEscForToolbar(s.autoLabel || 'Auto-activate at % sold') +
					' <input type="number" min="1" max="100" step="1" class="nera-iwt-auto-pct" name="nera_iwt_held_autotrigger_pct" value="' +
					neraIwtHtmlEscForToolbar(s.autoStored || '') + '" placeholder="' + autoDef + '" /></label>' +
				'<span class="description">' + neraIwtHtmlEscForToolbar(s.note || '') + '</span>' +
			'</div>'
		);
		$wrap.prepend($ui);
	}

	$(document).on('mousedown focus', '.nera-iwt-hold-group-select', function () {
		neraIwtRefreshHoldGroupOptions();
	});

	$(document).on('click', '.nera-iwt-hold-group-apply', function (e) {
		e.preventDefault();
		var groupId = String($('.nera-iwt-hold-group-select').val() || '');
		var $msg = $('.nera-iwt-hold-group-msg');
		if (!groupId) {
			return;
		}
		var admin = window.neraIwtAdmin || {};
		var groupLabel = String($('.nera-iwt-hold-group-select option:selected').text() || groupId);
		var confirmMsg = (admin.holdGroupConfirm || 'Set every prize in group “%s” to Held-back? You can still change individual prizes afterwards, then Save.').replace('%s', groupLabel);
		if (!window.confirm(confirmMsg)) {
			return;
		}
		var count = 0;
		$('.lty-instant-winners-rules-contents tr').each(function () {
			var $tr = $(this);
			var $g = $tr.find('.lty-instant-winner-prize-group-id').first();
			if (!$g.length || String($g.val() || '') !== groupId) {
				return;
			}
			var $type = $tr.find('.nera-iwt-public-rule-type').first();
			if (!$type.length || !$type.find('option[value="held"]').length) {
				return;
			}
			if ('held' !== String($type.val() || '')) {
				$type.val('held').trigger('change'); // change handler shows held UI + marks dirty
			}
			count++;
		});
		var tmpl = admin.holdGroupResult || '%d prize(s) set to Held-back — remember to Save.';
		$msg.text(tmpl.replace('%d', String(count)));
	});

	/**
	 * Filter visible rows in the rules table by the given term.
	 * Matches against rule ID or prize message textarea content.
	 *
	 * @param {string} term
	 */
	/**
	 * A row's unified status for the filter — now a single source of truth: the merged
	 * dot status on the rule-type cell (data-nera-status), kept in sync by transitions.
	 *
	 * @param {jQuery} $tr
	 * @return {string} 'locked' | 'available' | 'won' | 'unplaceable' | '' when unknown.
	 */
	function neraIwtRowStatusSlug($tr) {
		var $cell = $tr.find('.nera-iwt-public-rule-type-column[data-nera-status]').first();
		return $cell.length ? String($cell.attr('data-nera-status') || '') : '';
	}

	/**
	 * Filter rows by the search term AND the status dropdown (both optional; combined with AND).
	 * The term is passed in; the status filter is read from neraIwtPrizeStatusFilter.
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
		var status = neraIwtPrizeStatusFilter;
		var lc = String(term || '').toLowerCase();
		var hasTerm = lc.length > 0;
		var hasStatus = status.length > 0;

		if (!hasTerm && !hasStatus) {
			$rows.show();
			neraIwtUpdatePrizeSearchCount(0, 0, false);
			$('.nera-iwt-prize-search-clear').hide();
			return;
		}

		var matched = 0;

		$rows.each(function () {
			var $tr = $(this);
			var show = true;
			if (hasTerm) {
				var ticket  = String($tr.find('.lty-ticket-number').val() || '').toLowerCase();
				var message = String($tr.find('.lty-instant-winner-prize-message').val() || '').toLowerCase();
				var group   = String($tr.find('.lty-instant-winner-prize-group-id option:selected').text() || '').toLowerCase();
				show = ticket.indexOf(lc) !== -1 || message.indexOf(lc) !== -1 || group.indexOf(lc) !== -1;
			}
			if (show && hasStatus) {
				show = neraIwtRowStatusSlug($tr) === status;
			}
			$tr.toggle(show);
			if (show) {
				matched++;
			}
		});

		neraIwtUpdatePrizeSearchCount(matched, total, true);
		$('.nera-iwt-prize-search-clear').toggle(hasTerm);
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
		neraIwtApplyPrizeFilter(''); // still honours the status filter if one is set
		$('.nera-iwt-prize-search-clear').hide();
	});

	$(document).on('change', '.nera-iwt-prize-status-filter', function () {
		neraIwtPrizeStatusFilter = String($(this).val() || '');
		neraIwtApplyPrizeFilter(neraIwtPrizeSearchTerm);
	});

	// ── End prize search ───────────────────────────────────────────────────────

	// ── Row status colours ──────────────────────────────────────────────────────

	/**
	 * Short label for the status dot tooltip.
	 *
	 * @param {string} status
	 * @return {string}
	 */
	function neraIwtStatusLabel(status) {
		if (status === 'unplaceable') {
			return 'Needs attention';
		}
		if (status === 'won') {
			return 'Won';
		}
		if (status === 'available') {
			return 'Available';
		}
		return 'Not available yet';
	}

	/**
	 * Place a small status dot beside each row's "ID: xxxx" text, coloured from the
	 * server-rendered data-nera-status on the rule-type cell:
	 *   locked => red, available => green, won => orange.
	 */
	function neraIwtApplyRowStatusColors() {
		var statuses = ['locked', 'available', 'won', 'unplaceable'];
		$('.lty-instant-winners-rules-contents tbody tr').each(function () {
			var $tr  = $(this);
			var $cell = $tr.find('.nera-iwt-public-rule-type-column[data-nera-status]').first();
			if (!$cell.length) {
				return;
			}
			var status = String($cell.attr('data-nera-status') || '');
			if (statuses.indexOf(status) === -1) {
				return;
			}

			// Anchor the dot to the "ID:" <small> only (not the order-number line below it).
			var $anchor = $tr.find('td').first().find('small').not('.nera-iwt-rule-won-order').first();
			if (!$anchor.length) {
				$anchor = $tr.find('td').first();
			}
			if (!$anchor.length) {
				return;
			}

			var $dot = $anchor.find('.nera-iwt-status-dot').first();
			if (!$dot.length) {
				$dot = $('<span class="nera-iwt-status-dot" aria-hidden="true"></span>');
				$anchor.prepend($dot);
			}
			$dot
				.removeClass('nera-iwt-dot-locked nera-iwt-dot-available nera-iwt-dot-won nera-iwt-dot-unplaceable')
				.addClass('nera-iwt-dot-' + status)
				.attr('title', String($cell.attr('data-nera-tip') || '') || neraIwtStatusLabel(status));
		});
	}

	// ── End row status colours ───────────────────────────────────────────────────

	/**
	 * Move each row's held action icons (.nera-iwt-held-actions) out of the Rule-type cell and
	 * into LFW's Action column, before the Remove icon. Idempotent. Visibility is handled by CSS
	 * (.nera-iwt-held-actions--held + data-held-badge), so relocating non-held rows is harmless.
	 */
	function neraIwtRelocateHeldActions() {
		$('.lty-instant-winners-rules-contents tbody tr').each(function () {
			var $tr = $(this);
			var $actions = $tr.find('.nera-iwt-held-actions').first();
			if (!$actions.length || $actions.closest('.lty-instant-winner-action-column').length) {
				return; // nothing to move, or already relocated
			}
			var $col = $tr.find('.lty-instant-winner-action-column').first();
			if ($col.length) {
				$col.prepend($actions); // before LFW's Remove icon
			}
		});
	}

	/**
	 * Wrap the wide rules table in a horizontal-scroll container so small screens can scroll
	 * to the pinned Action column instead of clipping it. Idempotent. LFW replaces the whole
	 * .lty-instant-winners-rules-wrapper on pagination, so this is re-applied there.
	 */
	function neraIwtWrapRulesTableForScroll() {
		var $table = $('.lty-instant-winners-rules-contents').first();
		if (!$table.length || $table.parent().hasClass('nera-iwt-rules-scroll')) {
			return;
		}
		$table.wrap('<div class="nera-iwt-rules-scroll"></div>');
	}

	$(function () {
		neraIwtWrapRulesTableForScroll(); // wrap early — independent of the steps below
		neraIwtResetTicketPatternSequentialBaseline();
		$('#_lty_ticket_generation_type').each(function () {
			var $g = $(this);
			if ($g.attr('data-nera-iwt-prev-gen') === undefined) {
				$g.attr('data-nera-iwt-prev-gen', String($g.val() || ''));
			}
		});
		neraIwtAppendInstantWinTicketNote();
		movePopupFieldsToTop();
		scheduleColumnReorder();
		refreshAll();
		bindNeraIwtModalOpenHandlers();
		neraIwtInjectPrizeSearchUI();
		neraIwtInjectHoldGroupUI();
		neraIwtInjectHeldSettings();
		neraIwtApplyRowStatusColors();
		neraIwtRelocateHeldActions();
	});

	// Re-bind after Add New Rule link (modal node may have been replaced).
	$(document).on('click', 'a[href="#lty_lottery_instant_winners_rule_modal"]', function () {
		neraIwtClearAddRuleError(); // start each Add New Rule with a clean modal
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
			neraIwtWrapRulesTableForScroll(); // re-wrap early (LFW replaced the whole wrapper)
			movePopupFieldsToTop();
			scheduleColumnReorder();
			refreshAll();
			bindNeraIwtModalOpenHandlers();
			neraIwtInjectPrizeSearchUI();
			neraIwtAppendInstantWinTicketNote(); // re-add the note + status legend (wiped by the list refresh) BEFORE hold-group, which anchors below the legend
			neraIwtInjectHoldGroupUI();
			neraIwtInjectHeldSettings();
			neraIwtApplyRowStatusColors();
			neraIwtRelocateHeldActions();
			if (neraIwtPrizeSearchTerm || neraIwtPrizeStatusFilter) {
				neraIwtApplyPrizeFilter(neraIwtPrizeSearchTerm);
			}
		}, 0);
	});

	// Surface Add-rule SERVER errors (duplicate ticket, out-of-range, invalid) INLINE in the modal.
	// LFW returns these correctly but only shows them via window.alert() with no .fail() handler —
	// easy to miss / swallowed by automation — so a duplicate looked like "Create does nothing".
	function neraIwtAddRuleModalEl() {
		var b = document.querySelector('.blocker.jquery-modal.blocker.current');
		return ( b && b.querySelector('#lty_lottery_instant_winners_rule_modal') ) || document.getElementById('lty_lottery_instant_winners_rule_modal');
	}
	function neraIwtClearAddRuleError() {
		var els = document.querySelectorAll('.nera-iwt-add-error');
		for ( var i = 0; i < els.length; i++ ) { if ( els[i].parentNode ) { els[i].parentNode.removeChild( els[i] ); } }
	}
	function neraIwtShowAddRuleError( msg ) {
		var modal = neraIwtAddRuleModalEl();
		if ( ! modal ) { window.alert( msg ); return; }
		neraIwtClearAddRuleError();
		var box = document.createElement( 'div' );
		box.className = 'nera-iwt-add-error';
		box.setAttribute( 'role', 'alert' );
		box.textContent = msg;
		modal.insertBefore( box, modal.firstChild );
	}
	$(document).ajaxComplete(function (_event, xhr, settings) {
		if ( ! settings || ! settings.data || ! neraIwtIsLtyAddInstantWinnerRuleAjaxData( settings.data ) ) {
			return;
		}
		var resp = xhr && xhr.responseJSON;
		if ( ( resp === undefined || resp === null ) && xhr && xhr.responseText ) {
			try { resp = JSON.parse( xhr.responseText ); } catch ( e ) { resp = null; }
		}
		if ( xhr && 200 === xhr.status && resp && true === resp.success ) {
			neraIwtClearAddRuleError(); // LFW closes the modal on success
			return;
		}
		var msg = '';
		if ( resp && resp.data ) {
			msg = ( typeof resp.data === 'string' ) ? resp.data : ( resp.data.error || '' );
		}
		if ( ! msg ) {
			msg = ( xhr && xhr.status && 200 !== xhr.status )
				? ( 'Could not add the prize (server error ' + xhr.status + '). Check the ticket number and try again.' )
				: 'Could not add the prize. Check the ticket number and try again.';
		}
		neraIwtShowAddRuleError( msg );
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
			neraIwtClearAddRuleError(); // fresh submit — drop any previous inline error
			var v = neraIwtReadVisibilityFromAddRuleModal(neraIwtGetActiveAddRuleModal());
			settings.data = neraIwtAppendAddRuleVisibilityQuerySegment(settings.data, v);
			return;
		}
		if (neraIwtIsLtySaveInstantWinnersRulesAjaxData(settings.data)) {
			settings.data = neraIwtAppendBulkSaveVisibilityQuerySegment(settings.data);
		}
	});

	// After a successful bulk Save, LFW leaves the typed ticket numbers in the inputs — but the
	// server normalises them (e.g. alphabet "12" → "B2") and only a page refresh showed the
	// canonical form. Re-render the current page (via LFW's own pagination) so the canonical values
	// appear immediately. (Add New Rule already re-renders; only bulk Save didn't.)
	$(document).ajaxComplete(function (_event, xhr, settings) {
		if (!settings || !settings.data || typeof settings.data !== 'string') {
			return;
		}
		if (!neraIwtIsLtySaveInstantWinnersRulesAjaxData(settings.data)) {
			return;
		}
		if (!xhr || !xhr.responseJSON || !xhr.responseJSON.success) {
			return; // save failed — leave the rows so the admin can fix them
		}
		var $page = $('.lty-lottery-instant-winners-rules-pagination-wrapper .lty-current-page').first();
		if ($page.length) {
			$page.trigger('change'); // LFW re-renders the current page from the DB (canonical numbers)
		}
	});

	// ---------------------------------------------------------------------
	// Held-back prize activation / deactivation (admin table controls).
	// ---------------------------------------------------------------------
	var neraIwtHeldAdmin = window.neraIwtAdmin || {};
	var neraIwtHeldAjaxUrl = (typeof window.ajaxurl === 'string' && window.ajaxurl) || neraIwtHeldAdmin.ajaxUrl || '';

	function neraIwtHeldPost(action, ruleId, ticket, $btn, onDone, extra) {
		if (!neraIwtHeldAjaxUrl || !neraIwtHeldAdmin.activateHeldNonce || !ruleId) {
			return;
		}
		$btn.prop('disabled', true);
		var data = {
			action: action,
			nonce: neraIwtHeldAdmin.activateHeldNonce,
			rule_id: ruleId,
			ticket_number: ticket || ''
		};
		if (extra && typeof extra === 'object') {
			$.each(extra, function (k, v) { data[k] = v; });
		}
		$.post(neraIwtHeldAjaxUrl, data).done(function (resp) {
			if (resp && resp.success) {
				onDone(resp.data || {});
			} else {
				window.alert((resp && resp.data && resp.data.error) || neraIwtHeldAdmin.heldGenericError || 'Action failed.');
			}
		}).fail(function () {
			window.alert(neraIwtHeldAdmin.heldGenericError || 'Action failed.');
		}).always(function () {
			$btn.prop('disabled', false);
		});
	}

	/**
	 * Non-blocking success toast (bottom-right, auto-dismiss). Reassures the admin that held-prize
	 * actions are saved immediately — no manual "Save" needed.
	 *
	 * @param {string} message
	 */
	function neraIwtToast(message) {
		var $t = $('<div class="nera-iwt-toast" role="status" aria-live="polite"></div>').text(String(message || ''));
		$('body').append($t);
		window.setTimeout(function () { $t.addClass('is-visible'); }, 10);

		var hideTimer = null;
		function neraToastRemove() {
			window.clearTimeout(hideTimer);
			$t.removeClass('is-visible');
			window.setTimeout(function () { $t.remove(); }, 300);
		}
		function neraToastScheduleHide(delay) {
			window.clearTimeout(hideTimer);
			hideTimer = window.setTimeout(neraToastRemove, delay);
		}
		neraToastScheduleHide(4000);
		// Hovering (or focusing) pauses the auto-dismiss so the admin can read it; it dismisses
		// shortly after the pointer leaves. A click dismisses it right away.
		$t.on('mouseenter focusin', function () { window.clearTimeout(hideTimer); });
		$t.on('mouseleave focusout', function () { neraToastScheduleHide(1500); });
		$t.on('click', neraToastRemove);
	}

	function neraIwtHeldSetStatus($controls, status) {
		var $cell = $controls.closest('.nera-iwt-public-rule-type-column');
		if ($cell.length) {
			$cell.attr('data-nera-status', status);
			// Refresh the dot tooltip to the generic label for the new state (the detailed
			// server-rendered reason no longer applies once the admin changes the state live).
			$cell.attr('data-nera-tip', neraIwtStatusLabel(status));
			if (typeof neraIwtApplyRowStatusColors === 'function') {
				neraIwtApplyRowStatusColors();
			}
		}
	}

	// Open the "Set the winning ticket" modal for a held prize (Pending/Unplaceable → fresh activate).
	$(document).on('click', '.nera-iwt-open-activate', function (e) {
		e.preventDefault();
		neraIwtGridEditMode = false;
		$neraIwtGridTarget = $(this).closest('tr').find('.nera-iwt-held-controls').first();
		neraIwtGridModalEl().removeAttr('hidden');
		$neraIwtGridModal.find('.nera-iwt-modal-ticket').val('');
		neraIwtLoadGridIntoModal(0);
	});

	// "Edit number" on a Live prize — same picker, but re-assigns the number without deactivating.
	$(document).on('click', '.nera-iwt-held-edit', function (e) {
		e.preventDefault();
		neraIwtGridEditMode = true;
		$neraIwtGridTarget = $(this).closest('tr').find('.nera-iwt-held-controls').first();
		var current = String($neraIwtGridTarget.closest('tr').find('.lty-ticket-number').first().val() || '').replace(/^\s+|\s+$/g, '');
		neraIwtGridModalEl().removeAttr('hidden');
		$neraIwtGridModal.find('.nera-iwt-modal-ticket').val(current);
		neraIwtLoadGridIntoModal(0);
	});

	// ----- Activation modal (ticket field + grid picker) -------------------
	function neraIwtEsc(s) {
		return String(s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}

	function neraIwtRenderGrid($container, data) {
		var html = '<div class="nera-iwt-grid-tabs">';
		var i;
		for (i = 0; i < data.tabCount; i++) {
			html += '<button type="button" class="button button-small nera-iwt-grid-tab' + (i === data.tab ? ' is-active' : '') +
				'" data-tab="' + i + '">' + neraIwtEsc(data.labels[i] || ('Tab ' + (i + 1))) + '</button>';
		}
		html += '</div><div class="nera-iwt-grid-cells">';
		(data.tickets || []).forEach(function (t) {
			html += '<button type="button" class="button button-small nera-iwt-grid-cell' + (t.sold ? ' is-sold' : '') +
				'" data-n="' + neraIwtEsc(t.n) + '"' + (t.sold ? ' disabled' : '') + '>' + neraIwtEsc(t.n) + '</button>';
		});
		html += '</div>';
		$container.html(html);
	}

	var $neraIwtGridModal = null;
	var $neraIwtGridTarget = null; // the held-controls that opened the modal
	var neraIwtGridEditMode = false; // true when opened via "Edit number" on a Live prize

	function neraIwtCloseGridModal() {
		if ($neraIwtGridModal) {
			$neraIwtGridModal.attr('hidden', 'hidden');
		}
		$neraIwtGridTarget = null;
	}

	function neraIwtLoadGridIntoModal(tab) {
		if (!$neraIwtGridTarget || !$neraIwtGridModal) {
			return;
		}
		var ruleId = $neraIwtGridTarget.data('rule-id');
		var $body = $neraIwtGridModal.find('.nera-iwt-grid-holder').first();
		if (!neraIwtHeldAjaxUrl || !neraIwtHeldAdmin.activateHeldNonce || !ruleId) {
			return;
		}
		$body.html('<span class="description">' + neraIwtEsc(neraIwtHeldAdmin.gridLoading || 'Loading…') + '</span>');
		$.post(neraIwtHeldAjaxUrl, {
			action: 'nera_iwt_held_grid',
			nonce: neraIwtHeldAdmin.activateHeldNonce,
			rule_id: ruleId,
			tab: tab || 0
		}).done(function (resp) {
			if (resp && resp.success) {
				neraIwtRenderGrid($body, resp.data || {});
			} else {
				$body.html('<span class="description">' + neraIwtEsc((resp && resp.data && resp.data.error) || neraIwtHeldAdmin.heldGenericError || '') + '</span>');
			}
		}).fail(function () {
			$body.html('<span class="description">' + neraIwtEsc(neraIwtHeldAdmin.heldGenericError || 'Error') + '</span>');
		});
	}

	function neraIwtGridModalEl() {
		if ($neraIwtGridModal) {
			return $neraIwtGridModal;
		}
		$neraIwtGridModal = $(
			'<div class="nera-iwt-grid-modal-overlay" hidden>' +
				'<div class="nera-iwt-grid-modal" role="dialog" aria-modal="true">' +
					'<div class="nera-iwt-grid-modal-head">' +
						'<span class="nera-iwt-grid-modal-title">' + neraIwtEsc(neraIwtHeldAdmin.gridTitle || 'Set the winning ticket') + '</span>' +
						'<button type="button" class="nera-iwt-grid-modal-close" aria-label="Close">×</button>' +
					'</div>' +
					'<div class="nera-iwt-grid-modal-body">' +
						'<p class="nera-iwt-modal-field">' +
							'<label class="nera-iwt-modal-label">' + neraIwtEsc(neraIwtHeldAdmin.modalTicketLabel || 'Winning ticket number') + '</label>' +
							'<input type="text" class="nera-iwt-modal-ticket" autocomplete="off" placeholder="' + neraIwtEsc(neraIwtHeldAdmin.modalTicketPlaceholder || 'leave blank = system picks') + '" />' +
							'<span class="description">' + neraIwtEsc(neraIwtHeldAdmin.modalHint || 'Leave blank to let the system pick a definitely-unsold number.') + '</span>' +
						'</p>' +
						'<p class="nera-iwt-modal-grid-label">' + neraIwtEsc(neraIwtHeldAdmin.modalGridLabel || 'Or pick from the grid:') + '</p>' +
						'<div class="nera-iwt-grid-holder"></div>' +
					'</div>' +
					'<div class="nera-iwt-grid-modal-foot">' +
						'<button type="button" class="button nera-iwt-grid-modal-close">' + neraIwtEsc(neraIwtHeldAdmin.modalCancel || 'Cancel') + '</button>' +
						'<button type="button" class="button button-primary nera-iwt-modal-activate">' + neraIwtEsc(neraIwtHeldAdmin.modalActivate || 'Activate') + '</button>' +
					'</div>' +
				'</div>' +
			'</div>'
		);
		$('body').append($neraIwtGridModal);

		$neraIwtGridModal.on('click', '.nera-iwt-grid-modal-close', function (e) {
			e.preventDefault();
			neraIwtCloseGridModal();
		});
		$neraIwtGridModal.on('click', function (e) {
			if (e.target === this) { // click on the dim backdrop
				neraIwtCloseGridModal();
			}
		});
		$neraIwtGridModal.on('click', '.nera-iwt-grid-tab', function (e) {
			e.preventDefault();
			neraIwtLoadGridIntoModal(parseInt($(this).data('tab'), 10) || 0);
		});
		// Pick a cell → fill the modal's ticket field + highlight; admin confirms with Activate.
		$neraIwtGridModal.on('click', '.nera-iwt-grid-cell', function (e) {
			e.preventDefault();
			var $cell = $(this);
			if ($cell.hasClass('is-sold')) {
				return;
			}
			$neraIwtGridModal.find('.nera-iwt-modal-ticket').val(String($cell.data('n') || ''));
			$neraIwtGridModal.find('.nera-iwt-grid-cell.is-picked').removeClass('is-picked');
			$cell.addClass('is-picked');
		});
		// Activate from the modal using the ticket field (blank = system picks).
		$neraIwtGridModal.on('click', '.nera-iwt-modal-activate', function (e) {
			e.preventDefault();
			var $btn = $(this);
			var $target = $neraIwtGridTarget;
			if (!$target) {
				return;
			}
			var ruleId = $target.data('rule-id');
			var ticket = String($neraIwtGridModal.find('.nera-iwt-modal-ticket').val() || '').replace(/^\s+|\s+$/g, '');
			neraIwtHeldPost('nera_iwt_activate_held_prize', ruleId, ticket, $btn, function (data) {
				$target.attr('data-held-state', 'active').attr('data-held-badge', 'live');
				$target.closest('tr').find('.nera-iwt-held-actions').attr('data-held-badge', 'live'); // relocated icons live in the Action column
				// Keep LFW's own ticket-number input in sync so a later Save cannot clobber it.
				$target.closest('tr').find('.lty-ticket-number').first().val(data.number || '');
				neraIwtHeldSetStatus($target, 'available');
				var neraNum = String(data.number || '');
				neraIwtToast(neraIwtGridEditMode
					? ('Winning ticket updated to ' + neraNum + '. Saved automatically.')
					: ('Held prize activated — winning ticket ' + neraNum + '. Saved automatically — no need to click Save.'));
				neraIwtCloseGridModal();
			}, { edit: neraIwtGridEditMode ? 1 : 0 });
		});

		return $neraIwtGridModal;
	}

	$(document).on('keydown', function (e) {
		if ('Escape' === e.key && $neraIwtGridModal && !$neraIwtGridModal.attr('hidden')) {
			neraIwtCloseGridModal();
		}
	});

	$(document).on('click', '.nera-iwt-deactivate-held', function (e) {
		e.preventDefault();
		var $btn = $(this);
		var $controls = $btn.closest('tr').find('.nera-iwt-held-controls').first();
		var ruleId = $controls.data('rule-id');
		if (neraIwtHeldAdmin.deactivateHeldConfirm && !window.confirm(neraIwtHeldAdmin.deactivateHeldConfirm)) {
			return;
		}
		neraIwtHeldPost('nera_iwt_deactivate_held_prize', ruleId, '', $btn, function () {
			$controls.attr('data-held-state', 'held').attr('data-held-badge', 'pending');
			$controls.closest('tr').find('.nera-iwt-held-actions').attr('data-held-badge', 'pending');
			$controls.closest('tr').find('.lty-ticket-number').first().val('');
			neraIwtHeldSetStatus($controls, 'locked');
			neraIwtToast('Held prize deactivated. Saved automatically.');
		});
	});

	$(document).on('click', '.nera-iwt-run-held-draw', function (e) {
		e.preventDefault();
		var $btn = $(this);
		var $controls = $btn.closest('tr').find('.nera-iwt-held-controls').first();
		var ruleId = $controls.data('rule-id');
		if (neraIwtHeldAdmin.runHeldDrawConfirm && !window.confirm(neraIwtHeldAdmin.runHeldDrawConfirm)) {
			return;
		}
		neraIwtHeldPost('nera_iwt_run_held_draw', ruleId, '', $btn, function (data) {
			$controls.attr('data-held-badge', 'drawn');
			$controls.closest('tr').find('.nera-iwt-held-actions').attr('data-held-badge', 'drawn');
			if (data) {
				$controls.find('.nera-iwt-held-meta--drawn .nera-iwt-held-winner').text(data.user || '');
				$controls.find('.nera-iwt-held-meta--drawn .nera-iwt-held-number').text(data.ticket_number || '');
			}
			$controls.closest('tr').find('.lty-ticket-number').first().val((data && data.ticket_number) || '');
			neraIwtHeldSetStatus($controls, 'won');
			if (data && data.user) {
				window.alert((neraIwtHeldAdmin.runHeldDrawDone || 'Winner drawn:') + ' ' + (data.ticket_number || '') + ' — ' + data.user);
			}
		});
	});
})(jQuery);
