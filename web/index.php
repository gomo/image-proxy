<?php
function ImageProxy_ExceptionHandler($e)
{
  ImageProxy_Http::message('');
  ImageProxy_Http::message('%s', get_class($e));
  ImageProxy_Http::message('%s', $e->getMessage());
  foreach(explode("\n", $e->getTraceAsString()) as $trace)
  {
    ImageProxy_Http::message('%s', $trace);
  }
}
set_exception_handler('ImageProxy_ExceptionHandler');
/////////////////////////////////////////////////////////////////////////////////////////////
/////////////////////////////////////////////////////////////////////////////////////////////
/**
 * 画像のヘッダー情報などを保存・取得するクラス
 * サイズ指定画像、元画像用別々に扱います。一回のリクエストで2つ生成します。
 *
 * @author Masamoto Miyata
 */
class ImageProxy_Image_Data
{
  private $_data_path;
  private $_loaded_path;
  private $_data;
  private $_updated_values = array();

  public function __construct($data_path)
  {
    $this->_data_path = $data_path;
  }

  private function _maybeLoad()
  {
    if($this->_data === null)
    {
      $this->_data = array();
      @include $this->_data_path;
      if(isset($data))
      {
        $this->_data = $data;
      }
    }
  }

  public function getPath()
  {
    return $this->_data_path;
  }

  public function save()
  {
    if($this->_updated_values)
    {
      $code = '<?php $data = '.var_export($this->_data, true).';';
      ImageProxy_Http::mkdir(dirname($this->_data_path));
      file_put_contents($this->_data_path, $code);
      chmod($this->_data_path, 0777);
    }
  }

  public function set($key, $value)
  {
    if($this->get($key) != $value)
    {
      $this->_updated_values[] = $key;
      $this->_data[$key] = $value;
    }
  }

  public function isUpdated($key)
  {
    return in_array($key, $this->_updated_values);
  }

  public function getUpdatedValues()
  {
    return $this->_updated_values;
  }

  public function get($key)
  {
    $this->_maybeLoad();
    if(isset($this->_data[$key]))
    {
      return $this->_data[$key];
    }
  }

  public function toArray()
  {
    $this->_maybeLoad();
    return $this->_data;
  }
}










/////////////////////////////////////////////////////////////////////////////////////////////
/////////////////////////////////////////////////////////////////////////////////////////////
/**
 * 画像を管理するクラス。サイズ指定画像、元画像両方一度に扱います。一回のリクエストで一つしか生成しません。
 * @author Masamoto Miyata
 */
class ImageProxy_Image
{
  private $_save_path;

  //サイズ変更のない元画像のローカル保存パス
  private $_org_save_path;

  //元サーバーのURLパス
  private $_org_path;

  //ImageProxy_Image_Data
  private $_data;
  private $_origin_data;

  //横幅のサイズ（縮小有りの場合のみ）
  private $_width;

  //高さのサイズ（縮小有りの場合のみ）
  private $_height;

  //config.php/settings/serverの値の配列
  private $_server_values;

  //元サーバーのレスポンスゲッダー
  private $_headers;

  //元サーバーにリクエストしたカウント（ユニットテスト用）
  private $_request_count = 0;
  //ヘッダーのみ取りに行ったカウント
  private $_header_request_count = 0;


  //画像本体
  private $_body;
  private $_is_debug = false;
  private $_is_nocache = false;

