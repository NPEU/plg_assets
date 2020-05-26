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

    /**
     *
     * @param   string  $context  The context of the content passed to the plugin (added in 1.6)
     * @param   object  $article  A JTableContent object
     * @param   bool    $isNew    If the content has just been created
     *
     * @return  void
     */
    public function onContentAfterSave($context, $article, $isNew)
    {
        // Check if we're saving an media file:
        if ($context != 'com_media.file') {
            return true;
        }

        $type        = $article->type;
        $file        = $article->filepath;
        $info        = pathinfo($file);

        // We just add .png to the thumbnail filename so we can determine the real filename in the
        // 'Downloads Media Hack' (see elsewhere):
        $img         = $file . '.png';
        $delete_file = false;

        // If it's not a PDF, we need to convert it one to be able to created the thumbnail, then
        // delete it:
        if ($type != 'application/pdf') {
            // Note unoconv default export format is PDF so we don't need to specify:
            $cmd         = 'unoconv -e PageRange=1 "' . $file . '"';
            $output      = exec($cmd . ' 2>&1');
            $file        = preg_replace('#\.' . $info['extension'] . '$#', '.pdf', $file);
            $delete_file = true;
        }

        // Generate the thumbnail:
        $thumbsize        = $this->params->get('thumbsize', '1200');
        $imagemagick_path = 'convert';

        // ---Imagemagick/Ghostscript now fails with the -colorspace flag in place.
        // I haven't been able to figure out why, so just removing it for now as it still
        // seems to work without it, though the colours aren't great.---
        // UPDATE 20190603 - seems to have been resolved. At least it's working now so
        // reinstating -colorspace to fix colours.
        // Note "-background white -alpha remove -flatten -alpha off" adds a white background.
        $options  = ' -strip -colorspace rgb -density 300x300 -resize ' . $thumbsize . 'x'. $thumbsize . ' -quality 90 ';
        $cmd      = $imagemagick_path . $options . '"' . $file . '[0]" -background white -alpha remove -flatten -alpha off ' . '"' . $img . '"';

        $output = exec($cmd . ' 2>&1');

        if ($delete_file) {
            unlink($file);
        }

        return true;
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
            return true;
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

    /**
	 * onAfterRender
     * Joomla's Media component does not handle using it for downloads. Rather than replace the
     * component or hack it, we've ensured there's always a thumbnail of each download, so the
     * the media component is effectively tricked into displaying all the available downloads, since
     * there always exists a matching image (the Media Component will only show images when opened 
     * as a model for the Image file input, and there isn't a Downloads equivalent).
     * However, we need to make the sure actual file path is passed back to the editor, so we need
     * to replace all occurrences of the image filename with the download filename, except where it
     * appears as the thumbnail src.
     * This is why thumbnails have .png appended to the full filename, rather than replacing the
     * extension.
 	 */
	public function onAfterRender()
	{
        $app = JFactory::getApplication();
        if ($app->isClient('site')) {
            return;
        }

        $uri = Joomla\CMS\Uri\Uri::getInstance();

        if ($uri->getVar('option') != 'com_media') {
            return;
        }

		$response = JResponse::getBody();

        if ($uri->getVar('view') == 'imagesList') {
            preg_match_all('#<img[^>]+src="[^"]*/assets/downloads/([^"]+)"#', $response, $matches, PREG_SET_ORDER);

            foreach ($matches as $match) {
                $info     = pathinfo($match[1]);

                // Replace the whole image tag temporarily:
                $response = str_replace($match[0], '<<<TMP-IMAGE>>>', $response);

                // Replace all other occurrences of the filename:
                $response = str_replace($info['basename'], $info['filename'], $response);

                // Restore the image tag:
                $response = str_replace('<<<TMP-IMAGE>>>', $match[0], $response);
            }
        }

        if ($uri->getVar('view') == 'images' && $uri->getVar('fieldid') == 'jfile_href') {
            // Update labels image -> file:
            $response = str_replace('<label for="f_url">Image URL</label>', '<label for="f_url">File URL</label>', $response);
        }

		JResponse::setBody($response);
    }
}