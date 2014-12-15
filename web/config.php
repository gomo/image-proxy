<?php

$settings = array(
  //画像サーバーのプロトコル
  'protocol' => 'http',
  
  //画像サーバーのドメイン。プロトコルを含めることも可能。その場合、`protocol`よりもこちらが優先します。
  'server' => 'bucket.s3.amazonaws.com',

  //serverは配列を渡すことも可能。
  'server' => array(
    's3' => array(
      'base' => 'http://127.0.0.1',
      'headers' => array(//省略可能。元画像リクエスト時にヘッダーに送られます
        'Host' => 'bucket.s3.amazonaws.com'
      ),
    ),
  ),

  //省略可能。元画像リクエスト時にヘッダーに送られます
  'headers' => array(
    'Host' => 'bucket.s3.amazonaws.com',
  ),

  //ファイル名からリサイズ情報を取り出す正規表現。$matches[1]が'width_var'か'height_var'。$matches[2]が値（数字）
  //拡大はしません。false（に評価される値）を渡すとリサイズしません。
  'size_regex' => '/^(w|h)([0-9]{1,2}0)_/u',
  'width_var' => 'w',
  'height_var' => 'h',

  //スクリプトファイル`index.php`の階層から相対パスで、ここに指定したディレクトリを検索し、そこに画像をキャッシュします。
  //apacheユーザーから書き込みができないとエラーになります。
  'img_dir' => 'files',
);