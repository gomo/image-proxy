<?php
/**
 * /home/source/sites/phpunit/phpunit.php tests/ImageProxy
 * @author Masamoto Miyata
 */
class ImageProxy extends PHPUnit_Framework_TestCase
{
  private $_test_dir;

  public function setUp()
  {
    $_SERVER['ImageProxy_Test'] = true;
    $this->_test_dir = dirname(__FILE__);
    include_once $this->_test_dir.'/../web/index.php';

    //生成したファイルをすべて消す
    foreach(scandir($this->_test_dir.'/img') as $dir)
    {
      if(in_array($dir, array('.', '..'))) continue;
      $full_path = $this->_test_dir.'/img/'.$dir;
      exec('rm -rf '.$full_path);
    }

    //テスト用画像サーバーの状態をリセット
    $resp = file_get_contents('http://test.www.sincere-co.com/image-proxy/util.php?func=resetAll');
    $this->assertSame('0', $resp);
  }

  public function testDetectSavePath()
  {
    $http = new ImageProxy_Http($this->_test_dir.'/index.php');
    $this->assertSame('./img/server/path/to/image.jpg', $http->detectSavePath('/img/server/path/to/image.jpg'));
    $this->assertSame('./img/img/image.jpg', $http->detectSavePath('/img/img/image.jpg'));
  }

  public function testImage()
  {
    $http = new ImageProxy_Http($this->_test_dir.'/index.php');

    //追加設定の何もないサーバーのテスト
    $image = $http->createImage('/img/www.plane.com/image-proxy/sample.jpg');
    $this->assertSame('www.plane.com', $image->getServerValue('domain'));
    $this->assertNull($image->getServerValue('ip'));

    //色々設定のあるサーバー
    $image = $http->createImage('/img/foo/image-proxy/sample.jpg');
    $this->assertSame('www.foo.com', $image->getServerValue('domain'));
    $this->assertSame('127.0.0.1', $image->getServerValue('ip'));
    $this->assertSame('https', $image->getServerValue('protocol'));

    //継承（子供）
    $image = $http->createImage('/img/child/image-proxy/sample.jpg');
    $this->assertSame('www.foo.com', $image->getServerValue('domain'));
    $this->assertSame('127.0.0.1', $image->getServerValue('ip'));
    $this->assertSame('http', $image->getServerValue('protocol'));

    //継承（孫）
    $image = $http->createImage('/img/grandchild/image-proxy/sample.jpg');
    $this->assertSame('www.foo.com', $image->getServerValue('domain'));
    $this->assertSame('0.0.0.0', $image->getServerValue('ip'));
    $this->assertSame('http', $image->getServerValue('protocol'));


    //サイズ変更ありのパステスト
    $image = $http->createImage('/img/foo/image-proxy/w120_sample.jpg');

    //save path
    $this->assertSame('./img/foo/image-proxy/w120_sample.jpg', $image->getSavePath());
    $this->assertSame('./img/foo/image-proxy/w120_sample.jpg.php', $image->getDataPath());

    //origin save path
    $this->assertSame('./img/foo/image-proxy/sample.jpg', $image->getOriginSavePath());
    $this->assertSame('./img/foo/image-proxy/sample.jpg.php', $image->getOriginDataPath());
  }

  public function testExecuteNonExistsImage()
  {
    $http = new ImageProxy_Http($this->_test_dir.'/index.php');

    //存在しない画像初回
    $image = $http->createImage('/img/test.www.sincere-co.com/image-proxy/img/non-exists.jpg');
    $http->execute($image);
    $this->assertSame('404 Not Found', $this->_getHeader($http, 'Status'));
    $this->assertSame(1, $image->getRequestCount());
    $this->assertSame(0, $image->getHeaderRequestCount());
    clearstatcache();

    //存在しない画像キャッシュから
    $image = $http->createImage('/img/test.www.sincere-co.com/image-proxy/img/non-exists.jpg');
    $http->execute($image);
    $this->assertSame('404 Not Found', $this->_getHeader($http, 'Status'));
    $this->assertSame(0, $image->getRequestCount());
    $this->assertSame(0, $image->getHeaderRequestCount());
    clearstatcache();

    //存在しない画像キャッシュから（少し経過）
    $http->switchCurrentTime(time() + 2000);
    $image = $http->createImage('/img/test.www.sincere-co.com/image-proxy/img/non-exists.jpg');
    $http->execute($image);
    $this->assertSame('404 Not Found', $this->_getHeader($http, 'Status'));
    $this->assertSame(0, $image->getRequestCount());
    $this->assertSame(0, $image->getHeaderRequestCount());
    clearstatcache();

    //存在しない画像 check_interval_sec経過後
    $http->switchCurrentTime(time() + 3700);
    $image = $http->createImage('/img/test.www.sincere-co.com/image-proxy/img/non-exists.jpg');
    $http->execute($image);
    $this->assertSame('404 Not Found', $this->_getHeader($http, 'Status'));
    $this->assertSame(0, $image->getRequestCount());
    $this->assertSame(1, $image->getHeaderRequestCount());
    clearstatcache();

    //存在しない画像キャッシュから（少し経過）
    $http->switchCurrentTime(time() + 4000);
    $image = $http->createImage('/img/test.www.sincere-co.com/image-proxy/img/non-exists.jpg');
    $http->execute($image);
    $this->assertSame('404 Not Found', $this->_getHeader($http, 'Status'));
    $this->assertSame(0, $image->getRequestCount());
    $this->assertSame(0, $image->getHeaderRequestCount());
    clearstatcache();

    //non-exists.jpgをサーバーに出現させる
    $resp = file_get_contents('http://test.www.sincere-co.com/image-proxy/util.php?func=nonExists');
    $this->assertSame('0', $resp);
    $http->switchCurrentTime(time() + 8000);
    $image = $http->createImage('/img/test.www.sincere-co.com/image-proxy/img/non-exists.jpg');
    $http->execute($image);
    $this->assertSame('image/jpeg', $this->_getHeader($http, 'Content-Type'));
    $this->assertSame('106228', $this->_getHeader($http, 'Content-Length'));
    $this->assertSame(1, $image->getRequestCount());
    $this->assertSame(1, $image->getHeaderRequestCount());//一度ヘッダーのみ問い合わせて更新を検出してるので
  }

