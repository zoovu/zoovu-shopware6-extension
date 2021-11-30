<?php
declare(strict_types = 1);
/**
 *
 * @category siteSearch360
 * @package Shopware_Plugins
 * @subpackage Plugin
 * @copyright Copyright (c) 2021, siteSearch360
 * @version $Id$
 * @author siteSearch360
 */
namespace semknox\search;
use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\DeactivateContext;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use semknox\search\Framework\SemknoxsearchHelper;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;
use Symfony\Component\Config\FileLocator;
class semknoxSearch extends Plugin
{
    public const SHOPWARECOMPA_PATH = '/Resources/config/compatibility';
    public function build(ContainerBuilder $container): void
    {
        $this->loadServiceXml($container, $this->getShopwareVersionServicesFilePath());
        parent::build($container);
    }
    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);
    }
    private function getShopwareVersionServicesFilePath(): string
    {
        if (SemknoxsearchHelper::shopwareVersionCompare('6.2', '<')) {
            return self::SHOPWARECOMPA_PATH . '/shopware61';
        } elseif (SemknoxsearchHelper::shopwareVersionCompare('6.3', '<')) {
            return self::SHOPWARECOMPA_PATH . '/shopware62';
        } elseif (SemknoxsearchHelper::shopwareVersionCompare('6.4', '<')) {
            return self::SHOPWARECOMPA_PATH . '/shopware63';
        } else {
            return self::SHOPWARECOMPA_PATH . '/latest';
        }
    }
    private function loadServiceXml($container, string $filePath): void
    {
        $loader = new XmlFileLoader(
            $container,
            new FileLocator($this->getPath() . $filePath)
         );
        $loader->load('services.xml');
    }
}
