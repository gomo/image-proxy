<?php
class ImageProxy_Image
{
  private $_save_path;

  private $_server_name;
  private $_org_path;
  private $_data_path;
  private $_width;
  private $_height;
  private $_server_values;
  private $_headers;
  private $_body;

  public function __construct($save_path, $settings)
  {
    $this->_save_path = $save_path;

    $org_path = $save_path;

    //サイズの指定があったら内部変数に設定しパスから取り除く
    $filename = basename($org_path);
    if(isset($settings['size_regex']) && preg_match($settings['size_regex'], $filename, $matches))
    {
      if(strtolower($matches[1]) == @$settings['width_var'])
      {
        $this->_width = $matches[2];
      }
      else if(strtolower($matches[1]) == @$settings['height_var'])
      {
        $this->_height = $matches[2];
      }

      //サイズ指定がある場合$org_pathから取り除く
      $filename = substr($filename, strlen($matches[0]));
      $org_path = dirname($org_path).'/'.$filename;
    }

    //元サーバーのタイムスタンプを保存するデータファイルのパス
    $data_path = $org_path.'.data';

    //元サーバーの/からのパスを生成する
    $tmp_path = substr($org_path, strlen('./'.$settings['img_dir']));

    //ドメイン部分を抽出
    $paths = explode('/', $tmp_path);
    $server_name = $paths[1];

    //オリジンパスを抽出
    $org_path = substr($tmp_path, strlen('/'.$server_name));

    $this->_server_name = $server_name;
    $this->_org_path = $org_path;
    $this->_data_path = $data_path;

    //設定を取得
    $this->_server_values = $this->_detectServerValues($settings['server'], $server_name);
  }

  public function existsOnLocal()
  {
    return file_exists($this->_save_path);
  }

  public function existsOnRemote()
  {
    if(!$this->_headers)
    {
      return false;
    }

    $status = $this->_getHeader('Status');
    if(!$status)
    {
      return false;
    }

    if(strpos($status, '404') !== false)
    {
      return false;
    }

    $content_length = $this->_getHeader('Content-Length');
    if($content_length === '0')
    {
      return false;
    }

    return true;
  }

  public function loadOnlyHeader()
  {
    $this->_org_path = $this->_org_path;
    $ch = $this->_createCurlHandler();
    curl_setopt($ch, CURLOPT_NOBODY, true); //headerのみ

    //リクエストする
    $header_str = @curl_exec($ch);
    $this->_headers = $this->_headerStringToArray($header_str);
  }

  public function loadFromLocal()
  {
    $this->_body = file_get_contents($this->_save_path);
  }

  public function loadFromRemote()
  {
    $ch = $this->_createCurlHandler();
    $resp = @curl_exec($ch);

    list($header_str, $this->_body) = explode("\r\n\r\n", $resp);
    $this->_headers = $this->_headerStringToArray($header_str);
  }

  public function getBody()
  {
    return $this->_body;
  }

  public function getSavePath()
  {
    return $this->_save_path;
  }

  public function getContentType()
  {
    $content_type = $this->_getHeader('Content-Type');
    if($content_type)
    {
      return $content_type;
    }

    if($this->existsOnLocal())
    {
      return image_type_to_mime_type(exif_imagetype($this->_save_path));
    }

    $this->loadOnlyHeader();
    return $this->_getHeader('Content-Type');
  }

  public function getLastModified()
  {
    $last_modified = $this->_getHeader('Last-Modified');
    if($last_modified)
    {
      return $last_modified;
    }

    if($this->existsOnLocal())
    {
      $filectime = filectime($this->_save_path);
      return date('r', $filectime);
    }

    $this->loadOnlyHeader();
    return $this->_getHeader('Last-Modified');
  }

