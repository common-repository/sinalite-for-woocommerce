<?php

class Sinalite_Shipping_API
{
    const SINALITE_API_SHIPPING_RATES_URL = 'http://apiconnect.sinalite.com/woocommerce/shipping/rates.php?version=%s';

    private $version;

    public function __construct($version)
    {
        $this->version = $version;
    }

    public function get_shipping_rates(array $package)
    {

        $response = wp_remote_post(
            sprintf(
                self::SINALITE_API_SHIPPING_RATES_URL,
                $this->version
            ),
            [
                'timeout' => 10,
                'headers' => [
                    'Content-Type' => 'application/json',
					'WC_HOST' => get_home_url(),
                ],
                'body' => isset($package) ? json_encode($package) : null,
            ]
        );
     
        if(is_wp_error($response)) {
            error_log($response->get_error_message());
            return null;
        }

        return json_decode($response['body'], true); 
    }
}
