<?php


/**
 * PHP Mikrotik Billing (https://github.com/hotspotbilling/phpnuxbill/)
 *
 * Payment Gateway duitku.com
 **/

function duitku_validate_config()
{
    global $config;
    if (empty($config['duitku_merchant_key']) || empty($config['duitku_merchant_id'])) {
        Message::sendTelegram("Duitku payment gateway not configured");
        r2(U . 'order/package', 'w', Lang::T("Admin has not yet setup Duitku payment gateway, please tell admin"));
    }
}

function duitku_show_config()
{
    global $ui, $config;
    $ui->assign('_title', 'Duitku - Payment Gateway');
    $ui->assign('channels', json_decode(file_get_contents('system/paymentgateway/channel_duitku.json'), true));
    $ui->display('duitku.tpl');
}

function duitku_save_config()
{
    global $admin;
    $duitku_merchant_id = _post('duitku_merchant_id');
    $duitku_merchant_key = _post('duitku_merchant_key');
    $d = ORM::for_table('tbl_appconfig')->where('setting', 'duitku_merchant_id')->find_one();
    if ($d) {
        $d->value = $duitku_merchant_id;
        $d->save();
    } else {
        $d = ORM::for_table('tbl_appconfig')->create();
        $d->setting = 'duitku_merchant_id';
        $d->value = $duitku_merchant_id;
        $d->save();
    }
    $d = ORM::for_table('tbl_appconfig')->where('setting', 'duitku_merchant_key')->find_one();
    if ($d) {
        $d->value = $duitku_merchant_key;
        $d->save();
    } else {
        $d = ORM::for_table('tbl_appconfig')->create();
        $d->setting = 'duitku_merchant_key';
        $d->value = $duitku_merchant_key;
        $d->save();
    }
    $d = ORM::for_table('tbl_appconfig')->where('setting', 'duitku_channel')->find_one();
    if ($d) {
        $d->value = implode(',', $_POST['duitku_channel']);
        $d->save();
    } else {
        $d = ORM::for_table('tbl_appconfig')->create();
        $d->setting = 'duitku_channel';
        $d->value = implode(',', $_POST['duitku_channel']);
        $d->save();
    }
    _log('[' . $admin['username'] . ']: Duitku ' . Lang::T('Settings_Saved_Successfully'), 'Admin', $admin['id']);
    r2(U . 'paymentgateway/duitku', 's', Lang::T('Settings_Saved_Successfully'));
}

function duitku_create_transaction($trx, $user)
{
    global $config, $routes, $ui;

    $channels = json_decode(file_get_contents('system/paymentgateway/channel_duitku.json'), true);
    if (!in_array($routes[4], explode(",", $config['duitku_channel']))) {
        $ui->assign('_title', 'Duitku Channel');
        $ui->assign('channels', $channels);
        $ui->assign('duitku_channels', explode(",", $config['duitku_channel']));
        $ui->assign('path', $routes['2'] . '/' . $routes['3']);
        $ui->display('duitku_channel.tpl');
        die();
    }

    $json = [
        'paymentMethod' => $routes[4],
        'paymentAmount' => $trx['price'],
        'merchantCode' => $config['duitku_merchant_id'],
        'merchantOrderId' => $trx['id'],
        'productDetails' => $trx['plan_name'],
        'merchantUserInfo' =>  $user['fullname'],
        'customerVaName' =>  $user['fullname'],
        'email' => (empty($user['email'])) ? $user['username'] . '@' . $_SERVER['HTTP_HOST'] : $user['email'],
        'phoneNumber' => $user['phonenumber'],
        'itemDetails' => [
            [
                'name' => $trx['plan_name'],
                'price' => $trx['price'],
                'quantity' => 1
            ]
        ],
        'returnUrl' => U . 'order/view/' . $trx['id'] . '/check',
        'signature' => md5($config['duitku_merchant_id'] . $trx['id'] . $trx['price'] . $config['duitku_merchant_key'])
    ];

    $result = json_decode(Http::postJsonData(duitku_get_server() . 'v2/inquiry', $json), true);

    if (empty($result['paymentUrl'])) {
        Message::sendTelegram("Duitku payment failed\n\n" . json_encode($result, JSON_PRETTY_PRINT));
        r2(U . 'order/package', 'e', Lang::T("Failed to create transaction."));
    }
    $d = ORM::for_table('tbl_payment_gateway')
        ->where('username', $user['username'])
        ->where('status', 1)
        ->find_one();
    $d->gateway_trx_id = $result['reference'];
    $d->pg_url_payment = $result['paymentUrl'];
    $d->payment_method = $routes['4'];
    foreach ($channels as $channel) {
        if ($channel['id'] == $routes['4']) {
            $d->payment_channel = $channel['name'];
            break;
        }
    }
    $d->pg_request = json_encode($result);
    $d->expired_date = date('Y-m-d H:i:s', strtotime("+1 day"));
    $d->save();
    r2(U . "order/view/" . $d['id'], 's', Lang::T("Create Transaction Success"));
}

