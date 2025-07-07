<?php
require 'vendor/autoload.php';
require_once 'video_converter.php';
use Resque;

Resque::setBackend('localhost:6379');
$worker = new Resque_Worker('video_conversion');
$worker->work(5);
?>