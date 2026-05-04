<?php

function duitku_get_settings()
{
    global $config;

    $environment = strtolower(trim((string)($config['duitku_environment'] ?? 'sandbox')));
    if (!in_array($environment, ['sandbox', 'production'], true)) {
        $environment = 'sandbox';
    }

    $integrationMode = strtolower(trim((string)($config['duitku_integration_mode'] ?? 'v2_direct')));
    if (!in_array($integrationMode, ['v2_direct', 'pop_redirect'], true)) {
        $integrationMode = 'v2_direct';
    }

    $expiryPeriod = (int)($config['duitku_expiry_period'] ?? 0);
    if ($expiryPeriod < 0) {
        $expiryPeriod = 0;
    }

    $enabledChannels = array_values(array_filter(array_map('trim', explode(',', (string)($config['duitku_channel'] ?? '')))));
    if (count($enabledChannels) === 0) {
        $enabledChannels = array_map(function ($channel) {
            return $channel['id'];
        }, duitku_static_channels());
    }

    return [
        'merchant_id' => trim((string)($config['duitku_merchant_id'] ?? '')),
        'merchant_key' => trim((string)($config['duitku_merchant_key'] ?? '')),
        'environment' => $environment,
        'integration_mode' => $integrationMode,
        'expiry_period' => $expiryPeriod,
        'account_link_credential_code' => trim((string)($config['duitku_account_link_credential_code'] ?? '')),
        'enabled_channels' => $enabledChannels,
    ];
}

function duitku_minimum_amount()
{
    return 1;
}

function duitku_amount_supported($amount)
{
    return (int)round((float)$amount) >= 1;
}

function duitku_validate_config()
{
    $settings = duitku_get_settings();
    if ($settings['merchant_id'] === '' || $settings['merchant_key'] === '') {
        Message::sendTelegram("Duitku payment gateway not configured");
        r2(U . 'order/package', 'w', Lang::T("Admin has not yet setup Duitku payment gateway, please tell admin"));
    }
}

function duitku_show_config()
{
    global $ui;
    $ui->assign('_title', 'Duitku - Payment Gateway');
    $ui->assign('channels', duitku_static_channels());
    $ui->assign('duitku_settings', duitku_get_settings());
    $ui->display('duitku.tpl');
}

function duitku_save_app_config($setting, $value)
{
    $d = ORM::for_table('tbl_appconfig')->where('setting', $setting)->find_one();
    if (!$d) {
        $d = ORM::for_table('tbl_appconfig')->create();
        $d->setting = $setting;
    }
    $d->value = $value;
    $d->save();
}

function duitku_save_config()
{
    global $admin;

    $environment = strtolower(trim((string)_post('duitku_environment')));
    if (!in_array($environment, ['sandbox', 'production'], true)) {
        $environment = 'sandbox';
    }

    $integrationMode = strtolower(trim((string)_post('duitku_integration_mode')));
    if (!in_array($integrationMode, ['v2_direct', 'pop_redirect'], true)) {
        $integrationMode = 'v2_direct';
    }

    $expiryPeriod = (int)_post('duitku_expiry_period');
    if ($expiryPeriod < 0) {
        $expiryPeriod = 0;
    }

    $channels = isset($_POST['duitku_channel']) && is_array($_POST['duitku_channel'])
        ? array_values(array_filter(array_map('trim', $_POST['duitku_channel'])))
        : [];

    duitku_save_app_config('duitku_merchant_id', trim((string)_post('duitku_merchant_id')));
    duitku_save_app_config('duitku_merchant_key', trim((string)_post('duitku_merchant_key')));
    duitku_save_app_config('duitku_environment', $environment);
    duitku_save_app_config('duitku_integration_mode', $integrationMode);
    duitku_save_app_config('duitku_expiry_period', $expiryPeriod > 0 ? (string)$expiryPeriod : '');
    duitku_save_app_config('duitku_account_link_credential_code', trim((string)_post('duitku_account_link_credential_code')));
    duitku_save_app_config('duitku_channel', implode(',', $channels));

    _log('[' . $admin['username'] . ']: Duitku ' . Lang::T('Settings_Saved_Successfully'), 'Admin', $admin['id']);
    r2(U . 'paymentgateway/duitku', 's', Lang::T('Settings_Saved_Successfully'));
}

