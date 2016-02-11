<?php

/**
 * 1.ユーザ認証
 **/
// 省略

/**
 * 2.レシート検証 
 *
 * レシートの中身 
 * { 
 *  "orderId":"12999763169054705758.1371079406387615", 
 *  "packageName":"com.example.app",
 *  "productId":"exampleSku",
 *  "purchaseTime":1345678900000,
 *  "purchaseState":0,
 *  "developerPayload":"bGoa+V7g/yqDXvKRqq+JTFn4uQZbPiQJo4pf9RzJ",
 *  "purchaseToken":"rojeslcdyyiapnqcynkjyyjh"
 * }
 **/
$receipt   = $_POST['receipt']; // base64エンコードされているものがくる
$signature = $_POST['signature'];

// 2.1 GooglePlayの管理画面から取得した公開鍵をPEM形式に変換したもの
$public_key_path = "/path/to/file.pem";
$public_key      = file_get_contents($public_key_path);
$public_key_id   = openssl_get_publickey($public_key);

$decoded_signature = base64_decode($signature);
$result = (int)openssl_verify($receipt, $decoded_signature, $public_key_id);

if ($result === 0) {
  error_handle(1000, '署名が正しくありません');
  exit;
} elseif ($result === -1) {
  error_handle(1001, '署名の検証でエラーが発生しました');
  exit;
}

openssl_free_key($public_key_id);

// 2.2 uuidの確認
$json = base64_decode($receipt);
if ($user->uuid !== $json->developerPayload) {
  error_handle(1002, 'uuidが正しくありません');
  exit;
}

// 2.3 purchaseStateの確認 0 (purchased), 1 (canceled), or 2 (refunded)
if ($json->purchaseState != 0) {
  error_handle(1003, '購入ステータスが正しくありません');
  exit;
}

// 2.4 order_idの確認 既に付与済みであるかDBをみる
$model = Model_AndroidTransaction::query()
  ->where->('order_id' => $json->orderId)
  ->where->('user_id' => $user_id)
  ->where->('code' => 0);

if (count($model) > 0) {
  error_handle(1004, '既に付与済みです');
}

/**
 * 3.検証が成功した場合のハート付与処理
 **/
// 3.1 ポイントを取得
$point;
switch ($json->product_id) {
case self::GOLD_BOX_ID:
  $point = self::GOLD_BOX_POINT;
  break;

case self::SHILVER_BOX_ID:
  $point = self::SHILVER_BOX_POINT;
  break;

case self::BRONZE_BOX_ID:
  $point = self::BRONZE_BOX_POINT;
  break;
}

try {

  \DB::start_transaction();

  // 3.2 ユーザテーブルの課金ハートに加算
  \DB::update('ユーザテーブル')->where('id', '=', $user_id)
    ->set(array(
      'heart_charging' => \DB::expr('heart_charging + ' . $point)
    ))
    ->execute();

  // 3.3 最後にDBに追加
  $model = new Model_AndroidTransaction();
  $model->userId = $user_id;
  $model->orderId = $json->orderId;
  $model->receipt = $json;
  $model->code = 0; // 成功
  $model->save();

  \DB::commit_transaction();
} catch(\Database_exception $e) {
  \DB::rollback_transaction();
  error_handle(1005, "ハートの付与に失敗しました");
}

$response = array(
  'order_id' => $json->orderId,
  'product_id' => $json->productId,
);

header('Content-Type: application/json');
echo json_encode($response);

/**
 * エラーハンドル
 */
function error_handle($code, $message, $receipt) {

  $json = base64_decode($receipt);

  $model = new Model_AndroidTransaction();
  $model->userId = $user_id;
  $model->orderId = $json->orderId;
  $model->receipt = $json;
  $model->code = $code;
  $model->save();

  $response = array(
    'error' => array(
      'code' => $code,
      'message' => $message
    )
  );

  if ($code === 1005) {
    header('HTTP', true, 500);
  } else {
    header('HTTP', true, 400);
  }

  header('Content-Type: application/json');
  echo json_encode($response);
}
