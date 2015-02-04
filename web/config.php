<?php

$settings = array(
  //元画像のオリジナルサーバーの設定。キーにないドメインは404が返されます。
  'server' => array(
    'bucket.s3.amazonaws.com' => array(
      'ip' => '127.0.0.1',     //省略可能。省略時はDNSを利用する
      'protocol' => 'http',    //省略可能。省略時は`http`
      'inherit' => 'default',  //他の設定を継承できる
    ),
  ),

  //元画像が消えてるかどうかチェックするインターバル。秒。省略すると元サーバーのチェックはしません。
  'check_interval_sec' => 10800,

  //ファイル名からリサイズ情報を取り出す正規表現。$matches[1]が'width_var'か'height_var'。$matches[2]が値（数字）
  //拡大はしません。false（に評価される値）を渡すとリサイズしません。
  'size_regex' => '/^(w|h)([0-9]{1,2}0)_/u',
  'width_var' => 'w',
  'height_var' => 'h',

  //スクリプトファイル`index.php`の階層から相対パスで、ここに指定したディレクトリを検索し、そこに画像をキャッシュします。
  //apacheユーザーから書き込みができないとエラーになります。
  'img_dir' => 'files',
);