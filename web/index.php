<?php
class ImageProxy_Http
{
  //config.phpから変数
  private $_server;
  private $_size_regex;
  private $_width_var;
  private $_height_var;
  private $_img_dir;

  //内部変数
  private $_script_dir;
  private $_server_settings;
  private $_width;
  private $_height;

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

  /**
   * 保存先の相対パスを作る
   *
   * 例えば
   * ドキュメントルートが/home/sites/www/expample.com/web
   * このスクリプトが/home/sites/www/expample.com/web/img/index.php
   * 画像の保存ディレクトリが/home/sites/www/expample.com/web/img/files
   * リクエストURLがhttp://www.expample.com/img/files/path/to/sample.jpgだったとして
   * 元画像のパスは/path/to/sample.jpg
   * 画像キャッシュ保存パスは相対パスで./files/path/to/sample.jpg
   * @param string $request_uri comment
   * @return string
   */
  private function _detectSavePath($request_uri)
  {
    $save_path = null;
    for ($i=0; $i < strlen($this->_script_dir); $i++) 
    {
      // /home/sites/www/expample.com/web/img
      //                                 /img/files/path/to/sample.jpg
      //上を左側から削っていき、下の頭と一致した時点で、その部分を下のパスから取り除く
      //例の場合`/files/path/to/sample.jpg`が取得される
      //つまり、このスクリプトからの相対パスを作る。
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

    return '.'.$save_path;
  }

  /**
   * 保存パスから元画像のドメインとパスを抽出する。
   * サイズ指定があったらファイル名から取り除きメンバー変数に保存する。
   * @return array($domain, $origin_path) list($domain, $origin_path)で受け取ると便利
   */
  private function _detectOriginPath($save_path)
  {
    $tmp_path = substr($save_path, strlen('./'.$this->_img_dir));

    //ドメイン部分を抽出
    $paths = explode('/', $tmp_path);
    $domain = $paths[1];

    //オリジンパスを抽出
    $org_path = substr($tmp_path, strlen('/'.$domain));

    //サイズの指定があったら内部変数に設定しパスから取り除く
    $filename = basename($org_path);
    if($this->_size_regex && preg_match($this->_size_regex, $filename, $matches))
    {
      if(strtolower($matches[1]) == $this->_width_var)
      {
        $this->_width = $matches[2];
      }
      else if(strtolower($matches[1]) == $this->_height_var)
      {
        $this->_height = $matches[2];
      }

      //サイズ指定がある場合$org_pathから取り除く
      $filename = substr($filename, strlen($matches[0]));
      $org_path = dirname($org_path).'/'.$filename;
    }

    return array($domain, $org_path);
  }

  /**
   * 元画像サーバーの設定をメンバー変数に読み込む
   * inheritの解決はここでします。
   * @return bool 設定がなかった場合false
   */
  private function _loadServerValues($domain)
  {
    if(!isset($this->_server[$domain]))
    {
      return false;
    }

    //設定を取得
    $settings = $this->_server[$domain];
    if(isset($settings['inherit']))
    {
      if(!isset($this->_server[$settings['inherit']]))
      {
        throw new Exception('Missing server setting '.$settings['inherit']);
      }

      $settings = array_merge($this->_server[$settings['inherit']], $settings);
    }

    $this->_server_settings = $settings;

    return true;
  }

  /**
   * config.phpの$setting['server']から値を取得する
   * _loadServerValuesを事前に呼んでおく必要がある。
   */
  private function _getServerValue($key, $default = null)
  {
    if(isset($this->_server_settings[$key]))
    {
      return $this->_server_settings[$key];
    }

    return $default;
  }

  /**
   * 画像データを元サーバーから読み込む
   */
  private function _loadImageFromServer($domain, $org_path)
  {
    $context = null;
    if($ip = $this->_getServerValue('ip'))
    {
      $opts = array(
        'http' => array(
          'header' => 'Host: '.$domain."\r\n",
        )
      );

      $context = stream_context_create($opts);
      $domain = $ip;
    }
    
    return file_get_contents($this->_getServerValue('protocol', 'http').'://'.$domain.$org_path, false, $context);
  }

  /**
   * メインのエントリーメソッド。ここが起動されます。
   */
  public function execute()
  {
    //ファイルの保存パス
    $save_path = $this->_detectSavePath($_SERVER['REQUEST_URI']);

    //オリジナルデータのパスとドメイン
    list($domain, $org_path) = $this->_detectOriginPath($save_path);

    if(!$this->_loadServerValues($domain))
    {
      header("HTTP/1.0 404 Not Found");
      return;
    }

    //元サーバーから画像を読み込む
    $data = $this->_loadImageFromServer($domain, $org_path);
    if(!$data)
    {
      header("HTTP/1.0 404 Not Found");
      return;
    }

    //保存
    list($data, $content_type) = $this->_save($data, $save_path);

    header('Content-Type: '. $content_type);
    header('Content-Length: '. strlen($data));
    echo $data;
  }

  private function _save($data, $save_path)
  {
    $this->_mkdir(dirname($save_path));
    file_put_contents($save_path, $data);
    chmod($save_path, 0777);

    $size = getimagesize($save_path);
    list($raw_width, $raw_height,,) = $size;
    $content_type = $size['mime'];

    $need_reload = false;
    //リサイズ
    if($this->_width || $this->_height)
    {
      //拡大はしない
      if($raw_width > $this->_width && $raw_height > $this->_height)
      {
        if(!$this->_width)
        {
          $this->_width = (int) ($raw_width * ($this->_height / $raw_height));
        }
        else if(!$this->_height)
        {
          $this->_height = (int) ($raw_height * ($raw_width / $this->_width)); 
        }

        if(preg_match('/\.gif$/u', $save_path))
        {
          $command = 'convert %s -coalesce -resize %dx%d -deconstruct %s';
        }
        else
        {
          $command = 'convert %s -resize %dx%d %s';
        }

        exec(sprintf($command, $save_path, $this->_width, $this->_height, $save_path));
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
    else if($content_type == 'image/png')
    {
      if(shell_exec('which pngcrush'))
      {
        $tmp_path = $save_path.'tmp';
        exec(sprintf(
          'pngcrush -l 9 -rem alla -reduce %s %s && cp %s %s && rm %s',
          $save_path, $tmp_path,
          $tmp_path, $save_path,
          $tmp_path
        ));
        $need_reload = true;
      }
    }
    else if($content_type == 'image/gif')
    {
      if(shell_exec('which gifsicle'))
      {
        $tmp_path = $save_path.'tmp';
        exec(sprintf(
          'gifsicle -O2 %s > %s && cp %s %s && rm %s',
          $save_path, $tmp_path,
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