<?php

/*
 * Plugin Name: Printbest for WooCommerce
 * Description: Calculate shipping rates for products managed by Printbest.
 * Version: 1.0.1
 * Author: Printbest
 * Author URI: https://printbest.com
 */
if (in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins'))) ) {

    function sinalite_shipping_method_init() {
        if(!class_exists('Sinalite_Shipping_Method')) {
            require_once 'includes/sinalite-shipping-method.php';

            new Sinalite_Shipping_Method();
        }
    }

    add_action('woocommerce_shipping_init', 'sinalite_shipping_method_init');

    function sinalite_shipping_method( $methods ) {
	
		$methods['sinalite_shipping'] = 'Sinalite_Shipping_Method';
		return $methods;
	}

	add_filter( 'woocommerce_shipping_methods', 'sinalite_shipping_method' );
}
