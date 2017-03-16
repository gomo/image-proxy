<?php
require_once dirname(__FILE__) . '/ImageProxy/Image.php';
require_once dirname(__FILE__) . '/ImageProxy/Http.php';

if(isset($_POST["path"]))
{
  $file_path = '/home/source/sites/stand-alone/img.fuzoku-db.jp/web/ip/f/' . $_POST["path"];
  if(file_exists($file_path))
  {
    try {
      unlink($file_path);
      $response = "DONE";
    } catch (Exception $ex) {
      $response = "FAILED";
    }
  }else {
    $response = "File Not Exists " . $file_path;
  }
  
  echo $response;
}
else
{
  echo "bb";
}
