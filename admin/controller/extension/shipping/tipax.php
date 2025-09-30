<?php
class ControllerExtensionShippingTipax extends Controller {
    private $error = [];

    public function index() {
        $this->load->language('extension/shipping/tipax');
		$data['module_version'] = sprintf($this->language->get('text_version'), $this->language->get('OCM_VERSION'));
        $this->load->model('extension/shipping/tipax');
        $this->document->setTitle($this->language->get('heading_title'));
        $this->load->model('setting/setting');

        if (isset($this->request->get['action'])) {
            $this->handleAction();
            return;
        }

        if ($this->request->server['REQUEST_METHOD'] == 'POST' && $this->validate()) {
            // If env or credentials changed, clear token cache
            $prev_env = (string)$this->config->get('shipping_tipax_env');
            $prev_user = (string)$this->config->get('shipping_tipax_username');
            $prev_pass = (string)$this->config->get('shipping_tipax_password');
            $prev_key  = (string)$this->config->get('shipping_tipax_api_key');
            $new_env = (string)($this->request->post['shipping_tipax_env'] ?? $prev_env);
            $new_user = (string)($this->request->post['shipping_tipax_username'] ?? $prev_user);
            $new_pass = (string)($this->request->post['shipping_tipax_password'] ?? $prev_pass);
            $new_key  = (string)($this->request->post['shipping_tipax_api_key'] ?? $prev_key);

            $this->model_setting_setting->editSetting('shipping_tipax', $this->request->post);

            if ($new_env !== $prev_env || $new_user !== $prev_user || $new_pass !== $prev_pass || $new_key !== $prev_key) {
                $this->load->library('tipax');
                $this->tipax->clearTokenCache();
            }
            $this->session->data['success'] = $this->language->get('text_success');
            // $this->response->redirect($this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=shipping', true));
            $this->response->redirect($this->url->link('extension/shipping/tipax', 'user_token=' . $this->session->data['user_token'], true));
        }

        $data = $this->buildFormData();

        // success 
        if (isset($this->session->data['success'])) {
            $data['success'] = $this->session->data['success'];
            unset($this->session->data['success']);
        } else {
            $data['success'] = '';
        }

        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');
        $this->response->setOutput($this->load->view('extension/shipping/tipax', $data));
    }

    public function install() {
        $this->load->model('extension/shipping/tipax');
        $this->model_extension_shipping_tipax->install();

        // Add events
        $this->load->model('setting/event');
        $this->model_setting_event->addEvent('tipax_order_complete', 'catalog/model/checkout/order/addOrderHistory/before', 'extension/shipping/tipax/eventOrderComplete');
        $this->model_setting_event->addEvent('tipax_order_status_change', 'catalog/model/checkout/order/addOrderHistory/after', 'extension/shipping/tipax/eventOrderStatusChange');
    }

    public function uninstall() {
        $this->load->model('extension/shipping/tipax');
        $this->model_extension_shipping_tipax->uninstall();

        // Remove events
        $this->load->model('setting/event');
        $this->model_setting_event->deleteEventByCode('tipax_order_complete');
        $this->model_setting_event->deleteEventByCode('tipax_order_status_change');
    }

