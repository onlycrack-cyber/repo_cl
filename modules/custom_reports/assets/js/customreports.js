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

	// Fields of the schedule form that only apply to the data source "Zabbix problems".
	const PROBLEM_FIELDS = ['#groupids_', '#hostids_', 'input[name^="severities"]', 'input[name="period"]',
		'#show_suppressed', '#problem_name', 'input[name="acknowledged"]', 'input[name="problem_list"]',
		'input[name="analytics"]', '#include_changes', '[name="sortfield"]', 'input[name="split"]',
		'#recipient_access'
	];

	// Schedule form: show fields that depend on "Data source", "Frequency" and "Send separately".
	function toggleScheduleRows() {
		if (checkedValue('frequency') !== null) {
			setRowVisible('cr-weekday-row', checkedValue('frequency') === 'weekly');
		}

		// "Changes" tab: dates only for "Custom dates".
		if (checkedValue('filter_period') !== null) {
			setRowVisible('cr-changes-dates', checkedValue('filter_period') === 'custom');
		}

		// Data source "External script": the problem filter, analytics and split do not apply.
		const script = checkedValue('source') === 'script';

		if (checkedValue('source') !== null) {
			['cr-script-row', 'cr-script-params-row', 'cr-script-format-row'].forEach(function(id) {
				setRowVisible(id, script);
			});

			PROBLEM_FIELDS.forEach(function(selector) {
				const field = document.querySelector(selector);
				// The row of the form list, not an option of a radio button list (also an <li>).
				const row = field !== null ? field.closest('.table-forms > li') : null;

				if (row !== null) {
					row.style.display = script ? 'none' : '';
				}
			});

			setRowVisible('cr-split-hint-row', !script);
		}

		// "Test" form: the fields of the chosen recipients.
		if (checkedValue('test_target') !== null) {
			const target = checkedValue('test_target');

			setRowVisible('cr-test-me', target === 'me');
			setRowVisible('cr-test-users', target === 'users');
			setRowVisible('cr-test-address-type', target === 'address');
			setRowVisible('cr-test-address', target === 'address');
		}

		// "Own media of every user": the media type is optional (only for the additional recipients).
		if (checkedValue('media_mode') !== null) {
			const label = document.querySelector('label[for="label-mediatypeid"]');

			if (label !== null) {
				label.classList.toggle('form-label-asterisk', checkedValue('media_mode') !== 'user');
			}
		}

		if (checkedValue('split') !== null) {
			const split = script ? 'none' : checkedValue('split');

			setRowVisible('cr-group-recipients-row', split === 'group');
			setRowVisible('cr-host-recipients-row', split === 'host');
			setRowVisible('cr-common-recipients-row', split === 'group' || split === 'host');
		}
	}

	document.addEventListener('change', function(e) {
		if (['frequency', 'split', 'filter_period', 'source', 'media_mode', 'test_target'].includes(e.target.name)) {
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

	// Schedule form: "Run and show" runs the script of the form on the "Scripts" tab, in a new window
	// (POST with the CSRF token: running a script is not a plain link).
	document.addEventListener('click', function(e) {
		const button = e.target.closest('.js-cr-script-preview');

		if (button === null) {
			return;
		}

		e.preventDefault();

		const form = button.closest('form');
		const field = function(name) {
			const element = form.querySelector('[name="' + name + '"]');

			return element !== null ? element.value : '';
		};
		const values = {
			script: field('script'),
			script_params: field('script_params'),
			script_format: checkedValue('script_format') || 'auto',
			run: '1',
			[button.dataset.csrfName]: button.dataset.csrfToken
		};

		const post = document.createElement('form');

		post.method = 'post';
		post.action = button.dataset.scriptUrl;

		for (const [name, value] of Object.entries(values)) {
			const input = document.createElement('input');

			input.type = 'hidden';
			input.name = name;
			input.value = value;
			post.appendChild(input);
		}

		post.target = '_blank';
		document.body.appendChild(post);
		post.submit();
		post.remove();
	});

	// Delivery history: show / hide the recipients of a run.
	document.addEventListener('click', function(e) {
		const toggle = e.target.closest('.js-cr-history-toggle');

		if (toggle === null) {
			return;
		}

		e.preventDefault();

		const row = document.getElementById(toggle.dataset.target);

		if (row !== null) {
			row.style.display = row.style.display === 'none' ? '' : 'none';
		}
	});

	// "Changes" tab: remember whether the detailed analysis is expanded (per browser, optional).
	// <details data-cr-remember="key">: the open / collapsed state is remembered in the browser (collapsed by default).
	function restoreDetails() {
		document.querySelectorAll('details[data-cr-remember]').forEach(function(details) {
			const key = details.getAttribute('data-cr-remember');

			try {
				details.open = window.localStorage.getItem(key) === '1';
			}
			catch (e) {
				// Storage is not available: keep the default (collapsed).
			}

			details.addEventListener('toggle', function() {
				try {
					window.localStorage.setItem(key, details.open ? '1' : '0');
				}
				catch (e) {
					// Ignore.
				}
			});
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', restoreDetails);
	}
	else {
		restoreDetails();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', toggleScheduleRows);
	}
	else {
		toggleScheduleRows();
	}
})();