function duitku_static_channels()
{
    $path = __DIR__ . DIRECTORY_SEPARATOR . 'channel_duitku.json';
    $channels = json_decode((string)@file_get_contents($path), true);
    if (!is_array($channels)) {
        return [];
    }

    $normalized = [];
    foreach ($channels as $channel) {
        if (empty($channel['id']) || empty($channel['name'])) {
            continue;
        }
        $normalized[] = [
            'id' => (string)$channel['id'],
            'name' => (string)$channel['name'],
            'category' => (string)($channel['category'] ?? ''),
            'account_link' => !empty($channel['account_link']),
            'min_amount' => (int)($channel['min_amount'] ?? duitku_minimum_amount()),
            'max_amount' => (int)($channel['max_amount'] ?? 0),
            'image' => (string)($channel['image'] ?? ''),
            'fee' => '',
            'source' => 'static',
        ];
    }
    return $normalized;
}

function duitku_channel_name($paymentMethod)
{
    foreach (duitku_static_channels() as $channel) {
        if ($channel['id'] === $paymentMethod) {
            return $channel['name'];
        }
    }
    return $paymentMethod;
}

function duitku_endpoint($name)
{
    $settings = duitku_get_settings();
    $isProduction = $settings['environment'] === 'production';
    $v2Base = $isProduction
        ? 'https://passport.duitku.com/webapi/api/merchant/'
        : 'https://sandbox.duitku.com/webapi/api/merchant/';

    switch ($name) {
        case 'get_payment_method':
            return $v2Base . 'paymentmethod/getpaymentmethod';
        case 'transaction_status':
            return $v2Base . 'transactionStatus';
        case 'v2_inquiry':
            return $v2Base . 'v2/inquiry';
        case 'pop_create_invoice':
            return $isProduction
                ? 'https://api-prod.duitku.com/api/merchant/createInvoice'
                : 'https://api-sandbox.duitku.com/api/merchant/createInvoice';
    }

    return $v2Base;
}

function duitku_post_json($url, $payload, $headers = [])
{
    $raw = Http::postJsonData($url, $payload, $headers, null, 15, 30);
    $data = json_decode((string)$raw, true);
    if (!is_array($data)) {
        $data = [
            'statusCode' => '',
            'statusMessage' => 'Invalid JSON response from Duitku',
            'raw' => (string)$raw,
        ];
    }

    return [
        'raw' => (string)$raw,
        'data' => $data,
    ];
}

function duitku_get_payment_methods($amount)
{
    $settings = duitku_get_settings();
    $amount = max(0, (int)round((float)$amount));
    if ($amount <= 0) {
        return [];
    }

    if ($settings['merchant_id'] === '' || $settings['merchant_key'] === '') {
        return null;
    }

    $datetime = date('Y-m-d H:i:s');
    $payload = [
        'merchantcode' => $settings['merchant_id'],
        'amount' => $amount,
        'datetime' => $datetime,
        'signature' => hash('sha256', $settings['merchant_id'] . $amount . $datetime . $settings['merchant_key']),
    ];
    $response = duitku_post_json(duitku_endpoint('get_payment_method'), $payload);
    $data = $response['data'];
    if (($data['responseCode'] ?? '') !== '00' || !is_array($data['paymentFee'] ?? null)) {
        return null;
    }

    return $data['paymentFee'];
}

function duitku_channel_supports_amount($channel, $amount)
{
    $amount = (int)round((float)$amount);
    $minAmount = (int)($channel['min_amount'] ?? duitku_minimum_amount());
    $maxAmount = (int)($channel['max_amount'] ?? 0);

    if ($amount < $minAmount) {
        return false;
    }

    if ($maxAmount > 0 && $amount > $maxAmount) {
        return false;
    }

    return true;
}

function duitku_get_checkout_channels($amount)
{
    $settings = duitku_get_settings();
    $enabled = $settings['enabled_channels'];
    $credentialCode = $settings['account_link_credential_code'];
    $staticChannels = duitku_static_channels();
    $staticById = [];
    foreach ($staticChannels as $channel) {
        $staticById[$channel['id']] = $channel;
    }

    $apiChannels = duitku_get_payment_methods($amount);
    $channels = [];
    if (is_array($apiChannels)) {
        foreach ($apiChannels as $apiChannel) {
            $id = (string)($apiChannel['paymentMethod'] ?? '');
            if ($id === '' || !in_array($id, $enabled, true)) {
                continue;
            }
            $base = $staticById[$id] ?? [
                'id' => $id,
                'name' => (string)($apiChannel['paymentName'] ?? $id),
                'category' => '',
                'account_link' => in_array($id, ['SL', 'OL'], true),
                'min_amount' => duitku_minimum_amount(),
                'max_amount' => 0,
                'image' => '',
            ];
            if (!empty($base['account_link']) && $credentialCode === '') {
                continue;
            }
            if (!duitku_channel_supports_amount($base, $amount)) {
                continue;
            }
            $base['name'] = (string)($apiChannel['paymentName'] ?? $base['name']);
            $base['image'] = (string)($apiChannel['paymentImage'] ?? $base['image']);
            $base['fee'] = (string)($apiChannel['totalFee'] ?? '');
            $base['source'] = 'api';
            $channels[] = $base;
        }
        return $channels;
    }

    foreach ($staticChannels as $channel) {
        if (!in_array($channel['id'], $enabled, true)) {
            continue;
        }
        if (!empty($channel['account_link']) && $credentialCode === '') {
            continue;
        }
        if (!duitku_channel_supports_amount($channel, $amount)) {
            continue;
        }
        $channels[] = $channel;
    }
    return $channels;
}

