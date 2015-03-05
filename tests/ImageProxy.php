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
  }

  public function testDetectSavePath()
  {
    $http = new ImageProxy_Http($this->_test_dir.'/index.php');
    $this->assertEquals('./img/server/path/to/image.jpg', $http->detectSavePath('/img/server/path/to/image.jpg'));
    $this->assertEquals('./img/img/image.jpg', $http->detectSavePath('/img/img/image.jpg'));
  }

  public function testImage()
  {
    $http = new ImageProxy_Http($this->_test_dir.'/index.php');

    //追加設定の何もないサーバーのテスト
    $image = $http->createImage('/img/www.plane.com/image-proxy/sample.jpg');
    $this->assertEquals('www.plane.com', $image->getServerValue('domain'));
    $this->assertNull($image->getServerValue('ip'));

    //色々設定のあるサーバー
    $image = $http->createImage('/img/foo/image-proxy/sample.jpg');
    $this->assertEquals('www.foo.com', $image->getServerValue('domain'));
    $this->assertEquals('127.0.0.1', $image->getServerValue('ip'));
    $this->assertEquals('https', $image->getServerValue('protocol'));

    //継承（子供）
    $image = $http->createImage('/img/child/image-proxy/sample.jpg');
    $this->assertEquals('www.foo.com', $image->getServerValue('domain'));
    $this->assertEquals('127.0.0.1', $image->getServerValue('ip'));
    $this->assertEquals('http', $image->getServerValue('protocol'));

    //継承（孫）
    $image = $http->createImage('/img/grandchild/image-proxy/sample.jpg');
    $this->assertEquals('www.foo.com', $image->getServerValue('domain'));
    $this->assertEquals('0.0.0.0', $image->getServerValue('ip'));
    $this->assertEquals('http', $image->getServerValue('protocol'));


    //サイズ変更ありのパステスト
    $image = $http->createImage('/img/foo/image-proxy/w120_sample.jpg');

    //save path
    $this->assertEquals('./img/foo/image-proxy/w120_sample.jpg', $image->getSavePath());
    $this->assertEquals('./img/foo/image-proxy/w120_sample.jpg.php', $image->getDataPath());

    //origin save path
    $this->assertEquals('./img/foo/image-proxy/sample.jpg', $image->getOriginSavePath());
    $this->assertEquals('./img/foo/image-proxy/sample.jpg.php', $image->getOriginDataPath());
  }
}