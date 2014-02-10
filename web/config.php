<?php

$settings = array(
  //画像サーバーのプロトコル
  'protocol' => 'http',
  //画像サーバーのドメイン
  'server' => 'img.salon-navi.me.s3.amazonaws.com',
  //ファイル名からリサイズ情報を取り出す正規表現。$matches[1]が'width_var'か'height_var'。$matches[2]が値（数字）
  'size_regex' => '/^(w|h)([0-9]+)_/u',
  'width_var' => 'w',
  'height_var' => 'h',
);