function duitku_limited_text($value, $limit)
{
    $value = trim(preg_replace('/\s+/', ' ', (string)$value));
    if ($value === '') {
        return '-';
    }
    return strlen($value) > $limit ? substr($value, 0, $limit) : $value;
}

function duitku_customer_email($user)
{
    $email = trim((string)($user['email'] ?? ''));
    if (strpos($email, '@') !== false) {
        return $email;
    }
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return preg_replace('/[^a-zA-Z0-9._-]/', '', (string)($user['username'] ?? 'customer')) . '@' . $host;
}

function duitku_customer_detail($user)
{
    $fullName = duitku_limited_text($user['fullname'] ?? $user['username'] ?? 'Customer', 50);
    $parts = preg_split('/\s+/', $fullName, 2);
    $firstName = $parts[0] ?? $fullName;
    $lastName = $parts[1] ?? '';
    $phone = trim((string)($user['phonenumber'] ?? ''));
    $email = duitku_customer_email($user);
    $address = [
        'firstName' => duitku_limited_text($firstName, 50),
        'lastName' => duitku_limited_text($lastName, 50),
        'address' => duitku_limited_text($user['address'] ?? '-', 255),
        'city' => duitku_limited_text($user['city'] ?? '-', 50),
        'postalCode' => duitku_limited_text($user['zip'] ?? '-', 20),
        'phone' => $phone,
        'countryCode' => 'ID',
    ];

    return [
        'firstName' => duitku_limited_text($firstName, 50),
        'lastName' => duitku_limited_text($lastName, 50),
        'email' => $email,
        'phoneNumber' => $phone,
        'merchantCustomerId' => (string)($user['id'] ?? $user['username'] ?? ''),
        'billingAddress' => $address,
        'shippingAddress' => $address,
    ];
}

function duitku_transaction_payload($trx, $user, $paymentMethod = '')
{
    $settings = duitku_get_settings();
    $amount = max(0, (int)round((float)$trx['price']));
    $productName = duitku_limited_text($trx['plan_name'] ?? 'Internet Package', 255);
    $customerName = duitku_limited_text($user['fullname'] ?? $user['username'] ?? 'Customer', 20);

    $payload = [
        'paymentAmount' => $amount,
        'merchantOrderId' => (string)$trx['id'],
        'productDetails' => $productName,
        'additionalParam' => '',
        'merchantUserInfo' => (string)($user['username'] ?? ''),
        'customerVaName' => $customerName,
        'email' => duitku_customer_email($user),
        'phoneNumber' => (string)($user['phonenumber'] ?? ''),
        'itemDetails' => [
            [
                'name' => $productName,
                'price' => $amount,
                'quantity' => 1,
            ],
        ],
        'customerDetail' => duitku_customer_detail($user),
        'callbackUrl' => U . 'callback/duitku',
        'returnUrl' => (class_exists('CustomerVoucherCatalog') && CustomerVoucherCatalog::isVoucherPayment($trx))
            ? CustomerVoucherCatalog::gatewayReturnUrl($trx) . '/check'
            : U . 'order/view/' . $trx['id'] . '/check',
    ];

    if ($settings['expiry_period'] > 0) {
        $payload['expiryPeriod'] = $settings['expiry_period'];
    }

    if ($paymentMethod !== '') {
        $payload['paymentMethod'] = $paymentMethod;
    }

    return $payload;
}

