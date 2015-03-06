<?php

$settings = array(
  'server' => array(
    //追加設定の何もないサーバー
    'www.plane.com' => array(),
    //追加設定のあるサーバー
    'foo' => array(
      'domain' => 'www.foo.com',
      'ip' => '127.0.0.1',
      'protocol' => 'https',
    ),
    //継承
    'child' => array(
      'inherit' => 'foo',
      'protocol' => 'http',
    ),
    //孫
    'grandchild' => array(
      'inherit' => 'child',
      'ip' => '0.0.0.0'
    ),
    //実際のリクエスト用
    'test.www.sincere-co.com' => array(),
  ),

  //元画像が更新されているかどうかチェックするインターバル。秒。省略すると元サーバーのチェックはしません。
  //レスポンスヘッダーの`Last-Modified`, `Content-Length`, `Content-Type`が比較されます。
  'check_interval_sec' => 3600,

  //ファイル名からリサイズ情報を取り出す正規表現。$matches[1]が'width_var'か'height_var'。$matches[2]が値（数字）
  //拡大はしません。false（に評価される値）を渡すとリサイズしません。
  'size_regex' => '/^(w|h)([0-9]{1,2}0)_/u',
  'width_var' => 'w',
  'height_var' => 'h',

  //スクリプトファイル`index.php`の階層から相対パスで、ここに指定したディレクトリを検索し、そこに画像をキャッシュします。
  //apacheユーザーから書き込みができないとエラーになります。
  'img_dir' => 'img',

  //trueの時画像を返さず、デバッグに有用な情報を返します。またPHPのエラーを出力します。
  'is_debug' => false,

  //ローカル画像を無視して、元サーバーに画像を取りに行き、ローカルの画像を更新します。
  'is_nocache' => false,

  //画像のレスポンスヘッダーを追加できます。
  'headers' => array(
    'Pragma' => 'cache',
    'Cache-Control' => 'max-age='.(60 * 60 * 24),
  ),
);