  /**
   * @param string $save_path ローカルのパス
   * @param array $settings config.phpの$settings
   */
  public function __construct($save_path, array $settings)
  {
    if(@$settings['is_debug'])
    {
      $this->_is_debug = true;
    }

    if(@$settings['is_nocache'])
    {
      $this->_is_nocache = true;
    }

    $this->_save_path = $save_path;

    $org_path = $save_path;

    //サイズの指定があったら内部変数に設定しパスから取り除く
    $filename = basename($org_path);
    if(isset($settings['size_regex']) && preg_match($settings['size_regex'], $filename, $matches))
    {
      if(strtolower($matches[1]) == @$settings['width_var'])
      {
        $this->_width = $matches[2];
        if($this->_is_debug) ImageProxy_Http::message('Width: %s', $this->_width);
      }
      else if(strtolower($matches[1]) == @$settings['height_var'])
      {
        $this->_height = $matches[2];
        if($this->_is_debug) ImageProxy_Http::message('Height: %s', $this->_height);
      }

      //サイズ指定がある場合$org_pathから取り除く
      $filename = substr($filename, strlen($matches[0]));
      $org_path = dirname($org_path).'/'.$filename;

      $this->_org_save_path = $org_path;
    }
    else
    {
      $this->_org_save_path = $this->_save_path;
    }


    //元サーバーのタイムスタンプを保存するデータファイルのパス
    $this->_data = new ImageProxy_Image_Data($this->_save_path.'.php');
    $this->_origin_data = new ImageProxy_Image_Data($this->_org_save_path.'.php');

    //元サーバーの/からのパスを生成する
    $tmp_path = substr($org_path, strlen('./'.$settings['img_dir']));

    //ドメイン部分を抽出
    $paths = explode('/', $tmp_path);
    $server_name = $paths[1];

    //オリジンパスを抽出
    $this->_org_path = substr($tmp_path, strlen('/'.$server_name));

    //設定を取得
    $this->_server_values = $this->_detectServerValues($settings['server'], $server_name);
    //ドメイン設定がなかったらキーがドメイン名に成る
    $this->_server_values['domain'] = $this->getServerValue('domain', $server_name);


    if($this->_is_debug)
    {
      ImageProxy_Http::message('Created ImageProxy_Image');
      foreach($this->_server_values as $key => $value)
      {
        ImageProxy_Http::message('Server %s: %s', $key, $value);
      }

      ImageProxy_Http::message('Remote domain: %s', $this->getServerValue('domain'));
      ImageProxy_Http::message('Origin: %s', $this->_org_path);
      ImageProxy_Http::message('Data path: %s', $this->_data->getPath());
      foreach($this->_data->toArray() as $key => $value)
      {
        ImageProxy_Http::message('Data %s: %s', $key, $value);
      }
    }
  }

  public function changeModifiedTime($time)
  {
    touch($this->_data->getPath(), $time);
    touch($this->_origin_data->getPath(), $time);
  }

  public function getDataPath()
  {
    return $this->_data->getPath();
  }

  public function getOriginDataPath()
  {
    return $this->_origin_data->getPath();
  }

  /**
   * リモートにファイルが有るかどうかを返す。
   * 事前に`loadOnlyHeader`か`loadFromRemote`を呼んで置かないと必ずfalseが帰ります。
   */
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
    $ch = $this->_createCurlHandler();
    curl_setopt($ch, CURLOPT_NOBODY, true); //headerのみ

    //リクエストする
    $header_str = @curl_exec($ch);
    ++$this->_header_request_count;

