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

	function toggleWeekday() {
		const row = document.getElementById('cr-weekday-row');
		const checked = document.querySelector('input[name="frequency"]:checked');

		if (row !== null && checked !== null) {
			row.style.display = checked.value === 'weekly' ? '' : 'none';
		}
	}

	document.addEventListener('change', function(e) {
		if (e.target.name === 'frequency') {
			toggleWeekday();
		}
	});

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', toggleWeekday);
	}
	else {
		toggleWeekday();
	}
})();