    private function handleAction() {
        $this->load->language('extension/shipping/tipax');
        $this->load->model('extension/shipping/tipax');
        $action = $this->request->get['action'];

        try {
            switch ($action) {
                case 'auto_match':
                    $count = (int)$this->model_extension_shipping_tipax->getCitiesCount();
                    if ($count <= 0) {
                        $this->json(['success' => false, 'message' => 'لیست شهرهای تیپاکس خالی است. ابتدا همگام‌سازی شهرها را انجام دهید.']);
                        return;
                    }
                    $ok = $this->model_extension_shipping_tipax->autoMatchCities();
                    $this->json(['success' => true, 'matched' => $ok['matched'], 'total' => $ok['total']]);
                    return;

                case 'test_connection':
                    $ok = $this->model_extension_shipping_tipax->getWalletBalance();
                    $this->json(['success' => $ok !== false, 'message' => $ok !== false ? $this->language->get('success_connection') : $this->language->get('error_connection')]);
                    return;

                case 'check_wallet':
                    $bal = $this->model_extension_shipping_tipax->getWalletBalance();
                    $this->json($bal !== false ? ['success' => true, 'balance' => number_format($bal)] : ['success' => false, 'message' => $this->language->get('error_connection')]);
                    return;

                case 'charge_wallet':
                    $amount = isset($this->request->post['amount']) ? (float)$this->request->post['amount'] : 0;
                    if ($amount <= 0) {
                        $this->json(['success' => false, 'message' => $this->language->get('error_wallet_charge')]);
                        return;
                    }
                    $callback = html_entity_decode($this->url->link('extension/shipping/tipax', 'user_token=' . $this->session->data['user_token'], true));
                    $res = $this->model_extension_shipping_tipax->rechargeWallet($amount, $callback);
                    if (is_array($res) && !empty($res['paymentURL'])) {
                        $this->json(['success' => true, 'payment_url' => $res['paymentURL']]);
                    } else {
                        $this->json(['success' => false, 'message' => $this->language->get('error_connection')]);
                    }
                    return;

                case 'sync_cities':
                    $count = $this->model_extension_shipping_tipax->syncCities();
                    $this->json($count !== false ? ['success' => true, 'message' => sprintf($this->language->get('success_cities_synced'), $count)] : ['success' => false, 'message' => $this->language->get('error_cities_sync')]);
                    return;

                case 'load_addresses':
                    $ok = $this->model_extension_shipping_tipax->loadAddressBook();
                    if ($ok) {
                        $this->json(['success' => true, 'addresses' => $this->model_extension_shipping_tipax->getSavedAddresses()]);
                    } else {
                        $this->json(['success' => false, 'message' => $this->language->get('error_connection')]);
                    }
                    return;

                case 'orders':
                    $this->ordersPage();
                    return;

                case 'cancel_order':
                    $order_id = isset($this->request->post['order_id']) ? (int)$this->request->post['order_id'] : 0;
                    if ($order_id) {
                        $ok = $this->model_extension_shipping_tipax->cancelOrder($order_id);
                        $this->json($ok ? ['success' => true, 'message' => $this->language->get('success_cancelled')] : ['success' => false, 'message' => 'لغو امکان‌پذیر نیست (احتمالاً سفارش قبلاً لغو شده یا مجاز نیست).']);
                    } else {
                        $this->json(['success' => false, 'message' => 'شناسه نامعتبر']);
                    }
                    return;

                case 'submit_order':
                    $order_id = isset($this->request->get['order_id']) ? (int)$this->request->get['order_id'] : 0;
                    if ($order_id) {
                        $ok = $this->model_extension_shipping_tipax->submitOrder($order_id);
                        if ($ok) $this->session->data['success'] = $this->language->get('success_submitted');
                        else $this->session->data['error_warning'] = 'ارسال به تیپاکس ناموفق بود. لطفاً تنظیمات و لاگ‌ها را بررسی کنید.';
                    }
                    $this->response->redirect($this->url->link('sale/order', 'user_token=' . $this->session->data['user_token'], true));
                    return;

                case 'city_mapping':
                    $this->cityMappingPage();
                    return;

                case 'state_mapping':
                    $this->stateMappingPage();
                    return;

                case 'add_city_to_oc':
                    $tipax_city_id = (int)($this->request->post['tipax_city_id'] ?? 0);
                    $city_name = trim($this->request->post['city_name'] ?? '');
                    $state_name = trim($this->request->post['state_name'] ?? '');

                    if (!$tipax_city_id || !$city_name || !$state_name) {
                        $this->json(['success' => false, 'message' => 'اطلاعات ناقص است.']);
                        return;
                    }

                    $result = $this->model_extension_shipping_tipax->addCityToOpenCart($tipax_city_id, $city_name, $state_name);
                    $this->json($result);
                    return;

                case 'bulk_add_cities':
                    set_time_limit(300); // 5 minutes
                    ini_set('memory_limit', '256M');

                    $result = $this->model_extension_shipping_tipax->bulkAddCitiesToOpenCart();
                    $this->json($result);
                    return;

                case 'save_mapping':
                    $oc_id = (int)($this->request->post['oc_city_id'] ?? 0);
                    $tp_id = (int)($this->request->post['tipax_city_id'] ?? 0);
                    if (!$oc_id || !$tp_id) {
                        $this->json(['success' => false, 'message' => 'شهر انتخاب نشده است.']);
                        return;
                    }
                    $this->model_extension_shipping_tipax->saveCityMapping($oc_id, $tp_id);
                    $this->json(['success' => true, 'message' => $this->language->get('success_mapping_saved')]);
                    return;

                case 'save_state_mapping':
                    $oc_zone_id = (int)($this->request->post['oc_zone_id'] ?? 0);
                    $tp_state_id = (int)($this->request->post['tipax_state_id'] ?? 0);
                    if (!$oc_zone_id || !$tp_state_id) {
                        $this->json(['success' => false, 'message' => 'استان انتخاب نشده است.']);
                        return;
                    }
                    $this->model_extension_shipping_tipax->saveStateMappingAdmin($oc_zone_id, $tp_state_id);
                    $this->json(['success' => true, 'message' => $this->language->get('success_state_mapping_saved')]);
                    return;

                case 'bulk_submit':
                    $order_ids = isset($this->request->post['order_ids']) ? $this->request->post['order_ids'] : [];
                    if (empty($order_ids) || !is_array($order_ids)) {
                        $this->json(['success' => false, 'message' => 'هیچ سفارشی انتخاب نشده است.']);
                        return;
                    }
                    $results = $this->model_extension_shipping_tipax->bulkSubmitOrders($order_ids);
                    $success_count = count(array_filter($results));
                    $total_count = count($results);
                    $this->json([
                        'success' => true,
                        'message' => sprintf('%d از %d سفارش با موفقیت ارسال شد.', $success_count, $total_count),
                        'results' => $results
                    ]);
                    return;

                case 'bulk_cancel':
                    $order_ids = isset($this->request->post['order_ids']) ? $this->request->post['order_ids'] : [];
                    if (empty($order_ids) || !is_array($order_ids)) {
                        $this->json(['success' => false, 'message' => 'هیچ سفارشی انتخاب نشده است.']);
                        return;
                    }
                    $results = $this->model_extension_shipping_tipax->bulkCancelOrders($order_ids);
                    $success_count = count(array_filter($results));
                    $total_count = count($results);
                    $this->json([
                        'success' => true,
                        'message' => sprintf('%d از %d سفارش با موفقیت ابطال شد.', $success_count, $total_count),
                        'results' => $results
                    ]);
                    return;

                case 'auto_match_states':
                    $res = $this->model_extension_shipping_tipax->autoMatchStates();
                    $this->json(['success' => true, 'matched' => (int)$res['matched'], 'total' => (int)$res['total']]);
                    return;
            }
        } catch (\Throwable $e) {
            $this->json(['success' => false, 'message' => 'خطای غیرمنتظره: ' . $e->getMessage()]);
            return;
        }

        $this->json(['success' => false, 'message' => 'Invalid action']);
    }

