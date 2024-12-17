<?php
/**
 * Plugin Name: Signup Fee Discount for EDD
 * Description: Adds an option in the EDD discount edit screen to apply a discount to the signup fee of a recurring payment.
 * Version: 1.0.0
 * Author: James Welbes
 * Author URI: https://prototypewp.com
 * Text Domain: signup-fee-discount-edd
 * Domain Path: /languages
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

 namespace SignupFeeDiscountEdd;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use SignupFeeDiscountEdd\Admin;

// Define constants.
define( 'SIGNUP_FEE_DISCOUNT_EDD_VERSION', '1.0.0' );
define( 'SIGNUP_FEE_DISCOUNT_EDD_PLUGIN_FILE', __FILE__ );
define( 'SIGNUP_FEE_DISCOUNT_EDD_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SIGNUP_FEE_DISCOUNT_EDD_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SIGNUP_FEE_DISCOUNT_EDD_TEXT_DOMAIN', 'signup-fee-discount-edd' );


/**
 * Plugin activation hook.
 */
function activate() {}

/**
 * Plugin deactivation hook.
 */
function deactivate() {}

register_activation_hook( __FILE__, __NAMESPACE__ . '\\activate' );
register_deactivation_hook( __FILE__, __NAMESPACE__ . '\\deactivate' );

/**
 * Initialize the plugin.
 */
function init() {
    load_plugin_textdomain( SIGNUP_FEE_DISCOUNT_EDD_TEXT_DOMAIN, false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

        add_action( 'edd_add_discount_form_after_use_once', __NAMESPACE__ . '\\render_signup_fee_toggle', 10, 1 );
        add_action( 'edd_edit_discount_form_before_status', __NAMESPACE__ . '\\render_signup_fee_toggle', 10, 1 );
    
}

/**
 * Render the signup fee discount toggle.
 *
 * @param int $discount_id Discount ID.
 */
function render_signup_fee_toggle( $discount_id = 0 ) {
    $is_checked = $discount_id ? get_post_meta( $discount_id, '_apply_to_signup_fee', true ) : '';
    $toggle = new \EDD\HTML\CheckboxToggle(
        array(
            'name'    => 'apply_to_signup_fee',
            'current' => $is_checked,
            'label'   => __( 'Enable this option to apply the discount to the signup fee of a recurring payment.', 'signup-fee-discount-edd' ),
        )
    );
    ?>
    <tr>
        <th scope="row" valign="top">
            <label for="apply_to_signup_fee">
                <?php esc_html_e( 'Apply discount to signup fee', 'signup-fee-discount-edd' ); ?>
            </label>
        </th>
        <td>
            <?php $toggle->output(); ?>
        </td>
    </tr>
    <?php
}

/**
 * Save the signup fee discount toggle value.
 *
 * @param array $args        The array of discount args.
 * @param int   $discount_id The discount ID.
 */
function save_signup_fee_meta( $args, $discount_id ) {
    if ( empty( $_POST['edd-discount-nonce'] ) || ! isset( $_POST['apply_to_signup_fee'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['edd-discount-nonce'] ) ), 'edd_discount_nonce' ) ) {
        return;
    }

    $apply_to_signup_fee = sanitize_text_field( wp_unslash( $_POST['apply_to_signup_fee'] ) ) === '1' ? '1' : '';
    update_post_meta( $discount_id, '_apply_to_signup_fee', $apply_to_signup_fee );
}
add_action( 'edd_post_insert_discount', __NAMESPACE__ . '\\save_signup_fee_meta', 10, 2 );
add_action( 'edd_post_update_discount', __NAMESPACE__ . '\\save_signup_fee_meta', 10, 2 );

add_action( 'plugins_loaded', __NAMESPACE__ . '\\init' );

/**
 * Apply the discount to the signup fee if the toggle is enabled.
 *
 * @param float $discounted_amount The current discounted amount.
 * @param array $discounts         The discounts applied to the cart.
 * @param array $cart_items        The items in the cart.
 *
 * @return float The updated discounted amount.
 */
