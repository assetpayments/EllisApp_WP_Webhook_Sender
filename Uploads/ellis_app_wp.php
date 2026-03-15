<?php
/**
 * Plugin Name: EllisApp Webhook sender.
 * Description: Automated JSON webhooks sender.
 * Version: 2.1.0
 * Author: AssetPayments EllisApp
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Ellis_App_WP {

    public function __construct() {
        add_filter( 'woocommerce_get_settings_pages', array( $this, 'add_settings_page' ) );

        $plugin_base = plugin_basename( __FILE__ );
        add_filter( "plugin_action_links_$plugin_base", array( $this, 'add_settings_link' ) );

        add_action( 'woocommerce_order_status_changed', array( $this, 'handle_status_change' ), 10, 4 );
        add_action( 'rest_api_init', array( $this, 'register_ellis_routes' ) );
    }

    public static function activate_plugin() {
        $existing_token = get_option( 'ellis_bearer_token' );

        if ( empty( $existing_token ) || strlen( $existing_token ) < 50 ) {
            $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
            $token = '';
            $max = strlen( $characters ) - 1;

            for ( $i = 0; $i < 50; $i++ ) {
                $token .= $characters[ random_int( 0, $max ) ];
            }

            update_option( 'ellis_bearer_token', $token );
        }
    }

    public function add_settings_link( $links ) {
        $settings_link = '<a href="' . admin_url( 'admin.php?page=wc-settings&tab=ellis_app_settings' ) . '">Settings</a>';
        array_unshift( $links, $settings_link );
        return $links;
    }

    public function add_settings_page( $settings ) {
        $settings[] = include plugin_dir_path( __FILE__ ) . 'includes/class-ellis-settings.php';
        return $settings;
    }

    public function handle_status_change( $order_id, $old_status, $new_status, $order ) {
        $selected = get_option( 'ellis_trigger_statuses', array( 'wc-processing', 'wc-completed' ) );
        $status_to_check = ( strpos( $new_status, 'wc-' ) === 0 ) ? $new_status : 'wc-' . $new_status;

        if ( in_array( $status_to_check, $selected ) ) {
            $this->trigger_ellis_webhook( $order_id );
        }
    }

    public function trigger_ellis_webhook( $order_id ) {
        $url   = get_option( 'ellis_webhook_url' );
        $token = get_option( 'ellis_bearer_token' );

        if ( empty( $url ) ) return;

        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        $payload = $this->prepare_ellis_data( $order );

        wp_remote_post( $url, array(
            'method'    => 'POST',
            'headers'   => array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $token
            ),
            'body'      => json_encode( $payload ),
            'sslverify' => false,
            'timeout'   => 30,
            'blocking'  => false,
        ));
    }

    public function prepare_ellis_data( $order ) {
        $items = array();

        // Loop through standard products
        foreach ( $order->get_items() as $item_id => $item ) {
            $product = $item->get_product();

            $qty = $item->get_quantity();
            $line_total = $item->get_total();
            $price_per_unit = $qty > 0 ? ( $line_total / $qty ) : 0;

            $items[] = array(
                'ProductSku'      => $product ? $product->get_sku() : '',
                'ProductCode'     => $item->get_product_id(),
                'ProductName'     => $item->get_name(),
                'ProductQuantity' => $qty,
                'ProductPrice'    => wc_format_decimal( $price_per_unit, 2 ),
            );
        }

        // Add Shipping as a line item if a shipping method exists on the order
        $shipping_methods = $order->get_shipping_methods();
        if ( ! empty( $shipping_methods ) ) {
            $shipping_total = $order->get_shipping_total();
            $delivery_name  = get_option( 'ellis_delivery_name', 'Доставка' );

            // Fallback in case settings field is completely empty
            if ( empty( $delivery_name ) ) {
                $delivery_name = 'Доставка';
            }

            $items[] = array(
                'ProductSku'      => 'shipping',
                'ProductCode'     => 'shipping',
                'ProductName'     => $delivery_name,
                'ProductQuantity' => 1,
                'ProductPrice'    => wc_format_decimal( $shipping_total, 2 ),
            );
        }

        // Fetch User Settings
        $order_type = get_option( 'ellis_order_type', 'Payment' );

        // Address Fallback Logic
        $address = trim( $order->get_shipping_address_1() . ' ' . $order->get_shipping_address_2() );
        if ( empty( $address ) ) {
            $address = trim( $order->get_billing_address_1() . ' ' . $order->get_billing_address_2() );
        }

        return array(
            'Type'           => $order_type,
            'OrderNumber'    => $order->get_id(),
            'OrderStatus'    => $order->get_status(),
            'FullName'       => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
            'Email'          => $order->get_billing_email(),
            'Phone'          => $order->get_billing_phone(),
            'Address'        => $address,
            'City'           => $order->get_shipping_city() ?: $order->get_billing_city(),
            'Region'         => $order->get_shipping_state() ?: $order->get_billing_state(),
            'Country'        => $order->get_shipping_country() ?: $order->get_billing_country(),
            'Zip'            => $order->get_shipping_postcode() ?: $order->get_billing_postcode(),
            'DeliveryMethod' => $order->get_shipping_method(),
            'PaymentMethod'  => $order->get_payment_method_title(),
            'PaymentNumber'  => $order->get_transaction_id(),
            'Comment'        => $order->get_customer_note(),
            'Amount'         => $order->get_total(),
            'Currency'       => $order->get_currency(),
            'Discount'       => $order->get_discount_total(),
            'Products'       => $items,
        );
    }

    public function register_ellis_routes() {
        // Changed route to just /order
        register_rest_route( 'ellis-api/v1', '/order', array(
            'methods'  => 'GET',
            'callback' => array( $this, 'get_ellis_order' ),
            'permission_callback' => array( $this, 'check_ellis_auth' ),
        ));

        register_rest_route( 'ellis-api/v1', '/get-order', array(
            'methods'  => 'POST',
            'callback' => array( $this, 'get_order_by_json' ),
            'permission_callback' => array( $this, 'check_ellis_auth' ),
        ));
    }

    public function check_ellis_auth( $request ) {
        $auth = $request->get_header( 'authorization' );
        $token = get_option( 'ellis_bearer_token' );
        return ( ! empty( $token ) && $auth === 'Bearer ' . $token );
    }

    public function get_order_by_json( $request ) {
        $params = $request->get_json_params();
        $order_id = isset( $params['OrderNumber'] ) ? intval( $params['OrderNumber'] ) : 0;

        if ( ! $order_id ) {
            return new WP_Error( 'invalid_data', 'Missing OrderNumber in JSON body', array( 'status' => 400 ) );
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return new WP_Error( 'not_found', 'Order not found', array( 'status' => 404 ) );
        }

        return rest_ensure_response( $this->prepare_ellis_data( $order ) );
    }

    public function get_ellis_order( $request ) {
        // Look inside the JSON body first
        $params = $request->get_json_params();
        $order_id = isset( $params['OrderNumber'] ) ? intval( $params['OrderNumber'] ) : 0;

        // Fallback to URL parameter (?OrderNumber=136) if JSON body is empty or stripped
        if ( ! $order_id ) {
            $order_id = intval( $request->get_param( 'OrderNumber' ) );
        }

        if ( ! $order_id ) {
            return new WP_Error( 'invalid_data', 'Missing OrderNumber in request', array( 'status' => 400 ) );
        }

        $order = wc_get_order( $order_id );

        if ( ! $order ) {
            return new WP_Error( 'not_found', 'Order not found', array( 'status' => 404 ) );
        }

        return rest_ensure_response( $this->prepare_ellis_data( $order ) );
    }
}

register_activation_hook( __FILE__, array( 'Ellis_App_WP', 'activate_plugin' ) );

new Ellis_App_WP();
