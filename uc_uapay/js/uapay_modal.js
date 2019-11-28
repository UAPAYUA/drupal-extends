/**
 * @file
 * Handles asynchronous requests for order editing forms.
 */

jQuery(function ($) {
  Drupal.behaviors.uc_uapay = {
      attach: function (context, settings) {
        $('#edit-submit').click(function () {
          // var form = $('#uc-payment-uapay-offsite-form input')
          // $.each(form, function (key, item) {
          //   item.remove()
          console.log($('#uc-payment-uapay-offsite-form').attr("action"))
            location.href = $('#uc-payment-uapay-offsite-form').attr("action")
          // })
        });
      }
  }
});
