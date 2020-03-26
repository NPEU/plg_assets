<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  System.Assets
 *
 * @copyright   Copyright (C) NPEU 2020.
 * @license     MIT License; see LICENSE.md
 */

defined('_JEXEC') or die;

/**
 * Provides tools and services for assets (e.g. Images and Downloads).
 */
class plgSystemAssets extends JPlugin
{
    protected $autoloadLanguage = true;

    /*
     * @return  boolean  True on success
     */
    public function onAfterRoute()
	{
        $app = JFactory::getApplication();
        if ($app->isAdmin()) {
            return; // Don't run in admin
        }
        
        $app->enqueueMessage('Testing (' . date('c') . ')', 'notice');
        return;
    }
}