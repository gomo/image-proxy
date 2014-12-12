<?php
class ImageProxy_Http
{
  private $_protocol;
  private $_server;
  private $_script_dir;
  private $_size_regex;
  private $_width_var;
  private $_height_var;
  private $_img_dir;
  private $_cache_interval;

  public function __construct($script_path)
  {
    $this->_script_dir = dirname($script_path);

    include 'config.php';
    foreach($settings as $key => $value)
    {
      $name = '_'.$key;
      $this->$name = $value;
    }

    if(!is_writable('./'.$this->_img_dir))
    {
      throw new Exception('[./'.$this->_img_dir.'] is not writable.');
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
    //保存先の相対パスを作る
    //例えば
    //ドキュメントルートが/home/sites/www/expample.com/web
    //このスクリプトが/home/sites/www/expample.com/web/img/index.php
    //画像の保存ディレクトリが/home/sites/www/expample.com/web/img/files
    //リクエストURLがhttp://www.expample.com/img/files/path/to/sample.jpgだったとして
    //元画像のパスは/path/to/sample.jpg
    //画像キャッシュ保存パスは相対パスで./files/path/to/sample.jpg
    $save_path = null;
    for ($i=0; $i < strlen($this->_script_dir); $i++) 
    {
      $dir = substr($this->_script_dir, $i);
      if(strpos($request_uri, $dir) === 0)
      {
        $save_path = substr($request_uri, strlen($dir));
        break;
      }
    }

    if(!$save_path)
    {
      $save_path = $request_uri;
    }

    $save_path = '.'.$save_path;

    //オリジナルデータのパス
    $org_path = substr($save_path, strlen('./'.$this->_img_dir));

    if(is_array($this->_server))
    {
      //serverのキー
      $paths = explode('/', $org_path);
      $server_key = $paths[1];
      
      if(!isset($this->_server[$server_key]))
      {
        header("HTTP/1.0 404 Not Found");
        return;
      }

      $org_path = substr($org_path, strlen('/'.$server_key));
      $this->server = $this->_server[$server_key];
    }

    //protocolがseverに含まれてる場合
    if(preg_match('@^(https?)://([^/]+)@', $this->server, $matches))
    {
      $this->_protocol = $matches[1];
      $this->_server = $matches[2];
    }


    //サイズの指定があるか
    $width = null;
    $height = null;
    $filename = basename($org_path);

    if($this->_size_regex && preg_match($this->_size_regex, $filename, $matches))
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
      list($data, $content_type) = $this->_save($data, $save_path, $width, $height);

      header('Content-Type: '. $content_type);
      echo $data;
    }
    else
    {
      header("HTTP/1.0 404 Not Found");
    }
  }

  private function _save($data, $save_path, $width, $height)
  {
    $this->_mkdir(dirname($save_path));
    file_put_contents($save_path, $data);
    chmod($save_path, 0777);

    $size = getimagesize($save_path);
    list($raw_width, $raw_height,,) = $size;
    $content_type = $size['mime'];

    $need_reload = false;
    //リサイズ
    if($width || $height)
    {
      //拡大はしない
      if($raw_width > $width && $raw_height > $height)
      {
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
        $need_reload = true;
      }
    }


    //ロスレス圧縮
    if($content_type == 'image/jpeg')
    {
      if(shell_exec('which jpegtran'))
      {
        $tmp_path = $save_path.'tmp';
        exec(sprintf(
          'jpegtran -copy none -optimize -outfile %s %s && cp %s %s && rm %s',
          $tmp_path, $save_path,
          $tmp_path, $save_path,
          $tmp_path
        ));
        $need_reload = true;
      }
    }

    if($need_reload)
    {
      $data = file_get_contents($save_path);
    }



    return array($data, $content_type);
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

$ip = new ImageProxy_Http($_SERVER['SCRIPT_FILENAME']);
$ip->execute();