function duitku_get_status($trx, $user)
{
    global $config;
    $json = [
        'merchantCode' => $config['duitku_merchant_id'],
        'merchantOrderId' => $trx['id'],
        'signature' => md5($config['duitku_merchant_id'] . $trx['id'] . $config['duitku_merchant_key'])
    ];
    $result = json_decode(Http::postJsonData(duitku_get_server() . 'transactionStatus', $json), true);
    if ($result['reference'] != $trx['gateway_trx_id']) {
        Message::sendTelegram("Duitku payment status failed\n\n" . json_encode($result, JSON_PRETTY_PRINT));
        r2(U . "order/view/" . $trx['id'], 'w', Lang::T("Payment check failed."));
    }
    if ($result['statusCode'] == '01') {
        r2(U . "order/view/" . $trx['id'], 'w', Lang::T("Transaction still unpaid."));
    } else if ($result['statusCode'] == '00' && $trx['status'] != 2) {
        if (!Package::rechargeUser($user['id'], $trx['routers'], $trx['plan_id'], $trx['gateway'],  $trx['payment_channel'])) {
            r2(U . "order/view/" . $trx['id'], 'd', Lang::T("Failed to activate your Package, try again later."));
        }

    	// ngambil nomor invoice dr tbl_transactions
		$inv = null;
        $methodWant = 'duitku - ' . $trx['payment_channel'];
        $invQ = ORM::for_table('tbl_transactions')
            ->where('user_id', (int)$user['id'])
            ->where('price', (int)$trx['price'])
            ->where('method', $methodWant)
            ->where_like('invoice', 'INV-%')
            ->order_by_desc('id');
		$inv = $invQ->find_one();
		if ($inv && empty($trx->trx_invoice)) {
		    $trx->trx_invoice = $inv['invoice'];
		}

        $trx->pg_paid_response = json_encode($result);
        $trx->paid_date = date('Y-m-d H:i:s');
        $trx->status = 2;
        $trx->save();

        r2(U . "order/view/" . $trx['id'], 's', Lang::T("Transaction has been paid."));
    } else if ($result['statusCode'] == '02') {
        $trx->pg_paid_response = json_encode($result);
        $trx->status = 3;
        $trx->save();
        r2(U . "order/view/" . $trx['id'], 'd', Lang::T("Transaction expired or Failed."));
    } else if ($trx['status'] == 2) {
        r2(U . "order/view/" . $trx['id'], 'd', Lang::T("Transaction has been paid.."));
    }
}

// helper
function _r2_if_not_callback($url, $type, $msg) {
    if (defined('IS_GATEWAY_CALLBACK') && IS_GATEWAY_CALLBACK) {
        return; // jangan redirect/exit saat dari callback
    }
    r2($url, $type, $msg);
}

