<?php

$settings = array(
  //画像サーバーのプロトコル
  'protocol' => 'http',
  
  //画像サーバーのドメイン。プロトコルを含めることも可能。その場合、`protocol`よりもこちらが優先します。
  'server' => 'bucket.s3.amazonaws.com',

  //サーバーのIPアドレス。省略するとDNSが利用されます。
  'ip' => '127.0.0.1', 

  //serverは配列を渡すことも可能。配列の場合、キーにないドメインは404が返されます。
  'server' => array(
    'bucket.s3.amazonaws.com' => array(
      'ip' => '127.0.0.1',     //省略可能。省略時はDNSを利用する
      'protocol' => 'http',    //省略可能。省略時は`http`
      'inherit' => 'default',  //他の設定を継承できる
    ),
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