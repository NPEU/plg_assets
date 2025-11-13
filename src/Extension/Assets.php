<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  System.Assets
 *
 * @copyright   Copyright (C) NPEU 2023.
 * @license     MIT License; see LICENSE.md
 */

namespace NPEU\Plugin\System\Assets\Extension;

defined('_JEXEC') or die;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\FormHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\MVC\View\GenericDataException;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Uri\Uri;
use Joomla\Component\Media\Administrator\Provider\ProviderManagerHelperTrait;
use Joomla\Event\Event;
use Joomla\Event\SubscriberInterface;
use Joomla\Filesystem\File;
use Joomla\Registry\Registry;

/**
 * Provides tools and services for assets (e.g. Images and Downloads).<br>IMPORTANT - you must manually edit the Media Component options and set 'Path to Files Folder' to 'assets' and 'Path to Images Folder' to 'assets/images'.
 */
class Assets extends CMSPlugin implements SubscriberInterface
{
    use ProviderManagerHelperTrait;

    protected $autoloadLanguage = true;

    protected $supported_areas = [
        'com_content'          => ['model' => 'Article'],
        'com_researchprojects' => ['model' => 'Researchproject'],
        'com_users'            => ['model' => 'User']
    ];


    /**
     * An internal flag whether plugin should listen any event.
     *
     * @var bool
     *
     * @since   4.3.0
     */
    protected static $enabled = false;


    /**
     * Constructor
     *
     */
    public function __construct($subject, array $config = [], bool $enabled = true)
    {
        // The above enabled parameter was taken from teh Guided Tour plugin but it ir always seems
        // to be false so I'm not sure where this param is passed from. Overriding it for now.
        $enabled = true;

        $this->autoloadLanguage = $enabled;
        self::$enabled          = $enabled;

        parent::__construct($subject, $config);
    }

    /**
     * function for getSubscribedEvents : new Joomla 4 feature
     *
     * @return array
     *
     * @since   4.3.0
     */
    public static function getSubscribedEvents(): array
    {
        return self::$enabled ? [
            'onBeforeRender'              => 'onBeforeRender',
            'onContentAfterDelete'        => 'onContentAfterDelete',
            'onContentAfterSave'          => 'onContentAfterSave',
            'onContentBeforeSave'         => 'onContentBeforeSave',
            'onContentBeforeValidateData' => 'onContentBeforeValidateData'
        ] : [];
    }

    /**
     *
     * @param   string  $file  The file to be converted.
     *
     * @return  mixed   The image filename or false on failure
     */
    protected function generateThumbnail($filepath)
    {
        $upload_file_permissions = octdec($this->params->get('upload_file_permissions', false));
        $upload_file_group       = $this->params->get('upload_file_group', false);
        $upload_file_owner       = $this->params->get('upload_file_owner', false);

        // We need to make sure ImageMagick can convert the file to a PDF via a delegate
        // (LibreOffice) but now (as of Dec 2020) the /tmp prvate system file seems to prevent this
        // form generating a PDF first so we need to copy the tmp file to a different temporary
        // folder. I can't see any reason do add in the logic to ONLY do this if it's not a PDF,
        // so it makes sense to me to just make that copy anywyay in all cases.

        if ($upload_file_permissions) {
            chmod($filepath, octdec('770'));
        }

        // Set the file to belong to our preferred group:
        if ($upload_file_group) {
            chgrp($filepath, $upload_file_group);
        }

        // Set the file to belong to our preferred owner:
        if ($upload_file_owner) {
            chown($filepath, $upload_file_owner);
        }

        // We just add .png to the thumbnail filename so we can determine the real filename in the
        // 'Downloads Media Hack' (see elsewhere):
        $img_filepath = $filepath . '.png';

        $thumbsize        = $this->params->get('thumbsize', '1200');

        $imagemagick_path = 'HOME=/tmp convert';

        // ---Imagemagick/Ghostscript now fails with the -colorspace flag in place.
        // I haven't been able to figure out why, so just removing it for now as it still
        // seems to work without it, though the colours aren't great.---
        // UPDATE 20190603 - seems to have been resolved. At least it's working now so
        // reinstating -colorspace to fix colours.
        // Note "-background white -alpha remove -flatten -alpha off" adds a white background.
        //$options  = ' -strip -colorspace rgb -density 300x300 -resize ' . $thumbsize . 'x'. $thumbsize . ' -quality 90 ';
        // At some point some PDF's started to be thumbnailed much darker, so sRGB colourspace:
        $options  = ' -strip -colorspace sRGB -density 300x300 -resize ' . $thumbsize . 'x'. $thumbsize . ' -quality 90 ';
        $cmd      = $imagemagick_path . $options . '"' . $filepath . '[0]" -background white -alpha remove -flatten -alpha off ' . '"' . $img_filepath . '"';
        $output = exec($cmd . ' 2>&1');

        // Did the image get generated?
        if (file_exists($img_filepath)) {
            // Set the file to our preferred permissions:
            if ($upload_file_permissions) {
                chmod($img_filepath, $upload_file_permissions);
            }

            // Set the file to belong to our preferred group:
            if ($upload_file_group) {
                chgrp($img_filepath, $upload_file_group);
            }

            // Set the file to belong to our preferred owner:
            if ($upload_file_owner) {
                chown($img_filepath, $upload_file_owner);
            }

            rename($img_filepath, $img_filepath . '.preview');
            return $img_filepath . '.preview';
        } else {
            return false;
        }
    }

