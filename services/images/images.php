<?php

define('_JEXEC', 1);
// Not sure it's possible to detect this, but I don't think it's ever likely to be different:
define('JPATH_BASE', realpath(dirname(dirname(dirname(dirname(dirname(dirname(__DIR__)))) . '/administrator'))));

require_once JPATH_BASE . '/includes/defines.php';
require_once JPATH_BASE . '/includes/framework.php';

$app = JFactory::getApplication('administrator');
$app->initialise(null, false);

require_once('ImageService.php');

$plugin = JPluginHelper::getPlugin('system', 'assets');
$params = new JRegistry($plugin->params);

$file_perm = octdec($params->get('upload_file_permissions', false));
$dir_perm = 0771;
$dir_grp  = $params->get('upload_file_group', false);
$dir_own  = $params->get('upload_file_owner', false);

$path       = str_replace('?' . $_SERVER['QUERY_STRING'], '', $_SERVER['REQUEST_URI']);
$pathinfo   = pathinfo($path);
$cache_root = str_replace('/assets/', '/cache/assets/', $pathinfo['dirname']);

$img_service = new ImageService;
$img_service->cache_root = $cache_root;
$img_service->run(array(
    'dir_grp'  => $dir_grp,
    'dir_own'  => $dir_own,
    'dir_perm' => $dir_perm,
    'file_perm' => $file_perm
));

header($img_service->header);
echo $img_service->output;