  public function saveLocal()
  {
    $this->_mkdir(dirname($this->_save_path));
    file_put_contents($this->_save_path, $this->_body);
    chmod($this->_save_path, 0777);

    $size = getimagesize($this->_save_path);
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

        if(preg_match('/\.gif$/u', $this->_save_path))
        {
          $command = 'convert %s -coalesce -resize %dx%d -deconstruct %s';
        }
        else
        {
          $command = 'convert %s -resize %dx%d %s';
        }

        exec(sprintf($command, $this->_save_path, $this->_width, $this->_height, $this->_save_path));
        $need_reload = true;
      }
    }

    //ロスレス圧縮
    if($content_type == 'image/jpeg')
    {
      if(shell_exec('which jpegtran'))
      {
        $tmp_path = $this->_save_path.'tmp';
        exec(sprintf(
          'jpegtran -copy none -optimize -outfile %s %s && cp %s %s && rm %s',
          $tmp_path, $this->_save_path,
          $tmp_path, $this->_save_path,
          $tmp_path
        ), $out, $ret);
        $need_reload = true;
      }
    }
    else if($content_type == 'image/png')
    {
      if(shell_exec('which pngcrush'))
      {
        $tmp_path = $this->_save_path.'tmp';
        exec(sprintf(
          'pngcrush -l 9 -rem alla -reduce %s %s && cp %s %s && rm %s',
          $this->_save_path, $tmp_path,
          $tmp_path, $this->_save_path,
          $tmp_path
        ));
        $need_reload = true;
      }
    }
    else if($content_type == 'image/gif')
    {
      if(shell_exec('which gifsicle'))
      {
        $tmp_path = $this->_save_path.'tmp';
        exec(sprintf(
          'gifsicle -O2 %s > %s && cp %s %s && rm %s',
          $this->_save_path, $tmp_path,
          $tmp_path, $this->_save_path,
          $tmp_path
        ));
        $need_reload = true;
      }
    }

    if($need_reload)
    {
      $this->_body = file_get_contents($this->_save_path);
    }

    $this->_headers['Content-Type'] = $content_type;
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

  private function _headerStringToArray($header_str)
  {
    //headerを解析して配列にする
    $headers = array();
    foreach (explode("\r\n", $header_str) as $key => $line)
    {
      //空行が2行入っているので無駄なのでbreak
      if(!$line) break;

      //最初の行はステータスコード`:`はありません。
      if($key === 0)
      {
        $headers['Status'] = $line;
      }
      else
      {
        list($key, $value) = explode(': ', $line);
        $headers[$key] = $value;
      }
    }

    return $headers;
  }

  private function _getHeader($key, $default = null)
  {
    if(isset($this->_headers[$key]))
    {
      return $this->_headers[$key];
    }

    return $default;
  }

  /**
   * config.phpのserverから値を取得する
   */
  private function _getServerValue($key, $default = null)
  {
    if(isset($this->_server_values[$key]))
    {
      return $this->_server_values[$key];
    }

    return $default;
  }

  private function _createCurlHandler()
  {
    $ch = curl_init();

    curl_setopt($ch, CURLOPT_HEADER, true); //headerも取得
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);//curl_execでレスポンスを返す
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    //domain
    $domain = $this->_getServerValue('domain', $this->_server_name);

    //ipが設定してる時はheaderにHost:domainを指定してhttp://ipでアクセスする
    if($ip = $this->_getServerValue('ip'))
    {
      curl_setopt($ch, CURLOPT_HTTPHEADER, array('Host: '.$domain));
      $domain = $ip;
    }

    $url = $this->_getServerValue('protocol', 'http').'://'.$domain.$this->_org_path;
    curl_setopt($ch, CURLOPT_URL, $url);

    return $ch;
  }

  private function _detectServerValues($server, $server_name)
  {
    if(!isset($server[$server_name]))
    {
      throw new Exception('Missing server setting '.$server_name);
    }

    //継承の解決
    $values = $server[$server_name];
    if(isset($values['inherit']))
    {
      $values = array_merge($this->_detectServerValues($server, $values['inherit']), $values);
    }

    return $values;
  }
}

class ImageProxy_Http
{
  //config.phpから変数
  private $_settings;

  //内部変数
  private $_script_dir;
  private $_server_values;
  private $_width;
  private $_height;

