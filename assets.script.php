<?php
/**
 * @package     Joomla.Site
 * @subpackage  plg_assets
 *
 * @copyright   Copyright (C) NPEU 2024.
 * @license     MIT License; see LICENSE.md
 */


defined('_JEXEC') or die;

use Joomla\CMS\Filesystem\File;
use Joomla\CMS\Filesystem\Folder;

class PlgSystemAssetsInstallerScript
{
    protected $plugin_dir = JPATH_ROOT . '/plugins/system/assets';

    // On uninstall this folder SHOULD NOT be deleted, or all assets will be lost.
    // (However, we should delete the htaccess file this plugin added).
    protected $assets_dir = '/assets';

    /**
     * Called after any type of action
     *
     * @param   string  $route  Which action is happening (install|uninstall|discover_install)
     * @param   JAdapterInstance  $adapter  The object responsible for running this script
     *
     * @return  boolean  True on success
     */
    public function postflight(string $type, $adapter): bool
    {
        if (!($type == 'install' || $type == 'update')) {
            return false;
        }

        // Move latest version of htaccess file:
        if (!Folder::exists(JPATH_ROOT . $this->assets_dir)) {
            Folder::create(JPATH_ROOT . $this->assets_dir);
        }
        File::move(
            $this->plugin_dir . '/assets.htaccess.txt',
            JPATH_ROOT . $this->assets_dir . '/.htaccess'
        );

        return true;
    }

    /**
     * Called on uninstallation
     *
     * @param   JAdapterInstance  $adapter  The object responsible for running this script
     */
    public function uninstall($adapter): bool
    {
        File::delete(JPATH_ROOT . $this->assets_dir . '/.htaccess');
        return true;
    }
}
?>