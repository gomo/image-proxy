<?php
require_once dirname(__FILE__) . '/ImageProxy/Image.php';
require_once dirname(__FILE__) . '/ImageProxy/Http.php';
require_once dirname(__FILE__) . '/ImageProxy/ExceptionHandler.php';
require_once dirname(__FILE__) . '/ImageProxy/Image/Data.php';

set_exception_handler('ImageProxy_ExceptionHandler');

// 起動スクリプト
if(!isset($_SERVER['ImageProxy_Test']))//テスト時はIncludeのみで実行しません。
{
  $ip = new ImageProxy_Http($_SERVER['SCRIPT_FILENAME']);
  $ip->execute($ip->createImage($_SERVER['REQUEST_URI']));
  $ip->response();
}