function apply_signup_fee_discount( $discounted_amount, $discounts = array(), $cart_items = array() ) {
    // Ensure cart and discounts are loaded.
    if ( empty( $cart_items ) ) {
        $cart_items = edd_get_cart_contents();
    }

    if ( empty( $discounts ) ) {
        $discounts = edd_get_cart_discounts();
    }

    // Validate the presence of discounts and cart items.
    if ( empty( $discounts ) || empty( $cart_items ) ) {
        return $discounted_amount;
    }

    // Process each discount applied to the cart.
    foreach ( $discounts as $discount_code ) {
        $discount_id = edd_get_discount_id_by_code( $discount_code );

        if ( ! $discount_id ) {
            continue;
        }

        // Check if the discount applies to the signup fee.
        $apply_to_signup_fee = get_post_meta( $discount_id, '_apply_to_signup_fee', true );
        if ( '1' !== $apply_to_signup_fee ) {
            continue;
        }

        // Apply discount to each cart item with a recurring signup fee.
        foreach ( $cart_items as $cart_item ) {
            if ( isset( $cart_item['options']['recurring']['signup_fee'] ) ) {
                $signup_fee = floatval( $cart_item['options']['recurring']['signup_fee'] );

                // Calculate the discount based on type (percentage or flat).
                $discount = edd_get_discount_type( $discount_id ) === 'percent'
                    ? $signup_fee * ( edd_get_discount_amount( $discount_id ) / 100 )
                    : edd_get_discount_amount( $discount_id );

                // Ensure the discount doesn't exceed the signup fee.
                $final_discount = min( $discount, $signup_fee );

                // Add the calculated discount to the total discounted amount.
                $discounted_amount += $final_discount;
            }
        }
    }

    return $discounted_amount;
}
add_filter( 'edd_get_cart_discounted_amount', __NAMESPACE__ . '\\apply_signup_fee_discount', 10, 3 );

/**
 * Customize the display of discounts to include signup fee discounts.
 *
 * @param string $html         The existing discounts HTML.
 * @param array  $discounts    The discounts applied to the cart.
 * @param string $rate         The discount rate.
 * @param string $remove_url   The URL to remove the discount.
 *
 * @return string The updated discounts HTML.
 */
add_filter( 'edd_get_cart_discounts_html', function( $html, $discounts, $rate, $remove_url ) {
    error_log( 'edd_get_cart_discounts_html: Filter triggered.' );
    $updated_html = _n( 'Discount', 'Discounts', count( $discounts ), 'signup-fee-discount-edd' ) . ':&nbsp;';

    foreach ( $discounts as $discount ) {
        $discount_id     = edd_get_discount_id_by_code( $discount );
        $total_discount  = 0;
        $cart_items      = edd_get_cart_contents();

        error_log( 'Processing discount code: ' . $discount );
        error_log( 'Cart items: ' . print_r( $cart_items, true ) );

        if ( is_array( $cart_items ) && ! empty( $cart_items ) ) {
            foreach ( $cart_items as $item ) {
                $item_price = edd_get_cart_item_price( $item ); // Fetch item price

                // Recurring discount.
                if ( $item_price > 0 ) {
                    $recurring_discount = edd_get_discount_type( $discount_id ) === 'percent'
                        ? $item_price * ( edd_get_discount_amount( $discount_id ) / 100 )
                        : edd_get_discount_amount( $discount_id );

                    $recurring_discount = min( $recurring_discount, $item_price );
                    $total_discount += $recurring_discount;

                    error_log( "Item Price: {$item_price}, Recurring Discount: {$recurring_discount}" );
                }

                // Signup fee discount.
                if ( isset( $item['options']['recurring']['signup_fee'] ) ) {
                    $signup_fee = floatval( $item['options']['recurring']['signup_fee'] );
                    $apply_to_fee = get_post_meta( $discount_id, '_apply_to_signup_fee', true );

                    error_log( "Signup Fee: {$signup_fee}, Applies to Fee: {$apply_to_fee}" );

                    if ( '1' === $apply_to_fee ) {
                        $fee_discount = edd_get_discount_type( $discount_id ) === 'percent'
                            ? $signup_fee * ( edd_get_discount_amount( $discount_id ) / 100 )
                            : edd_get_discount_amount( $discount_id );

                        $fee_discount = min( $fee_discount, $signup_fee );
                        $total_discount += $fee_discount;

                        error_log( "Fee Discount: {$fee_discount}" );
                    }
                }
            }
        }

        error_log( "Total Discount for {$discount}: {$total_discount}" );

        // Display discount
        $type          = edd_get_discount_type( $discount_id );
        $rate_display  = edd_format_discount_rate( $type, edd_get_discount_amount( $discount_id ) );

        $discount_html  = "<span class=\"edd_discount\">\n";
        $discount_html .= "<span class=\"edd_discount_total\">{$discount}&nbsp;&ndash;&nbsp;" . edd_currency_filter( edd_format_amount( $total_discount ) ) . "</span>\n";
        $discount_html .= "<span class=\"edd_discount_rate\">($rate_display)</span>\n";
        $discount_html .= sprintf(
            '<a href="%s" data-code="%s" class="edd_discount_remove"><span class="screen-reader-text">%s</span></a>',
            esc_url( $remove_url ),
            esc_attr( $discount ),
            esc_attr__( 'Remove discount', 'signup-fee-discount-edd' )
        );
        $discount_html .= "</span>\n";

        $updated_html .= apply_filters( 'edd_get_cart_discount_html', $discount_html, $discount, $rate, $remove_url );
    }

    error_log( 'Updated Discounts HTML: ' . $updated_html );
    return $updated_html;
}, 10, 4 );

