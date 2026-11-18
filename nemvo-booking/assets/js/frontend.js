/* Nemvo Booking – booking widget. No dependencies. */
(function () {
	'use strict';

	var ICONS = {
		clock: '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>',
		video: '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="6" width="13" height="12" rx="2"/><path d="M16 10l5-3v10l-5-3z"/></svg>',
		globe: '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/></svg>',
		calendar: '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/></svg>',
		prev: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 6l-6 6 6 6"/></svg>',
		next: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>',
		check: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>'
	};

	/*
	 * Fixed address for the Cap widget's "Troubleshoot" link. Without it the widget appends the page
	 * address, title, referrer and browser details to the link; with it the link carries nothing.
	 */
	var CAP_TROUBLESHOOTING_URL = 'https://trycap.dev/guide/troubleshooting/instrumentation.html';

	function esc(value) {
		return String(value == null ? '' : value).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}

	function pad(n) {
		return (n < 10 ? '0' : '') + n;
	}

	/* Dates are handled as 'YYYY-MM-DD' strings and UTC math, so the visitor's local clock never interferes. */
	function ymd(y, m, d) {
		var dt = new Date(Date.UTC(y, m - 1, d));
		return dt.getUTCFullYear() + '-' + pad(dt.getUTCMonth() + 1) + '-' + pad(dt.getUTCDate());
	}

	function parts(str) {
		var p = str.split('-');
		return { y: +p[0], m: +p[1], d: +p[2] };
	}

	function toUTC(str) {
		var p = parts(str);
		return new Date(Date.UTC(p.y, p.m - 1, p.d));
	}

	function addDays(str, n) {
		var p = parts(str);
		return ymd(p.y, p.m, p.d + n);
	}

	function mondayIndex(str) {
		return (toUTC(str).getUTCDay() + 6) % 7;
	}

	function isoWeek(str) {
		var d = toUTC(str);
		var day = d.getUTCDay() || 7;
		d.setUTCDate(d.getUTCDate() + 4 - day);
		var yearStart = new Date(Date.UTC(d.getUTCFullYear(), 0, 1));
		return Math.ceil(((d - yearStart) / 86400000 + 1) / 7);
	}

	function todayIn(tz) {
		try {
			return new Intl.DateTimeFormat('en-CA', { timeZone: tz, year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date());
		} catch (e) {
			var n = new Date();
			return ymd(n.getFullYear(), n.getMonth() + 1, n.getDate());
		}
	}

	function detectTimezone(fallback) {
		try {
			return Intl.DateTimeFormat().resolvedOptions().timeZone || fallback;
		} catch (e) {
			return fallback;
		}
	}

	/* Signals that the page runs in an automated (headless) browser. Checked server-side when enabled. */
	function botSignals() {
		var s = [], n = window.navigator;
		if (n.webdriver) { s.push('webdriver'); }
		if (/HeadlessChrome/.test(n.userAgent)) { s.push('headless_ua'); }
		if (!n.languages || !n.languages.length) { s.push('no_languages'); }
		if (window.outerWidth === 0 && window.outerHeight === 0) { s.push('zero_window'); }
		if (window._phantom || window.callPhantom || window.__nightmare || window.domAutomation) { s.push('automation_globals'); }
		if (document.documentElement.getAttribute('webdriver') !== null) { s.push('webdriver_attr'); }
		return s;
	}

	function Widget(root) {
		this.root = root;
		this.cfg = JSON.parse(root.getAttribute('data-config'));
		this.t = this.cfg.i18n;
		this.tz = detectTimezone(this.cfg.timezone);
		this.duration = this.cfg.defaultDuration;
		this.cache = {};
		this.days = {};
		this.view = null;
		this.selectedDate = null;
		this.slot = null;
		this.step = 'calendar';
		this.values = {};
		this.brokenPhotos = {};
		this.root.addEventListener('click', this.onClick.bind(this));
		this.root.addEventListener('error', this.onPhotoError.bind(this), true);
		this.root.addEventListener('change', this.onChange.bind(this));
		this.root.addEventListener('submit', this.onSubmit.bind(this));

		if (this.cfg.rescheduleToken) {
			this.loadReschedule();
		} else if (this.cfg.cancelToken) {
			this.loadCancel();
		} else if (!this.cfg.pageId) {
			this.root.innerHTML = '';
		} else if (!this.cfg.bookable) {
			this.root.innerHTML = '<div class="nb-card nb-card--narrow"><p class="nb-muted">' + esc(this.t.notBookable) + '</p></div>';
		} else {
			var today = parts(todayIn(this.tz));
			this.view = { y: today.y, m: today.m };
			this.render();
			this.loadMonth(true);
		}
	}

	Widget.prototype.api = function (path, params, body) {
		var url = this.cfg.rest + path;
		if (params) {
			url += (url.indexOf('?') === -1 ? '?' : '&') + new URLSearchParams(params).toString();
		}
		var t = this.t;
		return fetch(url, {
			method: body ? 'POST' : 'GET',
			headers: body ? { 'Content-Type': 'application/json' } : {},
			body: body ? JSON.stringify(body) : undefined,
			credentials: 'same-origin'
		}).then(function (res) {
			return res.json().catch(function () { return {}; }).then(function (data) {
				if (!res.ok) {
					var err = new Error(data.message || t.error);
					err.code = data.code;
					err.data = data.data || {};
					throw err;
				}
				return data;
			});
		});
	};

	Widget.prototype.fmt = function (ts, opts) {
		var o = Object.assign({ timeZone: this.tz }, opts);
		try {
			return new Intl.DateTimeFormat(this.cfg.locale, o).format(new Date(ts * 1000));
		} catch (e) {
			return new Date(ts * 1000).toLocaleString();
		}
	};

	Widget.prototype.fmtTime = function (ts) {
		return this.fmt(ts, { hour: 'numeric', minute: '2-digit', hour12: this.cfg.hour12 });
	};

	Widget.prototype.fmtDate = function (str, opts) {
		try {
			return new Intl.DateTimeFormat(this.cfg.locale, Object.assign({ timeZone: 'UTC' }, opts)).format(toUTC(str));
		} catch (e) {
			return str;
		}
	};

	/* ---------- Data ---------- */

	Widget.prototype.gridRange = function () {
		var first = ymd(this.view.y, this.view.m, 1);
		var last = ymd(this.view.y, this.view.m + 1, 0);
		return {
			from: addDays(first, -mondayIndex(first)),
			to: addDays(last, 6 - mondayIndex(last)),
			first: first,
			last: last
		};
	};

	Widget.prototype.loadMonth = function (autoJump) {
		var self = this;
		var range = this.gridRange();
		var key = [this.duration, this.tz, range.from].join('|');
		this.loading = true;
		this.error = null;
		this.renderMain();

		var request = this.cache[key] ? Promise.resolve(this.cache[key]) : this.api('pages/' + this.cfg.pageId + '/slots', {
			from: range.from,
			to: range.to,
			tz: this.tz,
			duration: this.duration
		});

		return request.then(function (data) {
			self.cache[key] = data;
			if (key !== [self.duration, self.tz, self.gridRange().from].join('|')) {
				return; // The visitor navigated away meanwhile.
			}
			self.loading = false;
			self.days = data.days || {};
			self.minDate = data.minDate;
			self.maxDate = data.maxDate;
			self.nextAvailable = data.nextAvailable;
			self.formToken = self.formToken || data.formToken;

			var inMonth = Object.keys(self.days).filter(function (d) {
				return d >= range.first && d <= range.last;
			}).sort();

			if (autoJump && !inMonth.length && data.nextAvailable) {
				var n = parts(data.nextAvailable);
				self.view = { y: n.y, m: n.m };
				self.selectedDate = data.nextAvailable;
				return self.loadMonth(false);
			}
			if (!self.selectedDate || !self.days[self.selectedDate]) {
				self.selectedDate = inMonth[0] || null;
			}
			self.renderMain();
		}).catch(function (err) {
			self.loading = false;
			self.error = err.message;
			self.renderMain();
		});
	};

	/* ---------- Rendering ---------- */

	Widget.prototype.render = function () {
		this.root.innerHTML = '<div class="nb-card"><aside class="nb-info">' + this.infoHtml() + '</aside><section class="nb-main" aria-live="polite"></section></div>';
		this.renderMain();
	};

	Widget.prototype.infoHtml = function () {
		var c = this.cfg, t = this.t, self = this, html = '';

		var hosts = (c.hosts || []).filter(function (h) { return !self.brokenPhotos[h.photo]; });
		if (hosts.length) {
			html += '<div class="nb-hosts">' + hosts.map(function (h) {
				return '<img class="nb-host" src="' + esc(h.photo) + '" alt="' + esc(h.name) + '" title="' + esc(h.name) + '" width="56" height="56">';
			}).join('') + '</div>';
		}
		html += '<h2 class="nb-title">' + esc(c.title) + '</h2><ul class="nb-meta">';

		if (c.durations.length > 1) {
			html += '<li>' + ICONS.clock + '<div class="nb-durations" role="group" aria-label="' + esc(t.duration) + '">' + c.durations.map(function (d) {
				return '<button type="button" class="nb-pill' + (d === self.duration ? ' is-active' : '') + '" data-duration="' + d + '" aria-pressed="' + (d === self.duration) + '">' + esc(t.minutes.replace('%d', d)) + '</button>';
			}).join('') + '</div></li>';
		} else {
			html += '<li>' + ICONS.clock + '<span>' + esc(t.minutes.replace('%d', c.durations[0])) + '</span></li>';
		}
		html += '<li>' + ICONS.video + '<span>' + esc(t.googleMeet) + '</span></li>';
		html += '<li>' + ICONS.globe + '<label class="nb-tz"><span class="nb-sr">' + esc(t.timezone) + '</span><select data-tz>' + this.timezoneOptions() + '</select></label></li>';
		html += '</ul>';

		if (this.moving) {
			var b = this.moving;
			html += '<div class="nb-summary nb-current">' + ICONS.calendar + '<div><span class="nb-muted">' + esc(this.t.currentTime) + '</span><br><strong>' +
				esc(this.fmt(b.start, { weekday: 'long', day: 'numeric', month: 'long' })) + '</strong><br>' +
				esc(this.fmtTime(b.start) + ' – ' + this.fmtTime(b.start + b.duration * 60)) + '</div></div>';
		} else if (c.description) {
			html += '<div class="nb-description">' + c.description + '</div>';
		}
		return html;
	};

	/* A host without a Gravatar gets a 404: leave them out, and the whole row if nobody is left. */
	Widget.prototype.onPhotoError = function (e) {
		var img = e.target;
		if (!img.classList || !img.classList.contains('nb-host')) {
			return;
		}
		this.brokenPhotos[img.getAttribute('src')] = true;
		var row = img.parentNode;
		row.removeChild(img);
		if (!row.children.length) {
			row.parentNode.removeChild(row);
		}
	};

	Widget.prototype.timezoneOptions = function () {
		var zones = [];
		try {
			zones = Intl.supportedValuesOf('timeZone');
		} catch (e) {
			zones = [];
		}
		[this.tz, this.cfg.timezone].forEach(function (z) {
			if (z && zones.indexOf(z) === -1) {
				zones.push(z);
			}
		});
		var self = this;
		return zones.sort().map(function (z) {
			return '<option value="' + esc(z) + '"' + (z === self.tz ? ' selected' : '') + '>' + esc(z.replace(/_/g, ' ')) + '</option>';
		}).join('');
	};

	Widget.prototype.renderMain = function () {
		var main = this.root.querySelector('.nb-main');
		if (!main) {
			return;
		}
		if (this.step === 'form') {
			main.innerHTML = this.formHtml();
			this.bindCap();
			var first = main.querySelector('input, textarea, select');
			if (first) {
				first.focus({ preventScroll: true });
			}
		} else if (this.step === 'confirmMove') {
			main.innerHTML = this.confirmMoveHtml();
		} else if (this.step === 'done') {
			main.innerHTML = this.doneHtml();
		} else {
			main.innerHTML = '<div class="nb-picker">' + this.calendarHtml() + this.timesHtml() + '</div>';
		}
	};

	Widget.prototype.calendarHtml = function () {
		var t = this.t, c = this.cfg;
		var range = this.gridRange();
		var today = todayIn(this.tz);
		var monthLabel = this.fmtDate(range.first, { month: 'long', year: 'numeric' });
		var canPrev = range.first > today.slice(0, 8) + '01';
		var canNext = !this.maxDate || range.last < this.maxDate;

		var html = '<div class="nb-calendar"><h3 class="nb-step-title">' + esc(this.moving ? t.rescheduleTitle : t.selectDate) + '</h3>';
		html += '<div class="nb-cal-head">';
		html += '<button type="button" class="nb-nav" data-nav="-1" aria-label="' + esc(t.prevMonth) + '"' + (canPrev ? '' : ' disabled') + '>' + ICONS.prev + '</button>';
		html += '<span class="nb-month">' + esc(monthLabel.charAt(0).toUpperCase() + monthLabel.slice(1)) + '</span>';
		html += '<button type="button" class="nb-nav" data-nav="1" aria-label="' + esc(t.nextMonth) + '"' + (canNext ? '' : ' disabled') + '>' + ICONS.next + '</button>';
		html += '</div>';

		html += '<table class="nb-grid' + (this.loading ? ' is-loading' : '') + '" role="grid"><thead><tr>';
		if (c.weekNumbers) {
			html += '<th class="nb-wk" scope="col"><span title="' + esc(t.week) + '">' + esc(t.week) + '</span></th>';
		}
		for (var i = 0; i < 7; i++) {
			var wd = ymd(2024, 1, 1 + i); // 2024-01-01 is a Monday.
			html += '<th scope="col" abbr="' + esc(this.fmtDate(wd, { weekday: 'long' })) + '">' + esc(this.fmtDate(wd, { weekday: 'short' })) + '</th>';
		}
		html += '</tr></thead><tbody>';

		for (var day = range.from; day <= range.to; day = addDays(day, 7)) {
			html += '<tr>';
			if (c.weekNumbers) {
				html += '<td class="nb-wk">' + isoWeek(day) + '</td>';
			}
			for (var j = 0; j < 7; j++) {
				var d = addDays(day, j);
				var available = !this.loading && !!this.days[d];
				var cls = ['nb-day'];
				if (available) { cls.push('is-available'); }
				if (d === this.selectedDate) { cls.push('is-selected'); }
				if (d === today) { cls.push('is-today'); }
				if (d < range.first || d > range.last) { cls.push('is-outside'); }
				html += '<td><button type="button" class="' + cls.join(' ') + '" data-date="' + d + '"' +
					(available ? '' : ' disabled') +
					(d === this.selectedDate ? ' aria-pressed="true"' : '') +
					' aria-label="' + esc(this.fmtDate(d, { weekday: 'long', day: 'numeric', month: 'long' })) + '">' +
					parts(d).d + '</button></td>';
			}
			html += '</tr>';
		}
		html += '</tbody></table>';

		if (this.error) {
			html += '<p class="nb-error">' + esc(this.error) + '</p>';
		} else if (!this.loading) {
			var hasAny = Object.keys(this.days).some(function (d) { return d >= range.first && d <= range.last; });
			if (!hasAny) {
				if (this.nextAvailable) {
					html += '<p class="nb-muted">' + esc(t.noTimesMonth) + ' <button type="button" class="nb-link" data-jump="' + esc(this.nextAvailable) + '">' + esc(t.jumpNext) + ' →</button></p>';
				} else {
					html += '<p class="nb-muted">' + esc(t.noAvailability) + '</p>';
				}
			}
		}
		return html + '</div>';
	};

	Widget.prototype.timesHtml = function () {
		var t = this.t;
		if (this.loading || !this.selectedDate) {
			return '<div class="nb-times"></div>';
		}
		var slots = this.days[this.selectedDate] || [];
		var self = this;
		var html = '<div class="nb-times"><h3 class="nb-step-title">' + esc(this.fmtDate(this.selectedDate, { weekday: 'long', day: 'numeric', month: 'long' })) + '</h3>';
		if (!slots.length) {
			html += '<p class="nb-muted">' + esc(t.noTimes) + '</p>';
		} else {
			html += '<div class="nb-slots">' + slots.map(function (s) {
				return '<button type="button" class="nb-slot" data-start="' + s.start + '">' + esc(self.fmtTime(s.start)) + '</button>';
			}).join('') + '</div>';
		}
		return html + '</div>';
	};

	Widget.prototype.slotSummaryHtml = function () {
		var start = this.slot, end = start + this.duration * 60;
		var day = this.fmt(start, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
		return '<div class="nb-summary">' + ICONS.calendar + '<div><strong>' + esc(day) + '</strong><br>' +
			esc(this.fmtTime(start) + ' – ' + this.fmtTime(end)) + ' <span class="nb-muted">(' + esc(this.tz.replace(/_/g, ' ')) + ')</span></div></div>';
	};

	Widget.prototype.formHtml = function () {
		var t = this.t, self = this;
		var html = '<button type="button" class="nb-back" data-back>' + ICONS.prev + esc(t.back) + '</button>';
		html += '<h3 class="nb-step-title">' + esc(t.yourDetails) + '</h3>' + this.slotSummaryHtml();
		html += '<form class="nb-form" novalidate>';

		this.cfg.fields.forEach(function (f) {
			var id = 'nb-' + self.cfg.pageId + '-' + f.key;
			var val = self.values[f.key] || '';
			var req = f.required ? ' required' : '';
			var ph = f.placeholder ? ' placeholder="' + esc(f.placeholder) + '"' : '';
			var label = esc(f.label) + (f.required ? ' <span class="nb-req" aria-hidden="true">*</span>' : '');
			var input;

			if (f.type === 'checkbox') {
				html += '<div class="nb-field nb-field--checkbox" data-field="' + esc(f.key) + '"><label><input type="checkbox" name="' + esc(f.key) + '" value="1"' + (val ? ' checked' : '') + req + '><span>' + label + '</span></label><p class="nb-field-error"></p></div>';
				return;
			}
			if (f.type === 'textarea') {
				input = '<textarea id="' + id + '" name="' + esc(f.key) + '" rows="4"' + ph + req + '>' + esc(val) + '</textarea>';
			} else if (f.type === 'select') {
				input = '<select id="' + id + '" name="' + esc(f.key) + '"' + req + '><option value="">' + (f.placeholder ? esc(f.placeholder) : '—') + '</option>' +
					f.options.map(function (o) { return '<option' + (o === val ? ' selected' : '') + '>' + esc(o) + '</option>'; }).join('') + '</select>';
			} else {
				var auto = { name: 'name', email: 'email', phone: 'tel', company: 'organization', title: 'organization-title' }[f.key];
				input = '<input id="' + id + '" type="' + esc(f.type) + '" name="' + esc(f.key) + '" value="' + esc(val) + '"' + ph + req + (auto ? ' autocomplete="' + auto + '"' : '') + '>';
			}
			html += '<div class="nb-field" data-field="' + esc(f.key) + '"><label for="' + id + '">' + label + '</label>' + input + '<p class="nb-field-error"></p></div>';
		});

		if (this.cfg.guests.allowed) {
			var open = !!this.values.guests;
			html += '<div class="nb-field" data-field="guests">';
			html += '<button type="button" class="nb-link" data-guests' + (open ? ' hidden' : '') + '>' + esc(t.addGuests) + '</button>';
			html += '<div class="nb-guests"' + (open ? '' : ' hidden') + '><label for="nb-guests-' + this.cfg.pageId + '">' + esc(t.guests) + ' <span class="nb-muted">(' + esc(t.optional) + ')</span></label>';
			html += '<textarea id="nb-guests-' + this.cfg.pageId + '" name="guests" rows="2" placeholder="anna@example.com, erik@example.com">' + esc(this.values.guests || '') + '</textarea>';
			html += '<p class="nb-help">' + esc(t.guestsHelp) + '</p></div><p class="nb-field-error"></p></div>';
		}

		if (this.cfg.consent) {
			html += '<div class="nb-field nb-field--checkbox" data-field="consent"><label><input type="checkbox" name="consent" value="1"' +
				(this.values.consent ? ' checked' : '') + ' required><span>' + this.cfg.consent + '</span></label><p class="nb-field-error"></p></div>';
		}

		// Honeypot for bots; hidden from people and assistive tech.
		if (this.cfg.protection.honeypot) {
			html += '<div class="nb-hp" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>';
		}
		var cap = this.cfg.protection.cap;
		if (cap && cap.mode === 'visible') {
			html += '<div class="nb-field nb-cap" data-field="cap">' + this.capWidgetHtml() + '<p class="nb-field-error"></p></div>';
		}
		html += '<p class="nb-error" role="alert" hidden></p>';
		html += '<button type="submit" class="nb-button">' + esc(t.confirm) + '</button></form>';
		return html;
	};

	Widget.prototype.capWidgetHtml = function () {
		return '<cap-widget data-cap-api-endpoint="' + esc(this.cfg.protection.cap.endpoint) +
			'" data-cap-troubleshooting-url="' + esc(CAP_TROUBLESHOOTING_URL) + '"></cap-widget>';
	};

	/* Visible Cap widget: remember the token once the proof-of-work is solved. */
	Widget.prototype.bindCap = function () {
		var self = this;
		var widget = this.root.querySelector('cap-widget');
		this.capToken = '';
		if (!widget) {
			return;
		}
		widget.addEventListener('solve', function (e) {
			self.capToken = (e.detail && e.detail.token) || '';
			var field = self.root.querySelector('.nb-cap');
			if (field) {
				field.classList.remove('has-error');
				field.querySelector('.nb-field-error').textContent = '';
			}
		});
		['reset', 'error'].forEach(function (name) {
			widget.addEventListener(name, function () { self.capToken = ''; });
		});
	};

	/* Cap tokens are single use, so a new one is needed after every attempt. */
	Widget.prototype.resetCap = function () {
		var holder = this.root.querySelector('.nb-cap');
		if (holder) {
			holder.querySelector('cap-widget').outerHTML = this.capWidgetHtml();
			this.bindCap();
		}
	};

	Widget.prototype.getCapToken = function () {
		var cap = this.cfg.protection.cap;
		if (!cap) {
			return Promise.resolve('');
		}
		if (cap.mode === 'visible') {
			var input = this.root.querySelector('.nb-cap input[name="cap-token"]');
			return Promise.resolve(this.capToken || (input ? input.value : ''));
		}
		if (typeof window.Cap !== 'function') {
			return Promise.reject(new Error(this.t.capFailed));
		}
		var t = this.t;
		var config = { apiEndpoint: cap.endpoint, 'data-cap-troubleshooting-url': CAP_TROUBLESHOOTING_URL };
		return new window.Cap(config).solve().then(function (solution) {
			if (!solution || !solution.token) {
				throw new Error(t.capFailed);
			}
			return solution.token;
		});
	};

	Widget.prototype.doneHtml = function () {
		var b = this.result.booking, t = this.t;
		var html = '<div class="nb-done"><div class="nb-check">' + ICONS.check + '</div>';
		html += '<div class="nb-thanks">' + this.result.message + '</div>';
		html += '<div class="nb-summary">' + ICONS.calendar + '<div><strong>' + esc(b.title) + '</strong><br>' + esc(b.date) + '<br>' + esc(b.time) + ' <span class="nb-muted">(' + esc(b.timezone) + ')</span>';
		if (b.hosts.length) {
			html += '<br>' + esc(t.with) + ' ' + esc(b.hosts.join(', '));
		}
		html += '</div></div>';
		if (b.meetLink) {
			html += '<a class="nb-button nb-button--ghost" href="' + esc(b.meetLink) + '" target="_blank" rel="noopener">' + ICONS.video + esc(t.joinMeet) + '</a>';
		}
		return html + '</div>';
	};

	/* ---------- Events ---------- */

	Widget.prototype.onClick = function (e) {
		var el = e.target.closest('button');
		if (!el || !this.root.contains(el)) {
			return;
		}
		if (el.hasAttribute('data-duration')) {
			this.duration = +el.getAttribute('data-duration');
			this.step = 'calendar';
			this.render();
			this.loadMonth(true);
		} else if (el.hasAttribute('data-nav')) {
			var dir = +el.getAttribute('data-nav');
			this.view = { y: this.view.y, m: this.view.m + dir };
			if (this.view.m < 1) { this.view = { y: this.view.y - 1, m: 12 }; }
			if (this.view.m > 12) { this.view = { y: this.view.y + 1, m: 1 }; }
			this.selectedDate = null;
			this.loadMonth(false);
		} else if (el.hasAttribute('data-jump')) {
			var n = parts(el.getAttribute('data-jump'));
			this.view = { y: n.y, m: n.m };
			this.selectedDate = el.getAttribute('data-jump');
			this.loadMonth(false);
		} else if (el.hasAttribute('data-date')) {
			this.selectedDate = el.getAttribute('data-date');
			this.renderMain();
			var times = this.root.querySelector('.nb-times');
			if (times && window.matchMedia('(max-width: 760px)').matches) {
				times.scrollIntoView({ behavior: 'smooth', block: 'start' });
			}
		} else if (el.hasAttribute('data-start')) {
			this.slot = +el.getAttribute('data-start');
			this.step = this.moving ? 'confirmMove' : 'form';
			this.renderMain();
		} else if (el.hasAttribute('data-move-confirm')) {
			this.submitMove(el);
		} else if (el.hasAttribute('data-back')) {
			this.collectValues();
			this.step = 'calendar';
			this.renderMain();
		} else if (el.hasAttribute('data-guests')) {
			el.hidden = true;
			var box = this.root.querySelector('.nb-guests');
			box.hidden = false;
			box.querySelector('textarea').focus();
		} else if (el.hasAttribute('data-cancel')) {
			this.submitCancel(el);
		}
	};

	Widget.prototype.onChange = function (e) {
		if (e.target.hasAttribute('data-tz')) {
			this.tz = e.target.value;
			this.selectedDate = null;
			this.step = 'calendar';
			this.renderMain();
			this.loadMonth(true);
		}
	};

	Widget.prototype.collectValues = function () {
		var form = this.root.querySelector('.nb-form');
		if (!form) {
			return;
		}
		var self = this;
		Array.prototype.forEach.call(form.elements, function (el) {
			if (!el.name || el.name === 'website') {
				return;
			}
			self.values[el.name] = el.type === 'checkbox' ? (el.checked ? '1' : '') : el.value;
		});
	};

	Widget.prototype.showFieldErrors = function (errors) {
		var form = this.root.querySelector('.nb-form');
		var first = null;
		Array.prototype.forEach.call(form.querySelectorAll('.nb-field'), function (f) {
			var msg = errors[f.getAttribute('data-field')] || '';
			f.classList.toggle('has-error', !!msg);
			f.querySelector('.nb-field-error').textContent = msg;
			if (msg && !first) {
				first = f;
			}
		});
		if (first) {
			var input = first.querySelector('input, textarea, select');
			if (input) {
				input.focus();
			}
		}
		return !first;
	};

	Widget.prototype.validate = function () {
		var t = this.t, self = this, errors = {};
		this.cfg.fields.forEach(function (f) {
			var v = (self.values[f.key] || '').trim();
			if (f.required && !v) {
				errors[f.key] = t.required;
			} else if (f.type === 'email' && v && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v)) {
				errors[f.key] = t.invalidEmail;
			}
		});
		if (this.cfg.consent && !this.values.consent) {
			errors.consent = t.consentRequired;
		}
		return this.showFieldErrors(errors);
	};

	Widget.prototype.onSubmit = function (e) {
		if (!e.target.classList.contains('nb-form')) {
			return;
		}
		e.preventDefault();
		this.collectValues();
		if (!this.validate()) {
			return;
		}

		var self = this, t = this.t, form = e.target;
		var button = form.querySelector('button[type="submit"]');
		var alertEl = form.querySelector('.nb-error');
		var fields = {};
		this.cfg.fields.forEach(function (f) {
			fields[f.key] = self.values[f.key] || '';
		});

		var cap = this.cfg.protection.cap;
		if (cap && cap.mode === 'visible' && !this.capToken && !(form.querySelector('input[name="cap-token"]') || {}).value) {
			this.showFieldErrors({ cap: t.capRequired });
			return;
		}

		button.disabled = true;
		button.textContent = cap && cap.mode !== 'visible' ? t.verifying : t.booking;
		alertEl.hidden = true;

		this.getCapToken().then(function (capToken) {
			button.textContent = t.booking;
			return self.api('pages/' + self.cfg.pageId + '/bookings', null, {
				start: self.slot,
				duration: self.duration,
				tz: self.tz,
				fields: fields,
				guests: self.values.guests || '',
				consent: !!self.values.consent,
				website: form.elements.website ? form.elements.website.value : '',
				form_token: self.formToken || '',
				cap_token: capToken,
				client: { signals: botSignals() },
				page_url: window.location.href
			});
		}).then(function (result) {
			if (result.redirect) {
				window.location.href = result.redirect;
				return;
			}
			self.result = result;
			self.step = 'done';
			self.renderMain();
			self.root.scrollIntoView({ behavior: 'smooth', block: 'start' });
		}).catch(function (err) {
			button.disabled = false;
			button.textContent = t.confirm;
			if (err.code !== 'invalid_fields') {
				self.resetCap(); // The token may have been used.
			}
			if (err.code === 'slot_unavailable') {
				self.cache = {};
				self.step = 'calendar';
				self.error = null;
				self.loadMonth(false).then(function () {
					var cal = self.root.querySelector('.nb-calendar');
					if (cal) {
						cal.insertAdjacentHTML('beforeend', '<p class="nb-error">' + esc(err.message) + '</p>');
					}
				});
				return;
			}
			if (err.data && err.data.fields) {
				self.showFieldErrors(err.data.fields);
			}
			alertEl.textContent = err.message;
			alertEl.hidden = false;
		});
	};

	/* ---------- Rescheduling ---------- */

	Widget.prototype.loadReschedule = function () {
		var self = this, t = this.t, token = this.cfg.rescheduleToken;
		this.root.innerHTML = '<div class="nb-card nb-card--narrow"><p class="nb-muted">' + esc(t.loading) + '</p></div>';
		this.api('reschedule', { token: token }).then(function (data) {
			var b = data.booking;
			if (!b.canReschedule) {
				var msg = b.status === 'cancelled' ? t.alreadyCancelled : t.cannotReschedule;
				self.root.innerHTML = '<div class="nb-card nb-card--narrow"><p class="nb-muted">' + esc(msg) + '</p></div>';
				return;
			}
			// The booking page's settings come from the server, so this also works on the standalone page.
			self.cfg = Object.assign({}, data.config, { rescheduleToken: token });
			self.t = self.cfg.i18n;
			self.moving = b;
			self.cfg.durations = [b.duration];
			self.duration = b.duration;
			var today = parts(todayIn(self.tz));
			self.view = { y: today.y, m: today.m };
			self.render();
			self.loadMonth(true);
		}).catch(function (err) {
			self.root.innerHTML = '<div class="nb-card nb-card--narrow"><p class="nb-error">' + esc(err.message) + '</p></div>';
		});
	};

	Widget.prototype.confirmMoveHtml = function () {
		var t = this.t, b = this.moving;
		var html = '<button type="button" class="nb-back" data-back>' + ICONS.prev + esc(t.back) + '</button>';
		html += '<h3 class="nb-step-title">' + esc(t.rescheduleTitle) + '</h3>';
		html += '<p class="nb-muted">' + esc(t.currentTime) + ': <s>' + esc(this.fmt(b.start, { weekday: 'long', day: 'numeric', month: 'long' }) + ', ' + this.fmtTime(b.start)) + '</s></p>';
		html += '<p class="nb-muted">' + esc(t.newTime) + ':</p>' + this.slotSummaryHtml();
		html += '<p class="nb-error" role="alert" hidden></p>';
		html += '<button type="button" class="nb-button" data-move-confirm>' + esc(t.confirmMove) + '</button>';
		return html;
	};

	Widget.prototype.submitMove = function (button) {
		var self = this, t = this.t;
		var alertEl = this.root.querySelector('.nb-error');
		button.disabled = true;
		button.textContent = t.moving;
		this.api('reschedule', null, { token: this.cfg.rescheduleToken, start: this.slot }).then(function (result) {
			self.result = result;
			self.moving = null;
			self.step = 'done';
			self.render();
			self.root.scrollIntoView({ behavior: 'smooth', block: 'start' });
		}).catch(function (err) {
			button.disabled = false;
			button.textContent = t.confirmMove;
			if (err.code === 'slot_unavailable') {
				self.cache = {};
				self.step = 'calendar';
				self.loadMonth(false);
				return;
			}
			alertEl.textContent = err.message;
			alertEl.hidden = false;
		});
	};

	/* ---------- Cancellation ---------- */

	Widget.prototype.loadCancel = function () {
		var self = this;
		this.root.innerHTML = '<div class="nb-card nb-card--narrow"><p class="nb-muted">' + esc(this.t.loading) + '</p></div>';
		this.api('cancel', { token: this.cfg.cancelToken }).then(function (data) {
			self.renderCancel(data.booking, null);
		}).catch(function (err) {
			self.root.innerHTML = '<div class="nb-card nb-card--narrow"><p class="nb-error">' + esc(err.message) + '</p></div>';
		});
	};

	Widget.prototype.renderCancel = function (b, done) {
		var t = this.t;
		var html = '<div class="nb-card nb-card--narrow"><div class="nb-cancel"><h2 class="nb-title">' + esc(t.cancelTitle) + '</h2>';
		html += '<div class="nb-summary">' + ICONS.calendar + '<div><strong>' + esc(b.title) + '</strong><br>' + esc(b.date) + '<br>' + esc(b.time) + ' <span class="nb-muted">(' + esc(b.timezone) + ')</span>' +
			(b.hosts.length ? '<br>' + esc(t.with) + ' ' + esc(b.hosts.join(', ')) : '') + '</div></div>';

		if (done) {
			html += '<p class="nb-success">' + esc(t.cancelled) + '</p>';
		} else if (b.status === 'cancelled') {
			html += '<p class="nb-muted">' + esc(t.alreadyCancelled) + '</p>';
		} else if (!b.canCancel) {
			html += '<p class="nb-muted">' + esc(t.cannotCancel) + '</p>';
		} else {
			html += '<div class="nb-field"><label for="nb-cancel-reason">' + esc(t.cancelReason) + '</label><textarea id="nb-cancel-reason" rows="3"></textarea></div>';
			html += '<p class="nb-error" role="alert" hidden></p>';
			html += '<button type="button" class="nb-button nb-button--danger" data-cancel>' + esc(t.cancelButton) + '</button>';
			if (b.canReschedule) {
				// Links sent before 1.2.2 use nb_cancel; keep the name the link came with.
				var moveUrl = window.location.href.replace(/([?&])(nemvo_booking|nb)_cancel=/, '$1$2_reschedule=');
				html += ' <a class="nb-link nb-instead" href="' + esc(moveUrl) + '">' + esc(t.rescheduleInstead) + ' →</a>';
			}
		}
		if ((done || b.status === 'cancelled') && this.cfg.pageId) {
			var url = window.location.href.replace(/([?&])(?:nemvo_booking|nb)_cancel=[^&#]*&?/, '$1').replace(/[?&]$/, '');
			html += '<p><a class="nb-link" href="' + esc(url) + '">' + esc(t.bookNew) + ' →</a></p>';
		}
		this.root.innerHTML = html + '</div></div>';
	};

	Widget.prototype.submitCancel = function (button) {
		var self = this, t = this.t;
		var alertEl = this.root.querySelector('.nb-error');
		button.disabled = true;
		button.textContent = t.cancelling;
		this.api('cancel', null, {
			token: this.cfg.cancelToken,
			reason: this.root.querySelector('#nb-cancel-reason').value
		}).then(function (data) {
			self.renderCancel(data.booking, true);
		}).catch(function (err) {
			button.disabled = false;
			button.textContent = t.cancelButton;
			alertEl.textContent = err.message;
			alertEl.hidden = false;
		});
	};

	function boot() {
		Array.prototype.forEach.call(document.querySelectorAll('.nb-booking[data-config]'), function (el) {
			if (!el.nbWidget) {
				el.nbWidget = new Widget(el);
			}
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})();