    private function buildFormData() {
        $this->load->language('extension/shipping/tipax');
        $this->load->model('extension/shipping/tipax');
        $this->load->model('localisation/order_status');

        $data['heading_title'] = $this->language->get('heading_title');
        $data['text_edit']     = $this->language->get('text_edit');
        $data['text_enabled']  = $this->language->get('text_enabled');
        $data['text_disabled'] = $this->language->get('text_disabled');
        $data['text_none']     = $this->language->get('text_none');
        $data['text_saved_address'] = $this->language->get('text_saved_address');
        $data['text_map_address']   = $this->language->get('text_map_address');
        $data['text_services_hint'] = $this->language->get('text_services_hint');
        $data['text_sender_hint']   = $this->language->get('text_sender_hint');

        $data['tab_general']  = $this->language->get('tab_general');
        $data['tab_services'] = $this->language->get('tab_services');
        $data['tab_sender']   = $this->language->get('tab_sender');
        $data['tab_cities']   = $this->language->get('tab_cities');
        $data['tab_wallet']   = $this->language->get('tab_wallet');

        $keys = [
            'shipping_tipax_username',
            'shipping_tipax_password',
            'shipping_tipax_api_key',
            'shipping_tipax_env',
            'shipping_tipax_status',
            'shipping_tipax_sort_order',
            'shipping_tipax_payment_type',
            // Fallback defaults for package calculations
            'shipping_tipax_default_weight_kg',
            'shipping_tipax_default_length_cm',
            'shipping_tipax_default_width_cm',
            'shipping_tipax_default_height_cm',
            'shipping_tipax_sender_mode',
            'shipping_tipax_sender_selected_address_id',
            'shipping_tipax_sender_name',
            'shipping_tipax_sender_mobile',
            'shipping_tipax_sender_phone',
            'shipping_tipax_sender_full_address',
            'shipping_tipax_sender_postal_code',
            'shipping_tipax_sender_city_id',
            'shipping_tipax_sender_lat',
            'shipping_tipax_sender_lng',
            'shipping_tipax_sender_no',
            'shipping_tipax_sender_unit',
            'shipping_tipax_sender_floor',
            'shipping_tipax_auto_submit',
            'shipping_tipax_auto_submit_on_status_change',
        ];

        foreach ($keys as $k) {
            $data[$k] = $this->request->post[$k] ?? $this->config->get($k);
        }

        // Auto submit statuses handling
        $selected_statuses = array();
        $saved_statuses = $this->config->get('shipping_tipax_auto_submit_statuses');
        if (!empty($saved_statuses)) {
            if (is_array($saved_statuses)) {
                $selected_statuses = $saved_statuses;
            }
        }
        $data['shipping_tipax_auto_submit_statuses'] = array_map('intval', $selected_statuses);

        $selected_statuses_change = array();
        $saved_statuses_change = $this->config->get('shipping_tipax_auto_submit_on_status_change_statuses');
        if (!empty($saved_statuses_change)) {
            if (is_array($saved_statuses_change)) {
                $selected_statuses_change = $saved_statuses_change;
            }
        }
        $data['shipping_tipax_auto_submit_on_status_change_statuses'] = array_map('intval', $selected_statuses_change);

        // Defaults
        if (!$data['shipping_tipax_default_weight_kg']) $data['shipping_tipax_default_weight_kg'] = 10;
        if (!$data['shipping_tipax_default_length_cm']) $data['shipping_tipax_default_length_cm'] = 45;
        if (!$data['shipping_tipax_default_width_cm'])  $data['shipping_tipax_default_width_cm'] = 45;
        if (!$data['shipping_tipax_default_height_cm']) $data['shipping_tipax_default_height_cm'] = 45;
        if (!$data['shipping_tipax_payment_type']) $data['shipping_tipax_payment_type'] = 10;
        if (!$data['shipping_tipax_sender_mode'])   $data['shipping_tipax_sender_mode'] = 'saved';
        if ($data['shipping_tipax_auto_submit'] === null) $data['shipping_tipax_auto_submit'] = 1;

        // Errors
        $data['error_warning'] = $this->error['warning'] ?? '';
        $data['error_username'] = $this->error['username'] ?? '';
        $data['error_password'] = $this->error['password'] ?? '';
        $data['error_api_key']  = $this->error['api_key'] ?? '';
        $data['error_sender_name']   = $this->error['sender_name'] ?? '';
        $data['error_sender_mobile'] = $this->error['sender_mobile'] ?? '';
        $data['error_sender_address'] = $this->error['sender_address'] ?? '';

        // Breadcrumbs
        $data['breadcrumbs'] = [
            ['text' => $this->language->get('text_home'), 'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'], true)],
            ['text' => $this->language->get('text_extension'), 'href' => $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=shipping', true)],
            ['text' => $this->language->get('heading_title'), 'href' => $this->url->link('extension/shipping/tipax', 'user_token=' . $this->session->data['user_token'], true)],
        ];

