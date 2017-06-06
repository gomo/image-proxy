<?php
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

    //キャッシュファイルが異常なら
    if($image->isIllegalState())
    {
      //削除して処理を継続
      $image->delete();
    }

    //ローカルに画像が存在しなかった
    if(!file_exists($image->getSavePath()))
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
    
    $lifetime = time() - filemtime($image->getSavePath());

    touch($image->getSavePath());

    //時間が経っていなかった
    if($this->_getSetting('is_nocache') == false && $lifetime < $check_interval_sec)
    {
      if($this->_getSetting('is_debug')) ImageProxy_Http::message('Load image from local server because less than check_interval_sec.');
      $this->_responseLocal($image);
      return;
    }

    //この時点でローカルにキャッシュファイルがあって、なおかつチェックが必要な状態。
    $image->loadOnlyHeader();

    //リモートの元画像が無かった
    if(!$image->existsOnRemote())
    {
      $image->delete();
      $this->_response404();
      return;
    }

    //前回のリクエストに失敗して画像が無かった。
    if(!file_exists($image->getSavePath()))
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
      $image->delete();
      $this->_response404();
    }
  }

  private function _response(ImageProxy_Image $image)
  {
    $data = $image->getBody();
    if($data === null || !strlen($data))
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
}
