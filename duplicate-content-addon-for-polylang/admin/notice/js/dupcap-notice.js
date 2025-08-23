
(function ($) {
    jQuery(document).on('click', '.is-dismissible', (event) => {
      const data = jQuery(event.target).closest('.is-dismissible');
      const nonce = data.data('nonce');
      const url = data.data('url');
  
      jQuery.ajax({
        type: 'POST',
        url: url, // Set this using wp_localize_script
        data: {
          action: 'dupcap_notice_dismiss',
          dupcap_atp_dismiss: true,
          nonce: nonce,
        },
        error: (xhr, status, error) => {
          console.log(xhr.responseText);
          console.log(error);
          console.log(status);
        },
      });
    });
  
  })(jQuery);

