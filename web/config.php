<?php

$settings = array(
  //元画像のオリジナルサーバーの設定。キーにないドメインは404が返されます。
  'server' => array(
    'bucket.s3.amazonaws.com' => array(
      'ip' => '127.0.0.1',                   //省略可能。省略時はDNSを利用する
      'domain' => 'bucket.s3.amazonaws.com', //省略可能。省略時はキーが使用される。パス内にドメインを入れたくない時に使用します
      'protocol' => 'http',                  //省略可能。省略時は`http`
      'inherit' => 'default',                //他の設定を継承できる
    ),
  ),

  //元画像が更新されているかどうかチェックするインターバル。秒。省略すると元サーバーのチェックはしません。
  //レスポンスヘッダーの`Last-Modified`, `Content-Length`, `Content-Type`が比較されます。
  'check_interval_sec' => 10800,

  //ファイル名からリサイズ情報を取り出す正規表現。$matches[1]が'width_var'か'height_var'。$matches[2]が値（数字）
  //拡大はしません。false（に評価される値）を渡すとリサイズしません。
  'size_regex' => '/^(w|h)([0-9]{1,2}0)_/u',
  'width_var' => 'w',
  'height_var' => 'h',

  //スクリプトファイル`index.php`の階層から相対パスで、ここに指定したディレクトリを検索し、そこに画像をキャッシュします。
  //apacheユーザーから書き込みができないとエラーになります。
  'img_dir' => 'files',

  //trueの時画像を返さず、デバッグに有用な情報を返します。またPHPのエラーを出力します。
  'is_debug' => true,

  //ローカル画像を無視して、元サーバーに画像を取りに行き、ローカルの画像を更新します。
  'is_nocache' => true,

  //画像のレスポンスヘッダーを追加できます。
  'headers' => array(
    'Pragma' => 'cache',
    'Cache-Control' => 'max-age='.(60 * 60 * 24),
  ),
);