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
            'onContentBeforeSave'  => 'onContentBeforeSave',
            'onContentAfterSave'   => 'onContentAfterSave',
            'onContentAfterDelete' => 'onContentAfterDelete',
            'onContentPrepareData' => 'onContentPrepareData',
            'onContentPrepareForm' => 'onContentPrepareForm',
            'onBeforeRender'       => 'onBeforeRender'
        ] : [];
    }

    /**
     *
     * @param   string  $file  The file to be converted.
     *
     * @return  mixed   The image filename or false on failure
     */
    protected function generateThumbnail($filepath) {
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

        /*
        // ImageMagick can generate thumbnails from non-PDF's if a suitable delegate is install
        // (e.g. LibreOffice), but KEEP FOR REFERENCE:

        // If it's not a PDF, we need to convert it one to be able to created the thumbnail, then
        // delete it:
        if ($type != 'application/pdf') {
            $info = pathinfo($file);
            // Note unoconv default export format is PDF so we don't need to specify:
            $cmd         = 'unoconv -e PageRange=1 "' . $file . '"';
            $output      = exec($cmd . ' 2>&1');
            $pdf_file    = preg_replace('#\.' . $info['extension'] . '$#', '.pdf', $file);
        }
        */

        // PDF's that aren't A4 seem to default to A4, so see if we can fix that:
        // UPDATE this happened for PDF's exported from Excel where you select (just the chart) or
        // similar. For some reason that doesn't work very well and retains an A4 media box.
        // Printing those PDF's to another PDF solved the problem, and I wasn't able to find a way
        // to fix this here.
        /*if ($filetype == 'application/pdf') {
        }*/


        // We just add .png to the thumbnail filename so we can determine the real filename in the
        // 'Downloads Media Hack' (see elsewhere):
        #$img_filepath = $filepath . '.png.preview';
        $img_filepath = $filepath . '.png';

        $thumbsize        = $this->params->get('thumbsize', '1200');

        $imagemagick_path = 'HOME=/tmp convert';

        // ---Imagemagick/Ghostscript now fails with the -colorspace flag in place.
        // I haven't been able to figure out why, so just removing it for now as it still
        // seems to work without it, though the colours aren't great.---
        // UPDATE 20190603 - seems to have been resolved. At least it's working now so
        // reinstating -colorspace to fix colours.
        // Note "-background white -alpha remove -flatten -alpha off" adds a white background.
        $options  = ' -strip -colorspace rgb -density 300x300 -resize ' . $thumbsize . 'x'. $thumbsize . ' -quality 90 ';
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

            $img_filepath = $this->generateThumbnail($root_files_path . $object->path . '/' . $filename);

            // Did the image get generated?
            if (file_exists($img_filepath)) {
                // Looks like it's going to be ok, so delete it (in case something else fails the upload):
                File::delete($img_filepath);
                $this->getAdapter($object->adapter)->delete($object->path . '/' . $tmp_name);
                return;
            } else {
                // Something went wrong, reject the the upload and warn the user:
                //JError::raiseWarning(100, Text::_('PLG_SYSTEM_ASSETS_ERROR_FILE_PROCESS_FAIL'));
                throw new GenericDataException(Text::_('PLG_SYSTEM_ASSETS_ERROR_FILE_THUMB_FAIL'), 100);

                return;
            }

            return;
        }


        // The following is a totally seperate part of the mechanism to the file thumb generation
        // above. It just happens to need to hook into the same event. Don't get confused, they
        // are separate.

        // Check if we're saving an article:
        if ($context == 'com_content.article') {
            #echo '<pre>'; var_dump($data); echo '</pre>'; exit;
            // NEW! I think here I will have to alter the paths to check for and generate unlock
            // files in the new hidden downloads folder
            // !Maybe! - the .htaccess maybe easier (only possible) with unloack files in the same
            // dir not sure. unlock files won't show up in the Media Manager I don't think (unless
            // the extension is added to the config)
            // OR - perhaps we add an extra extension to hide the real download and htaccess that
            // away???

            // Delete the unlock files of all files that were assigned to this article when it was
            // last saved:
            $files = false;
            if (isset($data['attribs']['assets-downloads-list'])) {
                $s = $data['attribs']['assets-downloads-list'];
                /* Already decoded in onContentPrepareData but keep for now.
                $s = base64_decode($data['attribs']['assets-downloads-list'], true);
                if ($s) {
                    $files = str_replace("\r", '', trim(gzuncompress($s)));
                }
                */
                $files = str_replace("\r", '', trim($s));
            }

            if (!empty($files)) {
                $files = explode("\n", $files);

                foreach ($files as $file) {
                    $file = JPATH_ROOT . '/assets/downloads/' . urldecode($file) . '.unlock';
                    if (file_exists($file)) {
                        File::delete($file);
                    }
                }
            }

            // Next, get all the files now associated with the article:
            $html = $data['articletext'];
            $new_file_list = '';

            preg_match_all('#href="(\/assets/downloads/([^"]+))"#', $html, $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $file = JPATH_ROOT . '/assets/downloads/' . urldecode($match[2]) . '.download';
                if (file_exists($file)) {
                    File::write($file . '.unlock', time());
                    $new_file_list .= $match[2] . "\n";
                }
            }
            $new_file_list = base64_encode(gzcompress($new_file_list));

            $registry = new Registry;
            $registry->loadString($object->attribs);
            $registry['assets-downloads-list'] = $new_file_list;
            $new_attribs = $registry->toString();

            $object->attribs = $new_attribs;

            return;
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
        if ($context != 'com_media.file') {
            return;
        }

        $media_files_path = ComponentHelper::getParams('com_media')->get('file_path', 'images');
        $root_files_path = JPATH_ROOT .'/' . $media_files_path;

        $full_path = $root_files_path . $object->path . '/' . $object->name;
        #Log::add(print_r($full_path, true)); exit;
        $filetype = mime_content_type($full_path);
        #Log::add(print_r($mime_type, true)); exit;

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

        // If this is an image file, we're done:
        if (
            strpos($filetype, 'image') !== false
         || strpos($filetype, 'audio/mpeg') !== false
        ) {
            return;
        }

        $img_filepath = $this->generateThumbnail($full_path);

        /*if ($img_filepath) {
            // Rename them to add a .download extension or something?
            File::move($full_path, $full_path . '.download');
        }*/

        return;

    }

    /**
     * The delete event.
     *
     * @param   string    $context  The context
     * @param   \stdClass  $item     The item
     *
     * @return  void
     *
     * @since   3.7.0
     */
    public function onContentAfterDelete(Event $event): void
    {
        [$context, $item] = array_values($event->getArguments());

        // Check if we're saving an media file:
        if ($context != 'com_media.file') {
            return;
        }

        // File has been deleted, if this is a PNG preview we need to also delete the corresponding
        // .download and .unlock files.
        $media_files_path = ComponentHelper::getParams('com_media')->get('file_path', 'images');
        $root_files_path = JPATH_ROOT .'/' . $media_files_path;

        $download_file = $root_files_path . preg_replace('#\.png$#','.download', $item->path);
        if (file_exists($download_file)) {
            File::delete($download_file);
        }

        $unlock_file = $root_files_path . preg_replace('#\.png$#','.unlock', $item->path);
        if (file_exists($unlock_file)) {
            File::delete($unlock_file);
        }
    }

    /**
     * Runs on content preparation
     *
     * @param   Event  $event
     *
     * @return  boolean
     *
     * @since   1.6
     */
    public function onContentPrepareData(Event $event): void
    {
        [$context, $data] = array_values($event->getArguments());

        if ($context == 'com_content.article' && !empty($data->attribs['assets-downloads-list'])) {
            $list = $data->attribs['assets-downloads-list'];
            $string = '';
            // Check if the string is base64 encoded:
            if (base64_encode(base64_decode($list, true)) === $list){
                $string = base64_decode($list, true);
            } else {
                $string = $list;
            }

            // Check if the string was gzipped:
            if (@gzuncompress($string) == false) {
                $result = $string;
            } else {
                $result = gzuncompress($string);
            }

            $data->attribs['assets-downloads-list'] = $result;
        }
    }

    /**
     * Prepare form.
     *
     * @param   Event  $event
     *
     * @return  boolean
     */
    public function onContentPrepareForm(Event $event): void
    {
        [$form, $data] = array_values($event->getArguments());

        if (!($form instanceof \Joomla\CMS\Form\Form)) {
            $this->_subject->setError('JERROR_NOT_A_FORM');
            return;
        }

        if ($form->getName() != 'com_content.article') {
            return; // We only want to manipulate the article form.
        }

        // Add the extra fields to the form
        FormHelper::addFormPath(dirname(dirname(__DIR__)) . '/forms');
        $form->loadFile('assets', false);
        return;
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
        #Log::add(print_r($plugin_folder, true));
        $document->addStyleSheet($plugin_folder . '/css/assets.css');
        $document->addScript($plugin_folder . '/js/assets.js');
    }

    /** ARRRGH - The whole MEDIA thing is generated by JS so I can't use this here.
     * After Render Event. Check whether the current page is excluded from cache.
     *
     * @param   Event  $event  The CMS event we are handling.
     *
     * @return  void
     *
     * @since   3.9.12
     */
    /*public function onAfterRender(Event $event)
    {
        //Log::add(print_r($event->getArguments(), true));
        // Event has no context argument so we need to look for that elsewhere:
        $app = Factory::getApplication();

        if ($app->isClient('site')) {
            return;
        }

        $uri = Uri::getInstance();

        if ($uri->getVar('option') != 'com_media') {
            return;
        }

        $body = $app->getBody();

        // We need to replace various visual inconsistencies in the interface. Mainly to remove the
        // the '.png' extension for all visible views, but preserve it for forms and img src etc.

        // 1. <div class="media-browser-item-preview" title="<<<FILENAME>>>.pdf.png">
        #preg_match_all('#<div[^>]+class="media-browser-item-preview"[^>]+title="("[^"]*)\.png"  +src="[^"]*   /assets/downloads/([^"]+)"#', $response, $matches, PREG_SET_ORDER);
        $body = preg_replace('#(<div[^>]+class="media-browser-item-preview"[^>]+title="[^"]+)\.png"#m', '$1"', $body, -1, $count);

        Log::add(print_r($count, true));


        $app->setBody($body);
    }*/

    /**
     * onAfterRender
     * Joomla's Media component does not handle using it for downloads. Rather than replace the
     * component or hack it about, we've ensured there's always a thumbnail of each download, so the
     * the media component is effectively tricked into displaying all the available downloads, since
     * there always exists a matching image (the Media Component will only show images when opened
     * as a model for the Image file input, and there isn't a Downloads equivalent).
     * However, we need to make sure the actual file path is passed back to the editor, so we need
     * to replace all occurrences of the image filename with the download filename, except where it
     * appears as the thumbnail src.
     * This is why thumbnails have .png appended to the full filename, rather than replacing the
     * extension.
     */
    /*public function onAfterRender()
    {
        $app = Factory::getApplication();

        if ($app->isClient('site')) {
            return;
        }

        $uri = Uri::getInstance();

        if ($uri->getVar('option') != 'com_media') {
            return;
        }

        $body = $app->getBody();

        if ($uri->getVar('view') == 'imagesList') {
            preg_match_all('#<img[^>]+src="[^"]*   /assets/downloads/([^"]+)"#', $response, $matches, PREG_SET_ORDER);

            foreach ($matches as $match) {
                $info     = pathinfo($match[1]);

                // Replace the whole image tag temporarily:
                $body = str_replace($match[0], '<<<TMP-IMAGE>>>', $body);

                // Replace all other occurrences of the filename:
                $body = str_replace('/' . $info['basename'], '/'  . $info['filename'], $body);

                // Restore the image tag:
                $body = str_replace('<<<TMP-IMAGE>>>', $match[0], $body);
            }
        }

        if ($uri->getVar('view') == 'images' && $uri->getVar('fieldid') == 'jfile_href') {
            // Update labels image -> file:
            $body = str_replace('<label for="f_url">Image URL</label>', '<label for="f_url">File URL</label>', $body);
        }


        // NEW! I think here I will have the check for e.g. ".pdf" etc and replace with ".pdf.png" so the thumbnails are
        // shown

        $app->setBody($body);
    }*/
}