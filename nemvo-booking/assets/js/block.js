/* Nemvo Booking – "Booking page" block (server-rendered, no build step). */
(function (wp, data) {
	'use strict';

	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var pages = (data && data.pages) || [];

	wp.blocks.registerBlockType('nemvo-booking/booking-page', {
		apiVersion: 2,
		title: __('Booking page', 'nemvo-booking'),
		description: __('Lets visitors book a meeting.', 'nemvo-booking'),
		icon: 'calendar-alt',
		category: 'widgets',
		keywords: ['booking', 'appointment', 'meeting', 'calendar'],
		attributes: {
			pageId: { type: 'number', default: 0 }
		},
		supports: { html: false, align: ['wide', 'full'] },

		edit: function (props) {
			var blockProps = wp.blockEditor.useBlockProps();
			var selected = pages.filter(function (p) { return p.id === props.attributes.pageId; })[0];
			var options = [{ label: __('— Select a booking page —', 'nemvo-booking'), value: 0 }].concat(
				pages.map(function (p) { return { label: p.title, value: p.id }; })
			);

			return el('div', blockProps,
				el(wp.components.Placeholder, {
					icon: 'calendar-alt',
					label: selected ? __('Booking page', 'nemvo-booking') + ': ' + selected.title : __('Booking page', 'nemvo-booking'),
					instructions: pages.length
						? __('The booking calendar is shown on the published page.', 'nemvo-booking')
						: __('Create a booking page under Bookings → Booking pages first.', 'nemvo-booking')
				},
					pages.length ? el(wp.components.SelectControl, {
						value: props.attributes.pageId,
						options: options,
						onChange: function (value) {
							props.setAttributes({ pageId: parseInt(value, 10) || 0 });
						},
						__nextHasNoMarginBottom: true
					}) : el('a', { href: data.newUrl, target: '_blank' }, __('Add booking page', 'nemvo-booking'))
				)
			);
		},

		save: function () {
			return null;
		}
	});
})(window.wp, window.nemvoBookingBlock);
