<?php

require_once 'sinalite-shipping-api.php';

class Sinalite_Shipping_Method extends WC_Shipping_Method
{
    const WOO_TRUE = 'yes';
    const WOO_FALSE = 'no';

    const DEFAULT_ENABLED = self::WOO_TRUE;
    const DEFAULT_OVERRIDE = self::WOO_TRUE;
    const VERSION = '1.0';

    private $shipping_enabled;
    private $shipping_override;
    private $sinaliteApiClient;
    private $isSinalitePackage;

    public function __construct()
    {
        parent::__construct();

        $this->id = 'sinalite_shipping';
        $this->method_title = 'Printbest Shipping';
        $this->method_description = 'Calculate live shipping rates based on actual Printbest shipping costs.';
        $this->title = 'Printbest Shipping';
        $this->sinaliteApiClient = new Sinalite_Shipping_API(self::VERSION);

        $this->init();

        $this->shipping_enabled = isset($this->settings['enabled']) ? $this->settings['enabled'] : self::DEFAULT_ENABLED;
        $this->shipping_override = isset($this->settings['override_defaults']) ? $this->settings['override_defaults'] : self::DEFAULT_OVERRIDE;
    }

    public function init_form_fields()
    {
        $this->form_fields = [
            'enabled' => [
                'title' => 'Enabled',
                'type' => 'checkbox',
                'label' => 'Enable Printbest Shipping Method plugin',
                'default' => self::DEFAULT_ENABLED,
            ],
            'override_defaults' => [
                'title' => 'Override',
                'type' => 'checkbox',
                'label' => 'Override standard WooCommerce shipping rates',
                'default' => self::DEFAULT_OVERRIDE,
            ],
        ];
    }

    function init()
    {
        $this->init_form_fields();
        $this->init_settings();

        add_action('woocommerce_update_options_shipping_' . $this->id, [$this, 'process_admin_options']);

        add_action('woocommerce_load_shipping_methods', [$this, 'load_shipping_methods']);

        add_filter('woocommerce_shipping_methods', [$this, 'add_sinalite_shipping_method']);

        add_filter('woocommerce_cart_shipping_packages', [$this, 'calculate_shipping_rates']);
		
    }
	
	
    function add_sinalite_shipping_method($methods)
    {
       
        return self::WOO_TRUE === $this->shipping_override && true === $this->isSinalitePackage
            ? []
            : $methods;
    }

    function load_shipping_methods($package)
    {

        $this->isSinalitePackage = false;

        if (!$package) {
            WC()->shipping()->register_shipping_method($this);

            return;
        }

        if (self::WOO_FALSE === $this->enabled) {
            return;
        }

        if (isset($package['managed_by_sinalite']) && true === $package['managed_by_sinalite']) {
            if (self::WOO_TRUE === $this->shipping_override) {
                WC()->shipping()->unregister_shipping_methods();
            }

            $this->isSinalitePackage = true;

            WC()->shipping()->register_shipping_method($this);
        }
    }

