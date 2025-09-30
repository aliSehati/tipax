<?php
class Tipax {
    protected $registry;
    protected $config;
    protected $log;
    protected $cache;

    protected $api_base_url = 'https://omtestapi.tipax.ir';

    // Safety margin before expiry (seconds)
    protected $expiry_margin = 60;

    public function __construct($registry) {
        $this->registry = $registry;
        $this->config = $registry->get('config');
        $this->log = $registry->get('log');
        // cache is available in both admin and catalog
        $this->cache = $registry->get('cache');
    }

    // ========== Public Auth (with caching + refresh) ==========
    // Always call this to get a valid access token.
    public function getApiToken() {
        $bundle = $this->getTokenBundleFromCache();

        // Return cached token if still valid
        if ($bundle && $this->isTokenValid($bundle)) {
            return $bundle['accessToken'];
        }

        // If we have a refreshToken try to refresh
        if ($bundle && !empty($bundle['refreshToken'])) {
            $refreshed = $this->refreshToken($bundle['accessToken'] ?? '', $bundle['refreshToken']);
            if ($refreshed) {
                $this->saveTokenBundleToCache($refreshed);
                return $refreshed['accessToken'];
            }
        }

        // Otherwise login
        $loggedIn = $this->login();
        if ($loggedIn) {
            $this->saveTokenBundleToCache($loggedIn);
            return $loggedIn['accessToken'];
        }

        return false;
    }

    // Clear cached token (optional helper)
    public function clearTokenCache() {
        $key = $this->getTokenCacheKey();
        $this->cache->delete($key);
    }

    // ========== Login / Refresh helpers ==========
    protected function login() {
        $username = $this->config->get('shipping_tipax_username');
        $password = $this->config->get('shipping_tipax_password');
        $api_key  = $this->config->get('shipping_tipax_api_key');
        if (!$username || !$password || !$api_key) return false;

        $payload = [
            'username' => $username,
            'password' => $password,
            'apiKey'   => $api_key,
        ];
        $res = $this->request('POST', '/api/OM/v3/Account/token', null, $payload, false);
        if ($res['code'] === 200 && !empty($res['body'])) {
            $data = json_decode($res['body'], true);
            // Support both flat and nested response shapes
            $d = isset($data['data']) ? $data['data'] : $data;

            $accessToken  = $d['accessToken']  ?? null;
            $refreshToken = $d['refreshToken'] ?? null;
            $expiresIn    = (int)($d['expiresIn'] ?? ($d['expireIn'] ?? 0)); // handle both keys
            $expiresAt    = $expiresIn > 0 ? (time() + max(0, $expiresIn - $this->expiry_margin)) : (time() + 600);

            if ($accessToken) {
                return [
                    'accessToken'  => $accessToken,
                    'refreshToken' => $refreshToken,
                    'expires_at'   => $expiresAt,
                ];
            }
        }
        return false;
    }

    protected function refreshToken($accessToken, $refreshToken) {
        if (!$refreshToken) return false;

        // Some APIs expect both access and refresh tokens. We'll send both defensively.
        $payload = [
            'refreshToken' => $refreshToken,
            'accessToken'  => $accessToken,
        ];
        $res = $this->request('POST', '/api/OM/v3/Account/RefreshToken', null, $payload, false);
        if ($res['code'] === 200 && !empty($res['body'])) {
            $data = json_decode($res['body'], true);
            $d = isset($data['data']) ? $data['data'] : $data;

            $newAccess  = $d['accessToken']  ?? null;
            $newRefresh = $d['refreshToken'] ?? $refreshToken; // may or may not rotate
            $expiresIn  = (int)($d['expiresIn'] ?? ($d['expireIn'] ?? 0));
            $expiresAt  = $expiresIn > 0 ? (time() + max(0, $expiresIn - $this->expiry_margin)) : (time() + 600);

            if ($newAccess) {
                return [
                    'accessToken'  => $newAccess,
                    'refreshToken' => $newRefresh,
                    'expires_at'   => $expiresAt,
                ];
            }
        }
        return false;
    }

    protected function isTokenValid($bundle) {
        if (empty($bundle['accessToken'])) return false;
        $now = time();
        return !empty($bundle['expires_at']) && $bundle['expires_at'] > $now;
    }

    protected function getTokenCacheKey() {
        // Key derived from credentials to isolate different accounts
        $username = (string)$this->config->get('shipping_tipax_username');
        $api_key  = (string)$this->config->get('shipping_tipax_api_key');
        $hash = md5($username . '|' . $api_key);
        return 'tipax_token_' . $hash;
    }

