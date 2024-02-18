<?php

namespace Paysera\Bundle\RestBundle;

use Paysera\Bundle\RestBundle\DependencyInjection\Compiler\ApiCompilerPass;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * @deprecated Use https://github.com/paysera/lib-api-bundle instead.
 */
class PayseraRestBundle extends Bundle
{
    /**
     * Builds the bundle.
     *
     * It is only ever called once when the cache is empty.
     *
     * This method can be overridden to register compilation passes,
     * other extensions, ...
     *
     * @param ContainerBuilder $container
     */
    public function build(ContainerBuilder $container)
    {
        @trigger_error('Use https://github.com/paysera/lib-api-bundle instead', E_USER_DEPRECATED);

        parent::build($container);

        $container->addCompilerPass(new ApiCompilerPass());
    }
}