    public function calculate_shipping_rates($packages = [])
    {

        /*if ( ! in_the_loop() ) {
            return $packages;
        }

        if ( ! is_singular() ) {
            return $packages;
        }

        if ( ! is_main_query() ) {
            return $packages;
        }*/


        if ($this->shipping_enabled !== self::WOO_TRUE) {
            return $packages;
        }

        $requestParameters = [
            'skus' => [],
            'address' => [],
        ];

        foreach ($packages as $package) {

            // Collect skus and quantity
            foreach ($package['contents'] as $variation) {

                /** @var WC_Product_Variation $productVariation */
                if ($variation && $variation['data']) {

                    $productVariation = $variation['data'];

                    if (!isset($requestParameters['skus'][$productVariation->get_sku()])) {
                        $requestParameters['skus'][$productVariation->get_sku()] = [
                            'sku' => $productVariation->get_sku(),
                            'quantity' => $variation['quantity'],
                        ];
                    } else {
                        $requestParameters['skus'][$productVariation->get_sku()] = [
                            'sku' => $productVariation->get_sku(),
                            'quantity' => $requestParameters['skus'][$productVariation->get_sku()]['quantity'] + $variation['quantity'],
                        ];
                    }

                }

            }

            $requestParameters['address'] = [
                'country' => $package['destination']['country'],
                'state' => $package['destination']['state'],
                'city' => $package['destination']['city'],
                'zip' => isset($package['destination']['postcode']) ? $package['destination']['postcode'] : null,
            ];
        }

        if (!count($requestParameters['address'])) {
            return $packages;
        }


        // Collect shipping rates for found skus
        $sinaliteShippingRates = $this->sinaliteApiClient->get_shipping_rates(
            [
                'items' => $requestParameters['skus'],
                'country' => $requestParameters['address']['country'],
                'state' => $requestParameters['address']['state'],
                'city' => $requestParameters['address']['city'],
                'zip' => isset($requestParameters['address']['zip']) ? $requestParameters['address']['zip'] : null,
            ]
        );


        if (null === $sinaliteShippingRates || empty($sinaliteShippingRates['skus'])) {
            return $packages;
        }

        $splittedVariations = [
            'sinalite' => [],
            'other' => [],
        ];


        foreach ($packages as $package) {

            foreach ($package['contents'] as $variation) {

                /** @var WC_Product_Variation $productVariation */
                $productVariation = $variation['data'];
                if (in_array($productVariation->get_sku(), $sinaliteShippingRates['skus'])) {
                    $splittedVariations['sinalite']['shipping_rates'] = $sinaliteShippingRates['sinalite_shipping_methods'];
                    $splittedVariations['sinalite']['variations'][] = $variation;
                } else {
                    $splittedVariations['other']['variations'][] = $variation;
                }

            }

        }

        $splittedPackages = [];

        $pacakage_count_data = count($packages);
        if (!empty($pacakage_count_data) && $pacakage_count_data > 1) {

            $store_array_element = array();
            $array_last_element = end($packages);
            $array_element_count = count($array_last_element['contents']);
            if (!empty($array_element_count) && $array_element_count > 1) {
                foreach ($array_last_element['contents'] as $key => $value) {
                    $store_array_element[] = $value['variation_id'];
                }
            } else {
                $store_array_element = $array_last_element['contents'][0]['variation_id'];
            }
            $packages = array_unique($packages);

        } elseif (!empty($pacakage_count_data) && $pacakage_count_data == 1) {
            $packages = $packages;
        }

        foreach ($packages as $package) {

            foreach ($splittedVariations as $variationOwner => $splittedVariation) {

                if (!count($splittedVariation)) {
                    continue;
                }

                $splittedPackage = $package;
                $splittedPackage['contents_cost'] = 0;
                $splittedPackage['contents'] = [];

                if ('sinalite' === $variationOwner) {
                    $splittedPackage['managed_by_sinalite'] = true;
                    $splittedPackage['sinalite_shipping_rates'] = $splittedVariation['shipping_rates'];
                }

                //code  for remove duplicate array's variation id from base package
                if (!empty($store_array_element)) {

                    foreach ($splittedVariation['variations'] as $key => $variation) {

                        if (is_array($store_array_element)) {

                            if (in_array($variation['variation_id'], $store_array_element)) {
                                unset($splittedVariation['variations'][$key]);
                            }

                        } else {

                            if (!empty($store_array_element)) {
                                if ($variation['variation_id'] == $store_array_element) {
                                    unset($splittedVariation['variations'][$key]);
                                }
                            }

                        }

                    }

                }

                //code ended  for remove duplicate array's variation id from base package
                foreach ($splittedVariation['variations'] as $variation) {

                    /** @var WC_Product_Variation $productVariation */

                    $productVariation = $variation['data'];

                    $splittedPackage['contents'][] = $variation;

                    if ($productVariation->needs_shipping() && isset($variation['line_total'])) {
                        $splittedPackage['contents_cost'] += $variation['line_total'];
                    }
                }

                $splittedPackages[] = $splittedPackage;
            }

        }
//Added code for remove blank method array in new plugin version
foreach($splittedPackages as $key=>$value){
    if(empty($value['contents'])){
         unset($splittedPackages[$key]);   
    }
}

        if (!empty($array_last_element)) {
            $splittedPackages[] = $array_last_element;
        }

        //added new code for make array key values in sequence
        $splittedPackages = array_values($splittedPackages);
        
        //remove_filter('woocommerce_cart_shipping_packages', [$this, 'calculate_shipping_rates']);

        return $splittedPackages;
    }

    public function calculate_shipping($package = [])
    {
        if (isset($package['managed_by_sinalite']) && $package['managed_by_sinalite'] === true) {

            foreach($package['sinalite_shipping_rates'] as $ship_kay=>$ship_value){

                $rateData = array(
                    'id'       => $package['sinalite_shipping_rates'][$ship_kay]['id'],
                    'label'    => $package['sinalite_shipping_rates'][$ship_kay]['name'],
                    'cost'     => $package['sinalite_shipping_rates'][$ship_kay]['cost'],
                    'calc_tax' => 'per_order',
                );

                $this->add_rate( $rateData );

            }

        }
    }
}