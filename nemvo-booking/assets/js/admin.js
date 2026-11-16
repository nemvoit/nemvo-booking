/* Nemvo Booking – admin screens */
(function ($, l10n) {
	'use strict';

	$(function () {
		$('.nb-color').wpColorPicker();

		// Copy-to-clipboard fields: select the whole value on focus, copy on click.
		$(document).on('focus', '.nb-copy input', function () {
			this.select();
		});
		$(document).on('click', '[data-copy]', function () {
			var $button = $(this);
			var input = $button.siblings('input')[0];
			input.select();
			var done = function () {
				var label = $button.text();
				$button.text(l10n.copied);
				setTimeout(function () { $button.text(label); }, 1500);
			};
			if (navigator.clipboard) {
				navigator.clipboard.writeText(input.value).then(done, function () { document.execCommand('copy'); done(); });
			} else {
				document.execCommand('copy');
				done();
			}
		});

		$(document).on('click', '[data-confirm]', function (e) {
			if (!window.confirm(l10n.confirmDelete)) {
				e.preventDefault();
			}
		});

		// Settings: restore default email text.
		$(document).on('click', '[data-reset-email]', function () {
			if (!window.confirm(l10n.confirmReset)) {
				return;
			}
			var $email = $(this).closest('.nb-email');
			$email.find('[data-subject]').val($email.data('default-subject'));
			$email.find('[data-body]').val($email.data('default-body'));
		});

		// Settings: test the Cap connection with the values in the form.
		$(document).on('click', '[data-cap-test]', function () {
			var $button = $(this);
			var $result = $button.siblings('.nb-test-result');
			$button.prop('disabled', true);
			$result.removeClass('is-ok is-error').text(l10n.testing);
			$.post(window.ajaxurl, {
				action: 'nemvo_booking_cap_test',
				_ajax_nonce: $button.data('nonce'),
				url: $('#nb-cap-url').val(),
				site_key: $('#nb-cap-key').val(),
				secret: $('#nb-cap-secret').val()
			}).done(function (response) {
				var message = response && response.data && response.data.message;
				$result.addClass(response && response.success ? 'is-ok' : 'is-error').text(message || l10n.testFailed);
			}).fail(function () {
				$result.addClass('is-error').text(l10n.testFailed);
			}).always(function () {
				$button.prop('disabled', false);
			});
		});

		// Employee: photo from the media library.
		$(document).on('click', '[data-photo-choose]', function () {
			var $box = $(this).closest('[data-photo]');
			var frame = wp.media({ title: l10n.choosePhoto, button: { text: l10n.usePhoto }, library: { type: 'image' }, multiple: false });
			frame.on('select', function () {
				var photo = frame.state().get('selection').first().toJSON();
				$box.find('input').val(photo.id);
				$box.find('img').attr('src', (photo.sizes && photo.sizes.thumbnail ? photo.sizes.thumbnail : photo).url);
				$box.find('[data-photo-remove]').prop('hidden', false);
			});
			frame.open();
		});
		$(document).on('click', '[data-photo-remove]', function () {
			var $box = $(this).closest('[data-photo]');
			$box.find('input').val('');
			$box.find('img').attr('src', $box.find('img').data('placeholder'));
			$(this).prop('hidden', true);
		});

		// Booking page: form field editor.
		var $fields = $('.nb-fields tbody');
		$('[data-add-field]').on('click', function () {
			var html = $('#nemvo-booking-field-template').html().replace(/__i__/g, 'n' + Date.now());
			$fields.append(html);
			$fields.find('tr:last input:first').trigger('focus');
		});
		$fields.on('click', '[data-remove-field]', function () {
			$(this).closest('tr').remove();
		});
		$fields.on('click', '[data-move]', function () {
			var $row = $(this).closest('tr');
			if (+$(this).data('move') < 0) {
				$row.prev().before($row);
			} else {
				$row.next().after($row);
			}
		});
		$fields.on('change', '[data-field-type]', function () {
			$(this).closest('tr').find('.nb-options').prop('hidden', $(this).val() !== 'select');
		});
	});
})(jQuery, window.nemvoBookingAdmin || {});
