<?php
class ImageProxy_Http
{
  //config.phpから変数
  private $_server;
  private $_size_regex;
  private $_width_var;
  private $_height_var;
  private $_img_dir;
  private $_check_interval_sec;
  private $_headers;
  private $_is_debug = false;
  private $_is_nocache = false;

  //内部変数
  private $_script_dir;
  private $_server_values;
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

    if($this->_is_debug)
    {
      ini_set('display_errors', 1);
      ini_set('error_reporting', E_ALL);
      if($this->_is_nocache)
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

  /**
   * 保存パスから元画像のドメインとパスを抽出する。
   * サイズ指定があったらファイル名から取り除きメンバー変数に保存する。
   * @return array($domain, $origin_path, $data_path) list($domain, $origin_path, $data_path)で受け取ると便利
   */
  private function _detectOriginPath($save_path)
  {
    $org_path = $save_path;

    //サイズの指定があったら内部変数に設定しパスから取り除く
    $filename = basename($org_path);
    if($this->_size_regex && preg_match($this->_size_regex, $filename, $matches))
    {
      if(strtolower($matches[1]) == $this->_width_var)
      {
        $this->_width = $matches[2];
        if($this->_is_debug) $this->_echoStringLine('Width: %s', $this->_width);
      }
      else if(strtolower($matches[1]) == $this->_height_var)
      {
        $this->_height = $matches[2];
        if($this->_is_debug) $this->_echoStringLine('Height: %s', $this->_height);
      }

      //サイズ指定がある場合$org_pathから取り除く
      $filename = substr($filename, strlen($matches[0]));
      $org_path = dirname($org_path).'/'.$filename;
    }

    //元サーバーのタイムスタンプを保存するデータファイルのパス
    $data_path = $org_path.'.data';

    if($this->_is_debug) $this->_echoStringLine('Data file: %s', $data_path);

    //元サーバーの/からのパスを生成する
    $tmp_path = substr($org_path, strlen('./'.$this->_img_dir));

    //ドメイン部分を抽出
    $paths = explode('/', $tmp_path);
    $server_name = $paths[1];

    if($this->_is_debug)
    {
      $this->_echoStringLine('Server name: %s', $server_name);
    }

    //オリジンパスを抽出
    $org_path = substr($tmp_path, strlen('/'.$server_name));



    if($this->_is_debug) $this->_echoStringLine('Origin: %s', $org_path);

    return array($server_name, $org_path, $data_path);
  }

  /**
   * 元画像サーバーの設定をメンバー変数に読み込む
   * inheritの解決はここでします。
   * @return bool 設定がなかった場合false
   */
  private function _loadServerValues($server_name)
  {
    if(!isset($this->_server[$server_name]))
    {
      return false;
    }

    //設定を取得
    $values = $this->_server[$server_name];
    if(isset($values['inherit']))
    {
      if(!isset($this->_server[$values['inherit']]))
      {
        throw new Exception('Missing server setting '.$values['inherit']);
      }

      $values = array_merge($this->_server[$values['inherit']], $values);
    }

    $this->_server_values = $values;

    if($this->_is_debug)
    {
      foreach($this->_server_values as $key => $value)
      {
        $this->_echoStringLine('Server setting %s: %s', $key, $value);
      }
    }

    return true;
  }

  /**
   * config.phpの$setting['server']から値を取得する
   * _loadServerValuesを事前に呼んでおく必要がある。
   */
  private function _getServerValue($key, $default = null)
  {
    if(isset($this->_server_values[$key]))
    {
      return $this->_server_values[$key];
    }

    return $default;
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

    if($this->_is_debug)
    {
      foreach($headers as $key => $value)
      {
        $this->_echoStringLine('Header %s: %s', $key, $value);
      }
    }

    return $headers;
  }

  private function _createCurlHandler($domain, $org_path)
  {
    $ch = curl_init();

    curl_setopt($ch, CURLOPT_HEADER, true); //headerも取得
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);//curl_execでレスポンスを返す
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    //ipが設定してる時はheaderにHost:domainを指定してhttp://ipでアクセスする
    if($ip = $this->_getServerValue('ip'))
    {
      if($this->_is_debug) $this->_echoStringLine('Added header Host:%s to specify ip address', $domain);
      curl_setopt($ch, CURLOPT_HTTPHEADER, array('Host: '.$domain));
      $domain = $ip;
    }

    $url = $this->_getServerValue('protocol', 'http').'://'.$domain.$org_path;
    curl_setopt($ch, CURLOPT_URL, $url);

    if($this->_is_debug) $this->_echoStringLine('Created curl handler for %s', $url);

    return $ch;
  }

