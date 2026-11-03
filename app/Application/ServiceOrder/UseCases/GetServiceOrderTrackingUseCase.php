<?php

namespace App\Application\ServiceOrder\UseCases;

use App\Domain\ServiceOrder\Entites\ServiceOrderEntity;
use App\Domain\ServiceOrder\Exceptions\ServiceOrderNotFoundException;
use App\Domain\ServiceOrder\Repositories\ServiceOrderRepositoryInterface;

final class GetServiceOrderTrackingUseCase
{
    public function __construct(
        private readonly ServiceOrderRepositoryInterface $serviceOrderRepository,
    ) {}

    /**
     * @param int $customerId Dono do veículo da ordem, extraído do JWT do cliente autenticado.
     *
     * Lança "não encontrada" (404) e não "acesso negado" (403) quando a ordem existe mas
     * não pertence ao cliente — evita confirmar pra quem não tem acesso que aquele ID existe.
     */
    public function execute(int $orderId, int $customerId): ServiceOrderEntity
    {
        $order = $this->serviceOrderRepository->findWithCurrentStatus($orderId);

        if (!$order || !$order->getVehicule()?->belongsToCustomer($customerId)) {
            throw ServiceOrderNotFoundException::withId($orderId);
        }

        return $order;
    }
}
