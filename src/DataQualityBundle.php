<?php

declare(strict_types=1);

namespace Basilicom\DataQualityBundle;

use Basilicom\DataQualityBundle\DependencyInjection\Compiler\LanguageFlagsProviderPass;
use Basilicom\DataQualityBundle\DependencyInjection\Compiler\RuleDefinitionPass;
use Basilicom\DataQualityBundle\Tools\Installer;
use Exception;
use Pimcore\Extension\Bundle\AbstractPimcoreBundle;
use Pimcore\Extension\Bundle\Traits\PackageVersionTrait;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class DataQualityBundle extends AbstractPimcoreBundle
{
    use PackageVersionTrait {
        getVersion as protected getComposerVersion;
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new RuleDefinitionPass());
        $container->addCompilerPass(new LanguageFlagsProviderPass());
    }

    public function getInstaller(): Installer
    {
        /** @var Installer $installer */
        $installer = $this->container->get(Installer::class);

        return $installer;
    }

    protected function getComposerPackageName(): string
    {
        return 'basilicom/pimcore-data-quality-bundle';
    }

    public function getVersion(): string
    {
        try {
            return $this->getComposerVersion();
        } catch (Exception) {
            return 'unknown';
        }
    }
}
