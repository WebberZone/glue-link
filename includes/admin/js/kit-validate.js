/**
 * ConvertKit API validation script.
 *
 * @package WebberZone\Glue_Link
 */

jQuery(document).ready(function ($) {
	/**
	 * Handle API field validation.
	 *
	 * @param {Object} config Configuration object.
	 * @param {string} config.fieldName Name of the field to validate.
	 * @param {string} config.action AJAX action name.
	 * @param {jQuery} config.$button Button that triggered the validation.
	 */
	function validateApiField(config) {
		var $status = config.$button.siblings('.api-validation-status');
		var $input = $('input[name="glue_link_settings[' + config.fieldName + ']"]');

		if (!$input.length) {
			$status.html('<span style="color: #a60000;">Field not found.</span>');
			return;
		}

		config.$button.prop('disabled', true);
		$status.html('<span class="spinner is-active" style="float: none; margin: 0;"></span>');

		var data = {
			action: config.action,
			nonce: GlueLinkAdmin.nonce
		};
		data[config.fieldName] = $input.val();

		$.ajax({
			url: ajaxurl,
			type: 'POST',
			data: data,
			success: function (response) {
				if (response.success) {
					$status.html('<span style="color: green;">' + response.data.message + '</span>');
				} else {
					$status.html('<span style="color: #a60000;">' + response.data.message + '</span>');
				}
			},
			error: function () {
				$status.html('<span style="color: #a60000;">' + glue_link_admin.strings.api_validation_error + '</span>');
			},
			complete: function () {
				config.$button.prop('disabled', false);
			}
		});
	}

	// Handle API key validation.
	$('.validate-api-key').on('click', function (e) {
		e.preventDefault();
		validateApiField({
			fieldName: 'kit_api_key',
			action: 'glue_link_validate_api',
			$button: $(this)
		});
	});

	// Handle API secret validation.
	$('.validate-api-secret').on('click', function (e) {
		e.preventDefault();
		validateApiField({
			fieldName: 'kit_api_secret',
			action: 'glue_link_validate_api_secret',
			$button: $(this)
		});
	});
});
