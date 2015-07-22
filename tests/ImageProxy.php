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
    $image = $http->createImage('/img/test.www.sincere-co.com/image-proxy/img/non-exists.jpg');
    $image->changeModifiedTime(time() - 2000);
    $http->execute($image);
    $this->assertSame('404 Not Found', $this->_getHeader($http, 'Status'));
    $this->assertSame(0, $image->getRequestCount());
    $this->assertSame(0, $image->getHeaderRequestCount());
    clearstatcache();

    //存在しない画像 check_interval_sec経過後
    $image = $http->createImage('/img/test.www.sincere-co.com/image-proxy/img/non-exists.jpg');
    $image->changeModifiedTime(time() - 3700);
    $http->execute($image);
    $this->assertSame('404 Not Found', $this->_getHeader($http, 'Status'));
    $this->assertSame(0, $image->getRequestCount());
    $this->assertSame(1, $image->getHeaderRequestCount());
    clearstatcache();

    //non-exists.jpgをサーバーに出現させる
    $resp = file_get_contents('http://test.www.sincere-co.com/image-proxy/util.php?func=nonExists');
    $this->assertSame('0', $resp);
    $image = $http->createImage('/img/test.www.sincere-co.com/image-proxy/img/non-exists.jpg');
    $image->changeModifiedTime(time() - 3700);
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
    $this->assertFalse(file_exists($image->getSavePath()));
    $this->assertFalse(file_exists($image->getDataPath()));
    $http->execute($image);
    $this->assertTrue(file_exists($image->getSavePath()));
    $this->assertTrue(file_exists($image->getDataPath()));
    $this->assertSame('image/jpeg', $this->_getHeader($http, 'Content-Type'));
    $this->assertSame(1, $image->getRequestCount());
    $this->assertSame(0, $image->getHeaderRequestCount());
    clearstatcache();

    //2回め　スグ
    $image = $http->createImage('/img/test.www.sincere-co.com/image-proxy/img/image.jpg');
    $http->execute($image);
    $this->assertSame('image/jpeg', $this->_getHeader($http, 'Content-Type'));
    $this->assertSame(0, $image->getRequestCount());
    $this->assertSame(0, $image->getHeaderRequestCount());
    clearstatcache();

    //まだcheck_interval_secを過ぎていない（少し経過）
    $image = $http->createImage('/img/test.www.sincere-co.com/image-proxy/img/image.jpg');
    $image->changeModifiedTime(time() - 2000);
    $http->execute($image);
    $this->assertSame('image/jpeg', $this->_getHeader($http, 'Content-Type'));
    $this->assertSame(0, $image->getRequestCount());
    $this->assertSame(0, $image->getHeaderRequestCount());
    clearstatcache();

    //check_interval_secを過ぎた
    $image = $http->createImage('/img/test.www.sincere-co.com/image-proxy/img/image.jpg');
    $image->changeModifiedTime(time() - 3700);
    $http->execute($image);
    $this->assertSame('image/jpeg', $this->_getHeader($http, 'Content-Type'));
    $this->assertSame(0, $image->getRequestCount());
    $this->assertSame(1, $image->getHeaderRequestCount());
    clearstatcache();

    //画像が更新される（Last-Modifiedが変わる）
    $resp = file_get_contents('http://test.www.sincere-co.com/image-proxy/util.php?func=update&target=image.jpg');
    $this->assertSame('0', $resp);

    //でもまだcheck_interval_secを過ぎてない
    $image = $http->createImage('/img/test.www.sincere-co.com/image-proxy/img/image.jpg');
    $image->changeModifiedTime(time() - 2000);
    $http->execute($image);
    $this->assertSame('image/jpeg', $this->_getHeader($http, 'Content-Type'));
    $this->assertSame(0, $image->getRequestCount());
    $this->assertSame(0, $image->getHeaderRequestCount());
    clearstatcache();

    //更新時間が過ぎたらリクエストが飛ぶ
    $image = $http->createImage('/img/test.www.sincere-co.com/image-proxy/img/image.jpg');
    $image->changeModifiedTime(time() - 3700);
    $http->execute($image);
    $this->assertSame('image/jpeg', $this->_getHeader($http, 'Content-Type'));
    $this->assertSame(1, $image->getRequestCount());
    $this->assertSame(1, $image->getHeaderRequestCount());
  }

  public function testSwapOriginImage()
  {
    $http = new ImageProxy_Http($this->_test_dir.'/index.php');

    //サイズ違い3つ
    $image = $http->createImage('/img/test.www.sincere-co.com/image-proxy/img/image.jpg');
    $http->execute($image);
    $size_org = filesize($image->getSavePath());

    $image = $http->createImage('/img/test.www.sincere-co.com/image-proxy/img/w120_image.jpg');
    $http->execute($image);
    $size_120 = filesize($image->getSavePath());

    $image = $http->createImage('/img/test.www.sincere-co.com/image-proxy/img/w130_image.jpg');
    $http->execute($image);
    $size_130 = filesize($image->getSavePath());

    //画像が更新される（Last-Modifiedが変わる）
    $resp = file_get_contents('http://test.www.sincere-co.com/image-proxy/util.php?func=update&target=image.jpg');
    $this->assertSame('0', $resp);

    $image = $http->createImage('/img/test.www.sincere-co.com/image-proxy/img/image.jpg');
    $image->changeModifiedTime(time() - 3700);
    $http->execute($image);
    $this->assertNotEquals($size_org, filesize($image->getSavePath()));

    $image = $http->createImage('/img/test.www.sincere-co.com/image-proxy/img/w120_image.jpg');
    $image->changeModifiedTime(time() - 3700);
    $http->execute($image);
    $this->assertNotEquals($size_120, filesize($image->getSavePath()));

    $image = $http->createImage('/img/test.www.sincere-co.com/image-proxy/img/w130_image.jpg');
    $image->changeModifiedTime(time() - 3700);
    $http->execute($image);
    $this->assertNotEquals($size_130, filesize($image->getSavePath()));
  }

  public function testExecuteResizeJpegImage()
  {
    $http = new ImageProxy_Http($this->_test_dir.'/index.php');

    //初回
    $image = $http->createImage('/img/test.www.sincere-co.com/image-proxy/img/w120_image.jpg');
    $this->assertFalse(file_exists($image->getSavePath()));
    $this->assertFalse(file_exists($image->getDataPath()));
    $this->assertFalse(file_exists($image->getOriginSavePath()));
    $this->assertFalse(file_exists($image->getOriginDataPath()));
    $http->execute($image);
    $this->assertSame('image/jpeg', $this->_getHeader($http, 'Content-Type'));
    $this->assertTrue(file_exists($image->getSavePath()));
    $this->assertTrue(file_exists($image->getDataPath()));
    $this->assertTrue(file_exists($image->getOriginSavePath()));
    $this->assertTrue(file_exists($image->getOriginDataPath()));
    list($width,,,) = getimagesize($image->getSavePath());
    $this->assertSame(120, $width);
    $this->assertSame(1, $image->getRequestCount());
    $this->assertSame(0, $image->getHeaderRequestCount());
    clearstatcache();

    //2回め　スグ
    $image = $http->createImage('/img/test.www.sincere-co.com/image-proxy/img/w120_image.jpg');
    $http->execute($image);
    $this->assertSame(0, $image->getRequestCount());
    $this->assertSame(0, $image->getHeaderRequestCount());
    clearstatcache();

    //別サイズ初回
    $image = $http->createImage('/img/test.www.sincere-co.com/image-proxy/img/w130_image.jpg');
    $http->execute($image);
    $this->assertSame('image/jpeg', $this->_getHeader($http, 'Content-Type'));
    list($width,,,) = getimagesize($image->getSavePath());
    $this->assertSame(130, $width);
    $this->assertSame(0, $image->getRequestCount());//元画像から生成するのでリクエストは飛ばない
    $this->assertSame(1, $image->getHeaderRequestCount());//元画像と比べるのでヘッダーは取りに行く
    clearstatcache();

    //別サイズ2回め　スグ
    $image = $http->createImage('/img/test.www.sincere-co.com/image-proxy/img/w130_image.jpg');
    $http->execute($image);
    $this->assertSame(0, $image->getRequestCount());
    $this->assertSame(0, $image->getHeaderRequestCount());
    clearstatcache();

    //画像が更新される（Last-Modifiedが変わる）
    $resp = file_get_contents('http://test.www.sincere-co.com/image-proxy/util.php?func=update&target=image.jpg');
    $this->assertSame('0', $resp);

    //check_interval_sec後の120の画像
    $image = $http->createImage('/img/test.www.sincere-co.com/image-proxy/img/w120_image.jpg');
    $image->changeModifiedTime(time() - 3700);
    $http->execute($image);
    $this->assertSame(1, $image->getRequestCount());
    $this->assertSame(1, $image->getHeaderRequestCount());
    clearstatcache();

    //check_interval_sec後の130の画像
    $image = $http->createImage('/img/test.www.sincere-co.com/image-proxy/img/w130_image.jpg');
    $image->changeModifiedTime(time() - 3700);
    $http->execute($image);
    $this->assertSame(1, $image->getRequestCount());
    $this->assertSame(1, $image->getHeaderRequestCount());
    clearstatcache();

    //130 更新後2回め　スグ
    $image = $http->createImage('/img/test.www.sincere-co.com/image-proxy/img/w130_image.jpg');
    $http->execute($image);
    $this->assertSame(0, $image->getRequestCount());
    $this->assertSame(0, $image->getHeaderRequestCount());
  }

  public function testImageDelete()
  {
    $http = new ImageProxy_Http($this->_test_dir.'/index.php');

    //とりあえず存在する画像をサイズ違いで2つ読む
    $image = $http->createImage('/img/test.www.sincere-co.com/image-proxy/img/image.jpg');
    $http->execute($image);
    $image = $http->createImage('/img/test.www.sincere-co.com/image-proxy/img/w120_image.jpg');
    $http->execute($image);
    clearstatcache();

    //画像が更新される（Last-Modifiedが変わる）
    $resp = file_get_contents('http://test.www.sincere-co.com/image-proxy/util.php?func=delete&target=image.jpg');
    $this->assertSame('0', $resp);

    //キャッシュから読む（まだ画像はあるとみなしてキャッシュを返すはず）
    $image = $http->createImage('/img/test.www.sincere-co.com/image-proxy/img/image.jpg');
    $http->execute($image);
    $this->assertSame('image/jpeg', $this->_getHeader($http, 'Content-Type'));

    $image = $http->createImage('/img/test.www.sincere-co.com/image-proxy/img/w120_image.jpg');
    $http->execute($image);
    $this->assertSame('image/jpeg', $this->_getHeader($http, 'Content-Type'));

    //check_interval_sec後は404
    $image = $http->createImage('/img/test.www.sincere-co.com/image-proxy/img/image.jpg');
    $image->changeModifiedTime(time() - 3700);
    $http->execute($image);
    $this->assertSame('404 Not Found', $this->_getHeader($http, 'Status'));

    $image = $http->createImage('/img/test.www.sincere-co.com/image-proxy/img/w120_image.jpg');
    $image->changeModifiedTime(time() - 3700);
    $http->execute($image);
    $this->assertSame('404 Not Found', $this->_getHeader($http, 'Status'));

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