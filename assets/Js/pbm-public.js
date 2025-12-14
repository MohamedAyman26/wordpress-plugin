jQuery(function($) {
    var $form = $('.pbm-booking-form');
    if (!$form.length) return;

    var $totalBox   = $form.find('.pbm-total-box');
    var $totalValue = $form.find('.pbm-total-value');
    var $totalDetails = $form.find('.pbm-total-details');

    function collectData() {
        return {
            action: 'pbm_calc_price',
            nonce: PBM_Ajax.nonce,
            parking_type: $form.find('input[name="parking_type"]:checked').val() || '',
            start_datetime: $form.find('input[name="start_datetime"]').val(),
            end_datetime: $form.find('input[name="end_datetime"]').val(),
            payment_method: $form.find('input[name="payment_method"]:checked').val() || 'cash',
            promo_code: $form.find('input[name="promo_code"]').val()
        };
    }

    var timer = null;

    function updatePrice() {
        var data = collectData();

        if (!data.parking_type || !data.start_datetime || !data.end_datetime) {
            $totalValue.text('--');
            $totalDetails.text('');
            return;
        }

        if (timer) clearTimeout(timer);
        timer = setTimeout(function() {
            $.post(PBM_Ajax.url, data, function(response) {
                if (!response || !response.success) {
                    $totalValue.text('--');
                    $totalDetails.text('');
                    return;
                }

                var r = response.data;
                $totalValue.text(r.currency + ' ' + r.total.toFixed(2));

                var details = [];
                details.push('Base: ' + r.currency + ' ' + r.base_price.toFixed(2));
                if (r.online_discount > 0) {
                    details.push('Online -' + r.currency + ' ' + r.online_discount.toFixed(2));
                }
                if (r.promo_discount > 0) {
                    details.push('Promo -' + r.currency + ' ' + r.promo_discount.toFixed(2));
                }

                $totalDetails.text(details.join(' | '));
            });
        }, 300);
    }

    // events
    $form.on('change input', 'input', updatePrice);
});
