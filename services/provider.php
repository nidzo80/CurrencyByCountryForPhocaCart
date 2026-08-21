<?php
/**
 * @package    Plg_Pcv_Currencybycountry
 * @license    GNU General Public License version 3 or later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Database\DatabaseInterface;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Event\DispatcherInterface;
use YourVendor\Plugin\Pcv\Currencybycountry\Extension\Currencybycountry;

return new class implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->set(
            PluginInterface::class,
            function (Container $container) {
                $plugin  = PluginHelper::getPlugin('system', 'currencybycountry');
                $subject = $container->get(DispatcherInterface::class);

                $plugin = new Currencybycountry($subject, (array) $plugin);
                $plugin->setApplication(Factory::getApplication());
                $plugin->setDatabase($container->get(DatabaseInterface::class));

                return $plugin;
            }
        );
    }
};
