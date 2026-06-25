<?php

declare(strict_types=1);

namespace Frosh\Tools\Components;

use Shopware\Core\Framework\Increment\IncrementGatewayRegistry;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpTransport;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport;
use Symfony\Component\Messenger\Bridge\Redis\Transport\RedisTransport;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;

final class QueueInfoService
{
    /**
     * @param ServiceLocator<ReceiverInterface> $transportLocator
     * @param array<string, list<string>>       $messengerRouting
     */
    public function __construct(
        #[Autowire(service: 'shopware.increment.gateway.registry')]
        private readonly IncrementGatewayRegistry $incrementer,
        #[Autowire(service: 'messenger.receiver_locator')]
        private readonly ServiceLocator $transportLocator,
        #[Autowire(param: 'frosh_tools.messenger_routing')]
        private readonly array $messengerRouting,
        private readonly SystemConfigService $systemConfigService,
    ) {
    }

    /**
     * Returns a flat array of queue info entries. Each entry is one of:
     *   - message entry:   ['name' => string, 'size' => int, 'transports' => list<string>]
     *   - transport entry: ['name' => string, 'size' => int, 'type' => string]
     *
     * Message entries come first, then transport entries, both sorted by size descending.
     * A size of -1 on a transport entry means the count is unavailable.
     *
     * @return list<array{name: string, size: int, transports?: list<string>, type?: string}>
     */
    public function getQueueInfo(): array
    {
        $messageEntries   = $this->buildMessageEntries();
        $transportEntries = $this->buildTransportEntries();

        $entries = array_merge($messageEntries, $transportEntries);

        usort($entries, static function (array $a, array $b): int {
            // Treat -1 (unknown) as 0 for sort purposes so known counts sort above unknown.
            $sizeA = $a['size'] === -1 ? 0 : $a['size'];
            $sizeB = $b['size'] === -1 ? 0 : $b['size'];

            return $sizeB <=> $sizeA;
        });

        return array_values($entries);
    }

    /**
     * @return list<array{name: string, size: int, transports: list<string>}>
     */
    private function buildMessageEntries(): array
    {
        $incrementer = $this->incrementer->get('message_queue');
        $list        = $incrementer->list('message_queue_stats', -1);

        $showZeroCount = $this->systemConfigService->getBool('FroshTools.config.queueShowZeroCountMessages');

        $entries = [];
        foreach (array_values($list) as $entry) {
            if (!$showZeroCount && (int) $entry['count'] === 0) {
                continue;
            }

            $fqcn      = (string) $entry['key'];
            $entries[] = [
                'name'       => $fqcn,
                'size'       => (int) $entry['count'],
                'transports' => $this->resolveTransportsForMessage($fqcn),
            ];
        }

        return $entries;
    }

    /**
     * @return list<array{name: string, size: int, type: string}>
     */
    private function buildTransportEntries(): array
    {
        $entries = [];

        foreach ($this->getShortTransportNames() as $name) {
            if (!$this->transportLocator->has($name)) {
                continue;
            }

            $transport = $this->transportLocator->get($name);

            $size = $transport instanceof MessageCountAwareInterface
                ? $transport->getMessageCount()
                : -1;

            $entries[] = [
                'name' => $name,
                'size' => $size,
                'type' => $this->resolveTransportType($transport),
            ];
        }

        return $entries;
    }

    /**
     * Resolves which transport aliases a message FQCN is routed to.
     *
     * Explicit class routing takes precedence over interface-based routing,
     * mirroring Symfony Messenger's "most specific wins" behaviour.
     *
     * @return list<string>
     */
    private function resolveTransportsForMessage(string $fqcn): array
    {
        // Exact class match → use only this routing, skip interface fallback.
        if (isset($this->messengerRouting[$fqcn])) {
            return array_values(array_unique($this->messengerRouting[$fqcn]));
        }

        // Interface / parent-class fallback.
        $transports = [];

        foreach ($this->messengerRouting as $routingKey => $aliases) {
            if (!is_a($fqcn, $routingKey, true)) {
                continue;
            }

            foreach ($aliases as $alias) {
                if (!\in_array($alias, $transports, true)) {
                    $transports[] = $alias;
                }
            }
        }

        return $transports;
    }

    /**
     * Determines the human-readable transport type from the receiver object.
     * Uses class_exists guards so optional bridge packages are handled gracefully.
     */
    private function resolveTransportType(ReceiverInterface $transport): string
    {
        if ($transport instanceof DoctrineTransport) {
            return 'Doctrine';
        }

        /** @phpstan-ignore-next-line */
        if (class_exists(RedisTransport::class)
            && $transport instanceof RedisTransport) {
            return 'Redis';
        }

        /** @phpstan-ignore-next-line */
        if (class_exists(AmqpTransport::class)
            && $transport instanceof AmqpTransport) {
            return 'AMQP';
        }

        return 'Unknown';
    }

    /**
     * Returns only the short transport alias names (e.g. "async", "low_priority"),
     * filtering out the long-form service IDs (e.g. "messenger.transport.async").
     *
     * @return list<string>
     */
    private function getShortTransportNames(): array
    {
        $all = array_keys($this->transportLocator->getProvidedServices());

        return array_values(
            array_filter($all, static fn (string $name): bool => !str_starts_with($name, 'messenger.transport.'))
        );
    }
}
