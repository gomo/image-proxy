<?php
class ImageProxy_Http
{
  private $_protocol;
  private $_server;
  private $_script_dir;
  private $_size_regex;
  private $_width_var;
  private $_height_var;

  public function __construct($script_path)
  {
    $this->_script_dir = dirname($script_path);
    include $this->_script_dir.'/config.php';
    foreach($settings as $key => $value)
    {
      $name = '_'.$key;
      $this->$name = $value;
    }
  }

  public function setServerProtocol($value)
  {
    $this->_protocol = $value;
    return $this;
  }

  public function execute()
  {
    $request_uri = $_SERVER['REQUEST_URI'];

    //etag生成
    $etag = md5($this->_protocol.'://'.$this->_server.$request_uri);

    //保存対象となる$web_pathを生成
    $web_path = null;
    for ($i=0; $i < strlen($this->_script_dir); $i++) 
    {
      $dir = substr($this->_script_dir, $i);
      if(strpos($request_uri, $dir) === 0)
      {
        $web_path = str_replace($dir, '', $request_uri);
        break;
      }
    }

    if(!$web_path)
    {
      $this->_reaponse404();
    }

    //オリジナルデータのパス
    $org_path = $web_path;

    //サイズの指定があるか
    $width = null;
    $height = null;
    $filename = basename($org_path);
    if(preg_match($this->_size_regex, $filename, $matches))
    {
      if(strtolower($matches[1]) == $this->_width_var)
      {
        $width = $matches[2];
      }
      else if(strtolower($matches[1]) == $this->_height_var)
      {
        $height = $matches[2];
      }

      //サイズ指定がある場合$org_pathから取り除く
      $filename = substr($filename, strlen($matches[0]));
      $org_path = dirname($org_path).'/'.$filename;
    }

    //オリジナルデータの取得
    $data = @file_get_contents($this->_protocol.'://'.$this->_server.$org_path);

    if($data)
    {
      //保存
      $save_path = $this->_script_dir.$web_path;
      list($data, $content_type) = $this->_save($data, $save_path, $width, $height);

      $interval = 604800;
      header( "Expires: " . gmdate( "D, d M Y H:i:s", time() + $interval ) . " GMT" );
      header( "Cache-Control: max-age=" . $interval);
      header( "Pragma: cache");
      header('Content-Type: '. $content_type);
      header('Etag: '.$etag);
      echo $data;
    }
    else
    {
      $this->_reaponse404();
    }
  }

  private function _save($data, $save_path, $width, $height)
  {
    $this->_mkdir(dirname($save_path));
    file_put_contents($save_path, $data);

    $size = getimagesize($save_path);
    list($raw_width, $raw_height,,) = $size;
    $content_type = $size['mime'];

    //リサイズ
    if($width || $height)
    {
      //拡大はしない
      if($raw_width < $width || $raw_height < $height)
      {
        unlink($save_path);
        $this->_reaponse404();
      }

      if(!$width)
      {
        $width = (int) ($raw_width * ($height / $raw_height));
      }
      else if(!$height)
      {
        $height = (int) ($raw_height * ($raw_width / $width)); 
      }

      if(preg_match('/\.gif$/u', $save_path))
      {
        $command = 'convert %s -coalesce -resize %dx%d -deconstruct %s';
      }
      else
      {
        $command = 'convert %s -resize %dx%d %s';
      }

      exec(sprintf($command, $save_path, $width, $height, $save_path));
      $data = file_get_contents($save_path);
    }

    return array($data, $content_type);
  }

  private function _reaponse404()
  {
    header("HTTP/1.0 404 Not Found");
    die();
  }

  private function _mkdir($path)
  {
    if(!file_exists($path))
    {
      //階層名を分割
      $dirs = explode('/', $path);
      
      $dir_path = '';
      
      //上の階層から順にディレクトリをチェック＆作成
      foreach($dirs as $dir)
      {
        $dir_path .= $dir . '/';

        //ディレクトリのパスをつないでチェック。無かったらフォルダ作成
        if(file_exists($dir_path))
        {
          continue;
        }

        mkdir($dir_path);
        chmod($dir_path, 0777);
      }
    }
  }
}

$ip = new ImageProxy_Http(__FILE__);
$ip->execute();