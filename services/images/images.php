<?php
#echo "<pre>"; var_dump($_SERVER); echo "</pre>"; exit;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Registry\Registry;

#define('_JEXEC', 1);
// Not sure it's possible to detect this, but I don't think it's ever likely to be different:
#define('JPATH_BASE', realpath(dirname(dirname(dirname(dirname(dirname(dirname(__DIR__)))) . '/administrator'))));

#require_once JPATH_BASE . '/includes/defines.php';
#require_once JPATH_BASE . '/includes/framework.php';

#$app = Factory::getApplication('administrator');
#$app->initialise(null, false);
#$params = array();

// Set up Joomla User stuff:
define('DS', DIRECTORY_SEPARATOR);
$base_path = realpath(dirname(dirname(dirname(dirname(dirname(__DIR__))))));
define('BASE_PATH', $base_path . DS);
#echo "<pre>"; var_dump(BASE_PATH); echo "</pre>"; exit;

define('_JEXEC', 1);

//If this file is not placed in the /root directory of a Joomla instance put the directory for Joomla libraries here.
$joomla_directory = BASE_PATH;

// From https://joomla.stackexchange.com/questions/33140/how-to-create-an-instance-of-the-joomla-cms-from-the-browser-or-the-command-line
// Via: https://joomla.stackexchange.com/questions/33389/standalone-php-script-to-get-username-in-joomla-4
/**---------------------------------------------------------------------------------
 * Part 1 - Load the Framework and set up up the environment properties
 * -------------------------------------------------------------------------------*/

/**
 *  Site - Front end application when called from Browser via URL.
*/                                                  // Remove this '*/' to comment out this block
define('JPATH_BASE', (isset($joomla_directory)) ? $joomla_directory : __DIR__ );
require_once JPATH_BASE . '/includes/defines.php';
require_once JPATH_BASE . '/includes/framework.php';
$class_name             =  new \Joomla\CMS\Application\SiteApplication;
$session_alias          = 'session.web';
$session_suffix         = 'web.site';
/** end Site config */

/**---------------------------------------------------------------------------------
 * Part 2 - Start the application from the container ready to be used.
 * -------------------------------------------------------------------------------*/
// Boot the DI container
$container = \Joomla\CMS\Factory::getContainer();

// Alias the session service key to the web session service.
$container->alias($session_alias, 'session.' . $session_suffix)
          ->alias('JSession', 'session.' . $session_suffix)
          ->alias(\Joomla\CMS\Session\Session::class, 'session.' . $session_suffix)
          ->alias(\Joomla\Session\Session::class, 'session.' . $session_suffix)
          ->alias(\Joomla\Session\SessionInterface::class, 'session.' . $session_suffix);

// Instantiate the application.
$app = $container->get($class_name::class);
// Set the application as global app
\Joomla\CMS\Factory::$application = $app;


require_once('ImageService.php');

$plugin = PluginHelper::getPlugin('system', 'assets');
$params = new Registry($plugin->params);

$file_perm = octdec($params->get('upload_file_permissions', false));
$dir_perm = 0771;
$dir_grp  = $params->get('upload_file_group', false);
$dir_own  = $params->get('upload_file_owner', false);

$path       = str_replace('?' . $_SERVER['QUERY_STRING'], '', $_SERVER['REQUEST_URI']);
$pathinfo   = pathinfo($path);
$cache_root = str_replace('/assets/', '/cache/assets/', $pathinfo['dirname']);
$uri_appends = [];

// Check if we're really looking for a preview file:
$preview_path = urldecode(JPATH_ROOT . trim($path, '/') . '.preview');

if (file_exists($preview_path)) {
    $uri_appends[] = '.preview';
}

$img_service = new ImageService;
$img_service->cache_root = $cache_root;
$img_service->uri_appends = $uri_appends;
$img_service->run(array(
    'dir_grp'  => $dir_grp,
    'dir_own'  => $dir_own,
    'dir_perm' => $dir_perm,
    'file_perm' => $file_perm
));

header($img_service->header);
echo $img_service->output;