function duitku_v2_payload($trx, $user, $paymentMethod)
{
    $settings = duitku_get_settings();
    $payload = duitku_transaction_payload($trx, $user, $paymentMethod);
    $payload['merchantCode'] = $settings['merchant_id'];
    $payload['signature'] = md5($settings['merchant_id'] . $payload['merchantOrderId'] . $payload['paymentAmount'] . $settings['merchant_key']);

    if (in_array($paymentMethod, ['SL', 'OL'], true) && $settings['account_link_credential_code'] !== '') {
        $payload['accountLink'] = [
            'credentialCode' => $settings['account_link_credential_code'],
            'ovo' => [
                'paymentDetails' => [
                    [
                        'paymentType' => 'CASH',
                        'amount' => $payload['paymentAmount'],
                    ],
                ],
            ],
            'shopee' => [
                'useCoin' => false,
                'promoId' => '',
            ],
        ];
    }

    return $payload;
}

function duitku_pop_headers()
{
    $settings = duitku_get_settings();
    $timestamp = (string)round(microtime(true) * 1000);
    $signature = hash('sha256', $settings['merchant_id'] . $timestamp . $settings['merchant_key']);

    return [
        'Accept: application/json',
        'x-duitku-signature: ' . $signature,
        'x-duitku-timestamp: ' . $timestamp,
        'x-duitku-merchantcode: ' . $settings['merchant_id'],
    ];
}

function duitku_selected_channel()
{
    global $routes;
    if (class_exists('PaymentGateway')) {
        $channel = PaymentGateway::selectedPaymentChannel('duitku');
        if ($channel !== '') {
            return $channel;
        }
    }

    $channel = trim((string)_post('payment_channel'));
    if ($channel === '') {
        $channel = trim((string)_post('duitku_channel'));
    }
    if ($channel === '' && !empty($routes[4])) {
        $channel = trim((string)$routes[4]);
    }
    return $channel;
}

function duitku_channel_allowed($channel, $amount = null)
{
    $settings = duitku_get_settings();
    if (!in_array($channel, $settings['enabled_channels'], true)) {
        return false;
    }
    if (in_array($channel, ['SL', 'OL'], true) && $settings['account_link_credential_code'] === '') {
        return false;
    }
    if ($amount !== null) {
        foreach (duitku_get_checkout_channels($amount) as $availableChannel) {
            if ((string)$availableChannel['id'] === (string)$channel) {
                return true;
            }
        }
        return false;
    }

    return true;
}

function duitku_store_gateway_response($trx, $requestPayload, $response, $paymentMethod, $paymentChannel)
{
    $settings = duitku_get_settings();
    $reference = (string)($response['reference'] ?? '');
    $paymentUrl = (string)($response['paymentUrl'] ?? '');
    $existingRequest = json_decode((string)($trx['pg_request'] ?? ''), true);
    $localBilling = is_array($existingRequest) && isset($existingRequest['local_billing']) && is_array($existingRequest['local_billing'])
        ? $existingRequest['local_billing']
        : null;

    $trx->gateway_trx_id = $reference;
    $trx->pg_url_payment = $paymentUrl;
    $trx->payment_method = $paymentMethod;
    $trx->payment_channel = $paymentChannel;
    $requestLog = [
        'environment' => $settings['environment'],
        'integration_mode' => $settings['integration_mode'],
        'request' => $requestPayload,
        'response' => $response,
    ];
    if ($localBilling !== null) {
        $requestLog['local_billing'] = $localBilling;
    }
    $trx->pg_request = json_encode($requestLog, JSON_UNESCAPED_SLASHES);

    $expiryMinutes = $settings['expiry_period'] > 0 ? $settings['expiry_period'] : 1440;
    $trx->expired_date = date('Y-m-d H:i:s', strtotime('+' . $expiryMinutes . ' minutes'));
    $trx->save();
}

function duitku_retry_url($trx)
{
    if (class_exists('CustomerVoucherCatalog') && CustomerVoucherCatalog::isVoucherPayment($trx)) {
        return CustomerVoucherCatalog::gatewayReturnUrl($trx);
    }

    $planId = (int)($trx['plan_id'] ?? 0);
    if ($planId > 0) {
        return U . 'order/gateway/' . $trx['routers_id'] . '/' . $planId;
    }

    return U . 'order/balance';
}

function duitku_payment_status_url($trx)
{
    if (class_exists('CustomerVoucherCatalog') && CustomerVoucherCatalog::isVoucherPayment($trx)) {
        return CustomerVoucherCatalog::gatewayReturnUrl($trx);
    }

    $trxId = (int)($trx['id'] ?? 0);
    if ($trxId > 0) {
        return U . 'order/view/' . $trxId;
    }

    return duitku_retry_url($trx);
}

