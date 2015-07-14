<?php
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

  public function delete()
  {
    @unlink($this->_data_path);
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