    protected function getTokenBundleFromCache() {
        $key = $this->getTokenCacheKey();
        $bundle = $this->cache->get($key);
        if (is_string($bundle)) {
            $decoded = json_decode($bundle, true);
            if (is_array($decoded)) return $decoded;
        } elseif (is_array($bundle)) {
            return $bundle;
        }
        return null;
    }

    protected function saveTokenBundleToCache(array $bundle) {
        $key = $this->getTokenCacheKey();
        // TTL: until expires_at (fallback 10 min)
        $ttl = max(600, (int)($bundle['expires_at'] - time()));
        // Some cache drivers want strings; store json string for portability
        $this->cache->set($key, json_encode($bundle, JSON_UNESCAPED_UNICODE), $ttl);
    }

    // ========== Pricing ==========
    public function pricing($token, array $packageInputs) {
        $payload = ['packageInputs' => $packageInputs];
        $res = $this->request('POST', '/api/OM/v3/Pricing', $token, $payload);
        if ($res['code'] === 200 && strlen($res['body'])) {
            return json_decode($res['body'], true);
        }
        return $this->handleApiError($res);
    }

    // ========== Pricing with Origin Address ID ==========
    public function pricingWithOriginAddressId($token, array $packageInputs, $discountCode = null, $customerId = null) {
        $payload = [
            'packageInputs' => $packageInputs
        ];

        if ($discountCode) {
            $payload['discountCode'] = $discountCode;
        }

        if ($customerId) {
            $payload['customerId'] = (int)$customerId;
        }

        $res = $this->request('POST', '/api/OM/v3/Pricing/WithOriginAddressId', $token, $payload);
        if ($res['code'] === 200 && strlen($res['body'])) {
            return json_decode($res['body'], true);
        }
        return $this->handleApiError($res);
    }

    // ========== Cities ==========
    public function citiesPlusState($token) {
        $res = $this->request('GET', '/api/OM/v3/Cities/plusstate', $token);
        if ($res['code'] === 200 && strlen($res['body'])) {
            return json_decode($res['body'], true);
        }
        return $this->handleApiError($res);
    }

    // ========== Wallet ==========
    public function walletBalance($token) {
        $res = $this->request('GET', '/api/OM/v3/Customers/Wallet', $token);
        if ($res['code'] === 200 && strlen($res['body'])) {
            // API returns plain number string
            return (float)$res['body'];
        }
        return $this->handleApiError($res);
    }

    public function rechargeWallet($token, $amount, $callback_url) {
        $payload = [
            'amount' => (float)$amount,
            'frontCallBackUrl' => $callback_url
        ];
        $res = $this->request('POST', '/api/OM/v3/Customers/RechargeWallet', $token, $payload);
        if ($res['code'] === 200 && strlen($res['body'])) {
            return json_decode($res['body'], true);
        }
        return $this->handleApiError($res);
    }

    // ========== Addresses ==========
    public function addressesBook($token) {
        $res = $this->request('GET', '/api/OM/v3/Addresses/Book', $token);
        if ($res['code'] === 200 && strlen($res['body'])) {
            return json_decode($res['body'], true);
        }
        return $this->handleApiError($res);
    }

    // ========== Orders ==========
    public function submitOrders($token, array $payload) {
        $res = $this->request('POST', '/api/OM/v3/Orders', $token, $payload);
        if ($res['code'] === 200 && strlen($res['body'])) {
            return json_decode($res['body'], true);
        }
        return $this->handleApiError($res);
    }

    public function submitWithPredefinedOrigin($token, array $payload) {
        $res = $this->request('POST', '/api/OM/v3/Orders/WithPreDefinedOrigin', $token, $payload);
        if ($res['code'] === 200 && strlen($res['body'])) {
            return json_decode($res['body'], true);
        }
        return $this->handleApiError($res);
    }

    public function cancelOrder($token, $tipax_order_id) {
        $res = $this->request('POST', '/api/OM/v3/Orders/CancelOrder/' . rawurlencode((string)$tipax_order_id), $token);
        if ($res['code'] === 200 && strlen($res['body'])) {
            return json_decode($res['body'], true);
        }
        return $this->handleApiError($res);
    }

