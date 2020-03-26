<?php
require_once('ImageService.php');

$path       = str_replace('?' . $_SERVER['QUERY_STRING'], '', $_SERVER['REQUEST_URI']);
$pathinfo   = pathinfo($path);
$cache_root = str_replace('/assets/images', '/cache/assets/images', $pathinfo['dirname']);

$img_service = new ImageService;
$img_service->cache_root = $cache_root;
$img_service->run();

header($img_service->header);
echo $img_service->output;