function duitku_create_transaction($trx, $user)
{
    $settings = duitku_get_settings();
    $mode = $settings['integration_mode'];
    if (!duitku_amount_supported($trx['price'])) {
        if (class_exists('CustomerVoucherCatalog') && CustomerVoucherCatalog::isVoucherPayment($trx)) {
            CustomerVoucherCatalog::markPaymentFailed($trx, CustomerVoucherCatalog::ORDER_FAILED);
        }
        r2(
            duitku_retry_url($trx),
            'w',
            Lang::T("Invalid payment amount")
        );
    }

    if ($mode === 'pop_redirect') {
        $payload = duitku_transaction_payload($trx, $user, '');
        $response = duitku_post_json(duitku_endpoint('pop_create_invoice'), $payload, duitku_pop_headers());
        $result = $response['data'];
        if (empty($result['paymentUrl']) || empty($result['reference']) || ($result['statusCode'] ?? '') !== '00') {
            Message::sendTelegram("Duitku POP payment failed\n\n" . json_encode($result, JSON_PRETTY_PRINT));
            if (class_exists('CustomerVoucherCatalog') && CustomerVoucherCatalog::isVoucherPayment($trx)) {
                CustomerVoucherCatalog::markPaymentFailed($trx, CustomerVoucherCatalog::ORDER_FAILED);
            }
            r2(duitku_retry_url($trx), 'e', Lang::T("Failed to create transaction."));
        }
        duitku_store_gateway_response($trx, $payload, $result, 'POP', 'Duitku POP');
        r2((class_exists('CustomerVoucherCatalog') && CustomerVoucherCatalog::isVoucherPayment($trx)) ? CustomerVoucherCatalog::gatewayReturnUrl($trx) : U . 'order/view/' . $trx['id'], 's', Lang::T("Create Transaction Success"));
    }

    $paymentMethod = duitku_selected_channel();
    if ($paymentMethod === '' || !duitku_channel_allowed($paymentMethod, $trx['price'])) {
        if (class_exists('CustomerVoucherCatalog') && CustomerVoucherCatalog::isVoucherPayment($trx)) {
            CustomerVoucherCatalog::markPaymentFailed($trx, CustomerVoucherCatalog::ORDER_FAILED);
        }
        r2(duitku_retry_url($trx), 'w', Lang::T("Please select Payment Channel"));
    }

    $payload = duitku_v2_payload($trx, $user, $paymentMethod);
    $response = duitku_post_json(duitku_endpoint('v2_inquiry'), $payload);
    $result = $response['data'];
    if (empty($result['paymentUrl']) || empty($result['reference'])) {
        Message::sendTelegram("Duitku payment failed\n\n" . json_encode($result, JSON_PRETTY_PRINT));
        if (class_exists('CustomerVoucherCatalog') && CustomerVoucherCatalog::isVoucherPayment($trx)) {
            CustomerVoucherCatalog::markPaymentFailed($trx, CustomerVoucherCatalog::ORDER_FAILED);
        }
        r2(duitku_retry_url($trx), 'e', Lang::T("Failed to create transaction."));
    }

    duitku_store_gateway_response($trx, $payload, $result, $paymentMethod, duitku_channel_name($paymentMethod));
    r2((class_exists('CustomerVoucherCatalog') && CustomerVoucherCatalog::isVoucherPayment($trx)) ? CustomerVoucherCatalog::gatewayReturnUrl($trx) : U . 'order/view/' . $trx['id'], 's', Lang::T("Create Transaction Success"));
}

function duitku_no_redirect()
{
    return defined('IS_GATEWAY_CALLBACK') && IS_GATEWAY_CALLBACK;
}

function duitku_lock_name($trxId)
{
    return 'phpnuxbill_duitku_' . (int)$trxId;
}

function duitku_acquire_payment_lock($trxId, $timeout = 30)
{
    $lockName = duitku_lock_name($trxId);
    try {
        $db = ORM::get_db();
        $stmt = $db->prepare('SELECT GET_LOCK(?, ?)');
        if ($stmt && $stmt->execute([$lockName, max(1, (int)$timeout)])) {
            $result = $stmt->fetchColumn();
            return ((string)$result === '1') ? ['type' => 'mysql', 'name' => $lockName] : false;
        }
        return false;
    } catch (Throwable $e) {
        $path = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $lockName . '.lock';
        $handle = @fopen($path, 'c');
        if (!$handle) {
            return false;
        }
        $start = time();
        do {
            if (@flock($handle, LOCK_EX | LOCK_NB)) {
                return ['type' => 'file', 'handle' => $handle];
            }
            usleep(100000);
        } while ((time() - $start) < max(1, (int)$timeout));
        @fclose($handle);
        return false;
    }
}