        // URLs
        $data['action']              = $this->url->link('extension/shipping/tipax', 'user_token=' . $this->session->data['user_token'], true);
        $data['cancel']              = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=shipping', true);
        $data['test_connection_url'] = html_entity_decode($this->url->link('extension/shipping/tipax', 'user_token=' . $this->session->data['user_token'] . '&action=test_connection', true));
        $data['check_wallet_url']    = html_entity_decode($this->url->link('extension/shipping/tipax', 'user_token=' . $this->session->data['user_token'] . '&action=check_wallet', true));
        $data['charge_wallet_url']   = html_entity_decode($this->url->link('extension/shipping/tipax', 'user_token=' . $this->session->data['user_token'] . '&action=charge_wallet', true));
        $data['sync_cities_url']     = html_entity_decode($this->url->link('extension/shipping/tipax', 'user_token=' . $this->session->data['user_token'] . '&action=sync_cities', true));
        $data['load_addresses_url']  = html_entity_decode($this->url->link('extension/shipping/tipax', 'user_token=' . $this->session->data['user_token'] . '&action=load_addresses', true));
        $data['orders_url']          = html_entity_decode($this->url->link('extension/shipping/tipax', 'user_token=' . $this->session->data['user_token'] . '&action=orders', true));
        $data['mapping_url']         = html_entity_decode($this->url->link('extension/shipping/tipax', 'user_token=' . $this->session->data['user_token'] . '&action=city_mapping', true));
    $data['mapping_state_url']   = html_entity_decode($this->url->link('extension/shipping/tipax', 'user_token=' . $this->session->data['user_token'] . '&action=state_mapping', true));
        // Public cron URL (catalog side) for syncing Tipax cities (to be used in server cron)
        if (defined('HTTP_CATALOG')) {
            $data['cron_sync_cities_url'] = rtrim(HTTP_CATALOG, '/') . '/index.php?route=extension/cron/tipax&action=sync_cities';
        } else {
            // Fallback to store URL from config
            $base_catalog = $this->config->get('config_url') ?: ($this->config->get('config_ssl') ?: '');
            $data['cron_sync_cities_url'] = rtrim($base_catalog, '/') . '/index.php?route=extension/cron/tipax&action=sync_cities';
        }
    // Options no longer include tax class or geo zone for this module

