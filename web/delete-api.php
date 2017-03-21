<?php

if(isset($_POST["path"]))
{
  include dirname($_SERVER['SCRIPT_FILENAME']).'/config.php';
  $file_path =  "./" . $settings["img_dir"] . "/" . $_POST["path"];
  
  if(file_exists($file_path))
  {   
    $path_info = pathinfo($file_path);
    try 
    {
      //リサイズされた画像を含めファイル名が含まれているものを全て削除
      foreach (scandir($path_info["dirname"]) as $file)
      {
        if(strpos($file, $path_info["basename"]) !== false)
        {
          unlink($path_info["dirname"] . "/" . $file);
          echo $path_info["dirname"] . "/" . $file;
        }
      }      
      $response = "DONE";
    } catch (Exception $ex) {
      $response = "Failed ". $ex->getMessage();  
    }
  }else {
    $response = "File Not Exists " . $file_path;
  }
  
  echo $response;
}