// callback
function duitku_payment_notification()
{
    http_response_code(200); // hindari retry dari Duitku

    // log 1x aja (cuman buat error/gating)
    $logOnce = function (string $title, array $data = []) {
        static $sent = false; if ($sent) return;
        foreach ($data as $k => $v) if (is_array($v) || is_object($v)) $data[$k] = json_encode($v, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $msg = "[DUITKU CB] {$title}\nTime: " . date('Y-m-d H:i:s');
        foreach ($data as $k => $v) $msg .= "\n{$k}: {$v}";
        if (strlen($msg) > 3500) $msg = substr($msg, 0, 3500).'…(truncated)';
        if (class_exists('Message')) Message::sendTelegram($msg);
        $sent = true;
    };

    // parse payload (x-www-form-urlencoded / JSON fallback)
    $ct  = $_SERVER['CONTENT_TYPE'] ?? ($_SERVER['HTTP_CONTENT_TYPE'] ?? '');
    $raw = @file_get_contents('php://input');
    $post = $_POST;
    if (empty($post)) {
        if (stripos($ct, 'application/json') !== false) $post = json_decode($raw, true) ?: [];
        else parse_str($raw, $post);
    }

    // minimal fields
    foreach (['merchantCode','amount','merchantOrderId','resultCode','signature'] as $k) {
        if (!isset($post[$k])) { $logOnce('Missing field', ['field'=>$k]); echo 'OK'; return; }
    }

    // validasi signature: md5(merchantCode + amount + merchantOrderId + apiKey)
    global $config;
    $apiKey = (string)($config['duitku_merchant_key'] ?? '');
    $expected = md5($post['merchantCode'].$post['amount'].$post['merchantOrderId'].$apiKey);
    if (strcasecmp($expected, (string)$post['signature']) !== 0) {
        $logOnce('Signature mismatch', ['merchantOrderId'=>$post['merchantOrderId']]);
        echo 'OK'; return;
    }

    // key reference -> gateway_trx_id
    $reference = (string)($post['reference'] ?? '');
    if ($reference === '') { $logOnce('Reference empty'); echo 'OK'; return; }

    // ngambil transaksi
    $trx = ORM::for_table('tbl_payment_gateway')
        ->where('gateway','duitku')
        ->where('gateway_trx_id',$reference)
        ->order_by_desc('id')
        ->find_one();
    if (!$trx) { $logOnce('Transaction not found', ['reference'=>$reference]); echo 'OK'; return; }

    // local gating: skip jika sudah paid / invoice sudah ada biar gak recharge berkali" waktu resend callback di pg (tanpa log)
    if ((string)$trx['status'] === '2' || (string)$trx['status'] === 'paid' || !empty($trx['trx_invoice'])) {
        echo 'OK'; return;
    }

    // ambil user (ORM)
    $user = null;
    if (!empty($trx['user_id'])) {
        $user = ORM::for_table('tbl_customers')->find_one((int)$trx['user_id']);
    } elseif (!empty($trx['username'])) {
        $user = ORM::for_table('tbl_customers')->where('username',$trx['username'])->find_one();
    }
    if (!$user) { $logOnce('User not found', ['user_id'=>$trx['user_id'], 'username'=>$trx['username']]); echo 'OK'; return; }

    // trigger finishing lewat helper existing (ORM object)
    if (function_exists('duitku_get_status')) {
        if (!defined('IS_GATEWAY_CALLBACK')) define('IS_GATEWAY_CALLBACK', true);
        try {
            ob_start();
            duitku_get_status($trx, $user);
            ob_end_clean();
        } catch (Throwable $e) {
            $logOnce('Finishing error', ['trxId'=>$trx['id'], 'err'=>$e->getMessage()]);
            echo 'OK'; return;
        }

        // cek hasil akhir: kalau belum berubah, kirim log kalau sukses, kayak biasa aja 
        $fresh = ORM::for_table('tbl_payment_gateway')->find_one($trx['id']);
        $ok = ($fresh && ((string)$fresh['status'] === '2' || !empty($fresh['trx_invoice'])));
        if (!$ok) { $logOnce('Finishing did not mark paid', ['trxId'=>$trx['id']]); }
    } else {
        $logOnce('Helper duitku_get_status not found');
    }
    echo 'OK';
}

function duitku_get_server()
{
    global $_app_stage;
    if ($_app_stage == 'Live') {
        return 'https://passport.duitku.com/webapi/api/merchant/';
    } else {
        return 'https://sandbox.duitku.com/webapi/api/merchant/';
    }
}
