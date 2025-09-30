<?php
class ModelExtensionShippingTipax extends Model {

    public function install() {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "tipax_cities` (
                `tipax_city_id` int(11) NOT NULL,
                `city_title` varchar(128) NOT NULL,
                `state_id` int(11) DEFAULT NULL,
                `state_title` varchar(128) DEFAULT NULL,
                `jet_id` varchar(50) DEFAULT NULL,
                PRIMARY KEY (`tipax_city_id`)
            ) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;
        ");

        $this->db->query("
            CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "tipax_orders` (
                `order_id` int(11) NOT NULL,
                `tipax_order_id` varchar(64) DEFAULT NULL,
                `tracking_codes` text,
                `tracking_codes_with_titles` text,
                `status` varchar(20) DEFAULT 'pending',
                `service_id` int(11) DEFAULT NULL,
                `payment_type` int(11) DEFAULT NULL,
                `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
                `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                `error_message` text,
                `error_code` varchar(50) DEFAULT NULL,
                `retry_count` int(11) DEFAULT 0,
                `last_error_at` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`order_id`)
            ) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;
        ");

        $this->db->query("
            CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "tipax_addresses` (
                `address_id` int(11) NOT NULL AUTO_INCREMENT,
                `tipax_address_id` bigint(20) NOT NULL,
                `full_name` varchar(150) DEFAULT NULL,
                `mobile` varchar(30) DEFAULT NULL,
                `phone` varchar(30) DEFAULT NULL,
                `full_address` text,
                `postal_code` varchar(20) DEFAULT NULL,
                `city_id` int(11) DEFAULT NULL,
                `latitude` double DEFAULT NULL,
                `longitude` double DEFAULT NULL,
                `is_active` tinyint(1) DEFAULT 1,
                `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`address_id`),
                KEY `tipax_address_id` (`tipax_address_id`)
            ) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;
        ");

        $this->db->query("
            CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "tipax_city_mapping` (
                `oc_city_id` int(11) NOT NULL,
                `tipax_city_id` int(11) NOT NULL,
                PRIMARY KEY (`oc_city_id`),
                KEY `tipax_city_id` (`tipax_city_id`)
            ) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;
        ");

        // State (province) mapping table
        $this->db->query("
            CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "tipax_state_mapping` (
                `oc_zone_id` int(11) NOT NULL,
                `tipax_state_id` int(11) NOT NULL,
                PRIMARY KEY (`oc_zone_id`),
                KEY `tipax_state_id` (`tipax_state_id`)
            ) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;
        ");
    }

    public function uninstall() {
        $this->db->query("DROP TABLE IF EXISTS `" . DB_PREFIX . "tipax_cities`");
        $this->db->query("DROP TABLE IF EXISTS `" . DB_PREFIX . "tipax_orders`");
        $this->db->query("DROP TABLE IF EXISTS `" . DB_PREFIX . "tipax_addresses`");
        $this->db->query("DROP TABLE IF EXISTS `" . DB_PREFIX . "tipax_city_mapping`");
        $this->db->query("DROP TABLE IF EXISTS `" . DB_PREFIX . "tipax_state_mapping`");
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

    // =================== Cities ===================
    public function syncCities() {
        $this->load->library('tipax');
        $token = $this->tipax->getApiToken();
        if (!$token) return false;

        $cities = $this->tipax->citiesPlusState($token);
        if (!is_array($cities)) return false;

        $this->db->query("TRUNCATE TABLE `" . DB_PREFIX . "tipax_cities`");
        $inserted = 0;
        foreach ($cities as $city) {
            // Skip excluded cities from being saved or shown
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
        // Map all provinces (states) based on fresh tipax_cities
        $this->mapAllStatesFromTipaxCities();

        // Cleanup state mappings pointing to removed/unknown tipax states
        $this->cleanupStateMappingsStale();

        // Cleanup mappings pointing to removed/unknown tipax cities
        $this->cleanupCityMappingsStale();

        // Auto-match cities by name
        $this->autoMatchCities();

        // Auto-add unmatched cities into OC and map them
        $this->bulkAddCitiesToOpenCart();

        return $inserted;
    }

    public function getCitiesCount() {
        $q = $this->db->query("SELECT COUNT(*) AS total FROM `" . DB_PREFIX . "tipax_cities`");
        return (int)$q->row['total'];
    }

    public function getTipaxCities() {
        $q = $this->db->query("SELECT tipax_city_id, city_title FROM `" . DB_PREFIX . "tipax_cities` ORDER BY city_title");
        return $q->rows;
    }

    public function getOcCities($filter = '') {
        $sql = "SELECT city_id, name FROM `" . DB_PREFIX . "city`";
        if ($filter) {
            $sql .= " WHERE name LIKE '%" . $this->db->escape($filter) . "%'";
        }
        $sql .= " ORDER BY name";
        $q = $this->db->query($sql);
        return $q->rows;
    }

    public function getCityMappings() {
        $q = $this->db->query("
            SELECT c.city_id, c.name as oc_city_name, m.tipax_city_id, tc.city_title
            FROM `" . DB_PREFIX . "city` c
            LEFT JOIN `" . DB_PREFIX . "tipax_city_mapping` m ON (c.city_id = m.oc_city_id)
            LEFT JOIN `" . DB_PREFIX . "tipax_cities` tc ON (m.tipax_city_id = tc.tipax_city_id)
            ORDER BY c.name
        ");
        return $q->rows;
    }

    public function saveCityMapping($oc_city_id, $tipax_city_id) {
        $this->db->query("REPLACE INTO `" . DB_PREFIX . "tipax_city_mapping` SET oc_city_id='" . (int)$oc_city_id . "', tipax_city_id='" . (int)$tipax_city_id . "'");
        return true;
    }

    public function autoMatchCities() {
        $ocRows = $this->db->query("SELECT city_id, name FROM `" . DB_PREFIX . "city`")->rows;
        $ocIndex = [];
        foreach ($ocRows as $r) {
            $ocIndex[$this->normalizeName($r['name'])] = (int)$r['city_id'];
        }

        $tpRows = $this->db->query("SELECT tipax_city_id, city_title FROM `" . DB_PREFIX . "tipax_cities`")->rows;
        $mapped = 0;
        $total = count($tpRows);

        foreach ($tpRows as $t) {
            $tp_id = (int)$t['tipax_city_id'];
            $key = $this->normalizeName($t['city_title']);
            if (isset($ocIndex[$key])) {
                $oc_id = $ocIndex[$key];
                $this->saveCityMapping($oc_id, $tp_id);
                $mapped++;
            }
        }
        return ['matched' => $mapped, 'total' => $total];
    }

    private function normalizeName($s) {
        $s = mb_strtolower(trim($s ?? ''), 'UTF-8');
        $s = str_replace(['ي', 'ك', 'ة', 'ئ', 'أ', 'إ', 'ؤ'], ['ی', 'ک', 'ه', 'ی', 'ا', 'ا', 'و'], $s);
        $s = preg_replace('/[\s\-\_]+/u', '', $s);
        return $s;
    }

    public function getCityMappingCounts($q = '') {
        $w = $q ? " WHERE tc.city_title LIKE '%" . $this->db->escape($q) . "%'" : '';
        $all = $this->db->query("SELECT COUNT(*) as total FROM `" . DB_PREFIX . "tipax_cities` tc{$w}")->row['total'];
        $matched = $this->db->query("
            SELECT COUNT(DISTINCT tc.tipax_city_id) as total
            FROM `" . DB_PREFIX . "tipax_cities` tc
            INNER JOIN `" . DB_PREFIX . "tipax_city_mapping` m ON (tc.tipax_city_id = m.tipax_city_id)
            " . ($q ? " WHERE tc.city_title LIKE '%" . $this->db->escape($q) . "%'" : '') . "
        ")->row['total'];
        $unmatched = (int)$all - (int)$matched;
        return ['all' => (int)$all, 'matched' => (int)$matched, 'unmatched' => (int)$unmatched];
    }

    public function getCityMappingsPaginated($filter = 'unmatched', $q = '', $page = 1, $limit = 25) {
        $offset = ($page - 1) * $limit;
        if ($filter === 'matched') {
            $sql = "
                SELECT SQL_CALC_FOUND_ROWS
                    tc.tipax_city_id,
                    tc.city_title,
                    tc.state_title,
                    MIN(oc.city_id) as city_id,
                    MIN(oc.name) as oc_city_name
                FROM `" . DB_PREFIX . "tipax_cities` tc
                INNER JOIN `" . DB_PREFIX . "tipax_city_mapping` m ON (tc.tipax_city_id = m.tipax_city_id)
                LEFT JOIN `" . DB_PREFIX . "city` oc ON (oc.city_id = m.oc_city_id)
                " . ($q ? " WHERE tc.city_title LIKE '%" . $this->db->escape($q) . "%'" : '') . "
                GROUP BY tc.tipax_city_id
                ORDER BY tc.city_title
                LIMIT " . (int)$offset . "," . (int)$limit;
        } elseif ($filter === 'unmatched') {
            $sql = "
                SELECT SQL_CALC_FOUND_ROWS
                    tc.tipax_city_id,
                    tc.city_title,
                    tc.state_title,
                    NULL as city_id,
                    NULL as oc_city_name
                FROM `" . DB_PREFIX . "tipax_cities` tc
                LEFT JOIN `" . DB_PREFIX . "tipax_city_mapping` m ON (tc.tipax_city_id = m.tipax_city_id)
                WHERE m.tipax_city_id IS NULL " . ($q ? " AND tc.city_title LIKE '%" . $this->db->escape($q) . "%'" : '') . "
                ORDER BY tc.city_title
                LIMIT " . (int)$offset . "," . (int)$limit;
        } else {
            $sql = "
                SELECT SQL_CALC_FOUND_ROWS
                    tc.tipax_city_id,
                    tc.city_title,
                    tc.state_title,
                    MIN(oc.city_id) as city_id,
                    MIN(oc.name) as oc_city_name
                FROM `" . DB_PREFIX . "tipax_cities` tc
                LEFT JOIN `" . DB_PREFIX . "tipax_city_mapping` m ON (tc.tipax_city_id = m.tipax_city_id)
                LEFT JOIN `" . DB_PREFIX . "city` oc ON (oc.city_id = m.oc_city_id)
                " . ($q ? " WHERE tc.city_title LIKE '%" . $this->db->escape($q) . "%'" : '') . "
                GROUP BY tc.tipax_city_id
                ORDER BY tc.city_title
                LIMIT " . (int)$offset . "," . (int)$limit;
        }
        $rows = $this->db->query($sql)->rows;
        $total = $this->db->query("SELECT FOUND_ROWS() as t")->row['t'];
        return ['rows' => $rows, 'total' => $total];
    }

    // =================== States (Provinces) Mapping ===================
    public function getStateMappingCounts($q = '') {
        $w = $q ? " WHERE tc.state_title LIKE '%" . $this->db->escape($q) . "%'" : '';
        $all = $this->db->query("SELECT COUNT(*) AS total FROM (SELECT DISTINCT state_id FROM `" . DB_PREFIX . "tipax_cities` tc WHERE state_id IS NOT NULL AND state_id>0" . ($w ? substr($w, 0) : '') . ") x")->row['total'];
        $matched = $this->db->query("SELECT COUNT(*) AS total FROM (SELECT DISTINCT tc.state_id FROM `" . DB_PREFIX . "tipax_cities` tc INNER JOIN `" . DB_PREFIX . "tipax_state_mapping` sm ON (tc.state_id = sm.tipax_state_id) " . ($q ? " WHERE tc.state_title LIKE '%" . $this->db->escape($q) . "%'" : '') . ") x")->row['total'];
        $unmatched = (int)$all - (int)$matched;
        return ['all' => (int)$all, 'matched' => (int)$matched, 'unmatched' => (int)$unmatched];
    }

    public function getStateMappingsPaginated($filter = 'unmatched', $q = '', $page = 1, $limit = 25) {
        $offset = ($page - 1) * $limit;
        if ($filter === 'matched') {
            $sql = "SELECT SQL_CALC_FOUND_ROWS tc.state_id AS tipax_state_id, MIN(tc.state_title) AS state_title, MIN(z.zone_id) AS zone_id, MIN(z.name) AS oc_zone_name FROM `" . DB_PREFIX . "tipax_cities` tc INNER JOIN `" . DB_PREFIX . "tipax_state_mapping` sm ON (tc.state_id = sm.tipax_state_id) LEFT JOIN `" . DB_PREFIX . "zone` z ON (z.zone_id = sm.oc_zone_id) " . ($q ? " WHERE tc.state_title LIKE '%" . $this->db->escape($q) . "%'" : '') . " GROUP BY tc.state_id ORDER BY state_title LIMIT " . (int)$offset . "," . (int)$limit;
        } elseif ($filter === 'unmatched') {
            $sql = "SELECT SQL_CALC_FOUND_ROWS tc.state_id AS tipax_state_id, MIN(tc.state_title) AS state_title, NULL AS zone_id, NULL AS oc_zone_name FROM `" . DB_PREFIX . "tipax_cities` tc LEFT JOIN `" . DB_PREFIX . "tipax_state_mapping` sm ON (tc.state_id = sm.tipax_state_id) WHERE tc.state_id IS NOT NULL AND tc.state_id>0 AND sm.tipax_state_id IS NULL " . ($q ? " AND tc.state_title LIKE '%" . $this->db->escape($q) . "%'" : '') . " GROUP BY tc.state_id ORDER BY state_title LIMIT " . (int)$offset . "," . (int)$limit;
        } else {
            $sql = "SELECT SQL_CALC_FOUND_ROWS tc.state_id AS tipax_state_id, MIN(tc.state_title) AS state_title, MIN(z.zone_id) AS zone_id, MIN(z.name) AS oc_zone_name FROM `" . DB_PREFIX . "tipax_cities` tc LEFT JOIN `" . DB_PREFIX . "tipax_state_mapping` sm ON (tc.state_id = sm.tipax_state_id) LEFT JOIN `" . DB_PREFIX . "zone` z ON (z.zone_id = sm.oc_zone_id) " . ($q ? " WHERE tc.state_title LIKE '%" . $this->db->escape($q) . "%'" : '') . " GROUP BY tc.state_id ORDER BY state_title LIMIT " . (int)$offset . "," . (int)$limit;
        }
        $rows = $this->db->query($sql)->rows;
        $total = $this->db->query("SELECT FOUND_ROWS() as t")->row['t'];
        return ['rows' => $rows, 'total' => $total];
    }

    public function getOcZones($filter = '') {
        $countryId = $this->getIranCountryId();
        $sql = "SELECT zone_id, name FROM `" . DB_PREFIX . "zone`";
        $conds = [];
        if ($countryId) $conds[] = "country_id='" . (int)$countryId . "'";
        if ($filter !== '') $conds[] = "name LIKE '%" . $this->db->escape($filter) . "%'";
        if ($conds) $sql .= " WHERE " . implode(' AND ', $conds);
        $sql .= " ORDER BY name";
        return $this->db->query($sql)->rows;
    }

    public function saveStateMappingAdmin($oc_zone_id, $tipax_state_id) {
        $this->ensureStateMappingTable();
        $this->db->query("REPLACE INTO `" . DB_PREFIX . "tipax_state_mapping` SET oc_zone_id='" . (int)$oc_zone_id . "', tipax_state_id='" . (int)$tipax_state_id . "'");
        return true;
    }

    public function autoMatchStates($threshold = 90.0) {
        // Build OC zones index by normalized name
        $zones = $this->getOcZones('');
        $idx = [];
        foreach ($zones as $z) {
            $idx[$this->normalizeStateName($z['name'])] = (int)$z['zone_id'];
        }
        $rows = $this->db->query("SELECT DISTINCT state_id, state_title FROM `" . DB_PREFIX . "tipax_cities` WHERE state_id IS NOT NULL AND state_id>0")->rows;
        $matched = 0;
        $total = count($rows);
        foreach ($rows as $r) {
            $sid = (int)$r['state_id'];
            $title = (string)$r['state_title'];
            $norm = $this->normalizeStateName($title);
            $oc_id = $idx[$norm] ?? 0;
            if (!$oc_id) {
                // fuzzy
                $best_id = 0;
                $best_score = 0.0;
                foreach ($zones as $z) {
                    $score = $this->similarPercent($z['name'], $title);
                    if ($score > $best_score) {
                        $best_score = $score;
                        $best_id = (int)$z['zone_id'];
                    }
                }
                if ($best_id && $best_score >= (float)$threshold) $oc_id = $best_id;
            }
            if ($oc_id) {
                $this->saveStateMappingAdmin($oc_id, $sid);
                $matched++;
            }
        }
        return ['matched' => $matched, 'total' => $total];
    }

    public function addCityToOpenCart($tipax_city_id, $city_name, $state_name) {
        // Try to resolve Tipax state_id from tipax_city_id (for mapping)
        $tp_state_id = 0;
        $q = $this->db->query("SELECT state_id FROM `" . DB_PREFIX . "tipax_cities` WHERE tipax_city_id='" . (int)$tipax_city_id . "' LIMIT 1");
        if ($q->num_rows) {
            $tp_state_id = (int)$q->row['state_id'];
        }

        // Find or create the OC zone (province) with fuzzy matching; map it as well
        $zone_id = $this->findOrCreateZoneForState($state_name, $tp_state_id);
        if (!$zone_id) {
            return ['success' => false, 'message' => 'عدم امکان ایجاد/یافتن استان برای "' . $state_name . '".'];
        }

        // بررسی عدم وجود شهر در جدول city
        $existing_city = $this->db->query("SELECT city_id FROM `" . DB_PREFIX . "city` WHERE name = '" . $this->db->escape($city_name) . "' AND zone_id = '" . (int)$zone_id . "'");

        if ($existing_city->num_rows) {
            // اگر شهر وجود دارد، فقط mapping ایجاد کن
            $this->saveCityMapping($existing_city->row['city_id'], $tipax_city_id);
            return ['success' => true, 'message' => 'شهر قبلاً وجود داشت و تطابق ایجاد شد.'];
        }

        // اضافه کردن شهر جدید
        $this->db->query("INSERT INTO `" . DB_PREFIX . "city` SET 
            name = '" . $this->db->escape($city_name) . "',
            zone_id = '" . (int)$zone_id . "',
            status = '1'
        ");

        $new_city_id = $this->db->getLastId();

        // ایجاد mapping
        $this->saveCityMapping($new_city_id, $tipax_city_id);

        return ['success' => true, 'message' => 'شهر "' . $city_name . '" با موفقیت اضافه شد و تطابق ایجاد شد.'];
    }

    public function bulkAddCitiesToOpenCart() {
        try {
            // دریافت شهرهای نامطابق
            $unmatched_cities = $this->db->query("
            SELECT tc.tipax_city_id, tc.city_title, tc.state_title
            FROM `" . DB_PREFIX . "tipax_cities` tc
            LEFT JOIN `" . DB_PREFIX . "tipax_city_mapping` m ON (tc.tipax_city_id = m.tipax_city_id)
            WHERE m.tipax_city_id IS NULL
            ORDER BY tc.state_title, tc.city_title
        ")->rows;

            if (empty($unmatched_cities)) {
                return ['success' => true, 'message' => 'هیچ شهر نامطابقی یافت نشد.'];
            }

            $total_count = count($unmatched_cities);
            $success_count = 0;
            $failed_count = 0;
            $failed_cities = [];

            foreach ($unmatched_cities as $city) {
                try {
                    $result = $this->addCityToOpenCart(
                        (int)$city['tipax_city_id'],
                        $city['city_title'],
                        $city['state_title']
                    );

                    if ($result['success']) {
                        $success_count++;
                    } else {
                        $failed_count++;
                        $failed_cities[] = [
                            'name' => $city['city_title'] . ' (' . $city['state_title'] . ')',
                            'error' => $result['message']
                        ];
                    }

                    // کمی استراحت برای جلوگیری از timeout
                    if (($success_count + $failed_count) % 50 == 0) {
                        usleep(100000); // 0.1 second
                    }
                } catch (Exception $e) {
                    $failed_count++;
                    $failed_cities[] = [
                        'name' => $city['city_title'] . ' (' . $city['state_title'] . ')',
                        'error' => 'خطای سیستم: ' . $e->getMessage()
                    ];
                }
            }

            $message = sprintf(
                'افزودن گروهی شهرها تکمیل شد. موفق: %d، ناموفق: %d، کل: %d',
                $success_count,
                $failed_count,
                $total_count
            );

            return [
                'success' => true,
                'message' => $message,
                'details' => [
                    'success_count' => $success_count,
                    'failed_count' => $failed_count,
                    'total_count' => $total_count,
                    'failed_cities' => $failed_cities
                ]
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'خطای کلی در افزودن گروهی: ' . $e->getMessage()
            ];
        }
    }

    // =================== State (Province) helpers ===================
    private function normalizeStateName($s) {
        $s = $this->normalizeName($s);
        // remove prefix 'استان' if present
        $s = preg_replace('/^استان/u', '', $s);
        return $s;
    }

    private function getIranCountryId() {
        // Prefer ISO code 'IR'
        $q = $this->db->query("SELECT country_id FROM `" . DB_PREFIX . "country` WHERE UPPER(iso_code_2)='IR' LIMIT 1");
        if ($q->num_rows) return (int)$q->row['country_id'];
        $q = $this->db->query("SELECT country_id FROM `" . DB_PREFIX . "country` WHERE UPPER(iso_code_3)='IRN' LIMIT 1");
        if ($q->num_rows) return (int)$q->row['country_id'];
        $q = $this->db->query("SELECT country_id FROM `" . DB_PREFIX . "country` WHERE name LIKE '%ایران%' OR name LIKE '%Iran%' LIMIT 1");
        if ($q->num_rows) return (int)$q->row['country_id'];
        // Fallback: first active country
        $q = $this->db->query("SELECT country_id FROM `" . DB_PREFIX . "country` WHERE status='1' LIMIT 1");
        return $q->num_rows ? (int)$q->row['country_id'] : 0;
    }

    private function similarPercent($a, $b) {
        $a = $this->normalizeStateName($a);
        $b = $this->normalizeStateName($b);
        if ($a === $b) return 100.0;
        $percent = 0.0;
        // similar_text works on bytes but is acceptable for normalized Persian strings
        similar_text($a, $b, $percent);
        return (float)$percent;
    }

    private function findOrCreateZoneForState($state_title, $tipax_state_id = 0, $threshold = 90.0) {
        $state_title = trim((string)$state_title);
        if ($state_title === '') return 0;

        // If mapping exists, return mapped oc_zone_id
        if ($tipax_state_id) {
            $m = $this->db->query("SELECT oc_zone_id FROM `" . DB_PREFIX . "tipax_state_mapping` WHERE tipax_state_id='" . (int)$tipax_state_id . "' LIMIT 1");
            if ($m->num_rows) return (int)$m->row['oc_zone_id'];
        }

        $country_id = $this->getIranCountryId();
        // Fetch candidate zones (provinces)
        $zones = [];
        if ($country_id) {
            $zones = $this->db->query("SELECT zone_id, name, code FROM `" . DB_PREFIX . "zone` WHERE country_id='" . (int)$country_id . "'")->rows;
        } else {
            $zones = $this->db->query("SELECT zone_id, name, code FROM `" . DB_PREFIX . "zone`")->rows;
        }

        // Try exact normalized match first
        $target_norm = $this->normalizeStateName($state_title);
        foreach ($zones as $z) {
            if ($this->normalizeStateName($z['name']) === $target_norm) {
                if ($tipax_state_id) {
                    $this->saveStateMapping((int)$z['zone_id'], (int)$tipax_state_id);
                }
                return (int)$z['zone_id'];
            }
        }

        // Fuzzy match by similarity percent
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
            if ($tipax_state_id) {
                $this->saveStateMapping($best_id, (int)$tipax_state_id);
            }
            return $best_id;
        }

        // Create new zone (province) if not found
        $code = strtoupper(substr(md5($state_title), 0, 6));
        $country_id = $country_id ?: 0;
        $this->db->query("INSERT INTO `" . DB_PREFIX . "zone` SET 
            country_id='" . (int)$country_id . "',
            name='" . $this->db->escape($state_title) . "',
            code='" . $this->db->escape($code) . "',
            status='1'
        ");
        $zone_id = (int)$this->db->getLastId();
        if ($zone_id && $tipax_state_id) {
            $this->saveStateMapping($zone_id, (int)$tipax_state_id);
        }
        return $zone_id;
    }

    private function saveStateMapping($oc_zone_id, $tipax_state_id) {
        if (!$oc_zone_id || !$tipax_state_id) return;
        $this->ensureStateMappingTable();
        $this->db->query("REPLACE INTO `" . DB_PREFIX . "tipax_state_mapping` SET oc_zone_id='" . (int)$oc_zone_id . "', tipax_state_id='" . (int)$tipax_state_id . "'");
    }

    private function ensureStateMappingTable() {
        // Create table if not exists (for upgrades without reinstall)
        $this->db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "tipax_state_mapping` (
            `oc_zone_id` int(11) NOT NULL,
            `tipax_state_id` int(11) NOT NULL,
            PRIMARY KEY (`oc_zone_id`),
            KEY `tipax_state_id` (`tipax_state_id`)
        ) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;");
    }

    private function mapAllStatesFromTipaxCities() {
        $rows = $this->db->query("SELECT DISTINCT state_id, state_title FROM `" . DB_PREFIX . "tipax_cities` WHERE state_id IS NOT NULL AND state_id>0")->rows;
        foreach ($rows as $r) {
            $sid = (int)$r['state_id'];
            $title = (string)$r['state_title'];
            if ($sid && $title !== '') {
                $this->findOrCreateZoneForState($title, $sid, 90.0);
            }
        }
    }

    private function cleanupCityMappingsStale() {
        // Remove city mappings that point to tipax cities not present anymore
        $this->db->query("DELETE m FROM `" . DB_PREFIX . "tipax_city_mapping` m LEFT JOIN `" . DB_PREFIX . "tipax_cities` tc ON (tc.tipax_city_id = m.tipax_city_id) WHERE tc.tipax_city_id IS NULL");
    }

    private function cleanupStateMappingsStale() {
        $this->ensureStateMappingTable();
        $this->db->query("DELETE sm FROM `" . DB_PREFIX . "tipax_state_mapping` sm LEFT JOIN (SELECT DISTINCT state_id FROM `" . DB_PREFIX . "tipax_cities`) t ON (t.state_id = sm.tipax_state_id) WHERE t.state_id IS NULL");
    }

    // =================== Wallet ===================
    public function getWalletBalance() {
        $this->load->library('tipax');
        $token = $this->tipax->getApiToken();
        if (!$token) return false;
        return $this->tipax->walletBalance($token);
    }

    public function rechargeWallet($amount, $callback_url) {
        $this->load->library('tipax');
        $token = $this->tipax->getApiToken();
        if (!$token) return false;
        return $this->tipax->rechargeWallet($token, $amount, $callback_url);
    }

    // =================== Addresses ===================
    public function loadAddressBook() {
        $this->load->library('tipax');
        $token = $this->tipax->getApiToken();
        if (!$token) return false;

        $list = $this->tipax->addressesBook($token);
        if (!is_array($list)) return false;

        $this->db->query("TRUNCATE TABLE `" . DB_PREFIX . "tipax_addresses`");
        foreach ($list as $row) {
            $addr = $row['address'] ?? [];
            $addrId = isset($addr['id']) ? (int)$addr['id'] : 0;
            if (!$addrId) continue;

            $this->db->query("INSERT INTO `" . DB_PREFIX . "tipax_addresses` SET
                tipax_address_id = '" . (int)$addrId . "',
                full_name = '" . $this->db->escape($row['title'] ?? '') . "',
                mobile = '',
                phone = '',
                full_address = '" . $this->db->escape($addr['fullAddress'] ?? '') . "',
                postal_code = '" . $this->db->escape($addr['postalCode'] ?? '') . "',
                city_id = '" . (int)($addr['cityId'] ?? 0) . "',
                latitude = " . (isset($addr['latitude']) ? (float)$addr['latitude'] : 'NULL') . ",
                longitude = " . (isset($addr['longitude']) ? (float)$addr['longitude'] : 'NULL') . "
            ");
        }
        return true;
    }

    public function getSavedAddresses() {
        $q = $this->db->query("SELECT * FROM `" . DB_PREFIX . "tipax_addresses` WHERE is_active=1 ORDER BY full_address");
        return $q->rows;
    }

    // =================== Orders ===================
    public function getTipaxOrder($order_id) {
        $q = $this->db->query("SELECT * FROM `" . DB_PREFIX . "tipax_orders` WHERE order_id='" . (int)$order_id . "'");
        return $q->row;
    }

    public function getTipaxOrders($start = 0, $limit = 20) {
        $q = $this->db->query("
            SELECT to2.*, o.firstname, o.lastname, o.email, o.telephone, o.total, o.date_added, o.shipping_method, o.shipping_city, o.order_id
            FROM `" . DB_PREFIX . "tipax_orders` to2
            LEFT JOIN `" . DB_PREFIX . "order` o ON (o.order_id = to2.order_id)
            ORDER BY to2.created_at DESC
            LIMIT " . (int)$start . "," . (int)$limit);
        return $q->rows;
    }

    // Bulk submit orders
    public function bulkSubmitOrders($order_ids) {
        $results = [];
        foreach ($order_ids as $order_id) {
            $order_id = (int)$order_id;
            $result = $this->submitOrder($order_id);
            $results[$order_id] = $result !== false;
        }
        return $results;
    }

    // Bulk cancel orders
    public function bulkCancelOrders($order_ids) {
        $results = [];
        foreach ($order_ids as $order_id) {
            $order_id = (int)$order_id;
            $result = $this->cancelOrder($order_id);
            $results[$order_id] = $result !== false;
        }
        return $results;
    }

    // Get pending orders
    public function getPendingOrders($start = 0, $limit = 20) {
        $q = $this->db->query("
            SELECT to2.*, o.firstname, o.lastname, o.email, o.telephone, o.total, o.date_added, o.shipping_method, o.shipping_city, o.order_id
            FROM `" . DB_PREFIX . "tipax_orders` to2
            LEFT JOIN `" . DB_PREFIX . "order` o ON (o.order_id = to2.order_id)
            WHERE to2.status = 'pending'
            ORDER BY to2.created_at DESC
            LIMIT " . (int)$start . "," . (int)$limit);
        return $q->rows;
    }

    // Get orders by status
    public function getOrdersByStatus($status, $start = 0, $limit = 20) {
        $q = $this->db->query("
            SELECT to2.*, o.firstname, o.lastname, o.email, o.telephone, o.total, o.date_added, o.shipping_method, o.shipping_city, o.order_id
            FROM `" . DB_PREFIX . "tipax_orders` to2
            LEFT JOIN `" . DB_PREFIX . "order` o ON (o.order_id = to2.order_id)
            WHERE to2.status = '" . $this->db->escape($status) . "'
            ORDER BY to2.created_at DESC
            LIMIT " . (int)$start . "," . (int)$limit);
        return $q->rows;
    }

    public function cancelOrder($order_id) {
        $row = $this->getTipaxOrder($order_id);
        if (!$row || empty($row['tipax_order_id'])) return false;

        $this->load->library('tipax');
        $token = $this->tipax->getApiToken();
        if (!$token) return false;

        $res = $this->tipax->cancelOrder($token, $row['tipax_order_id']);
        if ($res !== false) {
            $this->db->query("UPDATE `" . DB_PREFIX . "tipax_orders` SET status='cancelled', updated_at=NOW() WHERE order_id='" . (int)$order_id . "'");
            return $res;
        }
        return false;
    }

    private function computePackageFromOrder($order_id) {
        // Fallback defaults from settings
        $defWeightKg = (float)($this->config->get('shipping_tipax_default_weight_kg') ?: 0.5);
        $defLenCm    = (float)($this->config->get('shipping_tipax_default_length_cm') ?: 10);
        $defWidCm    = (float)($this->config->get('shipping_tipax_default_width_cm') ?: 10);
        $defHeiCm    = (float)($this->config->get('shipping_tipax_default_height_cm') ?: 10);

        $q = $this->db->query("\n            SELECT op.product_id, op.quantity, p.length, p.width, p.height, p.length_class_id, p.weight, p.weight_class_id\n            FROM `" . DB_PREFIX . "order_product` op\n            LEFT JOIN `" . DB_PREFIX . "product` p ON (p.product_id = op.product_id)\n            WHERE op.order_id = '" . (int)$order_id . "'\n        ");

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
            // Single item: use its exact dims (ceil for safety)
            $L = max(1, (int)ceil($singleDims['L']));
            $W = max(1, (int)ceil($singleDims['W']));
            $H = max(1, (int)ceil($singleDims['H']));
        } else {
            // Multiple items: use max L/W and compute H from total volume
            $Lbase = max(1.0, $maxLcm);
            $Wbase = max(1.0, $maxWcm);
            $L = (int)ceil($Lbase);
            $W = (int)ceil($Wbase);
            $Hcalc = ($Lbase * $Wbase) > 0 ? ($totalVolumeCm3 / ($Lbase * $Wbase)) : $maxHcm;
            $H = (int)ceil(max($Hcalc, $maxHcm, 1.0));
        }

        return ['weight_kg' => $kilograms, 'length' => $L, 'width' => $W, 'height' => $H];
    }

    public function submitOrder($order_id) {
        try {
            // Prevent duplicate submit
            $already = $this->getTipaxOrder($order_id);
            if ($already && $already['status'] === 'submitted') return $already;

            $this->load->model('sale/order');
            $order_info = $this->model_sale_order->getOrder($order_id);
            if (!$order_info) {
                $this->logTipaxError($order_id, 'سفارش یافت نشد', 'ORDER_NOT_FOUND');
                return false;
            }

            if (empty($order_info['shipping_code']) || strpos($order_info['shipping_code'], 'tipax.') !== 0) {
                $this->logTipaxError($order_id, 'سفارش تیپاکس نیست', 'NOT_TIPAX_ORDER');
                return false;
            }

            $this->load->library('tipax');
            $token = $this->tipax->getApiToken();
            if (!$token) {
                $this->logTipaxError($order_id, 'عدم دریافت توکن API', 'TOKEN_FAILED');
                return false;
            }

            // Static/default params per new requirements
            $paymentType = (int)($this->config->get('shipping_tipax_payment_type') ?: 10);
            $packingId = 0;
            $contentId = 9;
            $pickupType  = 20;
            $distributionType = 10;
            $parcelBookId = 0;
            $isUnusual = false;
            $enableLabelPrivacy = true;

            // Build dimensions/weight/value
            $pkg = $this->computePackageFromOrder($order_id);
            $weight_kg = (float)$pkg['weight_kg'];
            $length = (int)$pkg['length'];
            $width  = (int)$pkg['width'];
            $height = (int)$pkg['height'];
            // Auto pack type: <=2kg => mini pack (50), else bast (20)
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

            // Destination from customer order
            $destCityId = $this->resolveTipaxCityIdFromOrder($order_info);

            // Optional recipient overrides from Select Location Map module (lat/lng/plaque/unit/phone)
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
                    // Read directly from table (no module model call)
                    $qRows = $this->db->query("SELECT * FROM `" . DB_PREFIX . "order_to_location_address` WHERE order_id='" . (int)$order_id . "'");
                    $rows = $qRows ? $qRows->rows : [];
                    if (!empty($rows)) {
                        // Prefer latest row that has useful data; fallback to last row
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
            $originCityId = 0;
            $sender_mode = $this->config->get('shipping_tipax_sender_mode') ?: 'saved';
            $sender_originId = (int)$this->config->get('shipping_tipax_sender_selected_address_id');
            if ($sender_mode === 'saved' && $sender_originId) {
                // try to resolve origin city via saved address
                $qoc = $this->db->query("SELECT city_id FROM `" . DB_PREFIX . "tipax_addresses` WHERE tipax_address_id='" . (int)$sender_originId . "' LIMIT 1");
                if ($qoc->num_rows) $originCityId = (int)$qoc->row['city_id'];
            }
            if (!$originCityId) {
                $originCityId = (int)($this->config->get('shipping_tipax_sender_city_id') ?: 0);
            }
            $serviceId   = ($originCityId && (int)$destCityId === $originCityId) ? 7 : 4;

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
                    'weight' => (float)$weight_kg,
                    'packageValue' => (int)$packageValue,
                    'packingId' => $packingId,
                    'packageContentId' => $contentId,
                    'packType' => (int)$packType,
                    // 'description' => 'OC-' . $order_id,
                    'serviceId' => (int)$serviceId,
                    // 'enableLabelPrivacy' => $enableLabelPrivacy,
                    'paymentType' => (int)$paymentType,
                    'pickupType' => (int)$pickupType,
                    'distributionType' => (int)$distributionType,
                    // 'cod' => 0,
                    // 'cashAmount' => 0,
                    // 'parcelBookId' => $parcelBookId,
                    // 'isUnusual' => $isUnusual,
                ];
                // Apply recipient location extras when provided
                if (!empty($recipient['lat'])) {
                    $pkgItem['destination']['latitude']  = (string)$recipient['lat'];
                }
                if (!empty($recipient['lng'])) {
                    $pkgItem['destination']['longitude'] = (string)$recipient['lng'];
                }
                if (!empty($recipient['plaque'])) {
                    $pkgItem['destination']['no']        = (string)$recipient['plaque'];
                }
                if (!empty($recipient['unit'])) {
                    $pkgItem['destination']['unit']      = (string)$recipient['unit'];
                }
                if ((int)$packType !== 50) {
                    $pkgItem['length'] = (int)$length;
                    $pkgItem['width']  = (int)$width;
                    $pkgItem['height'] = (int)$height;
                }
                $payload = [
                    'packages' => [$pkgItem],
                    // 'traceCode' => 'OC-' . $order_id,
                    // 'secondaryTraceCode' => (string)$order_id
                ];
                
                // $this->log->write('TIPAX payload: ' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                $data = $this->tipax->submitWithPredefinedOrigin($token, $payload);
            } else {
                // Manual sender origin
                $pkgItem = [
                    'origin' => [
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
                    ],
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
                    'weight' => (float)$weight_kg,
                    'packageValue' => (int)$packageValue,
                    'packingId' => $packingId,
                    'packageContentId' => $contentId,
                    'packType' => (int)$packType,
                    // 'description' => 'OC-' . $order_id,
                    'serviceId' => (int)$serviceId,
                    // 'enableLabelPrivacy' => $enableLabelPrivacy,
                    'paymentType' => (int)$paymentType,
                    'pickupType' => (int)$pickupType,
                    'distributionType' => (int)$distributionType,
                    // 'cod' => 0,
                    // 'cashAmount' => 0,
                    // 'parcelBookId' => $parcelBookId,
                    // 'isUnusual' => $isUnusual,
                ];
                // Apply recipient location extras when provided
                if (!empty($recipient['lat'])) {
                    $pkgItem['destination']['latitude']  = (string)$recipient['lat'];
                }
                if (!empty($recipient['lng'])) {
                    $pkgItem['destination']['longitude'] = (string)$recipient['lng'];
                }
                if (!empty($recipient['plaque'])) {
                    $pkgItem['destination']['no']        = (string)$recipient['plaque'];
                }
                if (!empty($recipient['unit'])) {
                    $pkgItem['destination']['unit']      = (string)$recipient['unit'];
                }
                if ((int)$packType !== 50) {
                    $pkgItem['length'] = (int)$length;
                    $pkgItem['width']  = (int)$width;
                    $pkgItem['height'] = (int)$height;
                }
                $payload = [
                    'packages' => [$pkgItem],
                    // 'traceCode' => 'OC-' . $order_id,
                    // 'secondaryTraceCode' => (string)$order_id
                ];
                
                // $this->log->write('TIPAX payload: ' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
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

    private function resolveTipaxCityIdFromOrder($order_info) {
        $name = $order_info['shipping_city'];
        $q = $this->db->query("SELECT c.city_id FROM `" . DB_PREFIX . "city` c WHERE c.name LIKE '%" . $this->db->escape($name) . "%' LIMIT 1");
        if ($q->num_rows) {
            $map = $this->db->query("SELECT tipax_city_id FROM `" . DB_PREFIX . "tipax_city_mapping` WHERE oc_city_id='" . (int)$q->row['city_id'] . "' LIMIT 1");
            if ($map->num_rows) return (int)$map->row['tipax_city_id'];
        }
        $q2 = $this->db->query("SELECT tipax_city_id FROM `" . DB_PREFIX . "tipax_cities` WHERE city_title LIKE '%" . $this->db->escape($name) . "%' LIMIT 1");
        if ($q2->num_rows) return (int)$q2->row['tipax_city_id'];
        return 1;
    }

    public function getServiceTypes() {
        return [
            1 => 'ارسال زمینی همان روز',
            2 => 'ارسال یک روزه',
            3 => 'ارسال دو روزه',
            6 => 'بین‌الملل',
            7 => 'اکسپرس درون‌شهری',
        ];
    }

    public function getPaymentTypes() {
        return [
            10 => 'سمت فرستنده - نقدی/اعتباری',
            20 => 'سمت گیرنده - پس‌کرایه',
            // 30 => 'سمت گیرنده - پرداخت در محل',
            // 40 => 'سمت فرستنده - پرداخت در محل',
            50 => 'سمت فرستنده - از کیف پول',
        ];
    }

    public function getPickupTypes() {
        return [10 => 'جمع‌آوری از محل مشتری', 20 => 'جمع‌آوری در نمایندگی'];
    }

    public function getDistributionTypes() {
        return [10 => 'تحویل در محل مشتری', 20 => 'تحویل در نمایندگی'];
    }

    public function getTipaxOrdersFiltered($filter = 'all', $start = 0, $limit = 20) {
        $where = '';
        switch ($filter) {
            case 'failed':
                $where = " WHERE to2.status = 'failed'";
                break;
            case 'submitted':
                $where = " WHERE to2.status = 'submitted'";
                break;
            case 'pending':
                $where = " WHERE to2.status = 'pending'";
                break;
            case 'cancelled':
                $where = " WHERE to2.status = 'cancelled'";
                break;
            case 'all':
            default:
                $where = '';
                break;
        }

        $q = $this->db->query("
        SELECT to2.*, o.firstname, o.lastname, o.email, o.telephone, o.total, o.date_added, o.shipping_method, o.shipping_city, o.order_id
        FROM `" . DB_PREFIX . "tipax_orders` to2
        LEFT JOIN `" . DB_PREFIX . "order` o ON (o.order_id = to2.order_id)
        {$where}
        ORDER BY 
            CASE WHEN to2.status = 'failed' THEN 1 ELSE 2 END,
            to2.created_at DESC
        LIMIT " . (int)$start . "," . (int)$limit);
        return $q->rows;
    }

    /**
     * نسخه جدید همراه با total و پشتیبانی از pending واقعی (سفارشاتی که در جدول tipax_orders نیستند ولی shipping_code مربوط به tipax دارند)
     */
    public function getTipaxOrdersFilteredPaginated($filter = 'all', $page = 1, $limit = 20, $f_order_id = '', $f_customer = '', $f_city = '') {
        $page = max(1, (int)$page);
        $limit = max(1, (int)$limit);
        $offset = ($page - 1) * $limit;

        // Build advanced search conditions
        $advConditions = [];
        if ($f_order_id !== '' && ctype_digit($f_order_id)) {
            $advConditions[] = "o.order_id = '" . (int)$f_order_id . "'";
        }
        if ($f_customer !== '') {
            $input = trim(preg_replace('/\s+/u', ' ', $f_customer));
            $esc = $this->db->escape($input);
            $advConditions[] = "(CONCAT(o.firstname,' ',o.lastname) LIKE '%{$esc}%' OR o.firstname LIKE '%{$esc}%' OR o.lastname LIKE '%{$esc}%' OR o.telephone LIKE '%{$esc}%')";
        }
        if ($f_city !== '') {
            $esc = $this->db->escape($f_city);
            $advConditions[] = "o.shipping_city LIKE '%{$esc}%'";
        }
        $advWhereAll = implode(' AND ', $advConditions); // usable standalone
        $advWhereAnd = $advWhereAll ? ' AND ' . $advWhereAll : ''; // prefix with AND when base WHERE exists

        // فیلتر سفارشات موجود در جدول tipax_orders
        $baseSelect = "SELECT to2.*, o.firstname, o.lastname, o.email, o.telephone, o.total, o.date_added, o.shipping_method, o.shipping_city, o.order_id
            FROM `" . DB_PREFIX . "tipax_orders` to2
            LEFT JOIN `" . DB_PREFIX . "order` o ON (o.order_id = to2.order_id)";

        if ($filter === 'pending') {
            // سفارشاتی که هنوز در tipax_orders ثبت نشده اند ولی کد ارسال tipax دارند
            $sql = "SELECT 
                o.order_id,
                '' AS tipax_order_id,
                '' AS tracking_codes,
                NULL AS tracking_codes_with_titles,
                'pending' AS status,
                NULL AS service_id,
                NULL AS payment_type,
                NULL AS error_message,
                NULL AS error_code,
                0 AS retry_count,
                NULL AS last_error_at,
                o.date_added AS created_at,
                o.date_added AS updated_at,
                o.firstname, o.lastname, o.email, o.telephone, o.total, o.shipping_method, o.shipping_city
            FROM `" . DB_PREFIX . "order` o
            LEFT JOIN `" . DB_PREFIX . "tipax_orders` to2 ON (to2.order_id = o.order_id)
            WHERE to2.order_id IS NULL AND o.shipping_code LIKE 'tipax.%'" . $advWhereAnd . "
            ORDER BY o.date_added DESC
            LIMIT " . (int)$offset . "," . (int)$limit;

            $count_sql = "SELECT COUNT(*) AS total
                FROM `" . DB_PREFIX . "order` o
                LEFT JOIN `" . DB_PREFIX . "tipax_orders` to2 ON (to2.order_id = o.order_id)
                WHERE to2.order_id IS NULL AND o.shipping_code LIKE 'tipax.%'" . $advWhereAnd;
        } elseif ($filter === 'all') {
            // همه: union بین رکوردهای ثبت شده تیپاکس و سفارشات در انتظار (فاقد رکورد در جدول tipax_orders)
            // ستون‌ها باید دقیقاً همسان باشند تا UNION بدون خطا اجرا شود.
            $sqlMain = "SELECT 
                to2.order_id,
                to2.tipax_order_id,
                to2.tracking_codes,
                to2.tracking_codes_with_titles,
                to2.status,
                to2.service_id,
                to2.payment_type,
                to2.created_at,
                to2.updated_at,
                to2.error_message,
                to2.error_code,
                to2.retry_count,
                to2.last_error_at,
                o.firstname,
                o.lastname,
                o.email,
                o.telephone,
                o.total,
                o.date_added,
                o.shipping_method,
                o.shipping_city
            FROM `" . DB_PREFIX . "tipax_orders` to2
            LEFT JOIN `" . DB_PREFIX . "order` o ON (o.order_id = to2.order_id)" . ($advWhereAll ? ' WHERE ' . $advWhereAll : '');

            $sqlPending = "SELECT 
                o.order_id,
                '' AS tipax_order_id,
                '' AS tracking_codes,
                NULL AS tracking_codes_with_titles,
                'pending' AS status,
                NULL AS service_id,
                NULL AS payment_type,
                o.date_added AS created_at,
                o.date_added AS updated_at,
                NULL AS error_message,
                NULL AS error_code,
                0 AS retry_count,
                NULL AS last_error_at,
                o.firstname,
                o.lastname,
                o.email,
                o.telephone,
                o.total,
                o.date_added,
                o.shipping_method,
                o.shipping_city
            FROM `" . DB_PREFIX . "order` o
            LEFT JOIN `" . DB_PREFIX . "tipax_orders` t ON (t.order_id = o.order_id)
            WHERE t.order_id IS NULL AND o.shipping_code LIKE 'tipax.%'" . ($advWhereAll ? ' AND ' . $advWhereAll : '');

            $sql = "SELECT * FROM ((" . $sqlMain . ") UNION ALL (" . $sqlPending . ")) x
                ORDER BY CASE WHEN x.status='failed' THEN 1 ELSE 2 END, x.created_at DESC
                LIMIT " . (int)$offset . "," . (int)$limit;

            if ($advWhereAll) {
                $count_sql = "SELECT ((SELECT COUNT(*) FROM `" . DB_PREFIX . "tipax_orders` to2 LEFT JOIN `" . DB_PREFIX . "order` o ON (o.order_id=to2.order_id) WHERE " . $advWhereAll . ") + (SELECT COUNT(*) FROM `" . DB_PREFIX . "order` o LEFT JOIN `" . DB_PREFIX . "tipax_orders` t ON (t.order_id=o.order_id) WHERE t.order_id IS NULL AND o.shipping_code LIKE 'tipax.%' AND " . $advWhereAll . ")) AS total";
            } else {
                $count_sql = "SELECT ((SELECT COUNT(*) FROM `" . DB_PREFIX . "tipax_orders`) + (SELECT COUNT(*) FROM `" . DB_PREFIX . "order` o LEFT JOIN `" . DB_PREFIX . "tipax_orders` t ON (t.order_id=o.order_id) WHERE t.order_id IS NULL AND o.shipping_code LIKE 'tipax.%')) AS total";
            }
        } else {
            $where = '';
            switch ($filter) {
                case 'failed':
                    $where = " WHERE to2.status='failed'";
                    break;
                case 'submitted':
                    $where = " WHERE to2.status='submitted'";
                    break;
                case 'cancelled':
                    $where = " WHERE to2.status='cancelled'";
                    break;
                case 'all':
                default:
                    $where = '';
                    break;
            }

            $sql = $baseSelect . $where . ($advWhereAll ? ($where ? ' AND ' : ' WHERE ') . $advWhereAll : '') . " ORDER BY 
                CASE WHEN to2.status='failed' THEN 1 ELSE 2 END,
                to2.created_at DESC
                LIMIT " . (int)$offset . "," . (int)$limit;
            $count_sql = "SELECT COUNT(*) AS total FROM `" . DB_PREFIX . "tipax_orders` to2 LEFT JOIN `" . DB_PREFIX . "order` o ON (o.order_id=to2.order_id)" . ($where ? $where : '') . ($advWhereAll ? ($where ? ' AND ' : ' WHERE ') . $advWhereAll : '');
        }

        $rows = $this->db->query($sql)->rows;
        $total = (int)$this->db->query($count_sql)->row['total'];
        return ['rows' => $rows, 'total' => $total];
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
}