        $data['service_types'] = $this->model_extension_shipping_tipax->getServiceTypes();
        $data['payment_types'] = $this->model_extension_shipping_tipax->getPaymentTypes();
        $data['pickup_types']  = $this->model_extension_shipping_tipax->getPickupTypes();
        $data['distribution_types'] = $this->model_extension_shipping_tipax->getDistributionTypes();

        // Stats
        $data['cities_count'] = $this->model_extension_shipping_tipax->getCitiesCount();
        $data['saved_addresses'] = $this->model_extension_shipping_tipax->getSavedAddresses();

        $data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();

        // Tipax-based mapping counts to show warning in module
        $counts = $this->model_extension_shipping_tipax->getCityMappingCounts('');
        $data['unmatched_count'] = (int)$counts['unmatched'];
        // Unmatched provinces (states) warning counter
        if (method_exists($this->model_extension_shipping_tipax, 'getStateMappingCounts')) {
            $state_counts = $this->model_extension_shipping_tipax->getStateMappingCounts('');
            $data['unmatched_states_count'] = (int)$state_counts['unmatched'];
        } else {
            $data['unmatched_states_count'] = 0;
        }

        // Tipax cities for dropdown (sender city)
        $data['tipax_cities'] = $this->model_extension_shipping_tipax->getTipaxCities();

