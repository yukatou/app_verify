<?php

/**
 * 1.ユーザ認証
 **/
// 省略

/**
 * 2.レシート検証 
 **/
$receipt = $_POST['receipt']; // base64エンコードされているものがくる

// POSTするデータ
$params = json_encode(
  array('receipt-data' => $receipt
);

// 本番環境
$response = callRequest("https://buy.itunes.apple.com/verifyReceipt", $params);

// 21007が返ってきた場合はサンドボックスのアイテム
if ($response->status == 21007) {
  $response = callRequest("https://sandbox.itunes.apple.com/verifyReceipt", $params);
}

// 検証失敗
if ($response->status != 0) {
  error_handle(1000, 'レシート検証に失敗しました' $receipt, $response);
  exit;
}

/**
 * 3.検証が成功した場合のハート付与処理
 **/

// テーブルにないtransactionを新規に追加
foreach ($response->receipt->in_app as $key => $value) {

  // DBにないtransaction_idであれば新規に追加する
  $data = Model_Transaction::query->where('transaction_id' => $value->transaction_id);

  if ($data->conunt() == 0){
    DB::start_transaction();

    // 購入管理モデルに追加
    $transaction_id = $value->transaction_id;
    $model = new Model_Transaction();
    $model->response = json_encode($response);
    $model->transactionId = $transaction_id;
    $model->rawstatus = $response->status;
    $model->status = 0;//未完了
    $model->receipts = json_encode($value);
    $model->userId = $user_id;
    $model->productId = $value->product_id;
    $model->inappCnt = count($response->receipt->in_app);
    $model->save();

    DB::commit_transaction();
  }
}

// 各transaction
$transactions = array('transactions' => array());
foreach ($response->receipt->in_app as $key => $value) {

  $tran_id = $value->transaction_id;
  $product_id = $value->product_id;

  // テーブル上のstatus が 0（未完了） であるか確認
  $status = $this->chkStatus($tran_id);

  if ($status == 0) {

    try {

      \DB::start_transaction();

      $point;
      switch ($product_id) {
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

      // ユーザテーブルの課金ハートに加算
      \DB::update('ユーザテーブル')->where('id', '=', $user_id)
        ->set(array(
          'heart_charging' => \DB::expr('heart_charging + ' . $point)
        ))
        ->execute();

      // 課金完了なのでstatusを1に変更する
      $model = Model_Transaction::query->where('transaction_id' => $value->transaction_id)
      $model->status = 1;
      $model->save();

      // 成功したトランザクションを格納
      $transactions['transactions'][] = array(
        'product_id'     => $product_id,
        'transaction_id' => $tran_id,
      );

      \DB::commit_transaction();
    } catch(\Database_exception $e) {
      \DB::rollback_transaction();
    }
  }
}


if (count($trancastions['transactions']) === 0) {
  error_handle(1005, 'システムで問題がおきました', $receipt, $response);
  exit;
}

header('Content-Type: application/json');
echo json_encode($transactions);



function callRequest($url, $params)
{
  $ch  = curl_init();
  $url = "https://sandbox.itunes.apple.com/verifyReceipt";// サンドボックス（テスト用）
  curl_setopt($ch, CURLOPT_URL, $url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
  curl_setopt($ch, CURLOPT_POST, true);
  curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
  curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
  curl_setopt($ch, CURLOPT_HEADER, false);
  $response = json_decode(curl_exec($ch));

  // 失敗したらリトライ

  curl_close($ch);
  return $response;
}

/**
 * エラーハンドル
 */
function error_handle($code, $message, $receipt) {

  $json = base64_decode($receipt);

  $model = new Model_Transaction();
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
