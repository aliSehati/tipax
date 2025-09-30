<?php
class ModelExtensionShippingTipax extends Model {

    // Resolve Tipax city by mapping/name
    private function resolveTipaxCityIdByAddress($addr) {
        // اگر city_id موجود باشد، مستقیماً از mapping استفاده کن
        if (!empty($addr['city_id'])) {
            $city_id = (int)$addr['city_id'];
            $map = $this->db->query("SELECT tipax_city_id FROM `" . DB_PREFIX . "tipax_city_mapping` WHERE oc_city_id='" . $city_id . "' LIMIT 1");
            if ($map->num_rows) {
                return (int)$map->row['tipax_city_id'];
            }
        }

        // Fallback: جستجو بر اساس نام شهر
        $name = $addr['city'] ?? '';
        if ($name) {
            // Try mapping via OC city table if available
            $q = $this->db->query("SHOW TABLES LIKE '" . DB_PREFIX . "city'");
            if ($q->num_rows) {
                $qc = $this->db->query("SELECT city_id FROM `" . DB_PREFIX . "city` WHERE name LIKE '%" . $this->db->escape($name) . "%' LIMIT 1");
                if ($qc->num_rows) {
                    $map = $this->db->query("SELECT tipax_city_id FROM `" . DB_PREFIX . "tipax_city_mapping` WHERE oc_city_id='" . (int)$qc->row['city_id'] . "' LIMIT 1");
                    if ($map->num_rows) return (int)$map->row['tipax_city_id'];
                }
            }

            // Fallback like-search on Tipax cities
            $qt = $this->db->query("SELECT tipax_city_id FROM `" . DB_PREFIX . "tipax_cities` WHERE city_title LIKE '%" . $this->db->escape($name) . "%' LIMIT 1");
            if ($qt->num_rows) return (int)$qt->row['tipax_city_id'];
        }

        return 1; // Default city ID
    }

    public function isCitySupported($address) {
        // اگر city_id موجود نباشد، false برگردان
        if (empty($address['city_id'])) {
            return false;
        }

        $city_id = (int)$address['city_id'];

        // چک کردن وجود mapping برای این شهر
        $query = $this->db->query("SELECT COUNT(*) as total FROM `" . DB_PREFIX . "tipax_city_mapping` WHERE oc_city_id = '" . $city_id . "'");
        return (int)$query->row['total'] > 0;
    }

    private function computePackageFromCart() {
        // Defaults from settings (admin) for missing product data
        $defWeightKg = (float)($this->config->get('shipping_tipax_default_weight_kg') ?: 0.5);
        $defLenCm    = (float)($this->config->get('shipping_tipax_default_length_cm') ?: 10);
        $defWidCm    = (float)($this->config->get('shipping_tipax_default_width_cm') ?: 10);
        $defHeiCm    = (float)($this->config->get('shipping_tipax_default_height_cm') ?: 10);

        $products = $this->cart->getProducts();

        // Store defaults
        $storeWeightClassId = (int)$this->config->get('config_weight_class_id');
        $storeLengthClassId = (int)$this->config->get('config_length_class_id');

        // Target classes
        $kgClassId = $this->getWeightClassIdByUnitKg() ?: $storeWeightClassId;
        $cmClassId = $this->getLengthClassIdByUnitCm() ?: $storeLengthClassId;

    $totalWeightKg = 0.0;     // in kg
    $totalVolumeCm3 = 0.0;    // in cubic cm
    $maxLcm = 0.0;
    $maxWcm = 0.0;
    $maxHcm = 0.0;
    $distinct = 0; $totalQty = 0; $singleDims = null;

        foreach ($products as $p) {
            $qty = (int)($p['quantity'] ?? 1);
            if ($qty <= 0) $qty = 1;
            $distinct++;
            $totalQty += $qty;

            // Weight to KG
            $w = isset($p['weight']) ? (float)$p['weight'] : 0.0;
            if ($w <= 0) {
                $wKg = $defWeightKg;
            } else {
                $fromWClass = (int)($p['weight_class_id'] ?? 0) ?: $storeWeightClassId;
                $wKg = (float)$this->convertWeight($w, $fromWClass, $kgClassId);
            }
            $totalWeightKg += ($wKg > 0 ? $wKg : $defWeightKg) * $qty;

            // Dimensions to CM
            $L = isset($p['length']) ? (float)$p['length'] : 0.0;
            $W = isset($p['width']) ? (float)$p['width'] : 0.0;
            $H = isset($p['height']) ? (float)$p['height'] : 0.0;
            if ($L <= 0) $L = $defLenCm;
            if ($W <= 0) $W = $defWidCm;
            if ($H <= 0) $H = $defHeiCm;

            $fromLClass = (int)($p['length_class_id'] ?? 0) ?: $storeLengthClassId;
            $Lcm = (float)$this->convertLength($L, $fromLClass, $cmClassId);
            $Wcm = (float)$this->convertLength($W, $fromLClass, $cmClassId);
            $Hcm = (float)$this->convertLength($H, $fromLClass, $cmClassId);

            $maxLcm = max($maxLcm, $Lcm);
            $maxWcm = max($maxWcm, $Wcm);
            $maxHcm = max($maxHcm, $Hcm);
            $totalVolumeCm3 += max(0.0, $Lcm) * max(0.0, $Wcm) * max(0.0, $Hcm) * $qty;
            if ($distinct === 1 && $totalQty === $qty) {
                $singleDims = ['L' => $Lcm, 'W' => $Wcm, 'H' => $Hcm];
            }
        }

        // Weight (kg), minimum 0.1 kg
        $kilograms = (float)round($totalWeightKg, 3);
        if ($kilograms <= 0) $kilograms = 0.1;

        // Dimensions (cm)
        if ($distinct === 1 && $totalQty === 1 && $singleDims) {
            $L = max(1, (int)ceil($singleDims['L']));
            $W = max(1, (int)ceil($singleDims['W']));
            $H = max(1, (int)ceil($singleDims['H']));
        } else {
            $Lbase = max(1.0, $maxLcm);
            $Wbase = max(1.0, $maxWcm);
            $L = (int)ceil($Lbase);
            $W = (int)ceil($Wbase);
            $Hcalc = ($Lbase * $Wbase) > 0 ? ($totalVolumeCm3 / ($Lbase * $Wbase)) : $maxHcm;
            $H = (int)ceil(max($Hcalc, $maxHcm, 1.0));
        }

        // Auto pack type: <=2kg => mini pack (50), else bast (20)
        $pack_type = ($kilograms <= 2.0) ? 50 : 20;

        return ['weight_kg' => $kilograms, 'length' => $L, 'width' => $W, 'height' => $H, 'pack_type' => $pack_type];
    }

