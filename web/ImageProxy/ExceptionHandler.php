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
