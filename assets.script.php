<?php
class plgSystemAssetsInstallerScript
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
	public function postflight($route, JAdapterInstance $adapter)
	{
		if (!($route == 'install' || $route == 'update')) {
			return;
		}

        // Move latest version of htaccess file:
        if (!JFolder::exists(JPATH_ROOT . $this->assets_dir)) {
            JFolder::create(JPATH_ROOT . $this->assets_dir);
        }
        JFile::move(
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
	public function uninstall(JAdapterInstance $adapter)
	{
        JFile::delete(JPATH_ROOT . $this->assets_dir . '/.htaccess');
	}
}
?>