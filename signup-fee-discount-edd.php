<?php
namespace SignupFeeDiscountEdd;

/**
 * Plugin Name: Signup Fee Discount for EDD
 * Description: Adds an option in the EDD discount edit screen to apply a discount to the signup fee of a recurring payment.
 * Version: 1.0.0
 * Author: James Welbes
 * Author URI: https://apexbranding.design
 * Text Domain: signup-fee-discount-edd
 * Domain Path: /languages
 */

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
 * Autoloader function.
 *
 * @param string $class_name The class name to load.
 */
function autoload( $class_name ) {
    if ( strpos( $class_name, __NAMESPACE__ ) === 0 ) {
        $relative_class = str_replace( __NAMESPACE__ . '\\', '', $class_name );
        $file = plugin_dir_path( __FILE__ ) . 'includes/' . str_replace( '\\', '/', $relative_class ) . '.php';

        if ( file_exists( $file ) ) {
            include $file;
        }
    }
}

spl_autoload_register( __NAMESPACE__ . '\\autoload' );

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

    // Load admin functionalities.
    if ( is_admin() ) {
        if ( file_exists( SIGNUP_FEE_DISCOUNT_EDD_PLUGIN_DIR . 'includes/admin/admin.php' ) ) {
            require_once SIGNUP_FEE_DISCOUNT_EDD_PLUGIN_DIR . 'includes/admin/admin.php';
            if ( function_exists( 'SignupFeeDiscountEdd\\Admin\\setup' ) ) {
                Admin\setup();
            }
        }

        add_action( 'edd_add_discount_form_after_use_once', __NAMESPACE__ . '\\render_signup_fee_toggle', 10, 1 );
        add_action( 'edd_edit_discount_form_before_status', __NAMESPACE__ . '\\render_signup_fee_toggle', 10, 1 );
    }
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