    /**
     * @param   string  $html  The file to be converted.
     *
     * @return  array   The array of download files found.
     */
    protected function findDownloads($html)
    {
        $downloads = [];

        preg_match_all('#href="(\/assets/downloads/([^"]+))"#', $html, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $file = JPATH_ROOT . '/assets/downloads/' . urldecode($match[2]);
            if (file_exists($file)) {
                $downloads[] = $file;
            }
        }

        return $downloads;
    }

    /**
     * @param   string  $item_id
     * @param   array  $new_downloads  The new downloads.
     *
     * @return  void
     */
    protected function unlockNewDownloads($item_id, $new_downloads) {
        if (!empty($new_downloads)) {
            // New downloads = downloads that appear in the content that's being save; we need
            // to unlock these files:
            foreach ($new_downloads as $file) {
                $id_list = false;
                $unlock_file = $file . '.unlock';
                // Check for existing unlock file:
                if (file_exists($unlock_file)) {
                    // Get the contents of the unlock file:
                    $id_list = json_decode(file_get_contents($unlock_file), true);
                }

                // Add the new download:
                if (!is_array($id_list)) {
                    $id_list = [];
                }
                if (!in_array($item_id, $id_list)) {
                    $id_list[] = $item_id;
                }
                file_put_contents($unlock_file, json_encode($id_list));
            }
        }
    }


    /**
     *
     * @param   string  $item_id
     * @param   array  $old_downloads  The old downloads.
     * @param   array  $new_downloads  The new downloads.
     *
     * @return  void
     */
    protected function TidyOldDownloads($item_id, $old_downloads, $new_downloads) {

        if (!empty($old_downloads)) {
            // Which downloads no longer appear in the new content:
            $diff = array_diff($old_downloads, $new_downloads);

            if ($diff) {
                foreach ($diff as $file) {
                    $unlock_file = $file . '.unlock';

                    if (file_exists($unlock_file)) {
                        // Inspect the contents of the unlock file:
                        $id_list = json_decode(file_get_contents($unlock_file), true);
                        if (!is_array($id_list)) {
                            $id_list = [];
                        }

                        $can_delete = false;
                        if (!$id_list) {
                            $can_delete = true;
                        } else {
                            // If this item previous unlocked this file, remove it:
                            if (in_array($item_id, $id_list)) {
                                unset($id_list[array_search($item_id, $id_list)]);
                            }
                            // If there are no items left we can delete the unlock file:
                            if (count($id_list) == 0) {
                                $can_delete = true;
                            } else {
                                file_put_contents($unlock_file, json_encode($id_list));
                            }
                        }
                        if ($can_delete) {
                            File::delete($unlock_file);
                        }
                    }
                }
            }
        }
    }

