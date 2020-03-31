<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  System.Assets
 *
 * @copyright   Copyright (C) NPEU 2020.
 * @license     MIT License; see LICENSE.md
 */

defined('_JEXEC') or die;

/*

When article is saved, get list of assigned downloads and delete the unlock files.
Parse the article HTML and generate an unlock file for each download found.

/*

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

    /**
     * Prepare form.
     *
     * @param   JForm  $form  The form to be altered.
     * @param   mixed  $data  The associated data for the form.
     *
     * @return  boolean
     */
    public function onContentPrepareForm(JForm $form, $data)
    {
        if (!($form instanceof JForm)) {
            $this->_subject->setError('JERROR_NOT_A_FORM');
            return false;
        }

        if ($form->getName() != 'com_content.article') {
            return; // We only want to manipulate the article form.
        }

        // Add the extra fields to the form
        JForm::addFormPath(__DIR__ . '/forms');
        $form->loadFile('assets', false);
        return true;
    }

    /**
     * The save event.
     *
     * @param   string   $context  The context
     * @param   JTable   $item     The table
     * @param   boolean  $isNew    Is new item
     * @param   array    $data     The validated data
     *
     * @return  boolean
     */
    public function onContentBeforeSave($context, $item, $isNew, $data = array())
    {
        // Check if we're saving an article:
        if ($context != 'com_content.article') {
            return false;
        }

        // Delete the unlock files of all files that were assigned to this article when it was
        // last saved:
        $files = str_replace("\r", '', trim($data['attribs']['assets-downloads-list']));
        if (!empty($files)) {
            $files = explode("\n", $files);

            foreach ($files as $file) {
                $file = JPATH_ROOT . '/assets/downloads/' . urldecode($file) . '.unlock';
                if (file_exists($file)) {
                    unlink($file);
                }
            }
        }

        // Next, get all the files now associated with the article:
        $html = $data['articletext'];

        $new_file_list = '';

        preg_match_all('#href="(\/assets/downloads/([^"]+))"#', $html, $matches, PREG_SET_ORDER);#

        foreach ($matches as $match) {
            $file = JPATH_ROOT . '/assets/downloads/' . urldecode($match[2]);
            if (file_exists($file)) {
                file_put_contents($file . '.unlock', time());
                $new_file_list .= $match[2] . "\n";
            }
        }

        $registry = Joomla\Registry\Registry::getInstance('');
        $registry->loadString($item->attribs);
        $registry['assets-downloads-list'] = $new_file_list;
        $new_attribs = $registry->toString();

        $item->attribs = $new_attribs;

        return true;
    }
}