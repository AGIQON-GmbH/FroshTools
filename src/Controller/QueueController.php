<?php

declare(strict_types=1);

namespace Frosh\Tools\Controller;

use Doctrine\DBAL\Connection;
use Frosh\Tools\Components\QueueInfoService;
use Shopware\Core\Framework\Increment\IncrementGatewayRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(path: '/api/_action/frosh-tools', defaults: ['_routeScope' => ['api'], '_acl' => ['frosh_tools:read']])]
class QueueController extends AbstractController
{
    public function __construct(
        private readonly Connection $connection,
        #[Autowire(service: 'shopware.increment.gateway.registry')]
        private readonly IncrementGatewayRegistry $incrementer,
        private readonly QueueInfoService $queueInfoService,
    ) {
    }

    #[Route(path: '/queue/list', name: 'api.frosh.tools.queue.list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        return new JsonResponse($this->queueInfoService->getQueueInfo());
    }

    #[Route(path: '/queue', name: 'api.frosh.tools.queue.clear', methods: ['DELETE'])]
    public function resetQueue(): JsonResponse
    {
        $incrementer = $this->incrementer->get('message_queue');
        $incrementer->reset('message_queue_stats');

        $this->connection->executeStatement('TRUNCATE `messenger_messages`');
        $this->connection->executeStatement('UPDATE product_export SET is_running = 0');

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
