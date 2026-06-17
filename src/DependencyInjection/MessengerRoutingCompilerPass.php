<?php

declare(strict_types=1);

namespace Frosh\Tools\DependencyInjection;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class MessengerRoutingCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition('messenger.senders_locator')) {
            $container->setParameter('frosh_tools.messenger_routing', []);

            return;
        }

        /** @var array<string, list<string>> $routingMap */
        $routingMap = $container->getDefinition('messenger.senders_locator')->getArgument(0);
        $container->setParameter('frosh_tools.messenger_routing', $routingMap);
    }
}
