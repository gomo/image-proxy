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
}