        return $data;
    }

    private function ordersPage() {
        $this->load->model('extension/shipping/tipax');
        $filter = $this->request->get['filter'] ?? 'all'; // all, failed, submitted, pending, cancelled
        $page = max(1, (int)($this->request->get['page'] ?? 1));
        $limit = max(5, (int)($this->request->get['limit'] ?? 20));
        // Advanced search filters
        $f_order_id = trim($this->request->get['f_order_id'] ?? '');
        $f_customer = trim($this->request->get['f_customer'] ?? '');
        $f_city = trim($this->request->get['f_city'] ?? '');
        $token = $this->session->data['user_token'];

        $this->load->language('extension/shipping/tipax');
        $data['heading_title'] = $this->language->get('heading_title') . ' - ' . ($this->language->get('tab_orders') ?: 'سفارشات');
        $data['user_token'] = $token;

        $result = $this->model_extension_shipping_tipax->getTipaxOrdersFilteredPaginated($filter, $page, $limit, $f_order_id, $f_customer, $f_city);
        $data['orders'] = $result['rows'];
        $total = (int)$result['total'];
        $data['filter'] = $filter;
        $data['f_order_id'] = $f_order_id;
        $data['f_customer'] = $f_customer;
        $data['f_city'] = $f_city;

        $search_q = '';
        if ($f_order_id !== '') $search_q .= '&f_order_id=' . urlencode($f_order_id);
        if ($f_customer !== '') $search_q .= '&f_customer=' . urlencode($f_customer);
        if ($f_city !== '') $search_q .= '&f_city=' . urlencode($f_city);

        // Pagination
        $pagination = new Pagination();
        $pagination->total = $total;
        $pagination->page = $page;
        $pagination->limit = $limit;
        $pagination->url = html_entity_decode($this->url->link(
            'extension/shipping/tipax',
            'user_token=' . $token . '&action=orders&filter=' . $filter . '&limit=' . $limit . $search_q . '&page={page}',
            true
        ));
        $data['pagination'] = $pagination->render();
        $pages = $limit ? (int)ceil($total / $limit) : 1;
        $start = $total ? (($page - 1) * $limit) + 1 : 0;
        $end = ((($page - 1) * $limit) + $limit > $total) ? $total : ((($page - 1) * $limit) + $limit);
        $text_pagination = $this->language->get('text_pagination');
        if ($text_pagination === 'text_pagination') {
            $text_pagination = 'نمایش %d تا %d از %d (%d صفحه)';
        }
        $data['results'] = sprintf($text_pagination, $start, $end, $total, $pages);

        // URLs
        $data['cancel_order_url'] = html_entity_decode($this->url->link('extension/shipping/tipax', 'user_token=' . $this->session->data['user_token'] . '&action=cancel_order', true));
        $data['sale_order_url'] = html_entity_decode($this->url->link('sale/order', 'user_token=' . $this->session->data['user_token'], true));
        $data['dashboard_url'] = html_entity_decode($this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'], true));
        $data['tipax_main_url'] = html_entity_decode($this->url->link('extension/shipping/tipax', 'user_token=' . $this->session->data['user_token'], true));
        $data['bulk_cancel_url'] = html_entity_decode($this->url->link('extension/shipping/tipax', 'user_token=' . $this->session->data['user_token'] . '&action=bulk_cancel', true));
        $data['submit_order_base_url'] = html_entity_decode($this->url->link('extension/shipping/tipax', 'user_token=' . $this->session->data['user_token'] . '&action=submit_order', true));
        $data['bulk_submit_url'] = html_entity_decode($this->url->link('extension/shipping/tipax', 'user_token=' . $this->session->data['user_token'] . '&action=bulk_submit', true));

        // فیلتر URLs
        $data['filter_urls'] = [
            'all' => html_entity_decode($this->url->link('extension/shipping/tipax', 'user_token=' . $token . '&action=orders&filter=all&limit=' . $limit . $search_q, true)),
            'failed' => html_entity_decode($this->url->link('extension/shipping/tipax', 'user_token=' . $token . '&action=orders&filter=failed&limit=' . $limit . $search_q, true)),
            'submitted' => html_entity_decode($this->url->link('extension/shipping/tipax', 'user_token=' . $token . '&action=orders&filter=submitted&limit=' . $limit . $search_q, true)),
            'pending' => html_entity_decode($this->url->link('extension/shipping/tipax', 'user_token=' . $token . '&action=orders&filter=pending&limit=' . $limit . $search_q, true)),
            'cancelled' => html_entity_decode($this->url->link('extension/shipping/tipax', 'user_token=' . $token . '&action=orders&filter=cancelled&limit=' . $limit . $search_q, true)),
        ];
        // Base URL for current filter without advanced params (used for clearing)
        $data['clear_filter_url'] = html_entity_decode($this->url->link('extension/shipping/tipax', 'user_token=' . $token . '&action=orders&filter=' . $filter . '&limit=' . $limit, true));

        // Language strings
        $data['button_cancel_tipax'] = 'ابطال سفارش تیپاکس';

        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');
        $this->response->setOutput($this->load->view('extension/shipping/tipax_orders', $data));
    }

    private function cityMappingPage() {
        $this->load->model('extension/shipping/tipax');

        $filter = $this->request->get['filter'] ?? 'unmatched'; // unmatched|matched|all
        $page = max(1, (int)($this->request->get['page'] ?? 1));
        $limit = max(5, (int)($this->request->get['limit'] ?? 25));
        $q = trim($this->request->get['q'] ?? '');
        $token = $this->session->data['user_token'];

        // Counts and data
        $counts = $this->model_extension_shipping_tipax->getCityMappingCounts($q);
        $list = $this->model_extension_shipping_tipax->getCityMappingsPaginated($filter, $q, $page, $limit);

        $total = (int)$list['total'];

        // OC Pagination
        $this->load->language('extension/shipping/tipax');
        $this->load->model('extension/shipping/tipax');

        $this->load->model('tool/image'); // no-op; ensures autoloads similar to core pages (optional)

        $pagination = new Pagination();
        $pagination->total = $total;
        $pagination->page = $page;
        $pagination->limit = $limit;
        $pagination->url = html_entity_decode($this->url->link(
            'extension/shipping/tipax',
            'user_token=' . $token . '&action=city_mapping&filter=' . $filter . '&q=' . urlencode($q) . '&limit=' . $limit . '&page={page}',
            true
        ));
        $data['pagination'] = $pagination->render();

        $pages = (int)ceil($total / $limit);
        $start = $total ? (($page - 1) * $limit) + 1 : 0;
        $end = ((($page - 1) * $limit) + $limit > $total) ? $total : ((($page - 1) * $limit) + $limit);
        $text_pagination = $this->language->get('text_pagination');
        if ($text_pagination === 'text_pagination') {
            $text_pagination = 'نمایش %d تا %d از %d (%d صفحه)';
        }
        $data['results'] = sprintf($text_pagination, $start, $end, $total, $pages);

        // Build decoded URLs for filters
        $data['heading_title'] = $this->language->get('heading_title') . ' - ' . $this->language->get('tab_mapping');
        $data['user_token'] = $token;
        $data['rows'] = $list['rows'];
        $data['total'] = $total;
        $data['page'] = $page;
        $data['limit'] = $limit;
        $data['filter'] = $filter;
        $data['q'] = $q;
        $data['counts'] = $counts;

        // Needed for dropdown (OC cities list)
        $data['oc_cities'] = $this->model_extension_shipping_tipax->getOcCities('');

        $data['filter_urls'] = [
            'unmatched' => html_entity_decode($this->url->link('extension/shipping/tipax', 'user_token=' . $token . '&action=city_mapping&filter=unmatched&q=' . urlencode($q) . '&limit=' . $limit, true)),
            'matched'   => html_entity_decode($this->url->link('extension/shipping/tipax', 'user_token=' . $token . '&action=city_mapping&filter=matched&q=' . urlencode($q) . '&limit=' . $limit, true)),
            'all'       => html_entity_decode($this->url->link('extension/shipping/tipax', 'user_token=' . $token . '&action=city_mapping&filter=all&q=' . urlencode($q) . '&limit=' . $limit, true)),
        ];
        $data['search_action']       = html_entity_decode($this->url->link('extension/shipping/tipax', 'user_token=' . $token . '&action=city_mapping', true));
        $data['cancel_url']          = html_entity_decode($this->url->link('extension/shipping/tipax', 'user_token=' . $token, true));
        $data['save_mapping_url']    = html_entity_decode($this->url->link('extension/shipping/tipax', 'user_token=' . $token . '&action=save_mapping', true));
        $data['auto_match_url']      = html_entity_decode($this->url->link('extension/shipping/tipax', 'user_token=' . $token . '&action=auto_match', true));
        $data['add_city_url']        = html_entity_decode($this->url->link('extension/shipping/tipax', 'user_token=' . $this->session->data['user_token'] . '&action=add_city_to_oc', true));
        $data['bulk_add_cities_url'] = html_entity_decode($this->url->link('extension/shipping/tipax', 'user_token=' . $this->session->data['user_token'] . '&action=bulk_add_cities', true));

        $data['error_warning']       = $this->session->data['error_warning'] ?? '';
        unset($this->session->data['error_warning']);
        $data['success'] = $this->session->data['success'] ?? '';
        unset($this->session->data['success']);

        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');
        $this->response->setOutput($this->load->view('extension/shipping/tipax_city_mapping', $data));
    }

    private function stateMappingPage() {
        $this->load->model('extension/shipping/tipax');

        $filter = $this->request->get['filter'] ?? 'unmatched';
        $page = max(1, (int)($this->request->get['page'] ?? 1));
        $limit = max(5, (int)($this->request->get['limit'] ?? 25));
        $q = trim($this->request->get['q'] ?? '');
        $token = $this->session->data['user_token'];

        $counts = $this->model_extension_shipping_tipax->getStateMappingCounts($q);
        $list = $this->model_extension_shipping_tipax->getStateMappingsPaginated($filter, $q, $page, $limit);
        $total = (int)$list['total'];

        $this->load->language('extension/shipping/tipax');
        $this->load->model('extension/shipping/tipax');

        $pagination = new Pagination();
        $pagination->total = $total;
        $pagination->page = $page;
        $pagination->limit = $limit;
        $pagination->url = html_entity_decode($this->url->link(
            'extension/shipping/tipax',
            'user_token=' . $token . '&action=state_mapping&filter=' . $filter . '&q=' . urlencode($q) . '&limit=' . $limit . '&page={page}',
            true
        ));
        $data['pagination'] = $pagination->render();

        $pages = (int)ceil($total / $limit);
        $start = $total ? (($page - 1) * $limit) + 1 : 0;
        $end = ((($page - 1) * $limit) + $limit > $total) ? $total : ((($page - 1) * $limit) + $limit);
        $text_pagination = $this->language->get('text_pagination');
        if ($text_pagination === 'text_pagination') {
            $text_pagination = 'نمایش %d تا %d از %d (%d صفحه)';
        }
        $data['results'] = sprintf($text_pagination, $start, $end, $total, $pages);

        $data['heading_title'] = $this->language->get('heading_title') . ' - ' . ($this->language->get('tab_state_mapping') ?: 'تطابق استان‌ها');
        $data['user_token'] = $token;
        $data['rows'] = $list['rows'];
        $data['total'] = $total;
        $data['page'] = $page;
        $data['limit'] = $limit;
        $data['filter'] = $filter;
        $data['q'] = $q;
        $data['counts'] = $counts;

        $data['oc_zones'] = $this->model_extension_shipping_tipax->getOcZones('');

        $data['filter_urls'] = [
            'unmatched' => html_entity_decode($this->url->link('extension/shipping/tipax', 'user_token=' . $token . '&action=state_mapping&filter=unmatched&q=' . urlencode($q) . '&limit=' . $limit, true)),
            'matched'   => html_entity_decode($this->url->link('extension/shipping/tipax', 'user_token=' . $token . '&action=state_mapping&filter=matched&q=' . urlencode($q) . '&limit=' . $limit, true)),
            'all'       => html_entity_decode($this->url->link('extension/shipping/tipax', 'user_token=' . $token . '&action=state_mapping&filter=all&q=' . urlencode($q) . '&limit=' . $limit, true)),
        ];

        $data['search_action']       = html_entity_decode($this->url->link('extension/shipping/tipax', 'user_token=' . $token . '&action=state_mapping', true));
        $data['cancel_url']          = html_entity_decode($this->url->link('extension/shipping/tipax', 'user_token=' . $token, true));
        $data['save_mapping_url']    = html_entity_decode($this->url->link('extension/shipping/tipax', 'user_token=' . $token . '&action=save_state_mapping', true));
        $data['auto_match_url']      = html_entity_decode($this->url->link('extension/shipping/tipax', 'user_token=' . $token . '&action=auto_match_states', true));

        $data['error_warning']       = $this->session->data['error_warning'] ?? '';
        unset($this->session->data['error_warning']);
        $data['success'] = $this->session->data['success'] ?? '';
        unset($this->session->data['success']);

        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');
        $this->response->setOutput($this->load->view('extension/shipping/tipax_state_mapping', $data));
    }

    private function validate() {
        if (!$this->user->hasPermission('modify', 'extension/shipping/tipax')) {
            $this->error['warning'] = $this->language->get('error_permission');
        }
        if (empty($this->request->post['shipping_tipax_username'])) $this->error['username'] = $this->language->get('error_username');
        if (empty($this->request->post['shipping_tipax_password'])) $this->error['password'] = $this->language->get('error_password');
        if (empty($this->request->post['shipping_tipax_api_key']))  $this->error['api_key']  = $this->language->get('error_api_key');

        if (($this->request->post['shipping_tipax_sender_mode'] ?? '') !== 'saved') {
            if (empty($this->request->post['shipping_tipax_sender_name'])) $this->error['sender_name'] = $this->language->get('error_sender_name');
            if (empty($this->request->post['shipping_tipax_sender_mobile'])) $this->error['sender_mobile'] = $this->language->get('error_sender_mobile');
            if (empty($this->request->post['shipping_tipax_sender_full_address'])) $this->error['sender_address'] = $this->language->get('error_sender_address');
        }

        return !$this->error;
    }

    private function json($arr) {
        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($arr, JSON_UNESCAPED_UNICODE));
    }
}
