<?php

namespace App\Application\ServiceOrder\UseCases;

use App\Application\ServiceOrder\DTOs\ServiceOrderDTO;
use App\Domain\ServiceOrder\Entites\ServiceOrderEntity;
use App\Domain\ServiceOrder\Repositories\ServiceOrderRepositoryInterface;
use App\Domain\Vehicule\Exceptions\VehiculeNotFoundException;
use App\Domain\Vehicule\Repositories\VehiculeRepositoryInterface;
use App\Infrastructure\Observability\ServiceOrderEvents;
use Illuminate\Support\Facades\DB;

/**
 * Cria a ordem e registra o status inicial "Recebida" em uma única transação.
 * O valor inicial é 0 — será atualizado quando produtos/serviços forem adicionados.
 */
final class CreateServiceOrderUseCase
{
    public function __construct(
        private readonly ServiceOrderRepositoryInterface $serviceOrderRepository,
        private readonly VehiculeRepositoryInterface $vehiculeRepository,
        private readonly ServiceOrderEvents $events,
    ) {}

    public function execute(ServiceOrderDTO $dto): ServiceOrderEntity
    {
        $vehicule = $this->vehiculeRepository->findByIdIgnoringStatus($dto->vehiculesId);

        if (!$vehicule) {
            throw VehiculeNotFoundException::withId($dto->vehiculesId);
        }

        $order = DB::transaction(function () use ($dto, $vehicule) {
            $order = $this->serviceOrderRepository->create([
                'users_id'      => $dto->usersId,
                'users_role_id' => $dto->usersRoleId,
                'vehicules_id'  => $dto->vehiculesId,
                'order_value'   => 0.00,
                'time_average'  => $dto->timeAverage,
                'status'        => true,
                'create_date'   => now()->toDateTimeString(),
            ]);

            $this->serviceOrderRepository->createStatusHistory(
                $order->getId(),
                ServiceOrderEntity::STATUS_RECEBIDA,
                $vehicule->getCustomerId(),
                $dto->usersId,
                $dto->usersRoleId,
            );

            return $this->serviceOrderRepository->findByIdIgnoringStatus($order->getId());
        });

        // Depois do commit, de propósito: um evento emitido dentro da transação continuaria
        // no log mesmo se ela desfizesse tudo, e o painel contaria uma ordem que não existe.
        $this->events->created($order->getId(), $order->getOrderValue());
        $this->events->statusChanged($order->getId(), ServiceOrderEntity::STATUS_RECEBIDA);

        return $order;
    }
}