  public function __construct($script_path)
  {
    $this->_script_dir = dirname($script_path);

    include 'config.php';
    $this->_settings = $settings;

    if(!is_writable('./'.$this->_settings['img_dir']))
    {
      throw new Exception('[./'.$this->_img_dir.'] is not writable.');
    }

    if($this->_getSetting('is_debug'))
    {
      ini_set('display_errors', 1);
      ini_set('error_reporting', E_ALL);
      if($this->_getSetting('is_nocache'))
      {
        $this->_echoStringLine('No cache mode enabled.');
      }
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
    if($_SERVER['QUERY_STRING'])
    {
      $request_uri = str_replace('?'.$_SERVER['QUERY_STRING'], '', $request_uri);
    }

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

  private function _echoStringLine()
  {
    $args = func_get_args();
    $first_arg = $args[0];
    unset($args[0]);

    if($args)
    {
      $value = vsprintf($first_arg, $args);
    }
    else
    {
      $value = $first_arg;
    }

    echo $value.' <br>'.PHP_EOL;
  }

  private function _getSetting($name, $default = null)
  {
    if(isset($this->_settings[$name]))
    {
      return $this->_settings[$name];
    }

    return $default;
  }

  /**
   * メインのエントリーメソッド。ここが起動されます。
   */
  public function execute()
  {
    //ファイルの保存パス
    $save_path = $this->_detectSavePath($_SERVER['REQUEST_URI']);

    if($this->_getSetting('is_debug'))
    {
      $this->_echoStringLine('Save path: %s', $save_path);
    }

    $image = new ImageProxy_Image($save_path, $this->_settings);

    //domainはServerValueのdomain、省略されていたらserver_name
    // $domain = $this->_getServerValue('domain', $server_name);
    // if($this->_getSetting('is_debug')) $this->_echoStringLine('Domain: %s', $domain);


    //ファイルがローカルに存在したらそれを返す
    if($this->_getSetting('is_nocache') == false && $image->existsOnLocal())
    {
      //check_interval_secより時間が立っていたら元サーバーに画像の存在を確認する
      //元サーバーの画像が`404 Not Found`を返したら404にする
      $check_interval_sec = $this->_getSetting('check_interval_sec');
      if($check_interval_sec !== null)
      {
        $lifetime = time() - filemtime($image->getSavePath());
        if($lifetime >  $check_interval_sec)
        {
          $image->loadOnlyHeader();

          //元画像がなかった
          if(!$image->existsOnRemote())
          {
            unlink($image->getSavePath());

            if($this->_getSetting('is_debug'))
            {
              $this->_echoStringLine('404: Origin file is not exists.');
            }
            else
            {
              header("HTTP/1.0 404 Not Found");
            }

            return;
          }
          else //元画像が存在した
          {
            touch($image->getSavePath());
          }
        }
      }

      //HTTP_IF_MODIFIED_SINCEが来ていたらブラウザキャシュがあるはずなので304
      if(isset($_SERVER["HTTP_IF_MODIFIED_SINCE"])){
        header("HTTP/1.1 304 Not Modified");
        return;
      }

      //ローカルから画像を読み込む
      $image->loadFromLocal();
      $this->_response($image);

      if($this->_getSetting('is_debug')) $this->_echoStringLine('Loaded image from local server.');

      return;
    }

    //元サーバーから画像を読み込む
    $image->loadFromRemote();
    if(!$image->getBody())
    {
      if($this->_getSetting('is_nocache') && file_exists($save_path))
      {
        //nocacheモードの時はここでファイルが存在することがあるので消しておく。
        unlink($save_path);
      }

      if($this->_getSetting('is_debug'))
      {
        $this->_echoStringLine('404: Fail to load image from origin.');
      }
      else
      {
        header("HTTP/1.0 404 Not Found");
      }

      return;
    }

    //保存
    $image->saveLocal();
    $this->_response($image);
  }

  private function _response(ImageProxy_Image $image)
  {
    if($this->_getSetting('is_debug'))
    {
      $this->_echoStringLine('Response Content-Type: %s', $image->getContentType());
      $this->_echoStringLine('Response Content-Length: %s', strlen($image->getBody()));
      $this->_echoStringLine('Response Last-Modified: %s', $image->getLastModified());
    }
    else
    {
      $data = $image->getBody();
      header('Content-Type: '. $image->getContentType());
      header('Content-Length: '. strlen($data));
      header("Last-Modified: " . $image->getLastModified());

      echo $data;
    }
  }
}

$ip = new ImageProxy_Http($_SERVER['SCRIPT_FILENAME']);
$ip->execute();