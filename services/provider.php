<?php
defined('_JEXEC') || die;

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Event\DispatcherInterface;

use NPEU\Plugin\System\Assets\Extension\Assets;

return new class () implements ServiceProviderInterface {
    public function register(Container $container)
    {
        $container->set(
            PluginInterface::class,
            function (Container $container)
            {
                $dispatcher = $container->get(DispatcherInterface::class);
                $config  = (array) PluginHelper::getPlugin('system', 'assets');

                $app = Factory::getApplication();

                /** @var \Joomla\CMS\Plugin\CMSPlugin $plugin */
                $plugin = new Assets(
                    $dispatcher,
                    $config,
                    $app->isClient('administrator')
                );
                $plugin->setApplication($app);

                return $plugin;
            }
        );
    }
};