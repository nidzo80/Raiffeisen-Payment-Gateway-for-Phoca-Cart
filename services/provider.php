<?php
/**
 * @package    Plg_Pcp_RaiAccept
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
use YourVendor\Plugin\Pcp\RaiAccept\Extension\RaiAccept;

return new class implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->set(
            PluginInterface::class,
            function (Container $container) {
                $plugin  = PluginHelper::getPlugin('pcp', 'raiaccept');
                $subject = $container->get(DispatcherInterface::class);

                $plugin = new RaiAccept($subject, (array) $plugin);
                $plugin->setApplication(Factory::getApplication());
                $plugin->setDatabase($container->get(DatabaseInterface::class));

                return $plugin;
            }
        );
    }
};