    /**
     * Method is called before user data is stored in the database
     *
     * @param   array    $user   Holds the old user data.
     * @param   boolean  $isNew  True if a new user is stored.
     * @param   array    $data   Holds the new user data.
     *
     * @return  boolean
     */
    public function onContentBeforeValidateData(Event $event): void
    {
        [$form, $data] = array_values($event->getArguments());
        $uri = Uri::getInstance();
        $component = $uri->getVar('option');

        if (!array_key_exists($component, $this->supported_areas)) {
            return;
        }

        // Get the what the content was BEFORE it was saved.
        // Find any downloads that WERE unlocked by it.
        // Delete any files that are ONLY lock by it.
        // OR
        // Remove the content identifier from the unlock files.
        // Then, we need to find downlaods in the CURRENT content and generate or append unlock
        // files for those downloads.
        // Each component supported will have content in a different variable name, and there may be
        // more than one.

        $is_new = false;

        // Get the ID:
        $id = (int) $data['id'];
        if ($id == 0) {
            $is_new = true;
        }

        $item_id = $component . '.' . $id;

        if (!$is_new) {
            // If this isn't new we'll need the old data before the save to compare:
            $app = Factory::getApplication();
            $model = $app->bootComponent($component)->getMVCFactory()->createModel($this->supported_areas[$component]['model'], 'Administrator', ['ignore_request' => true]);
            $item = $model->getItem($id);
        }

        // Let's handle articles first:
        if ($component == 'com_content') {
            $new_content = trim($data['articletext']);
            $old_content = trim($item->introtext . $item->fulltext);

            $new_downloads = $this->findDownloads($new_content);
            $this->unlockNewDownloads($item_id, $new_downloads);

            if (!$is_new) {
                $old_downloads = $this->findDownloads($old_content);
                $this->TidyOldDownloads($item_id, $old_downloads, $new_downloads);
            }
        }


        // Next, user profile:
        if ($component == 'com_users') {
            $user_data     = json_decode(file_get_contents(Uri::root() . 'data/staff?id=' . $item->id), true);

            // Biography:
            $new_biography = trim($data['profile']['biography']);
            $old_biography = trim($user_data[0]['biography']);

            $bio_item_id = $item_id . '.bio';
            $new_downloads = $this->findDownloads($new_biography);
            $this->unlockNewDownloads($bio_item_id, $new_downloads);

            if (!$is_new) {
                $old_downloads = $this->findDownloads($old_biography);
                $this->TidyOldDownloads($bio_item_id, $old_downloads, $new_downloads);
            }

            // Publications:
            $new_publications = trim($data['profile']['publications_manual']);
            $old_publications = trim($user_data[0]['publications_manual']);
            $pub_item_id = $item_id . '.pub';
            $new_downloads = $this->findDownloads($new_publications);
            $this->unlockNewDownloads($pub_item_id, $new_downloads);

            if (!$is_new) {
                $old_downloads = $this->findDownloads($old_publications);
                $this->TidyOldDownloads($pub_item_id, $old_downloads, $new_downloads);
            }

            // Custom Section:
            $new_custom = trim($data['profile']['custom']);
            $old_custom = trim($user_data[0]['custom']);

            $cus_item_id = $item_id . '.cus';
            $new_downloads = $this->findDownloads($new_custom);
            $this->unlockNewDownloads($cus_item_id, $new_downloads);

            if (!$is_new) {
                $old_downloads = $this->findDownloads($old_custom);
                $this->TidyOldDownloads($cus_item_id, $old_downloads, $new_downloads);
            }

        }

        // Next, research projects:
        if ($component == 'com_researchprojects') {
            // Content:
            $new_content = trim($data['content']);
            $old_content = trim($item->content);

            $con_item_id = $item_id . '.con';
            $new_downloads = $this->findDownloads($new_content);
            $this->unlockNewDownloads($con_item_id, $new_downloads);

            if (!$is_new) {
                $old_downloads = $this->findDownloads($old_content);
                $this->TidyOldDownloads($con_item_id, $old_downloads, $new_downloads);
            }

            // Publications:
            $new_content = trim($data['publications']);
            $old_content = trim($item->publications);
            $pub_item_id = $item_id . '.pub';
            $new_downloads = $this->findDownloads($new_content);
            $this->unlockNewDownloads($pub_item_id, $new_downloads);

            if (!$is_new) {
                $old_downloads = $this->findDownloads($old_content);
                $this->TidyOldDownloads($pub_item_id, $old_downloads, $new_downloads);
            }
        }

    }