  /**
   * 画像データを元サーバーから読み込む
   */
  private function _loadImageFromServer($domain, $org_path)
  {
    $ch = $this->_createCurlHandler($domain, $org_path);
    $resp = @curl_exec($ch);
    if($this->_is_debug) $this->_echoStringLine('Loaded header and body');


    list($header_str, $body) = explode("\r\n\r\n", $resp);
    $headers = $this->_headerStringToArray($header_str);
    return $body;
  }

  /**
   * 元サーバーの画像が存在するかを返す。
   * curlを使ってヘッダーのみ取得します。
   */
  public function _existsFileInServer($domain, $org_path)
  {
    $ch = $this->_createCurlHandler($domain, $org_path);
    curl_setopt($ch, CURLOPT_NOBODY, true); //headerのみ

    //リクエストする
    $header_str = @curl_exec($ch);
    if($this->_is_debug) $this->_echoStringLine('Loaded only header');

    if(!$header_str)
    {
      return false;
    }

    //headerを解析して配列にする
    $headers = $this->_headerStringToArray($header_str);

    if(strpos($headers['Status'], '404 Not Found') !== false)
    {
      return false;
    }

    if(isset($headers['Content-Length']) && $headers['Content-Length'] === '0')
    {
      return false;
    }

    return true;
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

  /**
   * メインのエントリーメソッド。ここが起動されます。
   */
  public function execute()
  {
    //ファイルの保存パス
    $save_path = $this->_detectSavePath($_SERVER['REQUEST_URI']);

    if($this->_is_debug)
    {
      $this->_echoStringLine('Save path: %s', $save_path);
    }

    //オリジナルデータのパスとドメイン
    list($server_name, $org_path, $data_path) = $this->_detectOriginPath($save_path);

    if(!$this->_loadServerValues($server_name))
    {
      if($this->_is_debug)
      {
        $this->_echoStringLine('404: Missing server setting for %s', $server_name);
      }
      else
      {
        header("HTTP/1.0 404 Not Found");
      }

      return;
    }

    //domainはServerValueのdomain、省略されていたらserver_name
    $domain = $this->_getServerValue('domain', $server_name);
    if($this->_is_debug) $this->_echoStringLine('Domain: %s', $domain);


    //ファイルがローカルに存在したらそれを返す
    if($this->_is_nocache == false && file_exists($save_path))
    {
      //$this->_check_interval_secより時間が立っていたら元サーバーに画像の存在を確認する
      //元サーバーの画像が`404 Not Found`を返したら404にする
      if($this->_check_interval_sec !== null)
      {
        $lifetime = time() - filemtime($save_path);
        if($lifetime >  $this->_check_interval_sec)
        {
          //元画像がなかった
          if(!$this->_existsFileInServer($domain, $org_path))
          {
            unlink($save_path);

            if($this->_is_debug)
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
            touch($save_path);
          }
        }
      }

      //HTTP_IF_MODIFIED_SINCEが来ていたらブラウザキャシュがあるはずなので304
      if(isset($_SERVER["HTTP_IF_MODIFIED_SINCE"])){
        header("HTTP/1.1 304 Not Modified");
        return;
      }

      //ローカルから画像を読み込む
      $data = file_get_contents($save_path);
      $this->_response($data, image_type_to_mime_type(exif_imagetype($save_path)), filectime($save_path));

      if($this->_is_debug) $this->_echoStringLine('Loaded image from local server.');

      return;
    }

    //元サーバーから画像を読み込む
    $data = $this->_loadImageFromServer($domain, $org_path);
    if(!$data)
    {
      if($this->_is_nocache && file_exists($save_path))
      {
        //nocacheモードの時はここでファイルが存在することがあるので消しておく。
        unlink($save_path);
      }

      if($this->_is_debug)
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
    list($data, $content_type) = $this->_save($data, $save_path);

    $this->_response($data, $content_type, filectime($save_path));
  }

  private function _response($data, $content_type, $filectime)
  {
    if(!$this->_is_debug)
    {
      header('Content-Type: '. $content_type);
      header('Content-Length: '. strlen($data));
      header("Last-Modified: " . date('r', $filectime));
      if($this->_headers)
      {
        foreach($this->_headers as $key => $value)
        {
          header($key.': '.$value);
        }
      }

      echo $data;
    }
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