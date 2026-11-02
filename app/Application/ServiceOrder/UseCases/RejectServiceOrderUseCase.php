<?php

namespace App\Application\ServiceOrder\UseCases;

use App\Domain\ServiceOrder\Entites\ServiceOrderEntity;
use App\Domain\ServiceOrder\Exceptions\ServiceOrderNotFoundException;
use App\Domain\ServiceOrder\Repositories\ServiceOrderRepositoryInterface;
use App\Domain\ServiceOrder\Security\OrderApprovalTokenSigner;
use App\Domain\Vehicule\Repositories\VehiculeRepositoryInterface;
use App\Infrastructure\Observability\ServiceOrderEvents;
use Illuminate\Support\Facades\DB;

final class RejectServiceOrderUseCase
{
    public function __construct(
        private readonly ServiceOrderRepositoryInterface $serviceOrderRepository,
        private readonly VehiculeRepositoryInterface $vehiculeRepository,
        private readonly OrderApprovalTokenSigner $tokenSigner,
        private readonly ServiceOrderEvents $events,
    ) {}

    public function execute(string $token): void
    {
        $payload = $this->tokenSigner->validate($token);

        $serviceOrder = $this->serviceOrderRepository->findById($payload['service_order_id']);

        if (!$serviceOrder) {
            throw ServiceOrderNotFoundException::withId($payload['service_order_id']);
        }

        $vehicule = $this->vehiculeRepository->findByIdIgnoringStatus($serviceOrder->getVehiculesId());

        DB::transaction(function () use ($serviceOrder, $vehicule) {
            $this->serviceOrderRepository->createStatusHistory(
                $serviceOrder->getId(),
                ServiceOrderEntity::STATUS_REPROVADA_PELO_CLIENTE,
                $vehicule->getCustomerId(),
                $serviceOrder->getUsersId(),
                $serviceOrder->getUsersRoleId(),
            );
        });

        // Depois do commit: um evento emitido dentro da transação continuaria no log
        // mesmo se ela desfizesse tudo, e o painel contaria uma aprovação que não houve.
        $this->events->rejectedByCustomer($serviceOrder->getId(), $vehicule->getCustomerId());
        // A linha do tempo de status precisa ficar completa: sem isto, um painel que
        // conta transições não enxergaria as decisões do cliente.
        $this->events->statusChanged($serviceOrder->getId(), ServiceOrderEntity::STATUS_REPROVADA_PELO_CLIENTE);
    }
}
