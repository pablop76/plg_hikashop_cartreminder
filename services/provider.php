<?php

defined('_JEXEC') or die;

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Event\DispatcherInterface;
use Pablop76\Plugin\Hikashop\Cartreminder\Extension\Cartreminder;

return new class() implements ServiceProviderInterface
{
    public function register(Container $container)
    {
        $container->set(
            PluginInterface::class,
            function (Container $container) {
                $config = (array) PluginHelper::getPlugin('hikashop', 'cartreminder');
                $subject = $container->get(DispatcherInterface::class);

                $plugin = new Cartreminder($subject, $config);
                $plugin->setApplication(Factory::getApplication());

                return $plugin;
            }
        );
    }
};