  public function testExecuteJpegImage()
  {
    $http = new ImageProxy_Http($this->_test_dir.'/index.php');

    //初回
    $image = $http->createImage('/img/test.www.sincere-co.com/image-proxy/img/image.jpg');
    $http->execute($image);
    $this->assertSame('image/jpeg', $this->_getHeader($http, 'Content-Type'));
    $this->assertSame('106228', $this->_getHeader($http, 'Content-Length'));
    $this->assertSame(1, $image->getRequestCount());
    $this->assertSame(0, $image->getHeaderRequestCount());
    clearstatcache();

    //2回め　スグ
    $image = $http->createImage('/img/test.www.sincere-co.com/image-proxy/img/image.jpg');
    $http->execute($image);
    $this->assertSame('image/jpeg', $this->_getHeader($http, 'Content-Type'));
    $this->assertSame('106228', $this->_getHeader($http, 'Content-Length'));
    $this->assertSame(0, $image->getRequestCount());
    $this->assertSame(0, $image->getHeaderRequestCount());
    clearstatcache();

    //まだcheck_interval_secを過ぎていない（少し経過）
    $http->switchCurrentTime(time() + 2000);
    $image = $http->createImage('/img/test.www.sincere-co.com/image-proxy/img/image.jpg');
    $http->execute($image);
    $this->assertSame('image/jpeg', $this->_getHeader($http, 'Content-Type'));
    $this->assertSame('106228', $this->_getHeader($http, 'Content-Length'));
    $this->assertSame(0, $image->getRequestCount());
    $this->assertSame(0, $image->getHeaderRequestCount());
    clearstatcache();

    //check_interval_secを過ぎた
    $http->switchCurrentTime(time() + 3700);
    $image = $http->createImage('/img/test.www.sincere-co.com/image-proxy/img/image.jpg');
    $http->execute($image);
    $this->assertSame('image/jpeg', $this->_getHeader($http, 'Content-Type'));
    $this->assertSame('106228', $this->_getHeader($http, 'Content-Length'));
    $this->assertSame(0, $image->getRequestCount());
    $this->assertSame(1, $image->getHeaderRequestCount());
    clearstatcache();

    //画像が更新される（Last-Modifiedが変わる）
    $resp = file_get_contents('http://test.www.sincere-co.com/image-proxy/util.php?func=update&target=image.jpg');
    $this->assertSame('0', $resp);

    //でもまだcheck_interval_secを過ぎてない
    $http->switchCurrentTime(time() + 4000);
    $image = $http->createImage('/img/test.www.sincere-co.com/image-proxy/img/image.jpg');
    $http->execute($image);
    $this->assertSame('image/jpeg', $this->_getHeader($http, 'Content-Type'));
    $this->assertSame('106228', $this->_getHeader($http, 'Content-Length'));
    $this->assertSame(0, $image->getRequestCount());
    $this->assertSame(0, $image->getHeaderRequestCount());
    clearstatcache();

    //更新時間が過ぎたらリクエストが飛ぶ
    $http->switchCurrentTime(time() + 8000);
    $image = $http->createImage('/img/test.www.sincere-co.com/image-proxy/img/image.jpg');
    $http->execute($image);
    $this->assertSame('image/jpeg', $this->_getHeader($http, 'Content-Type'));
    $this->assertSame('106228', $this->_getHeader($http, 'Content-Length'));
    $this->assertSame(1, $image->getRequestCount());
    $this->assertSame(1, $image->getHeaderRequestCount());
  }

  //////////////////////////////////////////////////////////////////////////////////////////////////
  //////////////////////////////////////////////////////////////////////////////////////////////////
  //ユーティリティーメソッド
  private function _getHeader(ImageProxy_Http $http, $name)
  {
    if($name == 'Status')
    {
      $name = 'HTTP/1.0';
    }
    else
    {
      $name .= ':';
    }

    foreach($http->getResponseHeaders() as $header)
    {
      if(strpos($header, $name) === 0)
      {
        //spaceの分一文字プラスして取り除く
        return substr($header, strlen($name) + 1);
      }
    }

    return null;
  }
}