/**
 * Save the signup fee discount as order meta during checkout.
 *
 * @param int   $payment_id Payment ID.
 * @param array $payment_data Payment data.
 */
function save_signup_fee_discount_meta( $payment_id, $payment_data ) {
    $signup_fee_discount = 0;

    $cart_items = edd_get_cart_contents();
    $discounts  = edd_get_cart_discounts();

    if ( ! empty( $discounts ) && ! empty( $cart_items ) ) {
        foreach ( $discounts as $discount_code ) {
            $discount_id = edd_get_discount_id_by_code( $discount_code );

            if ( ! $discount_id ) {
                continue;
            }

            $apply_to_signup_fee = get_post_meta( $discount_id, '_apply_to_signup_fee', true );
            if ( '1' !== $apply_to_signup_fee ) {
                continue;
            }

            foreach ( $cart_items as $item ) {
                if ( isset( $item['options']['recurring']['signup_fee'] ) ) {
                    $signup_fee = floatval( $item['options']['recurring']['signup_fee'] );

                    $discount = edd_get_discount_type( $discount_id ) === 'percent'
                        ? $signup_fee * ( edd_get_discount_amount( $discount_id ) / 100 )
                        : min( edd_get_discount_amount( $discount_id ), $signup_fee );

                    $signup_fee_discount += $discount;
                }
            }
        }
    }

    // Store the total signup fee discount as meta.
    update_post_meta( $payment_id, '_signup_fee_discount', $signup_fee_discount );
}
add_action( 'edd_complete_purchase', __NAMESPACE__ . '\\save_signup_fee_discount_meta', 10, 2 );

/**
 * Adjust the order total on the receipt to account for signup fee discounts.
 *
 * @param float $total    The original order total.
 * @param int   $order_id The order ID.
 * @return float Adjusted total.
 */
function adjust_receipt_total( $total, $order_id ) {
    $signup_fee_discount = get_post_meta( $order_id, '_signup_fee_discount', true );

    if ( ! empty( $signup_fee_discount ) ) {
        $adjusted_total = $total - floatval( $signup_fee_discount );
        return max( 0, $adjusted_total ); // Prevent negative totals.
    }

    return $total;
}
add_filter( 'edd_payment_amount', __NAMESPACE__ . '\\adjust_receipt_total', 10, 2 );






