<?php
/**
 * Plugin Name: WooCommerce Payment Gateway - FirstData
 * Description: FirstData (LV) payment gateway
 * Author: Aivis Zorgis
 * Version: 1.0.0
 */
 
add_action('plugins_loaded', 'firstdata_init');
function firstdata_init() {
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    };
	
	define('PLUGIN_DIR', plugins_url(basename(plugin_dir_path(__FILE__)), basename(__FILE__)) . '/');
	
	require_once(dirname(__FILE__) . '/vendor/Merchant.php');
    class WC_Gateway_FirstData extends WC_Payment_Gateway {
		
		public function __construct() {
            global $woocommerce;
            $this->id           = 'firstdata';
            $this->has_fields   = false;
            $this->method_title = __('FirstData', 'woocommerce');
			
			// Priekš ikonas
			//$this->icon = apply_filters('woocommerce_paypal_icon', PLUGIN_DIR . '/firstdata.png');
			
			// Load the form fields.
            $this->init_form_fields();
            // Load the settings.
            $this->init_settings();
			
			// Define user set variables
            $this->title       = $this->settings['title'];
            $this->description = $this->settings['description'];
			
			$this->cert_path        = $this->settings['cert_path'];
            $this->cert_password    = $this->settings['cert_password'];
			$this->server_url		= $this->settings['server_url'];
			$this->client_url		= $this->settings['client_url'];
			
			$this->currency = 978; //EUR
			
			$this->merchant = new Merchant($this->server_url, $this->cert_path, $this->cert_password, 1);
			
			add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
			add_action('firstdata_callback', array($this, 'payment_callback'));
			add_action('woocommerce_api_wc_gateway_firstdata', array($this, 'check_callback_request'));
		}
		
		public function admin_options() {
            ?>
            <h3><?php _e('FirstData', 'woothemes'); ?></h3>
            <p><?php _e('FirstData payment gateway', 'woothemes'); ?></p>
            <table class="form-table">
                <?php
                // Generate the HTML For the settings form.
                $this->generate_settings_html();
                ?>
            </table>
			<?php
        }
		
		function init_form_fields() {
			$this->form_fields = array(
                'enabled'     => array(
                    'title'       => __('Enable FirstData', 'woocommerce'),
                    'label'       => __('Enable FirstData payment gateway', 'woocommerce'),
                    'type'        => 'checkbox',
                    'description' => '',
                    'default'     => 'no'
                ),
                'description' => array(
                    'title'       => __('Description', 'woocommerce'),
                    'type'        => 'textarea',
                    'description' => __('This controls the description which the user sees during checkout.', 'woocommerce'),
                    'default'     => __('Maksāt ar karti')
                ),
                'title'       => array(
                    'title'       => __('Title', 'woocommerce'),
                    'type'        => 'text',
                    'description' => __('Payment method title that the customer will see on your website.', 'woocommerce'),
                    'default'     => __('Maksāt ar karti', 'woocommerce')
                ),
                'cert_path'       => array(
                    'title'       => __('Certificate path', 'woocommerce'),
                    'type'        => 'text',
					'default'	  => '',
                    'description' => __('Please provide certificate path (*keystore.pem)', 'woocommerce'),
                ),
				'cert_password'       => array(
                    'title'       => __('Certificate password', 'woocommerce'),
                    'type'        => 'password',
					'default'	  => '',
                    'description' => __('Please provide certificate password', 'woocommerce'),
                ),
				'server_url'       => array(
                    'title'       => __('ECOMM server url', 'woocommerce'),
                    'type'        => 'text',
					'default'	  => 'https://secureshop-test.firstdata.lv:8443/ecomm/MerchantHandler',
                    'description' => __('Please provide server url', 'woocommerce'),
                ),
				'client_url'       => array(
                    'title'       => __('ECOMM client url', 'woocommerce'),
                    'type'        => 'text',
					'default'	  => 'https://secureshop-test.firstdata.lv/ecomm/ClientHandler',
                    'description' => __('Please provide client url', 'woocommerce'),
                ),
            );
		}
		
		function process_payment($order_id) {
            global $woocommerce;
            $order = new WC_Order($order_id);
			
			$amount = $woocommerce->cart->total * 100;
			$ip = $this->get_the_user_ip();
			$description = '#'.$order->id;
			$language = 'lv';
			
			$resp = $this->merchant->startSMSTrans($amount, $this->currency, $ip, $description, $language);
			
			if (substr($resp,0,14)=="TRANSACTION_ID") {
                $trans_id = substr($resp,16,28);
                $url = $this->client_url."?trans_id=". urlencode($trans_id)."&order_id=".intval($order_id);
				
				return array(
					'result'   => 'success',
					'redirect' => $url,
				);
			}
			
			wc_add_notice( 'Notika kļūda maksājuma izveidē. Sazinaties ar sistēmas administratoru', 'error' );
			$order->add_order_note(__('Payment failed. '. $resp, 'woocomerce'));
			return array(
				'result'   => 'failed',
			);
		}
		
		function check_callback_request() {
            @ob_clean();
            do_action('firstdata_callback', $_REQUEST);
        }
		
		function payment_callback($request) {
            global $woocommerce;
			
			if( !empty($request['trans_id']) && !empty($request['order_id'])){
				
				$order = new WC_Order(intval($request['order_id']));
				$resp = $this->merchant->getTransResult(urlencode($request['trans_id']), $this->get_the_user_ip());
				
				if (strstr($resp, 'RESULT:')) {
					$result = explode('RESULT: ', $resp);
					$result = preg_split( '/\r\n|\r|\n/', $result[1] );
					$result = $result[0];
				}else{
					$result = '';
				}
				
				if (strstr($resp, 'RESULT_CODE:')) {
					$result_code = explode('RESULT_CODE: ', $resp);
					$result_code = preg_split( '/\r\n|\r|\n/', $result_code[1] );
					$result_code = $result_code[0];
				}else{
					$result_code = '';
				}
				
				if( $result === 'OK'){ //if (strpos($resp, "RESULT: OK") === true) { ?
					if ($order->status !== 'completed') {
						$woocommerce->cart->empty_cart();
						$order->add_order_note(__('Payment completed', 'woocomerce'));
						$order->payment_complete();
						
						wp_redirect($order->get_checkout_order_received_url());
					}
				}else{
					$order->add_order_note(__('Payment failed. Error code: '. $result_code, 'woocomerce'));
					wc_add_notice( 'Payment failed. Error code: '. $result_code, 'error' );
					wp_redirect($order->get_cancel_order_url());
				}
				
				echo 3;
				
			}else if( isset($request['close_day'])){
				$resp = $this->merchant->closeDay(); 
				echo (strstr($resp, 'RESULT:') ? 'OK' : 'NOK');
			}else{
				echo 2;
			}
			
			exit();
		}
		
		function get_the_user_ip() {
			if ( ! empty( $_SERVER['HTTP_CLIENT_IP'] ) ) {
				$ip = $_SERVER['HTTP_CLIENT_IP'];
			} elseif ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
				$ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
			} else {
				$ip = $_SERVER['REMOTE_ADDR'];
			}		
		}
	}
	
	/**
     * Add the gateway to WooCommerce
     *
     * @access public
     * @param array $methods
     * @package WooCommerce/Classes/Payment
     * @return array $methods
     */
    function add_firstdata_gateway($methods) {
        $methods[] = 'WC_Gateway_FirstData';
        return $methods;
    }
    add_filter('woocommerce_payment_gateways', 'add_firstdata_gateway');
}