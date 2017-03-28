<?php

$path = isset($_POST["path"]) ?  $_POST["path"] : "";
if($path)
{
  include dirname($_SERVER['SCRIPT_FILENAME']).'/config.php';
  
  try 
  {
    if(strpos($path, "..") !== false)
    {
      throw new Exception("file_path is invalid");      
    }
    
    $file_path =  "./" . $settings["img_dir"] . "/" . $path;
    if(file_exists($file_path))
    {   
      $path_info = pathinfo($file_path);
        foreach (scandir($path_info["dirname"]) as $file)
        {
          if(strpos($file, $path_info["basename"]) !== false)
          {
            unlink($path_info["dirname"] . "/" . $file);
          }
        }
        $response = "DONE";
    }else {
      $response = "File Not Exists " . $path;
    }
  } catch (Exception $ex) {
    $response = "Failed to delete" . $path;
  }
}
else
{
  $response = "Missing File Path";  
}

echo $response;