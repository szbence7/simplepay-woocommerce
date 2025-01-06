<?php
class WC_SimplePay_API {
    private $merchant_id;
    private $secret_key;
    private $is_test_mode;
    private $api_url;

    public function __construct($merchant_id, $secret_key, $is_test_mode = false) {
        $this->merchant_id = $merchant_id;
        $this->secret_key = $secret_key;
        $this->is_test_mode = $is_test_mode;
        $this->api_url = $is_test_mode 
            ? 'https://sandbox.simplepay.hu/payment/'
            : 'https://secure.simplepay.hu/payment/';
    }

    public function create_payment($order) {
        // Ellenőrizzük a kötelező mezőket
        if (empty($this->merchant_id) || empty($this->secret_key)) {
            throw new Exception(__('SimplePay configuration is missing. Please configure the payment gateway in WooCommerce settings.', 'wc-simplepay'));
        }

        // Ellenőrizzük a támogatott pénznemeket
        $currency = $order->get_currency();
        if (!in_array($currency, ['HUF', 'EUR'])) {
            throw new Exception(sprintf(__('Currency %s is not supported by SimplePay.', 'wc-simplepay'), $currency));
        }

        $total = $order->get_total();
        
        $data = [
            'merchant' => $this->merchant_id,
            'orderRef' => $order->get_id(),
            'currency' => $currency,
            'customerEmail' => $order->get_billing_email(),
            'language' => 'HU',
            'total' => round($total * 100), // SimplePay expects amount in smallest currency unit
            'timeout' => date('c', strtotime('+30 minutes')),
            'url' => $this->get_urls($order),
            'invoice' => [
                'name' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                'company' => $order->get_billing_company(),
                'country' => $order->get_billing_country(),
                'state' => $order->get_billing_state(),
                'city' => $order->get_billing_city(),
                'zip' => $order->get_billing_postcode(),
                'address' => $order->get_billing_address_1() . ' ' . $order->get_billing_address_2(),
            ],
        ];

        $data['signature'] = $this->generate_signature($data);

        $response = wp_remote_post($this->api_url . 'v2/start', [
            'body' => json_encode($data),
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
        ]);

        if (is_wp_error($response)) {
            throw new Exception($response->get_error_message());
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (!isset($body['paymentUrl'])) {
            throw new Exception(__('Invalid response from SimplePay', 'wc-simplepay'));
        }

        return $body;
    }

    private function get_urls($order) {
        return [
            'success' => $order->get_checkout_order_received_url(),
            'fail' => $order->get_checkout_payment_url(),
            'cancel' => $order->get_cancel_order_url(),
            'timeout' => $order->get_cancel_order_url(),
            'ipn' => WC()->api_request_url('wc_simplepay_gateway'),
        ];
    }

    private function generate_signature($data) {
        // Remove signature field if exists
        unset($data['signature']);
        
        // Convert to JSON and normalize
        $json = json_encode($data);
        
        // Generate hash
        return hash_hmac('sha384', $json, $this->secret_key);
    }

    public function verify_ipn($raw_data, $signature) {
        $calculated_signature = hash_hmac('sha384', $raw_data, $this->secret_key);
        return hash_equals($signature, $calculated_signature);
    }
} 