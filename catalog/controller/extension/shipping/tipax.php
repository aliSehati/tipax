<?php
class ControllerExtensionShippingTipax extends Controller {

    public function index($address) {
        $this->load->language('extension/shipping/tipax');
        $this->load->model('extension/shipping/tipax');

        if (!$this->config->get('shipping_tipax_status')) {
            return false;
        }

        // Check if city is supported via model
        if (!$this->model_extension_shipping_tipax->isCitySupported($address)) {
            return false;
        }

        $quote = $this->model_extension_shipping_tipax->getQuote($address);
        return $quote;
    }

    // Event handler for order completion
    public function eventOrderComplete(&$route, &$args) {
        if (isset($args[0])) {
            $order_id = $args[0];
        } else {
            $order_id = 0;
        }

        if (isset($args[1])) {
            $order_status_id = $args[1];
        } else {
            $order_status_id = 0;
        }

        $this->load->model('checkout/order');

        $order_info = $this->model_checkout_order->getOrder($order_id);
        if ($order_info) {
            if (!$order_info['order_status_id'] && $order_status_id) {

                $this->processOrderForTipax($order_id, 'order_complete', $order_status_id);
            }
        }
    }

    // Event handler for order status change
    public function eventOrderStatusChange(&$route, &$data, &$output) {
        if (isset($data[0]) && isset($data[1])) {
            $order_id = (int)$data[0];
            $order_status_id = (int)$data[1];

            $this->processOrderForTipax($order_id, 'status_change', $order_status_id);
        }
    }

    private function processOrderForTipax($order_id, $trigger_source = 'unknown', $order_status_id = null) {
        $this->load->model('extension/shipping/tipax');
        $this->load->model('checkout/order');

        $order_info = $this->model_checkout_order->getOrder($order_id);
        if (!$order_info) {
            return;
        }

        // Check if this is a Tipax order
        if (empty($order_info['shipping_code']) || strpos($order_info['shipping_code'], 'tipax.') !== 0) {
            return;
        }

        // Check if already processed
        $existing = $this->model_extension_shipping_tipax->getTipaxOrder($order_id);
        if ($existing) {
            return;
        }

        $current_status_id = $order_status_id ?: $order_info['order_status_id'];

        // Check based on trigger source
        if ($trigger_source == 'order_complete') {
            // Check auto submit setting - must be enabled
            $auto_submit = (bool)$this->config->get('shipping_tipax_auto_submit');
            if (!$auto_submit) {
                return;
            }

            // Check if auto submit statuses are configured and not empty
            $auto_submit_statuses_json = $this->config->get('shipping_tipax_auto_submit_statuses');
            if (empty($auto_submit_statuses_json)) {
                return;
            }

            // Check if current order status is in allowed statuses for auto submit
            if (!$this->shouldAutoSubmitForStatus($current_status_id)) {
                return;
            }
        } elseif ($trigger_source == 'status_change') {
            // Check auto submit on status change setting - must be enabled
            $auto_submit_on_status_change = (bool)$this->config->get('shipping_tipax_auto_submit_on_status_change');

            if (!$auto_submit_on_status_change) {
                return;
            }

            // Check if auto submit on status change statuses are configured and not empty
            $auto_submit_on_status_change_statuses_json = $this->config->get('shipping_tipax_auto_submit_on_status_change_statuses');
            if (empty($auto_submit_on_status_change_statuses_json)) {
                return;
            }

            // Check if current order status is in allowed statuses for auto submit on status change
            if (!$this->shouldAutoSubmitForStatusChange($current_status_id)) {
                return;
            }
        } else {
            return;
        }

        try {
            $result = $this->model_extension_shipping_tipax->submitOrder($order_id);
            // log write 
        } catch (Exception $e) {
            // Silent fail
        }
    }

    private function shouldAutoSubmitForStatus($order_status_id) {
        // Get configured auto submit statuses
        $auto_submit_statuses_json = $this->config->get('shipping_tipax_auto_submit_statuses');

        if (empty($auto_submit_statuses_json)) {
            return false;
        }

        $auto_submit_statuses = $auto_submit_statuses_json;
        if (!is_array($auto_submit_statuses)) {
            return false;
        }

        // Check if current status is in allowed statuses
        return in_array((int)$order_status_id, array_map('intval', $auto_submit_statuses));
    }

    private function shouldAutoSubmitForStatusChange($order_status_id) {
        // Get configured auto submit on status change statuses
        $auto_submit_on_status_change_statuses_json = $this->config->get('shipping_tipax_auto_submit_on_status_change_statuses');

        if (empty($auto_submit_on_status_change_statuses_json)) {
            return false;
        }

        $auto_submit_on_status_change_statuses = $auto_submit_on_status_change_statuses_json;
        if (!is_array($auto_submit_on_status_change_statuses)) {
            return false;
        }

        // Check if current status is in allowed statuses
        return in_array((int)$order_status_id, array_map('intval', $auto_submit_on_status_change_statuses));
    }
}