function duitku_release_payment_lock($lock)
{
    if (!is_array($lock)) {
        return;
    }
    if (($lock['type'] ?? '') === 'mysql' && !empty($lock['name'])) {
        try {
            $stmt = ORM::get_db()->prepare('SELECT RELEASE_LOCK(?)');
            if ($stmt) {
                $stmt->execute([$lock['name']]);
            }
        } catch (Throwable $e) {
        }
        return;
    }
    if (($lock['type'] ?? '') === 'file' && !empty($lock['handle'])) {
        @flock($lock['handle'], LOCK_UN);
        @fclose($lock['handle']);
    }
}

function duitku_payment_row($trxId)
{
    return ORM::for_table('tbl_payment_gateway')
        ->where('gateway', 'duitku')
        ->where('id', (int)$trxId)
        ->find_one();
}

function duitku_payment_is_paid($trx)
{
    return $trx && ((string)$trx['status'] === '2' || !empty($trx['trx_invoice']));
}

function duitku_find_paid_invoice($trx, $user)
{
    $reference = trim((string)($trx['gateway_trx_id'] ?? ''));
    $method = 'duitku - ' . (string)($trx['payment_channel'] ?? '');
    $query = ORM::for_table('tbl_transactions')
        ->where('user_id', (int)($user['id'] ?? 0))
        ->where('method', $method)
        ->where_like('invoice', 'INV-%')
        ->order_by_desc('id');

    if ($reference !== '') {
        $byReference = clone $query;
        $invoice = $byReference->where('note', $reference)->find_one();
        if ($invoice) {
            return $invoice;
        }
    }

    $invoice = $query
        ->where('price', (int)($trx['price'] ?? 0))
        ->where('routers', (string)($trx['routers'] ?? ''))
        ->find_one();
    return $invoice ?: null;
}

function duitku_mark_paid_transaction($trx, $result, $invoice = '')
{
    if (!$trx) {
        return false;
    }
    if ((string)$invoice !== '' && empty($trx->trx_invoice)) {
        $trx->trx_invoice = (string)$invoice;
    }
    $trx->pg_paid_response = json_encode($result, JSON_UNESCAPED_SLASHES);
    $trx->paid_date = date('Y-m-d H:i:s');
    $trx->status = 2;
    $trx->save();
    return true;
}

function duitku_finish_paid_transaction($trx, $user, $result)
{
    $trxId = (int)($trx['id'] ?? 0);
    if ($trxId < 1) {
        return false;
    }

    $lock = duitku_acquire_payment_lock($trxId);
    if (!$lock) {
        $freshTrx = duitku_payment_row($trxId);
        return duitku_payment_is_paid($freshTrx);
    }

    try {
        $trx = duitku_payment_row($trxId);
        if (!$trx) {
            return false;
        }
        if (duitku_payment_is_paid($trx)) {
            return true;
        }

        if (class_exists('CustomerVoucherCatalog') && CustomerVoucherCatalog::isVoucherPayment($trx)) {
            $voucherError = '';
            return CustomerVoucherCatalog::markPaymentPaid($trx, $user, $result, $voucherError);
        }

        $existingInvoice = duitku_find_paid_invoice($trx, $user);
        if ($existingInvoice) {
            return duitku_mark_paid_transaction($trx, $result, (string)$existingInvoice['invoice']);
        }

        $note = (string)($trx['gateway_trx_id'] ?? '');
        $previousGlobalTrx = $GLOBALS['trx'] ?? null;
        $GLOBALS['trx'] = $trx;
        try {
            $invoice = Package::rechargeUser($user['id'], $trx['routers'], $trx['plan_id'], $trx['gateway'], $trx['payment_channel'], $note);
        } catch (Throwable $e) {
            $existingInvoice = duitku_find_paid_invoice($trx, $user);
            if ($existingInvoice) {
                return duitku_mark_paid_transaction($trx, $result, (string)$existingInvoice['invoice']);
            }
            if (class_exists('Message')) {
                Message::sendTelegram("Duitku payment activation failed\n\n" . $e->getMessage());
            }
            return false;
        } finally {
            if ($previousGlobalTrx !== null) {
                $GLOBALS['trx'] = $previousGlobalTrx;
            } else {
                unset($GLOBALS['trx']);
            }
        }

        if (!$invoice) {
            $existingInvoice = duitku_find_paid_invoice($trx, $user);
            if ($existingInvoice) {
                return duitku_mark_paid_transaction($trx, $result, (string)$existingInvoice['invoice']);
            }
            return false;
        }

        if (empty($trx->trx_invoice)) {
            $trx->trx_invoice = is_string($invoice) ? $invoice : '';
        }

        if (empty($trx->trx_invoice)) {
            $inv = ORM::for_table('tbl_transactions')
                ->where('user_id', (int)$user['id'])
                ->where('price', (int)$trx['price'])
                ->where('method', 'duitku - ' . $trx['payment_channel'])
                ->where_like('invoice', 'INV-%')
                ->order_by_desc('id')
                ->find_one();
            if ($inv) {
                $trx->trx_invoice = $inv['invoice'];
            }
        }

        return duitku_mark_paid_transaction($trx, $result, (string)$trx->trx_invoice);
    } finally {
        duitku_release_payment_lock($lock);
    }
}

