<?php
/**
 * Plugin Name: Parking Booking Manager Pro
 * Description: Advanced parking booking system with smart pricing (day/monthly/event), internal/external, online discount, promo codes, email + WhatsApp notifications.
 * Version: 2.0.0
 * Author: Mohamed
 * Text Domain: parking-booking-manager-pro
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Parking_Booking_Manager_Pro' ) ) :

class Parking_Booking_Manager_Pro {

    private $bookings_table;
    private $promo_table;

    public function __construct() {
        global $wpdb;

        $this->bookings_table = $wpdb->prefix . 'parking_bookings';
        $this->promo_table    = $wpdb->prefix . 'parking_promo_codes';

        // Hooks
        add_action( 'init', array( $this, 'register_shortcodes' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_public_assets' ) );

        add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );

        add_action( 'admin_post_pbm_update_status', array( $this, 'handle_status_update' ) );
        add_action( 'admin_post_pbm_save_settings', array( $this, 'handle_save_settings' ) );
        add_action( 'admin_post_pbm_save_promo', array( $this, 'handle_save_promo' ) );
        add_action( 'admin_post_pbm_delete_promo', array( $this, 'handle_delete_promo' ) );
        add_action( 'wp_ajax_pbm_calc_price', array( $this, 'ajax_calc_price' ) );
        add_action( 'wp_ajax_nopriv_pbm_calc_price', array( $this, 'ajax_calc_price' ) );
    }

    public function ajax_calc_price() {
        check_ajax_referer( 'pbm_calc_price_nonce', 'nonce' );

        $parking_type   = sanitize_text_field( $_POST['parking_type'] ?? '' );
        $start_raw      = sanitize_text_field( $_POST['start_datetime'] ?? '' );
        $end_raw        = sanitize_text_field( $_POST['end_datetime'] ?? '' );
        $payment_method = sanitize_text_field( $_POST['payment_method'] ?? 'cash' );
        $promo_code_in  = sanitize_text_field( $_POST['promo_code'] ?? '' );

        try {
            if ( empty( $start_raw ) || empty( $end_raw ) || empty( $parking_type ) ) {
                throw new Exception( 'Missing data' );
            }

            $start_dt = new DateTime( $start_raw );
            $end_dt   = new DateTime( $end_raw );

            if ( $end_dt <= $start_dt ) {
                throw new Exception( 'End must be after start' );
            }

            $pricing = $this->calculate_smart_price( $start_dt, $end_dt, $parking_type );
            $base_price   = $pricing['base_price'];
            $booking_type = $pricing['booking_type'];

            // Online discount
            $online_enabled = intval( get_option( 'pbm_online_discount_enabled', 1 ) );
            $online_percent = floatval( get_option( 'pbm_online_discount_percent', 10 ) );

            $online_discount = 0;
            $after_online    = $base_price;

            if ( $payment_method === 'online' && $online_enabled && $online_percent > 0 ) {
                $online_discount = round( $base_price * ( $online_percent / 100 ), 2 );
                $after_online    = max( 0, $base_price - $online_discount );
            }

            // Promo (حساب فقط – بدون زيادة used_count)
            $promo_discount = 0;
            $promo_code     = '';

            if ( ! empty( $promo_code_in ) ) {
                $promo = $this->validate_and_apply_promo( $promo_code_in, $after_online, $payment_method );
                if ( $promo['valid'] ) {
                    $promo_discount = $promo['discount_amount'];
                    $promo_code     = $promo['code'];
                    $after_online   = max( 0, $after_online - $promo_discount );
                }
            }

            $total = $after_online;
            $currency = strtoupper( get_option( 'pbm_currency', 'usd' ) );

            wp_send_json_success( array(
                'base_price'      => $base_price,
                'online_discount' => $online_discount,
                'promo_discount'  => $promo_discount,
                'total'           => $total,
                'booking_type'    => $booking_type,
                'currency'        => $currency,
                'promo_code'      => $promo_code,
            ) );

        } catch ( Exception $e ) {
            wp_send_json_error( array(
                'message' => $e->getMessage(),
            ) );
        }
    }

    /**
     * Install DB tables & default options.
     */
    public static function install() {
        global $wpdb;

        $bookings_table  = $wpdb->prefix . 'parking_bookings';
        $promo_table     = $wpdb->prefix . 'parking_promo_codes';
        $charset_collate = $wpdb->get_charset_collate();

        // Stripe options
        add_option("pbm_stripe_enabled", 0);
        add_option("pbm_stripe_secret_key", "");
        add_option("pbm_stripe_publishable_key", "");

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // Bookings
        $sql1 = "CREATE TABLE $bookings_table (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            customer_name VARCHAR(255) NOT NULL,
            customer_email VARCHAR(255) DEFAULT NULL,
            customer_phone VARCHAR(50) DEFAULT NULL,
            car_plate VARCHAR(50) DEFAULT NULL,
            start_datetime DATETIME NOT NULL,
            end_datetime DATETIME NOT NULL,
            parking_type VARCHAR(20) NOT NULL,
            booking_type VARCHAR(20) NOT NULL,
            payment_method VARCHAR(20) NOT NULL,
            base_price DECIMAL(10,2) NOT NULL DEFAULT 0,
            discount_online DECIMAL(10,2) NOT NULL DEFAULT 0,
            discount_promo DECIMAL(10,2) NOT NULL DEFAULT 0,
            total_price DECIMAL(10,2) NOT NULL DEFAULT 0,
            promo_code VARCHAR(100) DEFAULT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset_collate;";

        // Promo codes
        $sql2 = "CREATE TABLE $promo_table (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            code VARCHAR(50) NOT NULL UNIQUE,
            discount_type VARCHAR(20) NOT NULL,
            discount_value DECIMAL(10,2) NOT NULL,
            min_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            max_uses INT NOT NULL DEFAULT 0,
            used_count INT NOT NULL DEFAULT 0,
            valid_from DATE NULL,
            valid_to DATE NULL,
            allow_online TINYINT(1) NOT NULL DEFAULT 1,
            allow_cash TINYINT(1) NOT NULL DEFAULT 1,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset_collate;";

        dbDelta( $sql1 );
        dbDelta( $sql2 );

        // Default pricing
        add_option( 'pbm_day_internal', 10 );
        add_option( 'pbm_day_external', 7 );
        add_option( 'pbm_month_internal', 90 );
        add_option( 'pbm_month_external', 70 );
        add_option( 'pbm_event_internal', 20 );
        add_option( 'pbm_event_external', 12 );

        // Monthly threshold
        add_option( 'pbm_monthly_threshold_days', 28 );

        // Online discount
        add_option( 'pbm_online_discount_enabled', 1 );
        add_option( 'pbm_online_discount_percent', 10 );

        // Event dates (Y-m-d separated by comma or new line)
        add_option( 'pbm_event_dates', '' );

        // Currency
        add_option( 'pbm_currency', 'usd' );

        // WhatsApp Cloud API
        add_option( 'pbm_whatsapp_enabled', 0 );
        add_option( 'pbm_whatsapp_phone_id', '' );
        add_option( 'pbm_whatsapp_token', '' );
        add_option( 'pbm_whatsapp_admin_number', '' );
    }

    public function enqueue_public_assets() {
        wp_enqueue_style(
            'pbm-public',
            plugins_url( 'assets/css/pbm-public.css', __FILE__ ),
            array(),
            '2.0.0'
        );

        wp_enqueue_script(
            'pbm-public-js',
            plugins_url( 'assets/js/pbm-public.js', __FILE__ ),
            array( 'jquery' ),
            '2.0.0',
            true
        );

        wp_localize_script(
            'pbm-public-js',
            'PBM_Ajax',
            array(
                'url'      => admin_url( 'admin-ajax.php' ),
                'nonce'    => wp_create_nonce( 'pbm_calc_price_nonce' ),
                'currency' => strtoupper( get_option( 'pbm_currency', 'usd' ) ),
            )
        );
    }

    public function register_shortcodes() {
        add_shortcode( 'parking_booking_form', array( $this, 'render_booking_form' ) );
    }

    /**
     * Front-end booking form.
     */
    public function render_booking_form() {
        if ( is_admin() ) {
            return '';
        }

        global $wpdb;
        $output = '';

        // Stripe return messages
        if ( isset( $_GET['pbm_stripe_success'], $_GET['pbm_booking_id'] ) ) {
            $output .= '<div class="pbm-success">Payment successful. Your booking is now confirmed.</div>';
        }
        if ( isset( $_GET['pbm_stripe_cancel'], $_GET['pbm_booking_id'] ) ) {
            $output .= '<div class="pbm-error">Payment was cancelled. Your booking is still pending.</div>';
        }

        if ( isset( $_GET['pbm_status'] ) ) {
            if ( $_GET['pbm_status'] === 'success' ) {
                $output .= '<div class="pbm-success">Your booking has been created successfully.</div>';
            } elseif ( $_GET['pbm_status'] === 'error' ) {
                $output .= '<div class="pbm-error">There was an error while creating your booking. Please try again.</div>';
            }
        }

        if (
            isset( $_POST['pbm_booking_form'] )
            && isset( $_POST['pbm_nonce'] )
            && wp_verify_nonce( $_POST['pbm_nonce'], 'pbm_booking_nonce' )
        ) {
            $customer_name  = sanitize_text_field( $_POST['customer_name'] ?? '' );
            $customer_email = sanitize_email( $_POST['customer_email'] ?? '' );
            $customer_phone = sanitize_text_field( $_POST['customer_phone'] ?? '' );
            $car_plate      = sanitize_text_field( $_POST['car_plate'] ?? '' );
            $parking_type   = sanitize_text_field( $_POST['parking_type'] ?? '' );
            $start_raw      = sanitize_text_field( $_POST['start_datetime'] ?? '' );
            $end_raw        = sanitize_text_field( $_POST['end_datetime'] ?? '' );
            $payment_method = sanitize_text_field( $_POST['payment_method'] ?? 'cash' );
            $promo_code_in  = sanitize_text_field( $_POST['promo_code'] ?? '' );

            try {
                if ( empty( $customer_name ) || empty( $start_raw ) || empty( $end_raw ) || empty( $parking_type ) ) {
                    throw new Exception( 'Please fill all required fields.' );
                }

                $start_dt = new DateTime( $start_raw );
                $end_dt   = new DateTime( $end_raw );

                if ( $end_dt <= $start_dt ) {
                    throw new Exception( 'End date/time must be after start date/time.' );
                }

                // Smart pricing
                $pricing = $this->calculate_smart_price( $start_dt, $end_dt, $parking_type );
                $base_price   = $pricing['base_price'];
                $booking_type = $pricing['booking_type'];

                // Online discount
                $online_discount = 0;
                $after_online = $base_price;

                $online_enabled = intval( get_option( 'pbm_online_discount_enabled', 1 ) );
                $online_percent = floatval( get_option( 'pbm_online_discount_percent', 10 ) );

                if ( $payment_method === 'online' && $online_enabled && $online_percent > 0 ) {
                    $online_discount = round( $base_price * ( $online_percent / 100 ), 2 );
                    $after_online    = max( 0, $base_price - $online_discount );
                }

                // Promo code
                $promo_code    = '';
                $promo_discount = 0;

                if ( ! empty( $promo_code_in ) ) {
                    $promo = $this->validate_and_apply_promo(
                        $promo_code_in,
                        $after_online,
                        $payment_method
                    );

                    if ( $promo['valid'] ) {
                        $promo_code     = $promo['code'];
                        $promo_discount = $promo['discount_amount'];
                        $after_online   = max( 0, $after_online - $promo_discount );

                        if ( ! empty( $promo['id'] ) ) {
                            $wpdb->query(
                                $wpdb->prepare(
                                    "UPDATE {$this->promo_table} SET used_count = used_count + 1 WHERE id = %d",
                                    $promo['id']
                                )
                            );
                        }
                    } else {
                        $output .= '<div class="pbm-error">Promo code is invalid or expired.</div>';
                    }
                }

                $total_price = $after_online;

                $inserted = $wpdb->insert(
                    $this->bookings_table,
                    array(
                        'customer_name'   => $customer_name,
                        'customer_email'  => $customer_email,
                        'customer_phone'  => $customer_phone,
                        'car_plate'       => $car_plate,
                        'start_datetime'  => $start_dt->format( 'Y-m-d H:i:s' ),
                        'end_datetime'    => $end_dt->format( 'Y-m-d H:i:s' ),
                        'parking_type'    => $parking_type,
                        'booking_type'    => $booking_type,
                        'payment_method'  => $payment_method,
                        'base_price'      => $base_price,
                        'discount_online' => $online_discount,
                        'discount_promo'  => $promo_discount,
                        'total_price'     => $total_price,
                        'promo_code'      => $promo_code,
                        'status'          => 'pending',
                    ),
                    array(
                        '%s','%s','%s','%s',
                        '%s','%s','%s','%s',
                        '%s','%f','%f','%f',
                        '%f','%s','%s'
                    )
                );

                if ( ! $inserted ) {
                    throw new Exception( 'Error saving booking. Please try again.' );
                }

                $booking_id = $wpdb->insert_id;

                $this->send_email_notifications( $booking_id );
                $this->send_whatsapp_notifications( $booking_id );

                $currency = strtoupper( get_option( 'pbm_currency', 'usd' ) );

                $stripe_enabled = intval( get_option( 'pbm_stripe_enabled', 0 ) );
                if ( $payment_method === 'online' && $stripe_enabled ) {
                    $session_url = $this->create_stripe_checkout_session(
                        $booking_id,
                        $total_price,
                        $currency
                    );

                    if ( $session_url ) {
                        // Keep booking pending until Stripe returns
                        wp_redirect( $session_url );
                        exit;
                    } else {
                        $output .= '<div class="pbm-error">Booking created but Stripe payment could not be started. Please contact support.</div>';
                    }
                }

                // If cash or Stripe not enabled → success message
                wp_redirect( add_query_arg( 'pbm_status', 'success', get_permalink() ) );
                exit;

            } catch ( Exception $e ) {
                $output .= '<div class="pbm-error">' . esc_html( $e->getMessage() ) . '</div>';
            }
        }

        ob_start();
        ?>
        <div class="pbm-wrapper">
            <div class="pbm-header">
                <h2><?php esc_html_e( 'Book your parking space', 'parking-booking-manager-pro' ); ?></h2>
                <p><?php esc_html_e( 'Choose your dates and parking type and we calculate the best price for you.', 'parking-booking-manager-pro' ); ?></p>
            </div>

            <form method="post" class="pbm-booking-form pbm-line-form">
                <div class="pbm-line-scroll">

                    <!-- Parking type -->
                    <div class="pbm-line-item">
                        <label><?php esc_html_e( 'Parking type', 'parking-booking-manager-pro' ); ?></label>
                        <select name="parking_type" required>
                            <option value=""><?php esc_html_e( 'Select', 'parking-booking-manager-pro' ); ?></option>
                            <option value="internal"><?php esc_html_e( 'Internal', 'parking-booking-manager-pro' ); ?></option>
                            <option value="external"><?php esc_html_e( 'External', 'parking-booking-manager-pro' ); ?></option>
                        </select>
                    </div>

                    <!-- Start datetime -->
                    <div class="pbm-line-item">
                        <label><?php esc_html_e( 'Start date & time *', 'parking-booking-manager-pro' ); ?></label>
                        <input type="datetime-local" name="start_datetime" required>
                    </div>

                    <!-- End datetime -->
                    <div class="pbm-line-item">
                        <label><?php esc_html_e( 'End date & time *', 'parking-booking-manager-pro' ); ?></label>
                        <input type="datetime-local" name="end_datetime" required>
                    </div>

                    <!-- Payment method -->
                    <div class="pbm-line-item">
                        <label><?php esc_html_e( 'Payment', 'parking-booking-manager-pro' ); ?></label>
                        <select name="payment_method" required>
                            <option value="online"><?php esc_html_e( 'Online (with discount)', 'parking-booking-manager-pro' ); ?></option>
                            <option value="cash"><?php esc_html_e( 'Cash on arrival', 'parking-booking-manager-pro' ); ?></option>
                        </select>
                    </div>

                    <!-- Promo code -->
                    <div class="pbm-line-item">
                        <label><?php esc_html_e( 'Promo code', 'parking-booking-manager-pro' ); ?></label>
                        <input type="text" name="promo_code" placeholder="<?php esc_attr_e( 'Optional', 'parking-booking-manager-pro' ); ?>">
                    </div>

                    <!-- Total price (live) -->
                    <div class="pbm-line-item pbm-line-total">
                        <label><?php esc_html_e( 'Total price', 'parking-booking-manager-pro' ); ?></label>
                        <div class="pbm-total-box-inline">
                            <span class="pbm-total-value">--</span>
                            <span class="pbm-total-details"></span>
                        </div>
                    </div>

                    <!-- Submit button -->
                    <div class="pbm-line-item pbm-line-button">
                        <label>&nbsp;</label>
                        <button type="submit" class="pbm-submit">
                            <?php esc_html_e( 'Book now', 'parking-booking-manager-pro' ); ?>
                        </button>
                    </div>
                </div>

                <?php wp_nonce_field( 'pbm_booking_nonce', 'pbm_nonce' ); ?>
                <input type="hidden" name="pbm_booking_form" value="1">
            </form>
        </div>
        <?php
        $form_html = ob_get_clean();

        return $output . $form_html;
    }

    /**
     * Smart pricing engine.
     */
    private function calculate_smart_price( DateTime $start, DateTime $end, $parking_type ) {
        $diff  = $start->diff( $end );
        $days  = (int) $diff->days;
        if ( $days < 1 ) {
            $days = 1;
        }

        $day_int   = floatval( get_option( 'pbm_day_internal', 10 ) );
        $day_ext   = floatval( get_option( 'pbm_day_external', 7 ) );
        $month_int = floatval( get_option( 'pbm_month_internal', 90 ) );
        $month_ext = floatval( get_option( 'pbm_month_external', 70 ) );
        $event_int = floatval( get_option( 'pbm_event_internal', 20 ) );
        $event_ext = floatval( get_option( 'pbm_event_external', 12 ) );

        $threshold = intval( get_option( 'pbm_monthly_threshold_days', 28 ) );
        if ( $threshold < 1 ) {
            $threshold = 28;
        }

        // Parse event dates
        $event_dates_raw = get_option( 'pbm_event_dates', '' );
        $event_dates = array();
        if ( ! empty( $event_dates_raw ) ) {
            $lines = preg_split( '/[\r\n,]+/', $event_dates_raw );
            foreach ( $lines as $line ) {
                $line = trim( $line );
                if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $line ) ) {
                    $event_dates[] = $line;
                }
            }
        }

        $is_internal = ( $parking_type === 'internal' );
        $base_price  = 0;
        $booking_type = 'day';

        if ( $days < $threshold ) {
            // day / event logic
            $event_days = 0;

            if ( ! empty( $event_dates ) ) {
                $period = new DatePeriod(
                    (clone $start),
                    new DateInterval( 'P1D' ),
                    (clone $end)
                );
                foreach ( $period as $dt ) {
                    $d = $dt->format( 'Y-m-d' );
                    if ( in_array( $d, $event_dates, true ) ) {
                        $event_days++;
                    }
                }
            }

            $normal_days = $days - $event_days;
            if ( $normal_days < 0 ) {
                $normal_days = 0;
            }

            if ( $is_internal ) {
                $base_price = $normal_days * $day_int + $event_days * $event_int;
            } else {
                $base_price = $normal_days * $day_ext + $event_days * $event_ext;
            }

            $booking_type = ( $event_days > 0 ) ? 'event' : 'day';

        } else {
            // monthly logic
            $months     = intdiv( $days, $threshold );
            $extra_days = $days % $threshold;

            if ( $is_internal ) {
                $base_price = $months * $month_int + $extra_days * $day_int;
            } else {
                $base_price = $months * $month_ext + $extra_days * $day_ext;
            }

            $booking_type = 'monthly';
        }

        return array(
            'base_price'   => round( $base_price, 2 ),
            'booking_type' => $booking_type,
        );
    }

    /**
     * Validate and apply promo code.
     */
    private function validate_and_apply_promo( $code, $current_amount, $payment_method ) {
        global $wpdb;

        $result = array(
            'valid'           => false,
            'discount_amount' => 0,
            'code'            => '',
            'id'              => 0,
        );

        $code = strtoupper( trim( $code ) );
        if ( empty( $code ) || $current_amount <= 0 ) {
            return $result;
        }

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->promo_table} WHERE UPPER(code) = %s AND active = 1",
                $code
            )
        );
        if ( ! $row ) {
            return $result;
        }

        $today = current_time( 'Y-m-d' );

        if ( $row->valid_from && $today < $row->valid_from ) {
            return $result;
        }
        if ( $row->valid_to && $today > $row->valid_to ) {
            return $result;
        }

        if ( $row->max_uses > 0 && $row->used_count >= $row->max_uses ) {
            return $result;
        }

        if ( $row->min_amount > 0 && $current_amount < $row->min_amount ) {
            return $result;
        }

        if ( $payment_method === 'online' && ! $row->allow_online ) {
            return $result;
        }
        if ( $payment_method === 'cash' && ! $row->allow_cash ) {
            return $result;
        }

        $discount = 0;
        if ( $row->discount_type === 'percent' ) {
            $discount = round( $current_amount * ( $row->discount_value / 100 ), 2 );
        } else {
            $discount = min( $current_amount, $row->discount_value );
        }

        if ( $discount <= 0 ) {
            return $result;
        }

        $result['valid']           = true;
        $result['discount_amount'] = $discount;
        $result['code']            = $row->code;
        $result['id']              = $row->id;

        return $result;
    }

    /**
     * Email notifications.
     */
    private function send_email_notifications( $booking_id ) {
        global $wpdb;

        $booking = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->bookings_table} WHERE id = %d",
                $booking_id
            )
        );
        if ( ! $booking ) {
            return;
        }

        $currency    = strtoupper( get_option( 'pbm_currency', 'usd' ) );
        $admin_email = get_option( 'admin_email' );

        $subject_client = sprintf( 'Your parking booking #%d', $booking_id );
        $subject_admin  = sprintf( 'New parking booking #%d', $booking_id );

        ob_start();
        ?>
        <h2>Thank you for your booking</h2>
        <p>Hello <?php echo esc_html( $booking->customer_name ); ?>,</p>
        <p>Your booking has been created successfully. Here are the details:</p>
        <ul>
            <li><strong>Booking ID:</strong> <?php echo esc_html( $booking_id ); ?></li>
            <li><strong>Parking type:</strong> <?php echo esc_html( ucfirst( $booking->parking_type ) ); ?></li>
            <li><strong>Booking type:</strong> <?php echo esc_html( ucfirst( $booking->booking_type ) ); ?></li>
            <li><strong>Start:</strong> <?php echo esc_html( $booking->start_datetime ); ?></li>
            <li><strong>End:</strong> <?php echo esc_html( $booking->end_datetime ); ?></li>
            <li><strong>Payment method:</strong> <?php echo esc_html( ucfirst( $booking->payment_method ) ); ?></li>
            <li><strong>Base price:</strong> <?php echo esc_html( $currency . ' ' . number_format( $booking->base_price, 2 ) ); ?></li>
            <?php if ( $booking->discount_online > 0 ) : ?>
                <li><strong>Online discount:</strong> -<?php echo esc_html( $currency . ' ' . number_format( $booking->discount_online, 2 ) ); ?></li>
            <?php endif; ?>
            <?php if ( ! empty( $booking->promo_code ) ) : ?>
                <li><strong>Promo code:</strong> <?php echo esc_html( $booking->promo_code ); ?> (<?php echo esc_html( $currency . ' ' . number_format( $booking->discount_promo, 2 ) ); ?>)</li>
            <?php endif; ?>
            <li><strong>Total:</strong> <?php echo esc_html( $currency . ' ' . number_format( $booking->total_price, 2 ) ); ?></li>
            <li><strong>Status:</strong> <?php echo esc_html( ucfirst( $booking->status ) ); ?></li>
        </ul>
        <p>We look forward to seeing you.</p>
        <?php
        $message_client = ob_get_clean();

        ob_start();
        ?>
        <h2>New parking booking</h2>
        <ul>
            <li><strong>ID:</strong> <?php echo esc_html( $booking_id ); ?></li>
            <li><strong>Name:</strong> <?php echo esc_html( $booking->customer_name ); ?></li>
            <li><strong>Email:</strong> <?php echo esc_html( $booking->customer_email ); ?></li>
            <li><strong>Phone:</strong> <?php echo esc_html( $booking->customer_phone ); ?></li>
            <li><strong>Car Plate:</strong> <?php echo esc_html( $booking->car_plate ); ?></li>
            <li><strong>Parking type:</strong> <?php echo esc_html( ucfirst( $booking->parking_type ) ); ?></li>
            <li><strong>Booking type:</strong> <?php echo esc_html( ucfirst( $booking->booking_type ) ); ?></li>
            <li><strong>Start:</strong> <?php echo esc_html( $booking->start_datetime ); ?></li>
            <li><strong>End:</strong> <?php echo esc_html( $booking->end_datetime ); ?></li>
            <li><strong>Payment method:</strong> <?php echo esc_html( ucfirst( $booking->payment_method ) ); ?></li>
            <li><strong>Total:</strong> <?php echo esc_html( $currency . ' ' . number_format( $booking->total_price, 2 ) ); ?></li>
        </ul>
        <?php
        $message_admin = ob_get_clean();

        $headers = array( 'Content-Type: text/html; charset=UTF-8' );

        if ( ! empty( $booking->customer_email ) ) {
            wp_mail( $booking->customer_email, $subject_client, $message_client, $headers );
        }

        if ( ! empty( $admin_email ) ) {
            wp_mail( $admin_email, $subject_admin, $message_admin, $headers );
        }
    }

    /**
     * WhatsApp (Cloud API) notification.
     */
    private function send_whatsapp_notifications( $booking_id ) {
        $enabled = intval( get_option( 'pbm_whatsapp_enabled', 0 ) );
        if ( ! $enabled ) {
            return;
        }

        global $wpdb;
        $booking = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->bookings_table} WHERE id = %d",
                $booking_id
            )
        );
        if ( ! $booking ) {
            return;
        }

        $phone_id = trim( get_option( 'pbm_whatsapp_phone_id', '' ) );
        $token    = trim( get_option( 'pbm_whatsapp_token', '' ) );
        $admin_no = trim( get_option( 'pbm_whatsapp_admin_number', '' ) );

        if ( empty( $phone_id ) || empty( $token ) ) {
            return;
        }

        $currency = strtoupper( get_option( 'pbm_currency', 'usd' ) );

        $client_msg = "Hello {$booking->customer_name}, your parking booking #{$booking_id} has been created.\n"
                    . "Type: {$booking->parking_type} ({$booking->booking_type})\n"
                    . "From: {$booking->start_datetime}\n"
                    . "To: {$booking->end_datetime}\n"
                    . "Payment: {$booking->payment_method}\n"
                    . "Total: {$currency} " . number_format( $booking->total_price, 2 );

        if ( ! empty( $booking->customer_phone ) ) {
            $this->whatsapp_send( $phone_id, $token, $booking->customer_phone, $client_msg );
        }

        if ( ! empty( $admin_no ) ) {
            $admin_msg = "New booking #{$booking_id}\n"
                       . "Name: {$booking->customer_name}\n"
                       . "Phone: {$booking->customer_phone}\n"
                       . "Type: {$booking->parking_type} ({$booking->booking_type})\n"
                       . "Total: {$currency} " . number_format( $booking->total_price, 2 );
            $this->whatsapp_send( $phone_id, $token, $admin_no, $admin_msg );
        }
    }

    private function whatsapp_send( $phone_id, $token, $to_number, $body_text ) {
        $to_number = preg_replace( '/\D+/', '', $to_number );
        if ( empty( $to_number ) ) {
            return;
        }

        $url = 'https://graph.facebook.com/v18.0/' . $phone_id . '/messages';

        $payload = array(
            'messaging_product' => 'whatsapp',
            'to'                => $to_number,
            'type'              => 'text',
            'text'              => array(
                'preview_url' => false,
                'body'        => $body_text,
            ),
        );

        $args = array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ),
            'body'    => wp_json_encode( $payload ),
            'timeout' => 30,
        );

        wp_remote_post( $url, $args );
    }

    private function create_stripe_checkout_session( $booking_id, $amount, $currency ) {
        $secret_key = trim( get_option( 'pbm_stripe_secret_key', '' ) );
        if ( empty( $secret_key ) ) {
            return false;
        }

        $amount_cents = (int) round( $amount * 100 );
        $currency     = strtolower( $currency );

        // success / cancel URLs return to the same booking page
        $success_url = add_query_arg(
            array(
                'pbm_stripe_success' => 1,
                'pbm_booking_id'     => $booking_id,
            ),
            get_permalink()
        );

        $cancel_url = add_query_arg(
            array(
                'pbm_stripe_cancel' => 1,
                'pbm_booking_id'    => $booking_id,
            ),
            get_permalink()
        );

        $body = array(
            'mode'                        => 'payment',
            'success_url'                 => $success_url,
            'cancel_url'                  => $cancel_url,
            'line_items[0][price_data][currency]'          => $currency,
            'line_items[0][price_data][product_data][name]' => 'Parking Booking #' . $booking_id,
            'line_items[0][price_data][unit_amount]'       => $amount_cents,
            'line_items[0][quantity]'                      => 1,
        );

        $response = wp_remote_post(
            'https://api.stripe.com/v1/checkout/sessions',
            array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $secret_key,
                ),
                'body'    => $body,
                'timeout' => 60,
            )
        );

        if ( is_wp_error( $response ) ) {
            return false;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );

        if ( $code !== 200 && $code !== 201 ) {
            return false;
        }

        $data = json_decode( $body, true );
        if ( ! isset( $data['url'] ) ) {
            return false;
        }

        return $data['url'];
    }

    /**
     * Admin menu.
     */
    public function register_admin_menu() {
        add_menu_page(
            __( 'Parking Booking', 'parking-booking-manager-pro' ),
            __( 'Parking Booking', 'parking-booking-manager-pro' ),
            'manage_options',
            'pbm-bookings',
            array( $this, 'admin_bookings_page' ),
            'dashicons-car',
            26
        );

        add_submenu_page(
            'pbm-bookings',
            __( 'Bookings', 'parking-booking-manager-pro' ),
            __( 'Bookings', 'parking-booking-manager-pro' ),
            'manage_options',
            'pbm-bookings',
            array( $this, 'admin_bookings_page' )
        );

        add_submenu_page(
            'pbm-bookings',
            __( 'Settings', 'parking-booking-manager-pro' ),
            __( 'Settings', 'parking-booking-manager-pro' ),
            'manage_options',
            'pbm-settings',
            array( $this, 'admin_settings_page' )
        );

        add_submenu_page(
            'pbm-bookings',
            __( 'Promo Codes', 'parking-booking-manager-pro' ),
            __( 'Promo Codes', 'parking-booking-manager-pro' ),
            'manage_options',
            'pbm-promo-codes',
            array( $this, 'admin_promo_page' )
        );
    }

    /**
     * Bookings page.
     */
    public function admin_bookings_page() {
        global $wpdb;

        $bookings = $wpdb->get_results( "SELECT * FROM {$this->bookings_table} ORDER BY created_at DESC" );
        $currency = strtoupper( get_option( 'pbm_currency', 'usd' ) );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Parking Bookings', 'parking-booking-manager-pro' ); ?></h1>

            <table class="widefat fixed striped">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Customer</th>
                        <th>Phone</th>
                        <th>Type</th>
                        <th>Booking Type</th>
                        <th>Start</th>
                        <th>End</th>
                        <th>Payment</th>
                        <th>Total</th>
                        <th>Status</th>
                        <th>Promo</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ( $bookings ) : ?>
                    <?php foreach ( $bookings as $b ) : ?>
                        <tr>
                            <td><?php echo esc_html( $b->id ); ?></td>
                            <td><?php echo esc_html( $b->customer_name ); ?></td>
                            <td><?php echo esc_html( $b->customer_phone ); ?></td>
                            <td><?php echo esc_html( ucfirst( $b->parking_type ) ); ?></td>
                            <td><?php echo esc_html( ucfirst( $b->booking_type ) ); ?></td>
                            <td><?php echo esc_html( $b->start_datetime ); ?></td>
                            <td><?php echo esc_html( $b->end_datetime ); ?></td>
                            <td><?php echo esc_html( ucfirst( $b->payment_method ) ); ?></td>
                            <td><?php echo esc_html( $currency . ' ' . number_format( $b->total_price, 2 ) ); ?></td>
                            <td><?php echo esc_html( ucfirst( $b->status ) ); ?></td>
                            <td><?php echo esc_html( $b->promo_code ); ?></td>
                            <td>
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                                    <?php wp_nonce_field( 'pbm_update_status_action', 'pbm_update_status_nonce' ); ?>
                                    <input type="hidden" name="action" value="pbm_update_status">
                                    <input type="hidden" name="booking_id" value="<?php echo esc_attr( $b->id ); ?>">
                                    <select name="status">
                                        <option value="pending"   <?php selected( $b->status, 'pending' ); ?>>Pending</option>
                                        <option value="confirmed" <?php selected( $b->status, 'confirmed' ); ?>>Confirmed</option>
                                        <option value="canceled"  <?php selected( $b->status, 'canceled' ); ?>>Canceled</option>
                                    </select>
                                    <button class="button button-small" type="submit">Update</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else : ?>
                    <tr><td colspan="12">No bookings found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public function handle_status_update() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Not allowed.' );
        }
        check_admin_referer( 'pbm_update_status_action', 'pbm_update_status_nonce' );

        $booking_id = isset( $_POST['booking_id'] ) ? intval( $_POST['booking_id'] ) : 0;
        $status     = isset( $_POST['status'] ) ? sanitize_text_field( $_POST['status'] ) : '';

        $allowed = array( 'pending', 'confirmed', 'canceled' );
        if ( $booking_id && in_array( $status, $allowed, true ) ) {
            global $wpdb;
            $wpdb->update(
                $this->bookings_table,
                array( 'status' => $status ),
                array( 'id' => $booking_id ),
                array( '%s' ),
                array( '%d' )
            );
        }

        wp_redirect( admin_url( 'admin.php?page=pbm-bookings' ) );
        exit;
    }

    /**
     * Settings page.
     */
    public function admin_settings_page() {
        $day_int   = get_option( 'pbm_day_internal', 10 );
        $day_ext   = get_option( 'pbm_day_external', 7 );
        $month_int = get_option( 'pbm_month_internal', 90 );
        $month_ext = get_option( 'pbm_month_external', 70 );
        $event_int = get_option( 'pbm_event_internal', 20 );
        $event_ext = get_option( 'pbm_event_external', 12 );

        $threshold = get_option( 'pbm_monthly_threshold_days', 28 );
        $currency  = get_option( 'pbm_currency', 'usd' );

        $online_enabled = intval( get_option( 'pbm_online_discount_enabled', 1 ) );
        $online_percent = get_option( 'pbm_online_discount_percent', 10 );

        $event_dates = get_option( 'pbm_event_dates', '' );

        $w_enabled  = intval( get_option( 'pbm_whatsapp_enabled', 0 ) );
        $w_phone_id = get_option( 'pbm_whatsapp_phone_id', '' );
        $w_token    = get_option( 'pbm_whatsapp_token', '' );
        $w_admin    = get_option( 'pbm_whatsapp_admin_number', '' );
        $stripe_enabled = intval(get_option("pbm_stripe_enabled",0));
        $stripe_secret_key = get_option("pbm_stripe_secret_key","");
        $stripe_pub = get_option("pbm_stripe_publishable_key","");

        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Parking Booking Settings', 'parking-booking-manager-pro' ); ?></h1>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'pbm_settings_save', 'pbm_settings_nonce' ); ?>
                <input type="hidden" name="action" value="pbm_save_settings">

                <h2>Pricing</h2>
                <table class="form-table">
                    <tr>
                        <th><label>Day Pass - Internal (per day)</label></th>
                        <td><input type="number" step="0.01" name="day_internal" value="<?php echo esc_attr( $day_int ); ?>"></td>
                    </tr>
                    <tr>
                        <th><label>Day Pass - External (per day)</label></th>
                        <td><input type="number" step="0.01" name="day_external" value="<?php echo esc_attr( $day_ext ); ?>"></td>
                    </tr>
                    <tr>
                        <th><label>Monthly - Internal (per month)</label></th>
                        <td><input type="number" step="0.01" name="month_internal" value="<?php echo esc_attr( $month_int ); ?>"></td>
                    </tr>
                    <tr>
                        <th><label>Monthly - External (per month)</label></th>
                        <td><input type="number" step="0.01" name="month_external" value="<?php echo esc_attr( $month_ext ); ?>"></td>
                    </tr>
                    <tr>
                        <th><label>Event - Internal (per day)</label></th>
                        <td><input type="number" step="0.01" name="event_internal" value="<?php echo esc_attr( $event_int ); ?>"></td>
                    </tr>
                    <tr>
                        <th><label>Event - External (per day)</label></th>
                        <td><input type="number" step="0.01" name="event_external" value="<?php echo esc_attr( $event_ext ); ?>"></td>
                    </tr>
                    <tr>
                        <th><label>Monthly threshold (days)</label></th>
                        <td><input type="number" name="monthly_threshold" value="<?php echo esc_attr( $threshold ); ?>"></td>
                    </tr>
                    <tr>
                        <th><label>Currency (e.g., usd)</label></th>
                        <td><input type="text" name="currency" value="<?php echo esc_attr( $currency ); ?>"></td>
                    </tr>
                </table>

                <h2>Online Payment Discount</h2>
                <table class="form-table">
                    <tr>
                        <th><label>Enable Online Discount</label></th>
                        <td>
                            <label>
                                <input type="checkbox" name="online_enabled" value="1" <?php checked( $online_enabled, 1 ); ?>>
                                Enable discount for "online" payment method
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th><label>Discount percent (%)</label></th>
                        <td><input type="number" step="0.01" name="online_percent" value="<?php echo esc_attr( $online_percent ); ?>"></td>
                    </tr>
                </table>

                <h2>Event Dates</h2>
                <p>Write event dates (Y-m-d), one per line or separated with commas.</p>
                <textarea name="event_dates" rows="5" cols="60"><?php echo esc_textarea( $event_dates ); ?></textarea>

                <h2>WhatsApp Cloud API</h2>
                <table class="form-table">
                    <tr>
                        <th><label>Enable WhatsApp notifications</label></th>
                        <td>
                            <label>
                                <input type="checkbox" name="whatsapp_enabled" value="1" <?php checked( $w_enabled, 1 ); ?>>
                                Send WhatsApp messages to customer and admin after booking.
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th><label>Phone Number ID</label></th>
                        <td><input type="text" name="whatsapp_phone_id" value="<?php echo esc_attr( $w_phone_id ); ?>" style="width: 350px;"></td>
                    </tr>
                    <tr>
                        <th><label>Access Token</label></th>
                        <td><input type="text" name="whatsapp_token" value="<?php echo esc_attr( $w_token ); ?>" style="width: 350px;"></td>
                    </tr>
                    <tr>
                        <th><label>Admin WhatsApp Number (with country code)</label></th>
                        <td><input type="text" name="whatsapp_admin" value="<?php echo esc_attr( $w_admin ); ?>"></td>
                    </tr>
                </table>

                <h2>Stripe Checkout</h2>
                <table class="form-table">
                    <tr>
                        <th><label>Enable Stripe Checkout</label></th>
                        <td>
                            <label>
                                <input type="checkbox" name="stripe_enabled" value="1" <?php checked( $stripe_enabled, 1 ); ?>>
                                Use Stripe Checkout for "online" payments
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th><label>Stripe Secret Key</label></th>
                        <td><input type="text" name="stripe_secret" value="<?php echo esc_attr( $stripe_secret_key ); ?>" style="width:350px;"></td>
                    </tr>
                    <tr>
                        <th><label>Stripe Publishable Key (optional)</label></th>
                        <td><input type="text" name="stripe_pub" value="<?php echo esc_attr( $stripe_pub ); ?>" style="width:350px;"></td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="submit" class="button button-primary">Save Settings</button>
                </p>
            </form>
        </div>
        <?php
    }

    public function handle_save_settings() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Not allowed.' );
        }
        check_admin_referer( 'pbm_settings_save', 'pbm_settings_nonce' );

        update_option( 'pbm_day_internal', floatval( $_POST['day_internal'] ?? 10 ) );
        update_option( 'pbm_day_external', floatval( $_POST['day_external'] ?? 7 ) );
        update_option( 'pbm_month_internal', floatval( $_POST['month_internal'] ?? 90 ) );
        update_option( 'pbm_month_external', floatval( $_POST['month_external'] ?? 70 ) );
        update_option( 'pbm_event_internal', floatval( $_POST['event_internal'] ?? 20 ) );
        update_option( 'pbm_event_external', floatval( $_POST['event_external'] ?? 12 ) );

        update_option( 'pbm_monthly_threshold_days', intval( $_POST['monthly_threshold'] ?? 28 ) );
        update_option( 'pbm_currency', sanitize_text_field( $_POST['currency'] ?? 'usd' ) );

        update_option( 'pbm_online_discount_enabled', isset( $_POST['online_enabled'] ) ? 1 : 0 );
        update_option( 'pbm_online_discount_percent', floatval( $_POST['online_percent'] ?? 10 ) );

        update_option( 'pbm_event_dates', sanitize_textarea_field( $_POST['event_dates'] ?? '' ) );

        update_option( 'pbm_whatsapp_enabled', isset( $_POST['whatsapp_enabled'] ) ? 1 : 0 );
        update_option( 'pbm_whatsapp_phone_id', sanitize_text_field( $_POST['whatsapp_phone_id'] ?? '' ) );
        update_option( 'pbm_whatsapp_token', sanitize_text_field( $_POST['whatsapp_token'] ?? '' ) );
        update_option( 'pbm_whatsapp_admin_number', sanitize_text_field( $_POST['whatsapp_admin'] ?? '' ) );
        update_option( 'pbm_stripe_enabled', isset( $_POST['stripe_enabled'] ) ? 1 : 0 );
        update_option( 'pbm_stripe_secret_key', sanitize_text_field( $_POST['stripe_secret'] ?? '' ) );
        update_option( 'pbm_stripe_publishable_key', sanitize_text_field( $_POST['stripe_pub'] ?? '' ) );

        wp_redirect( admin_url( 'admin.php?page=pbm-settings&saved=1' ) );
        exit;
    }

    /**
     * Promo codes admin.
     */
    public function admin_promo_page() {
        global $wpdb;

        $promos = $wpdb->get_results( "SELECT * FROM {$this->promo_table} ORDER BY created_at DESC" );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Promo Codes', 'parking-booking-manager-pro' ); ?></h1>

            <h2>Add Promo Code</h2>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'pbm_save_promo', 'pbm_save_promo_nonce' ); ?>
                <input type="hidden" name="action" value="pbm_save_promo">

                <table class="form-table">
                    <tr>
                        <th><label>Code</label></th>
                        <td><input type="text" name="code" required placeholder="WELCOME10"></td>
                    </tr>
                    <tr>
                        <th><label>Discount Type</label></th>
                        <td>
                            <select name="discount_type">
                                <option value="percent">Percent %</option>
                                <option value="fixed">Fixed amount</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label>Discount Value</label></th>
                        <td><input type="number" step="0.01" name="discount_value" value="10"></td>
                    </tr>
                    <tr>
                        <th><label>Minimum booking amount</label></th>
                        <td><input type="number" step="0.01" name="min_amount" value="0"></td>
                    </tr>
                    <tr>
                        <th><label>Max uses (0 = unlimited)</label></th>
                        <td><input type="number" name="max_uses" value="0"></td>
                    </tr>
                    <tr>
                        <th><label>Valid from (Y-m-d)</label></th>
                        <td><input type="date" name="valid_from"></td>
                    </tr>
                    <tr>
                        <th><label>Valid to (Y-m-d)</label></th>
                        <td><input type="date" name="valid_to"></td>
                    </tr>
                    <tr>
                        <th><label>Allowed payment methods</label></th>
                        <td>
                            <label><input type="checkbox" name="allow_online" value="1" checked> Online</label>
                            <label style="margin-left:15px;"><input type="checkbox" name="allow_cash" value="1" checked> Cash</label>
                        </td>
                    </tr>
                    <tr>
                        <th><label>Active</label></th>
                        <td>
                            <label><input type="checkbox" name="active" value="1" checked> Active</label>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="submit" class="button button-primary">Save Promo Code</button>
                </p>
            </form>

            <hr>

            <h2>Existing Promo Codes</h2>
            <table class="widefat fixed striped">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Code</th>
                        <th>Type</th>
                        <th>Value</th>
                        <th>Min Amount</th>
                        <th>Uses</th>
                        <th>Valid</th>
                        <th>Payments</th>
                        <th>Active</th>
                        <th>Delete</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ( $promos ) : ?>
                    <?php foreach ( $promos as $p ) : ?>
                        <tr>
                            <td><?php echo esc_html( $p->id ); ?></td>
                            <td><?php echo esc_html( $p->code ); ?></td>
                            <td><?php echo esc_html( ucfirst( $p->discount_type ) ); ?></td>
                            <td><?php echo esc_html( $p->discount_value ); ?></td>
                            <td><?php echo esc_html( $p->min_amount ); ?></td>
                            <td><?php echo esc_html( $p->used_count . '/' . ( $p->max_uses ?: '∞' ) ); ?></td>
                            <td><?php echo esc_html( $p->valid_from . ' → ' . $p->valid_to ); ?></td>
                            <td>
                                <?php
                                $methods = array();
                                if ( $p->allow_online ) {
                                    $methods[] = 'Online';
                                }
                                if ( $p->allow_cash ) {
                                    $methods[] = 'Cash';
                                }
                                echo esc_html( implode( ', ', $methods ) );
                                ?>
                            </td>
                            <td><?php echo $p->active ? 'Yes' : 'No'; ?></td>
                            <td>
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('Delete this promo code?');">
                                    <?php wp_nonce_field( 'pbm_delete_promo', 'pbm_delete_promo_nonce' ); ?>
                                    <input type="hidden" name="action" value="pbm_delete_promo">
                                    <input type="hidden" name="promo_id" value="<?php echo esc_attr( $p->id ); ?>">
                                    <button class="button button-small button-link-delete" type="submit">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else : ?>
                    <tr><td colspan="10">No promo codes.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public function handle_save_promo() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Not allowed.' );
        }
        check_admin_referer( 'pbm_save_promo', 'pbm_save_promo_nonce' );

        global $wpdb;

        $code           = strtoupper( sanitize_text_field( $_POST['code'] ?? '' ) );
        $discount_type  = sanitize_text_field( $_POST['discount_type'] ?? 'percent' );
        $discount_value = floatval( $_POST['discount_value'] ?? 0 );
        $min_amount     = floatval( $_POST['min_amount'] ?? 0 );
        $max_uses       = intval( $_POST['max_uses'] ?? 0 );
        $valid_from     = sanitize_text_field( $_POST['valid_from'] ?? '' );
        $valid_to       = sanitize_text_field( $_POST['valid_to'] ?? '' );
        $allow_online   = isset( $_POST['allow_online'] ) ? 1 : 0;
        $allow_cash     = isset( $_POST['allow_cash'] ) ? 1 : 0;
        $active         = isset( $_POST['active'] ) ? 1 : 0;

        if ( empty( $code ) || $discount_value <= 0 ) {
            wp_redirect( admin_url( 'admin.php?page=pbm-promo-codes&error=1' ) );
            exit;
        }

        $wpdb->insert(
            $this->promo_table,
            array(
                'code'          => $code,
                'discount_type' => $discount_type,
                'discount_value'=> $discount_value,
                'min_amount'    => $min_amount,
                'max_uses'      => $max_uses,
                'valid_from'    => $valid_from ?: null,
                'valid_to'      => $valid_to ?: null,
                'allow_online'  => $allow_online,
                'allow_cash'    => $allow_cash,
                'active'        => $active,
            ),
            array(
                '%s','%s','%f','%f',
                '%d','%s','%s',
                '%d','%d','%d'
            )
        );

        wp_redirect( admin_url( 'admin.php?page=pbm-promo-codes&saved=1' ) );
        exit;
    }

    public function handle_delete_promo() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Not allowed.' );
        }
        check_admin_referer( 'pbm_delete_promo', 'pbm_delete_promo_nonce' );

        $promo_id = intval( $_POST['promo_id'] ?? 0 );
        if ( $promo_id > 0 ) {
            global $wpdb;
            $wpdb->delete( $this->promo_table, array( 'id' => $promo_id ), array( '%d' ) );
        }

        wp_redirect( admin_url( 'admin.php?page=pbm-promo-codes&deleted=1' ) );
        exit;
    }
}

endif;

// Activation
register_activation_hook( __FILE__, array( 'Parking_Booking_Manager_Pro', 'install' ) );
// Bootstrap
new Parking_Booking_Manager_Pro();