    if($this->_is_debug) ImageProxy_Http::message('Load only header');
    $this->_setHeaders($header_str);
  }

  public function loadFromOriginLocal()
  {
    if($this->_is_debug) ImageProxy_Http::message('Load from origin local');
    if(!file_exists($this->_org_save_path))
    {
      throw new Exception('Missing file '.$this->_org_save_path);
    }
    $this->_body = file_get_contents($this->_org_save_path);
  }

  public function loadFromLocal()
  {
    if($this->_is_debug) ImageProxy_Http::message('Load from local');
    if(!file_exists($this->_save_path))
    {
      throw new Exception('Missing file '.$this->_save_path);
    }
    $this->_body = file_get_contents($this->_save_path);
  }

  public function delete()
  {
    @unlink($this->_org_save_path);
    @unlink($this->_save_path);
  }

  public function loadFromRemote()
  {
    $ch = $this->_createCurlHandler();
    $resp = @curl_exec($ch);
    ++$this->_request_count;

    if($this->_is_debug) ImageProxy_Http::message('Load from remote');
    @list($header_str, $this->_body) = explode("\r\n\r\n", $resp);
    $this->_setHeaders($header_str);
  }

  public function getRequestCount()
  {
    return $this->_request_count;
  }

  /**
   * ヘッダーのみリクエストしたカウント。本体をリクエストした時は増えません。
   */
  public function getHeaderRequestCount()
  {
    return $this->_header_request_count;
  }

  public function needsUpdate()
  {
    if($this->_data->isUpdated('Content-Length'))
    {
      return true;
    }

    if($this->_data->isUpdated('Last-Modified'))
    {
      return true;
    }

    return false;
  }

  public function getBody()
  {
    return $this->_body;
  }

  public function getSavePath()
  {
    return $this->_save_path;
  }

  public function getOriginSavePath()
  {
    return $this->_org_save_path;
  }

  public function getContentType()
  {
    $content_type = $this->_getHeader('Content-Type');
    if($content_type)
    {
      return $content_type;
    }

    if(file_exists($this->_org_save_path))
    {
      return image_type_to_mime_type(exif_imagetype($this->_org_save_path));
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

    if(file_exists($this->_org_save_path))
    {
      $filectime = filectime($this->_org_save_path);
      return date('r', $filectime);
    }

    $this->loadOnlyHeader();
    return $this->_getHeader('Last-Modified');
  }

  public function saveLocal()
  {
    $need_reload = false;

    //ローカルのリサイズなし画像
    if(!file_exists($this->_org_save_path))
    {
      ImageProxy_Http::mkdir(dirname($this->_org_save_path));
      file_put_contents($this->_org_save_path, $this->_body);
      chmod($this->_org_save_path, 0777);

      if($this->_losslessCompress($this->_org_save_path))
      {
        $need_reload = true;
      }
    }

    //リサイズ
    if($this->_width || $this->_height)
    {
      list($raw_width, $raw_height,,) = getimagesize($this->_org_save_path);
      //拡大はしない
      if($raw_width < $this->_width || $raw_height < $this->_height)
      {
        if($this->_is_debug) ImageProxy_Http::message('Illgal size specified. '.$this->_width.'/'.$this->_height);
        //bodyをnullにすると強制的に404になります。
        $this->_body = null;
        return;
      }

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

      exec(sprintf($command, $this->_org_save_path, $this->_width, $this->_height, $this->_save_path));
      chmod($this->_save_path, 0777);
      $need_reload = true;
    }

    if($this->_losslessCompress($this->_save_path))
    {
      $need_reload = true;
    }

    if($need_reload)
    {
      $this->_body = file_get_contents($this->_save_path);
    }
  }

  private function _losslessCompress($path)
  {
    $content_type = $this->getContentType();
    //ロスレス圧縮
    if($content_type == 'image/jpeg')
    {
      if(shell_exec('which jpegtran'))
      {
        $tmp_path = $this->_save_path.'tmp';
        exec(sprintf(
          'jpegtran -copy none -optimize -outfile %s %s && cp %s %s && rm %s',
          $tmp_path, $path,
          $tmp_path, $path,
          $tmp_path
        ), $out, $ret);

        if($this->_is_debug) ImageProxy_Http::message('jpegtran return '.$ret);

        return true;
      }
    }
    else if($content_type == 'image/png')
    {
      if(shell_exec('which pngcrush'))
      {
        $tmp_path = $path.'tmp';
        exec(sprintf(
          'pngcrush -l 9 -rem alla -reduce %s %s && cp %s %s && rm %s',
          $path, $tmp_path,
          $tmp_path, $path,
          $tmp_path
        ), $out, $ret);

        if($this->_is_debug) ImageProxy_Http::message('pngcrush return '.$ret);

        return true;
      }
    }
    else if($content_type == 'image/gif')
    {
      if(shell_exec('which gifsicle'))
      {
        $tmp_path = $path.'tmp';
        exec(sprintf(
          'gifsicle -O2 %s > %s && cp %s %s && rm %s',
          $path, $tmp_path,
          $tmp_path, $path,
          $tmp_path
        ), $out, $ret);

        if($this->_is_debug) ImageProxy_Http::message('gifsicle return '.$ret);

        return true;
      }
    }
  }

  private function _setHeaders($header_str)
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

    //dataに保存するヘッダー
    foreach(array('Last-Modified', 'Content-Length', 'Content-Type') as $header_key)
    {
      if(isset($headers[$header_key]))
      {
        $this->_data->set($header_key, $headers[$header_key]);
        $this->_origin_data->set($header_key, $headers[$header_key]);
      }
    }

    $this->_data->save();
    $this->_origin_data->save();

    $this->_headers = $headers;

    //元サーバーが404で画像ファイルがあったら消す。データファイルは残しておく。
    if(!$this->existsOnRemote())
    {
      foreach(array($this->_save_path, $this->_org_save_path) as $file)
      {
        @unlink($file);
      }
    }

    if($this->_is_debug)
    {
      foreach($headers as $key => $value)
      {
        ImageProxy_Http::message('Header %s: %s', $key, $value);
      }

      foreach($this->_data->getUpdatedValues() as $key)
      {
        ImageProxy_Http::message('Data updated %s', $key);
      }

      foreach($this->_origin_data->getUpdatedValues() as $key)
      {
        ImageProxy_Http::message('Origin Data updated %s', $key);
      }
    }
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
  public function getServerValue($key, $default = null)
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
    $domain = $this->getServerValue('domain');

    //ipが設定してる時はheaderにHost:domainを指定してhttp://ipでアクセスする
    if($ip = $this->getServerValue('ip'))
    {
      curl_setopt($ch, CURLOPT_HTTPHEADER, array('Host: '.$domain));
      if($this->_is_debug) ImageProxy_Http::message('Added header Host: %s to specify ip address', $domain);
      $domain = $ip;
    }

    $url = $this->getServerValue('protocol', 'http').'://'.$domain.$this->_org_path;
    curl_setopt($ch, CURLOPT_URL, $url);

    if($this->_is_debug) ImageProxy_Http::message('Created curl handle for %s', $url);
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










/////////////////////////////////////////////////////////////////////////////////////////////
/////////////////////////////////////////////////////////////////////////////////////////////
/**
 * Controllerクラス
 * `execute()`が起動されます。
 * @author Masamoto Miyata
 */
class ImageProxy_Http
{
  //config.phpから変数
  private $_settings;

  //内部変数
  private $_script_dir;
  private $_width;
  private $_height;
  private $_time_start;
  private $_response;

  public function __construct($script_path)
  {
    $this->_time_start = microtime(true);
    $this->_script_dir = dirname($script_path);
    chdir($this->_script_dir);

    include $this->_script_dir.'/'.'config.php';
    $this->_settings = $settings;

    if(!is_writable('./'.$this->_settings['img_dir']))
    {
      throw new Exception('[./'.$this->_settings['img_dir'].'] is not writable.');
    }

    if($this->_getSetting('is_debug'))
    {
      ini_set('display_errors', 1);
      ini_set('error_reporting', E_ALL);
      if($this->_getSetting('is_nocache'))
      {
        ImageProxy_Http::message('No cache mode enabled.');
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
  public function detectSavePath($request_uri)
  {
    if(!empty($_SERVER['QUERY_STRING']))
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

  public static function message()
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

  public function createImage($request_uri)
  {
    //ファイルの保存パス
    $save_path = $this->detectSavePath($request_uri);

    if($this->_getSetting('is_debug'))
    {
      ImageProxy_Http::message('Save path: %s', $save_path);
    }

    return new ImageProxy_Image($save_path, $this->_settings);
  }

  public function execute(ImageProxy_Image $image)
  {
    //レスポンスを初期化
    $this->_response = array(
      'headers' => array(),
      'body' => null,
    );

    //ローカルの画像が0byteなら
    if(file_exists($image->getDataPath()) && !strlen($image->getBody()))
    {
      $image->delete();
    }

    //ローカルに画像が存在しなかった
    if(!file_exists($image->getDataPath()))
    {
      //元画像が404だとこの時点で元画像データはあるが、元画像ファイルはないので、ここでチェックするのは`getOriginPath`
      if($this->_getSetting('is_nocache') == false && file_exists($image->getOriginSavePath()))
      {
        $image->loadOnlyHeader();
        $image->loadFromOriginLocal();
      }
      else
      {
        $image->loadFromRemote();
      }

      if(!$image->existsOnRemote())
      {
        $image->delete();//必要ないかもしれないけど念のため消しておく
        $this->_response404();
        return;
      }

      //保存
      $image->saveLocal();
      $this->_response($image);
      if($this->_getSetting('is_debug')) ImageProxy_Http::message('Loaded image from remote because no local file.');
      return;
    }

    //ローカルにデータファイルが有った。

    //check_interval_secより時間が立っていたら元サーバーに画像の存在を確認する
    //元サーバーの画像が`404 Not Found`を返したら404にする
    $check_interval_sec = $this->_getSetting('check_interval_sec');

    //check_interval_secがない場合は常にローカルを返す。
    if($this->_getSetting('is_nocache') == false && $check_interval_sec === null)
    {
      if($this->_getSetting('is_debug')) ImageProxy_Http::message('Load image from local because no check_interval_sec setting.');
      $this->_responseLocal($image);
      return;
    }

    //時間が経っていなかった
    $lifetime = time() - filemtime($image->getDataPath());
    if($this->_getSetting('is_nocache') == false && $lifetime < $check_interval_sec)
    {
      if($this->_getSetting('is_debug')) ImageProxy_Http::message('Load image from local server because less than check_interval_sec.');
      $this->_responseLocal($image);
      return;
    }



    //この時点でローカルにキャッシュファイルがあって、なおかつチェックが必要な状態。

    $image->loadOnlyHeader();
    //ヘッダーをチェックしたのでmtimeを更新。
    touch($image->getDataPath());

    //リモートの元画像が無かった
    if(!$image->existsOnRemote())
    {
      $image->delete();
      $this->_response404();
      return;
    }

    //元画像が更新されていた、あるいは、前回のリクエストに失敗して画像が無かった
    if($image->needsUpdate() || !file_exists($image->getSavePath()))
    {
      $image->delete();
      $image->loadFromRemote();
      if($this->_getSetting('is_debug')) ImageProxy_Http::message('Loaded image from remote because original image was updated.');
      $image->saveLocal();
      $this->_response($image);
      return;
    }

    //元サーバーの画像をチェックしたところ何も変わっていなかった。
    $this->_responseLocal($image);
    if($this->_getSetting('is_debug')) ImageProxy_Http::message('Loaded image local server.');
  }

  private function _response404()
  {
    $this->_response['headers'][] = "HTTP/1.0 404 Not Found";
  }

  private function _responseLocal(ImageProxy_Image $image)
  {
    if(file_exists($image->getSavePath()))
    {
      //ローカルから画像を読み込む
      $image->loadFromLocal();
      $this->_response($image);
    }
    else//前のリクエストが404だった
    {
      $this->_response404();
    }
  }

  private function _response(ImageProxy_Image $image)
  {
    $data = $image->getBody();
    if($data === null)
    {
      $image->delete();
      $this->_response404();
      return;
    }
    else
    {
      $this->_response['body'] = $data;
      $this->_response['headers'][] = 'Content-Type: '. $image->getContentType();
      $this->_response['headers'][] = 'Content-Length: '. strlen($data);
      $this->_response['headers'][] = "Last-Modified: " . $image->getLastModified();
    }
  }

  public function getResponseHeaders()
  {
    return $this->_response['headers'];
  }

  public function response()
  {
    foreach($this->_response['headers'] as $header)
    {
      if($this->_getSetting('is_debug'))
      {
        ImageProxy_Http::message('Response %s', $header);
      }
      else
      {
        header($header);
      }
    }

    if($this->_getSetting('is_debug'))
    {
      $time = microtime(true) - $this->_time_start;
      ImageProxy_Http::message('Time: %01.10f sec', $time);
    }
    else if($this->_response['body'])
    {
      echo $this->_response['body'];
    }
  }

  public static function mkdir($path)
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

  public function lock($target_path)
  {
    $path = $this->_getSetting('img_dir') . '/tempdir/' . md5($target_path);
    $wait = 0;
    while(!mkdir($path)){
      if($wait >= 8){
        $this->_response404();
        return FALSE;
      }
      $wait ++;
      sleep(1);
    }
    return TRUE;
  }

  public function unlock($target_path)
  {
    $path = $this->_getSetting('img_dir') . '/tempdir/' . md5($target_path);
    try{
      rmdir($path);
    }
    catch(Exception $e)
    {
      throw new Exception('unlock failed. '.$target_path);
    }
  }
}



/////////////////////////////////////////////////////////////////////////////////////////////
/////////////////////////////////////////////////////////////////////////////////////////////
// 起動スクリプト
if(!isset($_SERVER['ImageProxy_Test']))//テスト時はIncludeのみで実行しません。
{
  $ip = new ImageProxy_Http($_SERVER['SCRIPT_FILENAME']);
  if($ip->lock($_SERVER['REQUEST_URI']))
  {
    $ip->execute($ip->createImage($_SERVER['REQUEST_URI']));
  }
  $ip->response();
  $ip->unlock($_SERVER['REQUEST_URI']);
}