function duitku_mark_failed_transaction($trx, $result)
{
    $trxId = (int)($trx['id'] ?? 0);
    if ($trxId < 1) {
        return false;
    }

    $lock = duitku_acquire_payment_lock($trxId);
    if (!$lock) {
        $freshTrx = duitku_payment_row($trxId);
        return duitku_payment_is_paid($freshTrx);
    }

    try {
        $trx = duitku_payment_row($trxId);
        if (!$trx) {
            return false;
        }
        if (duitku_payment_is_paid($trx)) {
            return true;
        }

        if (class_exists('CustomerVoucherCatalog') && CustomerVoucherCatalog::isVoucherPayment($trx)) {
            CustomerVoucherCatalog::markPaymentFailed($trx, CustomerVoucherCatalog::ORDER_FAILED);
            $trx = duitku_payment_row($trxId);
        }

        if ($trx && !duitku_payment_is_paid($trx)) {
            $trx->pg_paid_response = json_encode($result, JSON_UNESCAPED_SLASHES);
            $trx->status = 3;
            $trx->save();
        }
        return false;
    } finally {
        duitku_release_payment_lock($lock);
    }
}

function duitku_apply_status_result($trx, $user, $result)
{
    $statusUrl = duitku_payment_status_url($trx);
    if (empty($result['reference']) || (string)$result['reference'] !== (string)$trx['gateway_trx_id']) {
        Message::sendTelegram("Duitku payment status failed\n\n" . json_encode($result, JSON_PRETTY_PRINT));
        if (!duitku_no_redirect()) {
            r2($statusUrl, 'w', Lang::T("Payment check failed."));
        }
        return false;
    }

    $statusCode = (string)($result['statusCode'] ?? '');
    if ($statusCode === '01') {
        if (!duitku_no_redirect()) {
            r2($statusUrl, 'w', Lang::T("Transaction still unpaid."));
        }
        return false;
    }

    if ($statusCode === '00') {
        if (!duitku_finish_paid_transaction($trx, $user, $result)) {
            if (!duitku_no_redirect()) {
                r2($statusUrl, 'd', Lang::T("Failed to activate your Package, try again later."));
            }
            return false;
        }
        if (!duitku_no_redirect()) {
            r2($statusUrl, 's', Lang::T("Transaction has been paid."));
        }
        return true;
    }

    if ($statusCode === '02' && (string)$trx['status'] !== '2') {
        $alreadyPaid = duitku_mark_failed_transaction($trx, $result);
        if ($alreadyPaid) {
            if (!duitku_no_redirect()) {
                r2($statusUrl, 's', Lang::T("Transaction has been paid."));
            }
            return true;
        }
        if (!duitku_no_redirect()) {
            r2($statusUrl, 'd', Lang::T("Transaction expired or Failed."));
        }
        return false;
    }

    if ((string)$trx['status'] === '2' && !duitku_no_redirect()) {
        r2($statusUrl, 's', Lang::T("Transaction has been paid."));
    }

    return (string)$trx['status'] === '2';
}

function duitku_get_status($trx, $user)
{
    $settings = duitku_get_settings();
    $payload = [
        'merchantCode' => $settings['merchant_id'],
        'merchantOrderId' => (string)$trx['id'],
        'signature' => md5($settings['merchant_id'] . $trx['id'] . $settings['merchant_key']),
    ];
    $response = duitku_post_json(duitku_endpoint('transaction_status'), $payload);
    $result = $response['data'];
    return duitku_apply_status_result($trx, $user, $result);
}

