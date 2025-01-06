<?php
use Automattic\WooCommerce\Utilities\OrderUtil;

class WC_SimplePay_Gateway extends WC_Payment_Gateway {
    public function __construct() {
        $this->id = 'simplepay';
        $this->icon = apply_filters(
            'woocommerce_simplepay_icon', 
            plugins_url('assets/images/simplepay-logo.png', dirname(__FILE__))
        );
        $this->has_fields = false;
        $this->method_title = 'SimplePay';
        $this->method_description = __('Accept payments through SimplePay payment gateway', 'wc-simplepay');
        
        // Load settings
        $this->init_form_fields();
        $this->init_settings();
        
        // Define properties
        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->merchant_id = $this->test_mode ? 
            $this->get_option('test_merchant_id') : 
            $this->get_option('merchant_id');
        $this->secret_key = $this->test_mode ? 
            $this->get_option('test_secret_key') : 
            $this->get_option('secret_key');
        $this->test_mode = 'yes' === $this->get_option('test_mode');
        
        // Actions
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('woocommerce_api_wc_simplepay_gateway', array($this, 'handle_callback'));
        
        require_once WC_SIMPLEPAY_PATH . 'includes/class-wc-simplepay-api.php';
        $this->api = new WC_SimplePay_API(
            $this->merchant_id,
            $this->secret_key,
            $this->test_mode
        );
        
        // Admin assets hozzáadása
        add_action('admin_enqueue_scripts', array($this, 'admin_scripts'));
    }

    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title' => __('Enable/Disable', 'wc-simplepay'),
                'type' => 'checkbox',
                'label' => __('Enable SimplePay Payment', 'wc-simplepay'),
                'default' => 'no'
            ),
            'title' => array(
                'title' => __('Title', 'wc-simplepay'),
                'type' => 'text',
                'description' => __('Payment method title that the customer sees at checkout.', 'wc-simplepay'),
                'default' => __('SimplePay', 'wc-simplepay'),
                'desc_tip' => true,
            ),
            'description' => array(
                'title' => __('Description', 'wc-simplepay'),
                'type' => 'textarea',
                'description' => __('Payment method description that the customer sees at checkout.', 'wc-simplepay'),
                'default' => __('Pay securely via SimplePay', 'wc-simplepay'),
                'desc_tip' => true,
            ),
            'merchant_id' => array(
                'title' => __('Merchant ID', 'wc-simplepay'),
                'type' => 'text',
                'description' => __('Your SimplePay Merchant ID', 'wc-simplepay'),
                'default' => '',
                'desc_tip' => true,
            ),
            'secret_key' => array(
                'title' => __('Secret Key', 'wc-simplepay'),
                'type' => 'password',
                'description' => __('Your SimplePay Secret Key', 'wc-simplepay'),
                'default' => '',
                'desc_tip' => true,
            ),
            'test_mode' => array(
                'title' => __('Test mode', 'wc-simplepay'),
                'type' => 'checkbox',
                'label' => __('Enable test mode', 'wc-simplepay'),
                'default' => 'yes',
                'description' => sprintf(
                    __('Place the payment gateway in test mode. Test card numbers: %1$s', 'wc-simplepay'),
                    '<br/>4908 3660 9990 0425 (Success)<br/>4908 3660 9990 0004 (Fail)<br/>4908 3660 9990 0017 (Timeout)'
                ),
            ),
            'test_merchant_id' => array(
                'title' => __('Test Merchant ID', 'wc-simplepay'),
                'type' => 'text',
                'description' => __('Your SimplePay Test Merchant ID', 'wc-simplepay'),
                'default' => '',
                'desc_tip' => true,
                'custom_attributes' => array(
                    'data-show-if' => 'test_mode'
                )
            ),
            'test_secret_key' => array(
                'title' => __('Test Secret Key', 'wc-simplepay'),
                'type' => 'password',
                'description' => __('Your SimplePay Test Secret Key', 'wc-simplepay'),
                'default' => '',
                'desc_tip' => true,
                'custom_attributes' => array(
                    'data-show-if' => 'test_mode'
                )
            ),
        );
    }

    public function process_payment($order_id) {
        $order = wc_get_order($order_id);
        
        if (!$order) {
            wc_add_notice(__('Order not found.', 'wc-simplepay'), 'error');
            return;
        }

        try {
            // Létrehozzuk a fizetést a SimplePay-en
            $response = $this->api->create_payment($order);
            
            // Mentjük a tranzakció adatait
            $order->update_meta_data('_simplepay_transaction_id', $response['transactionId']);
            $order->save();
            
            // Átirányítjuk a vásárlót a SimplePay fizetési oldalára
            return array(
                'result' => 'success',
                'redirect' => $response['paymentUrl']
            );
            
        } catch (Exception $e) {
            wc_add_notice($e->getMessage(), 'error');
            return array(
                'result' => 'failure',
                'messages' => $e->getMessage()
            );
        }
    }

    public function handle_callback() {
        $raw_data = file_get_contents('php://input');
        $signature = $_SERVER['HTTP_SIGNATURE'] ?? '';
        
        // Ellenőrizzük az IPN üzenet hitelességét
        if (!$this->api->verify_ipn($raw_data, $signature)) {
            wp_die('Invalid signature', 'SimplePay Error', array('response' => 403));
        }
        
        $data = json_decode($raw_data, true);
        $order = wc_get_order($data['orderRef']);
        
        if (!$order) {
            wp_die('Order not found', 'SimplePay Error', array('response' => 404));
        }
        
        // Tranzakció státusz kezelése
        switch ($data['status']) {
            case 'FINISHED':
                $order->payment_complete($data['transactionId']);
                $order->add_order_note(__('Payment completed via SimplePay.', 'wc-simplepay'));
                break;
                
            case 'FAILED':
                $order->update_status('failed', __('Payment failed via SimplePay.', 'wc-simplepay'));
                break;
                
            case 'TIMEOUT':
                $order->update_status('cancelled', __('Payment timeout via SimplePay.', 'wc-simplepay'));
                break;
        }
        
        exit('OK');
    }

    public function admin_scripts() {
        if ('woocommerce_page_wc-settings' !== get_current_screen()->id) {
            return;
        }

        wp_enqueue_script(
            'wc-simplepay-admin',
            plugins_url('assets/js/admin.js', dirname(__FILE__)),
            array('jquery'),
            WC_SIMPLEPAY_VERSION,
            true
        );
    }
} 