    // ========== Error Handling ==========
    protected function handleApiError($response) {
        $error_info = [
            'success' => false,
            'error_code' => $response['code'],
            'error_message' => 'خطای ناشناخته',
            'raw_response' => $response
        ];

        // Parse error message from response body
        if (!empty($response['body'])) {
            // Try to decode JSON error response (support various key casings)
            $decoded = json_decode($response['body'], true);
            if (is_array($decoded)) {
                // Normalize top-level keys for case-insensitive access
                $dl = array_change_key_case($decoded, CASE_LOWER);

                $parts = [];
                // Prefer combined Title + Message when available
                if (!empty($dl['title'])) {
                    $parts[] = (string)$dl['title'];
                }
                if (!empty($dl['message'])) {
                    $parts[] = (string)$dl['message'];
                } elseif (!empty($dl['error'])) {
                    $parts[] = (string)$dl['error'];
                } elseif (!empty($dl['errors']) && is_array($dl['errors'])) {
                    $parts[] = implode(', ', $dl['errors']);
                }

                if (!empty($parts)) {
                    $error_info['error_message'] = implode(' - ', $parts);
                } else {
                    // Fallback: if body is JSON but keys are unexpected, keep raw JSON body
                    $error_info['error_message'] = $response['body'];
                }

                // Preserve useful extras (e.g., payment URL) when available
                if (!empty($dl['paymenturl'])) {
                    $error_info['payment_url'] = (string)$dl['paymenturl'];
                } elseif (!empty($dl['payment_url'])) {
                    $error_info['payment_url'] = (string)$dl['payment_url'];
                }
            } else {
                // If not JSON, use raw body as error message
                $error_info['error_message'] = $response['body'];
            }
        }

        // Handle specific HTTP codes
        switch ($response['code']) {
            case 400:
                $error_info['error_message'] = 'درخواست نامعتبر: ' . $error_info['error_message'];
                break;
            case 401:
                $error_info['error_message'] = 'خطای احراز هویت: ' . $error_info['error_message'];
                break;
            case 403:
                $error_info['error_message'] = 'عدم دسترسی: ' . $error_info['error_message'];
                break;
            case 404:
                $error_info['error_message'] = 'یافت نشد: ' . $error_info['error_message'];
                break;
            case 500:
                $error_info['error_message'] = 'خطای سرور: ' . $error_info['error_message'];
                break;
        }

        // Log the error (with improved message)
        if ($this->log) {
            $this->log->write('Tipax API Error: ' . json_encode($error_info, JSON_UNESCAPED_UNICODE));
        }

        return $error_info;
    }

    // ========== Core HTTP with auto-refresh retry ==========
    // If a 401 occurs and a token was provided, we try to refresh token once and retry the call.
    protected function request($method, $path, $token = null, $payload = null, $retryOnUnauthorized = true) {
        $response = $this->execCurl($method, $path, $token, $payload);
        if ($response['code'] === 401 && $token && $retryOnUnauthorized) {
            // Try refresh and retry once
            $bundle = $this->getTokenBundleFromCache();
            if ($bundle && !empty($bundle['refreshToken'])) {
                $newBundle = $this->refreshToken($bundle['accessToken'] ?? '', $bundle['refreshToken']);
                if ($newBundle) {
                    $this->saveTokenBundleToCache($newBundle);
                    // Retry with new token (avoid infinite loop)
                    return $this->execCurl($method, $path, $newBundle['accessToken'], $payload);
                }
            } else {
                // If no refresh token, try login and retry
                $loggedIn = $this->login();
                if ($loggedIn) {
                    $this->saveTokenBundleToCache($loggedIn);
                    return $this->execCurl($method, $path, $loggedIn['accessToken'], $payload);
                }
            }
        }
        return $response;
    }

    protected function execCurl($method, $path, $token = null, $payload = null) {
        $ch = curl_init();
        $headers = ['Content-Type: application/json'];
        if ($token) $headers[] = 'Authorization: Bearer ' . $token;

        $opts = [
            CURLOPT_URL => $this->api_base_url . $path,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 60,
        ];

        $method = strtoupper($method);
        if ($method === 'POST') {
            $opts[CURLOPT_POST] = true;
            if ($payload !== null) $opts[CURLOPT_POSTFIELDS] = json_encode($payload);
        } elseif ($method === 'PUT' || $method === 'PATCH') {
            $opts[CURLOPT_CUSTOMREQUEST] = $method;
            if ($payload !== null) $opts[CURLOPT_POSTFIELDS] = json_encode($payload);
        } else {
            $opts[CURLOPT_HTTPGET] = true;
        }

        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) {
            if ($this->log) $this->log->write('Tipax API error: ' . $err . ' on ' . $path);
        }
        return ['code' => (int)$code, 'body' => (string)$body, 'error' => $err];
    }
}