function duitku_parse_callback_payload()
{
    $contentType = $_SERVER['CONTENT_TYPE'] ?? ($_SERVER['HTTP_CONTENT_TYPE'] ?? '');
    $raw = @file_get_contents('php://input');
    $post = $_POST;
    if (empty($post)) {
        if (stripos($contentType, 'application/json') !== false) {
            $post = json_decode($raw, true) ?: [];
        } else {
            parse_str($raw, $post);
        }
    }
    return is_array($post) ? $post : [];
}

function duitku_payment_notification()
{
    http_response_code(200);
    $settings = duitku_get_settings();
    $post = duitku_parse_callback_payload();

    $logOnce = function ($title, $data = []) {
        static $sent = false;
        if ($sent) {
            return;
        }
        $msg = "[DUITKU CB] " . $title . "\nTime: " . date('Y-m-d H:i:s');
        foreach ($data as $key => $value) {
            if (is_array($value) || is_object($value)) {
                $value = json_encode($value, JSON_UNESCAPED_SLASHES);
            }
            $msg .= "\n" . $key . ': ' . $value;
        }
        if (strlen($msg) > 3500) {
            $msg = substr($msg, 0, 3500) . '...(truncated)';
        }
        if (class_exists('Message')) {
            Message::sendTelegram($msg);
        }
        $sent = true;
    };

    foreach (['merchantCode', 'amount', 'merchantOrderId', 'resultCode', 'reference', 'signature'] as $field) {
        if (!isset($post[$field])) {
            $logOnce('Missing field', ['field' => $field]);
            echo 'OK';
            return;
        }
    }

    if ((string)$post['merchantCode'] !== $settings['merchant_id']) {
        $logOnce('Merchant mismatch', ['merchantCode' => $post['merchantCode']]);
        echo 'OK';
        return;
    }

    $expected = md5($post['merchantCode'] . $post['amount'] . $post['merchantOrderId'] . $settings['merchant_key']);
    if (strcasecmp($expected, (string)$post['signature']) !== 0) {
        $logOnce('Signature mismatch', ['merchantOrderId' => $post['merchantOrderId']]);
        echo 'OK';
        return;
    }

    $trx = ORM::for_table('tbl_payment_gateway')
        ->where('gateway', 'duitku')
        ->where('id', (int)$post['merchantOrderId'])
        ->order_by_desc('id')
        ->find_one();
    if (!$trx) {
        $logOnce('Transaction not found', ['merchantOrderId' => $post['merchantOrderId']]);
        echo 'OK';
        return;
    }

    if ((string)$trx['gateway_trx_id'] !== '' && (string)$trx['gateway_trx_id'] !== (string)$post['reference']) {
        $logOnce('Reference mismatch', ['trxId' => $trx['id'], 'reference' => $post['reference']]);
        echo 'OK';
        return;
    }

    if ((int)round((float)$trx['price']) !== (int)round((float)$post['amount'])) {
        $logOnce('Amount mismatch', ['trxId' => $trx['id'], 'amount' => $post['amount'], 'expected' => $trx['price']]);
        echo 'OK';
        return;
    }

    if ((string)$trx['status'] === '2' || !empty($trx['trx_invoice'])) {
        echo 'OK';
        return;
    }

    $user = !empty($trx['user_id'])
        ? ORM::for_table('tbl_customers')->find_one((int)$trx['user_id'])
        : ORM::for_table('tbl_customers')->where('username', $trx['username'])->find_one();
    if (!$user) {
        $logOnce('User not found', ['trxId' => $trx['id']]);
        echo 'OK';
        return;
    }

    if (!defined('IS_GATEWAY_CALLBACK')) {
        define('IS_GATEWAY_CALLBACK', true);
    }

    if ((string)$post['resultCode'] === '00') {
        $result = [
            'merchantOrderId' => (string)$post['merchantOrderId'],
            'reference' => (string)$post['reference'],
            'amount' => (string)$post['amount'],
            'statusCode' => '00',
            'statusMessage' => 'SUCCESS',
            'callback' => $post,
        ];
        if (!duitku_finish_paid_transaction($trx, $user, $result)) {
            $logOnce('Finishing did not mark paid', ['trxId' => $trx['id']]);
        }
        echo 'OK';
        return;
    }

    duitku_mark_failed_transaction($trx, ['callback' => $post]);
    echo 'OK';
}

function duitku_get_server()
{
    return str_replace('v2/inquiry', '', duitku_endpoint('v2_inquiry'));
}
