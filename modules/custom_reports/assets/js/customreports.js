/*
** Custom Problem Reports module for Zabbix 7.0 LTS.
*/


(function() {
	'use strict';

	document.addEventListener('submit', function(e) {
		const form = e.target;

		if (form instanceof HTMLFormElement && form.dataset.confirm !== undefined
				&& !window.confirm(form.dataset.confirm)) {
			e.preventDefault();
		}
	}, true);

	document.addEventListener('click', function(e) {
		const button = e.target.closest('[data-cr-open]');

		if (button !== null) {
			e.preventDefault();
			window.open(button.dataset.crOpen, '_blank', 'noopener');
		}
	});

	function setRowVisible(id, visible) {
		const row = document.getElementById(id);

		if (row !== null) {
			row.style.display = visible ? '' : 'none';
		}
	}

	function checkedValue(name) {
		const checked = document.querySelector('input[name="' + name + '"]:checked');

		return checked !== null ? checked.value : null;
	}

	// Schedule form: show fields that depend on "Frequency" and "Send separately".
	function toggleScheduleRows() {
		if (checkedValue('frequency') !== null) {
			setRowVisible('cr-weekday-row', checkedValue('frequency') === 'weekly');
		}

		if (checkedValue('split') !== null) {
			const split = checkedValue('split');

			setRowVisible('cr-group-recipients-row', split === 'group');
			setRowVisible('cr-host-recipients-row', split === 'host');
			setRowVisible('cr-common-recipients-row', split === 'group' || split === 'host');
		}
	}

	document.addEventListener('change', function(e) {
		if (e.target.name === 'frequency' || e.target.name === 'split') {
			toggleScheduleRows();
		}
	});

	// "Recipients per host group / per host": rows are added and removed by re-rendering the form with its current values.
	document.addEventListener('click', function(e) {
		const add = e.target.closest('.js-cr-rule-add');
		const remove = e.target.closest('.js-cr-rule-remove');
		const button = add || remove;

		if (button === null) {
			return;
		}

		e.preventDefault();

		const form = button.closest('form');
		const vars = {form_refresh: '1', rule_list: button.dataset.list};

		if (add !== null) {
			vars.rule_add = '1';
		}
		else {
			vars.rule_remove = remove.dataset.index;
		}

		form.querySelector('input[name="action"]').value = 'customreports.schedule.edit';

		for (const [name, value] of Object.entries(vars)) {
			const input = document.createElement('input');

			input.type = 'hidden';
			input.name = name;
			input.value = value;
			form.appendChild(input);
		}

		form.submit();
	});

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', toggleScheduleRows);
	}
	else {
		toggleScheduleRows();
	}
})();