    /**
     * The save event.
     *
     * @param   Event  $event
     *
     * @return  boolean
     */
    public function onContentBeforeSave(Event $event): void
    {
        [$context, $object, $isNew, $data] = array_values($event->getArguments());

        // Check if we're saving an media file:
        if ($context == 'com_media.file') {
            $media_files_path = ComponentHelper::getParams('com_media')->get('file_path', 'images');
            $root_files_path = JPATH_ROOT .'/' . $media_files_path;

            // We need to preempt the default upload process, check if an image is created, and
            // return false if it can't be to quit the upload process.
            // However, we need the upload in place before we can do that so it's a bit of a chicken
            // / egg situation.
            // It may be tempting to remove the duplicate code in the onContentAfterSave handler,
            // BUT DON'T - other things may cause the upload to ultimately fail, so we don't want
            // to leave behind the generated image.

            // Here we're prematurely creating the file (prefixed 'tmp.') so that we can generate
            // the thumbnail in order to check for success of thumbnail. Both these files are then
            // deleted so that if anythign fails no files are lift behind to cause problems.
            // If everything is ok these files a created again by the normal subsequest processes,
            $tmp_name = 'tmp.' . $object->name;

            // Create the file:
            $filename = $this->getAdapter($object->adapter)->createFile($tmp_name, $object->path, $object->data);

            if (!$filename) {
                throw new GenericDataException(Text::_('PLG_SYSTEM_ASSETS_ERROR_FILE_CREATE_FAIL'), 100);
                return;
            }


            $tmp_filepath = $root_files_path . $object->path . '/' . $filename;

            // Dont' try to generagte thumbnails for ZIP files:
            $mime_type = mime_content_type($tmp_filepath);
            if ($mime_type == 'application/zip' || $mime_type == 'application/x-zip') {
                File::delete($tmp_filepath);
                return;
            }

            $img_filepath = $this->generateThumbnail($root_files_path . $object->path . '/' . $filename);

            // Did the image get generated?
            if (file_exists($img_filepath)) {
                // Looks like it's going to be ok, so delete it (in case something else fails the upload):
                File::delete($img_filepath);
                File::delete($tmp_filepath);
                return;
            } else {
                // Something went wrong, reject the the upload and warn the user:
                throw new GenericDataException(Text::_('PLG_SYSTEM_ASSETS_ERROR_FILE_THUMB_FAIL'), 100);

                return;
            }

        }

        return;
    }

    /**
     *
     * @param   Event  $event
     *
     * @return  void
     */
    public function onContentAfterSave(Event $event): void
    {
        [$context, $object, $isNew] = array_values($event->getArguments());

        // Check if we're saving an media file:
        if ($context == 'com_media.file') {

            $media_files_path = ComponentHelper::getParams('com_media')->get('file_path', 'images');
            $root_files_path = JPATH_ROOT .'/' . $media_files_path;

            $full_path = $root_files_path . $object->path . '/' . $object->name;
            $filetype = mime_content_type($full_path);

            $upload_file_permissions = octdec($this->params->get('upload_file_permissions', false));
            $upload_file_group       = $this->params->get('upload_file_group', false);
            $upload_file_owner       = $this->params->get('upload_file_owner', false);

            // Set the file to our preferred permissions:
            if ($upload_file_permissions) {
                chmod($full_path, $upload_file_permissions);
            }

            // Set the file to belong to our preferred group:
            if ($upload_file_group) {
                chgrp($full_path, $upload_file_group);
            }

            // Set the file to belong to our preferred owner:
            if ($upload_file_owner) {
                chown($full_path, $upload_file_owner);
            }

            // If this is an image or audio file, we're done:
            if (
                strpos($filetype, 'image') !== false
            || strpos($filetype, 'audio/mpeg') !== false
            ) {
                return;
            }

            // Dont' try to generagte thumbnails for ZIP files:
            $mime_type = mime_content_type($full_path);
            if ($mime_type == 'application/zip' || $mime_type == 'application/x-zip') {
                return;
            }

            $img_filepath = $this->generateThumbnail($full_path);

        }

        return;

    }

    /**
     * The delete event.
     *
     * @param   Event  $event
     *
     * @return  void
     */
    public function onContentAfterDelete(Event $event): void
    {
        [$context, $object] = array_values($event->getArguments());

        // Check if we're saving an media file:
        if ($context != 'com_media.file') {
            return;
        }

        $media_files_path = ComponentHelper::getParams('com_media')->get('file_path', 'images');
        $root_files_path = JPATH_ROOT .'/' . $media_files_path;

        $full_path = $root_files_path . $object->path;

        $preview_file = $full_path . '.png.preview';
        if (file_exists($preview_file)) {
            File::delete($preview_file);
        }

        $unlock_file = $full_path . '.unlock';
        if (file_exists($unlock_file)) {
            File::delete($unlock_file);
        }
    }

    /**
     * Add CSS and JS.
     */
    public function onBeforeRender(Event $event): void
    {
        $app = Factory::getApplication();
        if (!$app->isClient('administrator')) {
            return; // Only run in admin
        }
        $document = Factory::getDocument();
        $plugin_folder = str_replace(JPATH_ROOT, '', dirname(dirname(__DIR__)));

        $document->addStyleSheet($plugin_folder . '/css/assets.css');
        $document->addScript($plugin_folder . '/js/assets.js');
    }

}