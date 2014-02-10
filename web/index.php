<?php
require_once 'ImageProxy/Http.php';

$ip = new ImageProxy_Http('img.salon-navi.me.s3.amazonaws.com');
$ip->execute();