    // Public: used by OCMOD auto-submit guard
    public function getTipaxOrder($order_id) {
        $q = $this->db->query("SELECT * FROM `" . DB_PREFIX . "tipax_orders` WHERE order_id='" . (int)$order_id . "'");
        return $q->row;
    }

    // =================== Unit Conversion Helpers ===================
    private function convertWeight($value, $from_class_id, $to_class_id) {
        if ($from_class_id == $to_class_id) {
            return $value;
        }

        // Get weight class data
        $from_query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "weight_class` WHERE weight_class_id = '" . (int)$from_class_id . "'");
        $to_query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "weight_class` WHERE weight_class_id = '" . (int)$to_class_id . "'");

        if ($from_query->num_rows && $to_query->num_rows) {
            $from_value = (float)$from_query->row['value'];
            $to_value = (float)$to_query->row['value'];

            if ($from_value > 0 && $to_value > 0) {
                return $value * ($from_value / $to_value);
            }
        }

        return $value;
    }

    private function convertLength($value, $from_class_id, $to_class_id) {
        if ($from_class_id == $to_class_id) {
            return $value;
        }

        // Get length class data
        $from_query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "length_class` WHERE length_class_id = '" . (int)$from_class_id . "'");
        $to_query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "length_class` WHERE length_class_id = '" . (int)$to_class_id . "'");

        if ($from_query->num_rows && $to_query->num_rows) {
            $from_value = (float)$from_query->row['value'];
            $to_value = (float)$to_query->row['value'];

            if ($from_value > 0 && $to_value > 0) {
                return $value * ($from_value / $to_value);
            }
        }

        return $value;
    }

    // Try to find weight class id for kilogram by unit code/title
    private function getWeightClassIdByUnitKg() {
        $q = $this->db->query("SELECT weight_class_id FROM `" . DB_PREFIX . "weight_class_description` WHERE LOWER(unit) IN ('kg','کیلو','کیلوگرم','kilogram') LIMIT 1");
        if ($q->num_rows) return (int)$q->row['weight_class_id'];
        return (int)$this->config->get('config_weight_class_id');
    }

    // Try to find length class id for centimeter by unit code/title
    private function getLengthClassIdByUnitCm() {
        $q = $this->db->query("SELECT length_class_id FROM `" . DB_PREFIX . "length_class_description` WHERE LOWER(unit) IN ('cm','سانتی متر','سانتیمتر','centimeter','سانتی‌متر') LIMIT 1");
        if ($q->num_rows) return (int)$q->row['length_class_id'];
        return (int)$this->config->get('config_length_class_id');
    }

    private function computePackageFromOrder($order_id) {
        // Fallback defaults from settings
        $defWeightKg = (float)($this->config->get('shipping_tipax_default_weight_kg') ?: 0.5);
        $defLenCm    = (float)($this->config->get('shipping_tipax_default_length_cm') ?: 10);
        $defWidCm    = (float)($this->config->get('shipping_tipax_default_width_cm') ?: 10);
        $defHeiCm    = (float)($this->config->get('shipping_tipax_default_height_cm') ?: 10);

        $q = $this->db->query("
            SELECT op.product_id, op.quantity, p.length, p.width, p.height, p.length_class_id, p.weight, p.weight_class_id
            FROM `" . DB_PREFIX . "order_product` op
            LEFT JOIN `" . DB_PREFIX . "product` p ON (p.product_id = op.product_id)
            WHERE op.order_id = '" . (int)$order_id . "'
        ");

        // Store classes and target kg/cm classes
        $storeWeightClassId = (int)$this->config->get('config_weight_class_id');
        $storeLengthClassId = (int)$this->config->get('config_length_class_id');
        $kgClassId = $this->getWeightClassIdByUnitKg() ?: $storeWeightClassId;
        $cmClassId = $this->getLengthClassIdByUnitCm() ?: $storeLengthClassId;

    $totalWeightKg = 0.0;
    $totalVolumeCm3 = 0.0;
    $maxLcm = 0.0;
    $maxWcm = 0.0;
    $maxHcm = 0.0;
    $distinct = 0; $totalQty = 0; $singleDims = null;

        foreach ($q->rows as $r) {
            $qty = (int)$r['quantity'];
            if ($qty <= 0) $qty = 1;
            $distinct++;
            $totalQty += $qty;

            // Weight to KG with default fallback per item
            $w = (float)$r['weight'];
            if ($w > 0 && $r['weight_class_id']) {
                $w = (float)$this->convertWeight($w, (int)$r['weight_class_id'], $kgClassId);
            } else {
                $w = $defWeightKg;
            }
            $totalWeightKg += ($w > 0 ? $w : $defWeightKg) * $qty;

            // Dimensions to CM with default fallback per item
            $L = (float)$r['length'];
            $W = (float)$r['width'];
            $H = (float)$r['height'];
            if ($L <= 0) $L = $defLenCm;
            if ($W <= 0) $W = $defWidCm;
            if ($H <= 0) $H = $defHeiCm;
            if ($r['length_class_id']) {
                $L = (float)$this->convertLength($L, (int)$r['length_class_id'], $cmClassId);
                $W = (float)$this->convertLength($W, (int)$r['length_class_id'], $cmClassId);
                $H = (float)$this->convertLength($H, (int)$r['length_class_id'], $cmClassId);
            }
            $maxLcm = max($maxLcm, $L);
            $maxWcm = max($maxWcm, $W);
            $maxHcm = max($maxHcm, $H);
            $totalVolumeCm3 += max(0.0, $L) * max(0.0, $W) * max(0.0, $H) * $qty;
            if ($distinct === 1 && $totalQty === $qty) {
                $singleDims = ['L' => $L, 'W' => $W, 'H' => $H];
            }
        }

        // Weight (kg), minimum 0.1 kg
        $kilograms = (float)round($totalWeightKg, 3);
        if ($kilograms <= 0) $kilograms = 0.1;

        // Dimensions (cm)
        if ($distinct === 1 && $totalQty === 1 && $singleDims) {
            $L = max(1, (int)ceil($singleDims['L']));
            $W = max(1, (int)ceil($singleDims['W']));
            $H = max(1, (int)ceil($singleDims['H']));
        } else {
            $Lbase = max(1.0, $maxLcm);
            $Wbase = max(1.0, $maxWcm);
            $L = (int)ceil($Lbase);
            $W = (int)ceil($Wbase);
            $Hcalc = ($Lbase * $Wbase) > 0 ? ($totalVolumeCm3 / ($Lbase * $Wbase)) : $maxHcm;
            $H = (int)ceil(max($Hcalc, $maxHcm, 1.0));
        }

        return ['weight_kg' => $kilograms, 'length' => $L, 'width' => $W, 'height' => $H];
    }

    public function submitOrder($order_id, $force_submit = null) {
        try {
            // prevent duplicate
            $ex = $this->getTipaxOrder($order_id);
            if ($ex && $ex['status'] === 'submitted') return $ex;

            $this->load->model('checkout/order');
            $order_info = $this->model_checkout_order->getOrder($order_id);
            if (!$order_info) {
                $this->logTipaxError($order_id, 'سفارش یافت نشد', 'ORDER_NOT_FOUND');
                return false;
            }

            if (empty($order_info['shipping_code']) || strpos($order_info['shipping_code'], 'tipax.') !== 0) {
                $this->logTipaxError($order_id, 'سفارش تیپاکس نیست', 'NOT_TIPAX_ORDER');
                return false;
            }

            // Check auto submit setting
            $auto_submit = $force_submit !== null ? $force_submit : (bool)$this->config->get('shipping_tipax_auto_submit');

            if (!$auto_submit) {
                // Save as pending order
                return $this->savePendingOrder($order_id, $order_info);
            }

            // Continue with actual submission...
            $this->load->library('tipax');
            $token = $this->tipax->getApiToken();
            if (!$token) {
                $this->logTipaxError($order_id, 'عدم دریافت توکن API', 'TOKEN_FAILED');
                return false;
            }

            // New static/default params per requirements
            $paymentType = (int)($this->config->get('shipping_tipax_payment_type') ?: 10);
            $packingId = 0;
            $contentId = 9;
            $pickupType  = 20;
            $distributionType = 10;
            $parcelBookId = 0;
            $isUnusual = false;
            $enableLabelPrivacy = true;

            $pkg = $this->computePackageFromOrder($order_id);
            // Derive pack type using same rule as quote: <=2kg mini(50), else bast(20)
            $weight_kg = (float)$pkg['weight_kg'];
            $length = (int)$pkg['length'];
            $width  = (int)$pkg['width'];
            $height = (int)$pkg['height'];
            $packType = ($weight_kg <= 2.0) ? 50 : 20;

            // Convert order total to Rial if needed
            $orderTotal = (float)$order_info['total'];
            $currentCurrency = $this->session->data['currency'];

            // Check if current currency is Toman and convert to Rial
            if ($currentCurrency === 'TOM' || $currentCurrency === 'IRT') {
                $packageValue = (int)round($orderTotal * 10); // Convert Toman to Rial
            } else {
                $packageValue = (int)round($orderTotal); // Assume it's already in Rial
            }

            $destCityId = $this->resolveTipaxCityIdByAddress(['city' => $order_info['shipping_city'], 'city_id' => $order_info['shipping_city_id'] ?? 0]);

            // Optional recipient overrides from Select Location Map module
            $recipient = [
                'lat'    => '',
                'lng'    => '',
                'plaque' => '',
                'unit'   => '',
                'phone'  => ''
            ];
            $use_location_map = (bool)$this->config->get('module_select_location_map_status');
            if ($use_location_map) {
                // Ensure table exists before reading
                $tbl = $this->db->query("SHOW TABLES LIKE '" . DB_PREFIX . "order_to_location_address'");
                if ($tbl && $tbl->num_rows) {
                    // Read directly from table
                    $qRows = $this->db->query("SELECT * FROM `" . DB_PREFIX . "order_to_location_address` WHERE order_id='" . (int)$order_id . "'");
                    $rows = $qRows ? $qRows->rows : [];
                    if (!empty($rows)) {
                        // Prefer the latest row that has useful data; fallback to last row
                        $recRow = null;
                        foreach ($rows as $row) {
                            if (!empty($row['lat']) || !empty($row['lng']) || !empty($row['phone_recipient']) || !empty($row['plaque']) || !empty($row['unit'])) {
                                $recRow = $row; // keep updating to end up with latest with data
                            }
                        }
                        if (!$recRow) {
                            $recRow = end($rows);
                        }
                        if ($recRow) {
                            $recipient['lat']    = isset($recRow['lat']) ? (string)$recRow['lat'] : '';
                            $recipient['lng']    = isset($recRow['lng']) ? (string)$recRow['lng'] : '';
                            $recipient['plaque'] = isset($recRow['plaque']) ? (string)$recRow['plaque'] : '';
                            $recipient['unit']   = isset($recRow['unit']) ? (string)$recRow['unit'] : '';
                            $recipient['phone']  = isset($recRow['phone_recipient']) ? (string)$recRow['phone_recipient'] : '';
                        }
                    }
                }
            }
            // Dynamic service selection: intracity (7) if origin city equals destination, else intercity (4)
            $originCityId = (int)($this->config->get('shipping_tipax_sender_city_id') ?: 0);
            $serviceId   = ($originCityId && (int)$destCityId === $originCityId) ? 7 : 4;

            $sender_mode = $this->config->get('shipping_tipax_sender_mode') ?: 'saved';
            $sender_originId = (int)$this->config->get('shipping_tipax_sender_selected_address_id');

            if ($sender_mode === 'saved' && $sender_originId) {
                $pkgItem = [
                    'originId' => (int)$sender_originId,
                    'destination' => [
                        'cityId' => (int)$destCityId,
                        'fullAddress' => trim($order_info['shipping_address_1'] . ' ' . $order_info['shipping_address_2']),
                        'postalCode'  => (string)$order_info['shipping_postcode'],
                        'beneficiary' => [
                            'fullName' => trim($order_info['shipping_firstname'] . ' ' . $order_info['shipping_lastname']),
                            'mobile'   => (string)($recipient['phone'] ?: $order_info['telephone']),
                            'phone'    => (string)($recipient['phone'] ?: $order_info['telephone']),
                        ]
                    ],
                    'weight'             => (float)$weight_kg,
                    'packageValue'       => (int)$packageValue,
                    'packingId'          => $packingId,
                    'packageContentId'   => $contentId,
                    'packType'           => $packType,
                    // 'description'        => 'OC-' . $order_id,
                    'serviceId'          => (int)$serviceId,
                    // 'enableLabelPrivacy' => $enableLabelPrivacy,
                    'paymentType'        => (int)$paymentType,
                    'pickupType'         => (int)$pickupType,
                    'distributionType'   => (int)$distributionType,
                    // 'cod'                => 0,
                    // 'cashAmount'         => 0,
                    // 'parcelBookId'       => $parcelBookId,
                    // 'isUnusual'          => $isUnusual,
                ];
                // Apply recipient location extras when provided
                if (!empty($recipient['lat'])) {
                    $pkgItem['destination']['latitude']  = (string)$recipient['lat'];
                }
                if (!empty($recipient['lng'])) {
                    $pkgItem['destination']['longitude'] = (string)$recipient['lng'];
                }
                if (!empty($recipient['plaque'])) {
                    $pkgItem['destination']['no']      = (string)$recipient['plaque'];
                }
                if (!empty($recipient['unit'])) {
                    $pkgItem['destination']['unit']    = (string)$recipient['unit'];
                }

                if ((int)$packType !== 50) {
                    $pkgItem['length'] = (int)$length;
                    $pkgItem['width']  = (int)$width;
                    $pkgItem['height'] = (int)$height;
                }
                $payload = [
                    'packages'           => [$pkgItem],
                    // 'traceCode'          => 'OC-' . $order_id,
                    // 'secondaryTraceCode' => (string)$order_id
                ];
                $data = $this->tipax->submitWithPredefinedOrigin($token, $payload);
            } else {
                $origin = [
                    'cityId' => (int)($this->config->get('shipping_tipax_sender_city_id') ?: 1),
                    'fullAddress' => (string)$this->config->get('shipping_tipax_sender_full_address'),
                    'postalCode'  => (string)$this->config->get('shipping_tipax_sender_postal_code'),
                    'latitude'    => (string)$this->config->get('shipping_tipax_sender_lat'),
                    'longitude'   => (string)$this->config->get('shipping_tipax_sender_lng'),
                    'no'          => (string)$this->config->get('shipping_tipax_sender_no'),
                    'unit'        => (string)$this->config->get('shipping_tipax_sender_unit'),
                    'floor'       => (string)$this->config->get('shipping_tipax_sender_floor'),
                    'beneficiary' => [
                        'fullName' => (string)$this->config->get('shipping_tipax_sender_name'),
                        'mobile'   => (string)$this->config->get('shipping_tipax_sender_mobile'),
                        'phone'    => (string)$this->config->get('shipping_tipax_sender_phone'),
                    ]
                ];
                $pkgItem = [
                    'origin' => $origin,
                    'destination' => [
                        'cityId' => (int)$destCityId,
                        'fullAddress' => trim($order_info['shipping_address_1'] . ' ' . $order_info['shipping_address_2']),
                        'postalCode'  => (string)$order_info['shipping_postcode'],
                        'beneficiary' => [
                            'fullName' => trim($order_info['shipping_firstname'] . ' ' . $order_info['shipping_lastname']),
                            'mobile'   => (string)($recipient['phone'] ?: $order_info['telephone']),
                            'phone'    => (string)($recipient['phone'] ?: $order_info['telephone']),
                        ]
                    ],
                    'weight'             => (float)$weight_kg,
                    'packageValue'       => (int)$packageValue,
                    'packingId'          => $packingId,
                    'packageContentId'   => $contentId,
                    'packType'           => $packType,
                    // 'description'        => 'OC-' . $order_id,
                    'serviceId'          => (int)$serviceId,
                    // 'enableLabelPrivacy' => $enableLabelPrivacy,
                    'paymentType'        => (int)$paymentType,
                    'pickupType'         => (int)$pickupType,
                    'distributionType'   => (int)$distributionType,
                    // 'cod'                => 0,
                    // 'cashAmount'         => 0,
                    // 'parcelBookId'       => $parcelBookId,
                    // 'isUnusual'          => $isUnusual,
                ];
                // Apply recipient location extras when provided
                if (!empty($recipient['lat'])) {
                    $pkgItem['destination']['latitude']  = (string)$recipient['lat'];
                }
                if (!empty($recipient['lng'])) {
                    $pkgItem['destination']['longitude'] = (string)$recipient['lng'];
                }
                if (!empty($recipient['plaque'])) {
                    $pkgItem['destination']['no']      = (string)$recipient['plaque'];
                }
                if (!empty($recipient['unit'])) {
                    $pkgItem['destination']['unit']    = (string)$recipient['unit'];
                }

                if ((int)$packType !== 50) {
                    $pkgItem['length'] = (int)$length;
                    $pkgItem['width']  = (int)$width;
                    $pkgItem['height'] = (int)$height;
                }
                $payload = [
                    'packages'           => [$pkgItem],
                    // 'traceCode'          => 'OC-' . $order_id,
                    // 'secondaryTraceCode' => (string)$order_id
                ];
                $data = $this->tipax->submitOrders($token, $payload);
            }

            if ($data !== false) {
                // Check if it's an error response
                if (is_array($data) && isset($data['success']) && $data['success'] === false) {
                    // This is an error response from the API
                    $error_message = $data['error_message'] ?? 'خطای ناشناخته';
                    $error_code = $data['error_code'] ?? 'UNKNOWN_ERROR';
                    $this->logTipaxError($order_id, $error_message, $error_code);
                    return false;
                }

                // موفقیت - پاک کردن خطاهای قبلی
                $this->clearTipaxError($order_id);

                $tracking_codes = '';
                $tracking_codes_with_titles = '';

                if (isset($data['trackingCodes']) && is_array($data['trackingCodes'])) {
                    $tracking_codes = implode(',', $data['trackingCodes']);
                }

                if (isset($data['trackingCodesWithTitles']) && is_array($data['trackingCodesWithTitles'])) {
                    $tracking_codes_with_titles = json_encode($data['trackingCodesWithTitles'], JSON_UNESCAPED_UNICODE);
                }

                $tipax_order_id = $data['orderId'] ?? '';

                $this->db->query("REPLACE INTO `" . DB_PREFIX . "tipax_orders` SET
                    order_id = '" . (int)$order_id . "',
                    tipax_order_id = '" . $this->db->escape($tipax_order_id) . "',
                    tracking_codes = '" . $this->db->escape($tracking_codes) . "',
                    tracking_codes_with_titles = '" . $this->db->escape($tracking_codes_with_titles) . "',
                    status = 'submitted',
                    service_id = '" . (int)$serviceId . "',
                    payment_type = '" . (int)$paymentType . "',
                    error_message = NULL,
                    error_code = NULL,
                    retry_count = 0,
                    last_error_at = NULL,
                    created_at = NOW()
                ");

                return $data;
            } else {
                $this->logTipaxError($order_id, 'خطا در ارسال به API تیپاکس', 'API_SUBMIT_FAILED');
                return false;
            }
        } catch (Exception $e) {
            $this->logTipaxError($order_id, 'خطای سیستمی: ' . $e->getMessage(), 'SYSTEM_ERROR');
            return false;
        }
    }

    private function savePendingOrder($order_id, $order_info) {
        // ServiceId is dynamic at submit time; store 0 as placeholder.
        $serviceId   = 0;
        $paymentType = (int)($this->config->get('shipping_tipax_payment_type') ?: 10);

        $this->db->query("REPLACE INTO `" . DB_PREFIX . "tipax_orders` SET
            order_id = '" . (int)$order_id . "',
            tipax_order_id = '',
            tracking_codes = '',
            tracking_codes_with_titles = '',
            status = 'pending',
            service_id = '" . (int)$serviceId . "',
            payment_type = '" . (int)$paymentType . "',
            created_at = NOW()
        ");

        return ['status' => 'pending', 'order_id' => $order_id];
    }

    // تابع لاگ خطاها
    private function logTipaxError($order_id, $error_message, $error_code = null) {
        $existing = $this->getTipaxOrder($order_id);
        $retry_count = $existing ? ((int)$existing['retry_count'] + 1) : 1;

        $this->db->query("REPLACE INTO `" . DB_PREFIX . "tipax_orders` SET
        order_id = '" . (int)$order_id . "',
        tipax_order_id = " . ($existing && $existing['tipax_order_id'] ? "'" . $this->db->escape($existing['tipax_order_id']) . "'" : "NULL") . ",
        tracking_codes = " . ($existing && $existing['tracking_codes'] ? "'" . $this->db->escape($existing['tracking_codes']) . "'" : "NULL") . ",
        tracking_codes_with_titles = " . ($existing && $existing['tracking_codes_with_titles'] ? "'" . $this->db->escape($existing['tracking_codes_with_titles']) . "'" : "NULL") . ",
        status = 'failed',
        service_id = " . ($existing && $existing['service_id'] ? (int)$existing['service_id'] : 0) . ",
        payment_type = " . ($existing && $existing['payment_type'] ? (int)$existing['payment_type'] : (int)($this->config->get('shipping_tipax_payment_type') ?: 10)) . ",
        error_message = '" . $this->db->escape($error_message) . "',
        error_code = " . ($error_code ? "'" . $this->db->escape($error_code) . "'" : "NULL") . ",
        retry_count = '" . (int)$retry_count . "',
        last_error_at = NOW(),
        created_at = " . ($existing && $existing['created_at'] ? "'" . $this->db->escape($existing['created_at']) . "'" : "NOW()") . "
        ");
    }

    // تابع پاک کردن خطاها
    private function clearTipaxError($order_id) {
        $this->db->query("UPDATE `" . DB_PREFIX . "tipax_orders` SET 
        error_message = NULL,
        error_code = NULL,
        retry_count = 0,
        last_error_at = NULL
        WHERE order_id = '" . (int)$order_id . "'
        ");
    }

    // Pricing quote for checkout
    public function getQuote($address) {
        $this->load->language('extension/shipping/tipax');
        if (!$this->config->get('shipping_tipax_status')) return [];

        // Do not expose Tipax on the cart page to prevent early price aggregation
        $route = isset($this->request->get['route']) ? (string)$this->request->get['route'] : '';
        $uri   = isset($this->request->server['REQUEST_URI']) ? (string)$this->request->server['REQUEST_URI'] : '';
        if (($route && strpos($route, 'checkout/cart') === 0) || (!$route && stripos($uri, 'checkout/cart') !== false)) {
            return [];
        }

        if (empty($address['city'])) return [];

        $this->load->library('tipax');
        $token = $this->tipax->getApiToken();
        if (!$token) return [];

        $pkg = $this->computePackageFromCart();
        $weightKg = (float)$pkg['weight_kg'];
        $length = (int)$pkg['length'];
        $width  = (int)$pkg['width'];
        $height = (int)$pkg['height'];
        $packType  = (int)$pkg['pack_type'];
        $paymentType = (int)($this->config->get('shipping_tipax_payment_type') ?: 10);
        // Static API params per request
        $packingId = 3;
        $contentId = 9;
        $pickupType  = 10;
        $distributionType = 10;
        $parcelBookId = 0;
        $isUnusual = false;

        // Check sender mode and prepare origin accordingly
        $sender_mode = $this->config->get('shipping_tipax_sender_mode') ?: 'saved';
        $sender_originId = (int)$this->config->get('shipping_tipax_sender_selected_address_id');

        $destCityId = $this->resolveTipaxCityIdByAddress($address);

        // Convert cart total to Rial if needed
        $cartTotal = (float)$this->cart->getTotal();
        $currentCurrency = $this->session->data['currency'];

        // Check if current currency is Toman and convert to Rial
        if ($currentCurrency === 'TOM' || $currentCurrency === 'IRT') {
            $packageValue = $cartTotal * 10; // Convert Toman to Rial
        } else {
            $packageValue = $cartTotal; // Assume it's already in Rial (RLS)
        }

        // Dynamic service selection: intracity (7) if origin city equals destination, else intercity (4)
        $originCityId = (int)($this->config->get('shipping_tipax_sender_city_id') ?: 0);
        $serviceId   = ($originCityId && (int)$destCityId === $originCityId) ? 7 : 4;

        if ($sender_mode === 'saved' && $sender_originId) {
            // Use WithOriginAddressId API
            $base = [
                'origin' => ['id' => (int)$sender_originId],
                'destination' => ['cityId' => (int)$destCityId],
                'weight' => (float)$weightKg,
                'packageValue' => (float)$packageValue,
                'packingId' => $packingId,
                'packageContentId' => $contentId,
                'parcelBookId' => $parcelBookId,
                'isUnusual' => $isUnusual,
                'packType' => $packType,
                'paymentType' => $paymentType,
                'pickupType' => $pickupType,
                'distributionType' => $distributionType,
                'serviceId' => (int)$serviceId
            ];
            if ((int)$packType !== 50) {
                $base['length'] = (int)$length;
                $base['width']  = (int)$width;
                $base['height'] = (int)$height;
            }
            $packageInputs = [$base];

            $arr = $this->tipax->pricingWithOriginAddressId($token, $packageInputs);
        } else {
            // Use regular pricing API with cityId
            $originCityId = (int)($this->config->get('shipping_tipax_sender_city_id') ?: 1);

            $base = [
                'origin' => ['cityId' => (int)$originCityId],
                'destination' => ['cityId' => (int)$destCityId],
                'weight' => (float)$weightKg,
                'packageValue' => (float)$packageValue,
                'packingId' => $packingId,
                'packageContentId' => $contentId,
                'packType' => $packType,
                'paymentType' => $paymentType,
                'pickupType' => $pickupType,
                'distributionType' => $distributionType,
                'serviceId' => (int)$serviceId
            ];
            if ((int)$packType !== 50) {
                $base['length'] = (int)$length;
                $base['width']  = (int)$width;
                $base['height'] = (int)$height;
            }
            $packageInputs = [$base];

            $arr = $this->tipax->pricing($token, $packageInputs);
        }

        if (!$arr) return [];

        $costInRial = $this->pickRate($arr, (int)$serviceId);
        if ($costInRial === null) return [];

        // Convert cost from Rial to display currency if needed
        $displayCost = $costInRial;
        if ($currentCurrency === 'TOM' || $currentCurrency === 'IRT') {
            $displayCost = $costInRial / 10; // Convert Rial to Toman for display
        }

        // Adjust cost/title based on payment type display rules
        $currency = $this->session->data['currency'];
        $title = $this->serviceTitle($serviceId);
        $costForOrder = (float)$displayCost; // default (paymentType=10)
        if ((int)$paymentType === 20) {
            // Post Pay: show cost but do not charge customer
            $title .= ' (پس‌کرایه، مبلغ ' . $this->currency->format((float)$displayCost, $currency) . ')';
            $costForOrder = 0.0;
        } else if ((int)$paymentType === 50) {
            // Wallet: free for customer, show note
            // $title .= ' (پرداخت از کیف‌پول مدیر، مبلغ ' . $this->currency->format((float)$displayCost, $currency) . ')';
            $title .= '';
            $costForOrder = 0.0;
        }

        $quote = [
            'code' => 'tipax.' . (int)$serviceId,
            'title' => $title,
            'cost' => (float)$costForOrder,
            'tax_class_id' => 0,
            'text' => (int)$paymentType === 50 ? $this->language->get('text_free') : $this->currency->format((float)$costForOrder ?: (float)$displayCost, $currency)
        ];

        return [
            'code' => 'tipax',
            'title' => $this->language->get('text_title'),
            'quote' => [(int)$serviceId => $quote],
            'sort_order' => $this->config->get('shipping_tipax_sort_order'),
            'error' => false
        ];
    }

    private function pickRate($arr, $serviceId) {
        if (!is_array($arr)) return null;
        foreach ($arr as $it) {
            foreach (['regularRate', 'regularPlusRate', 'expressRate', 'sameDayExpressRate', 'airExpressRate'] as $k) {
                if (isset($it[$k]) && is_array($it[$k]) && (int)$it[$k]['serviceId'] === (int)$serviceId) {
                    return (float)$it[$k]['finalPrice'];
                }
            }
        }
        return null;
    }

    private function serviceTitle($id) {
        $map = [
            1 => 'ارسال زمینی همان روز',
            2 => 'ارسال یک روزه',
            3 => 'ارسال دو روزه',
            6 => 'بین‌الملل',
            7 => 'تیپاکس (اکسپرس درون‌شهری)'
        ];
        return $map[$id] ?? 'تیپاکس';
    }

    // For cron sync in catalog side
    public function syncCities() {
        $this->load->library('tipax');
        $token = $this->tipax->getApiToken();
        if (!$token) return false;

        $cities = $this->tipax->citiesPlusState($token);
        if (!is_array($cities)) return false;

        $this->db->query("TRUNCATE TABLE `" . DB_PREFIX . "tipax_cities`");
        $inserted = 0;
        foreach ($cities as $city) {
            // Skip excluded cities
            $title = trim($city['title'] ?? '');
            if ($title === '') continue;
            $norm = $this->normalizeName($title);
            if ($norm === $this->normalizeName('RW city') || $norm === $this->normalizeName('شهر ستادی')) {
                continue;
            }
            $this->db->query("INSERT INTO `" . DB_PREFIX . "tipax_cities` SET
                tipax_city_id = '" . (int)$city['id'] . "',
                city_title = '" . $this->db->escape($city['title']) . "',
                state_id = '" . (int)($city['stateId'] ?? 0) . "',
                state_title = '" . $this->db->escape($city['stateTitle'] ?? '') . "',
                jet_id = '" . $this->db->escape($city['jetId'] ?? '') . "'
            ");
            $inserted++;
        }
        // Province mapping and cleanup, then auto city mapping and bulk-add
        $this->mapAllStatesFromTipaxCities();
        $this->cleanupStateMappingsStale();
        $this->cleanupCityMappingsStale();
        $this->autoMatchCities();
        $this->bulkAddCitiesToOpenCart();
        return $inserted;
    }

    private function normalizeName($s) {
        $s = mb_strtolower(trim($s ?? ''), 'UTF-8');
        $s = str_replace(['ي', 'ك', 'ة', 'ئ', 'أ', 'إ', 'ؤ'], ['ی', 'ک', 'ه', 'ی', 'ا', 'ا', 'و'], $s);
        $s = preg_replace('/[\s\-\_]+/u', '', $s);
        return $s;
    }

    // ===== Province helpers and automations for cron sync =====
    private function normalizeStateName($s) {
        $s = $this->normalizeName($s);
        $s = preg_replace('/^استان/u', '', $s);
        return $s;
    }

    private function getIranCountryId() {
        $q = $this->db->query("SELECT country_id FROM `" . DB_PREFIX . "country` WHERE UPPER(iso_code_2)='IR' LIMIT 1");
        if ($q->num_rows) return (int)$q->row['country_id'];
        $q = $this->db->query("SELECT country_id FROM `" . DB_PREFIX . "country` WHERE UPPER(iso_code_3)='IRN' LIMIT 1");
        if ($q->num_rows) return (int)$q->row['country_id'];
        $q = $this->db->query("SELECT country_id FROM `" . DB_PREFIX . "country` WHERE name LIKE '%ایران%' OR name LIKE '%Iran%' LIMIT 1");
        if ($q->num_rows) return (int)$q->row['country_id'];
        $q = $this->db->query("SELECT country_id FROM `" . DB_PREFIX . "country` WHERE status='1' LIMIT 1");
        return $q->num_rows ? (int)$q->row['country_id'] : 0;
    }

    private function similarPercent($a, $b) {
        $a = $this->normalizeStateName($a);
        $b = $this->normalizeStateName($b);
        if ($a === $b) return 100.0;
        $percent = 0.0;
        similar_text($a, $b, $percent);
        return (float)$percent;
    }

    private function ensureStateMappingTable() {
        $this->db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "tipax_state_mapping` (
            `oc_zone_id` int(11) NOT NULL,
            `tipax_state_id` int(11) NOT NULL,
            PRIMARY KEY (`oc_zone_id`),
            KEY `tipax_state_id` (`tipax_state_id`)
        ) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;");
    }

    private function saveStateMapping($oc_zone_id, $tipax_state_id) {
        if (!$oc_zone_id || !$tipax_state_id) return;
        $this->ensureStateMappingTable();
        $this->db->query("REPLACE INTO `" . DB_PREFIX . "tipax_state_mapping` SET oc_zone_id='" . (int)$oc_zone_id . "', tipax_state_id='" . (int)$tipax_state_id . "'");
    }

    private function findOrCreateZoneForState($state_title, $tipax_state_id = 0, $threshold = 90.0) {
        $state_title = trim((string)$state_title);
        if ($state_title === '') return 0;

        if ($tipax_state_id) {
            $m = $this->db->query("SELECT oc_zone_id FROM `" . DB_PREFIX . "tipax_state_mapping` WHERE tipax_state_id='" . (int)$tipax_state_id . "' LIMIT 1");
            if ($m->num_rows) return (int)$m->row['oc_zone_id'];
        }

        $country_id = $this->getIranCountryId();
        $zones = [];
        if ($country_id) {
            $zones = $this->db->query("SELECT zone_id, name, code FROM `" . DB_PREFIX . "zone` WHERE country_id='" . (int)$country_id . "'")->rows;
        } else {
            $zones = $this->db->query("SELECT zone_id, name, code FROM `" . DB_PREFIX . "zone`")->rows;
        }

        $target_norm = $this->normalizeStateName($state_title);
        foreach ($zones as $z) {
            if ($this->normalizeStateName($z['name']) === $target_norm) {
                if ($tipax_state_id) $this->saveStateMapping((int)$z['zone_id'], (int)$tipax_state_id);
                return (int)$z['zone_id'];
            }
        }

        $best_id = 0;
        $best_score = 0.0;
        foreach ($zones as $z) {
            $score = $this->similarPercent($z['name'], $state_title);
            if ($score > $best_score) {
                $best_score = $score;
                $best_id = (int)$z['zone_id'];
            }
        }
        if ($best_id && $best_score >= (float)$threshold) {
            if ($tipax_state_id) $this->saveStateMapping($best_id, (int)$tipax_state_id);
            return $best_id;
        }

        $code = strtoupper(substr(md5($state_title), 0, 6));
        $country_id = $country_id ?: 0;
        $this->db->query("INSERT INTO `" . DB_PREFIX . "zone` SET country_id='" . (int)$country_id . "', name='" . $this->db->escape($state_title) . "', code='" . $this->db->escape($code) . "', status='1'");
        $zone_id = (int)$this->db->getLastId();
        if ($zone_id && $tipax_state_id) $this->saveStateMapping($zone_id, (int)$tipax_state_id);
        return $zone_id;
    }

    private function mapAllStatesFromTipaxCities() {
        $rows = $this->db->query("SELECT DISTINCT state_id, state_title FROM `" . DB_PREFIX . "tipax_cities` WHERE state_id IS NOT NULL AND state_id>0")->rows;
        foreach ($rows as $r) {
            $sid = (int)$r['state_id'];
            $title = (string)$r['state_title'];
            if ($sid && $title !== '') $this->findOrCreateZoneForState($title, $sid, 90.0);
        }
    }

    private function cleanupCityMappingsStale() {
        $this->db->query("DELETE m FROM `" . DB_PREFIX . "tipax_city_mapping` m LEFT JOIN `" . DB_PREFIX . "tipax_cities` tc ON (tc.tipax_city_id = m.tipax_city_id) WHERE tc.tipax_city_id IS NULL");
    }

    private function cleanupStateMappingsStale() {
        $this->ensureStateMappingTable();
        $this->db->query("DELETE sm FROM `" . DB_PREFIX . "tipax_state_mapping` sm LEFT JOIN (SELECT DISTINCT state_id FROM `" . DB_PREFIX . "tipax_cities`) t ON (t.state_id = sm.tipax_state_id) WHERE t.state_id IS NULL");
    }

    private function autoMatchCities() {
        $ocRows = $this->db->query("SELECT city_id, name FROM `" . DB_PREFIX . "city`")->rows;
        $ocIndex = [];
        foreach ($ocRows as $r) {
            $ocIndex[$this->normalizeName($r['name'])] = (int)$r['city_id'];
        }
        $tpRows = $this->db->query("SELECT tipax_city_id, city_title FROM `" . DB_PREFIX . "tipax_cities`")->rows;
        foreach ($tpRows as $t) {
            $tp_id = (int)$t['tipax_city_id'];
            $key = $this->normalizeName($t['city_title']);
            if (isset($ocIndex[$key])) {
                $oc_id = $ocIndex[$key];
                $this->db->query("REPLACE INTO `" . DB_PREFIX . "tipax_city_mapping` SET oc_city_id='" . (int)$oc_id . "', tipax_city_id='" . (int)$tp_id . "'");
            }
        }
    }

    private function addCityToOpenCart($tipax_city_id, $city_name, $state_name) {
        $tp_state_id = 0;
        $q = $this->db->query("SELECT state_id FROM `" . DB_PREFIX . "tipax_cities` WHERE tipax_city_id='" . (int)$tipax_city_id . "' LIMIT 1");
        if ($q->num_rows) $tp_state_id = (int)$q->row['state_id'];

        $zone_id = $this->findOrCreateZoneForState($state_name, $tp_state_id);
        if (!$zone_id) return ['success' => false, 'message' => 'عدم امکان ایجاد/یافتن استان برای "' . $state_name . '".'];

        $existing_city = $this->db->query("SELECT city_id FROM `" . DB_PREFIX . "city` WHERE name='" . $this->db->escape($city_name) . "' AND zone_id='" . (int)$zone_id . "'");
        if ($existing_city->num_rows) {
            $this->db->query("REPLACE INTO `" . DB_PREFIX . "tipax_city_mapping` SET oc_city_id='" . (int)$existing_city->row['city_id'] . "', tipax_city_id='" . (int)$tipax_city_id . "'");
            return ['success' => true, 'message' => 'شهر قبلاً وجود داشت و تطابق ایجاد شد.'];
        }

        $this->db->query("INSERT INTO `" . DB_PREFIX . "city` SET name='" . $this->db->escape($city_name) . "', zone_id='" . (int)$zone_id . "', status='1'");
        $new_city_id = (int)$this->db->getLastId();
        $this->db->query("REPLACE INTO `" . DB_PREFIX . "tipax_city_mapping` SET oc_city_id='" . (int)$new_city_id . "', tipax_city_id='" . (int)$tipax_city_id . "'");
        return ['success' => true, 'message' => 'شهر "' . $city_name . '" با موفقیت اضافه شد و تطابق ایجاد شد.'];
    }

    private function bulkAddCitiesToOpenCart() {
        $unmatched = $this->db->query("SELECT tc.tipax_city_id, tc.city_title, tc.state_title FROM `" . DB_PREFIX . "tipax_cities` tc LEFT JOIN `" . DB_PREFIX . "tipax_city_mapping` m ON (tc.tipax_city_id = m.tipax_city_id) WHERE m.tipax_city_id IS NULL ORDER BY tc.state_title, tc.city_title")->rows;
        foreach ($unmatched as $city) {
            $this->addCityToOpenCart((int)$city['tipax_city_id'], $city['city_title'], $city['state_title']);
        }
    }
}
