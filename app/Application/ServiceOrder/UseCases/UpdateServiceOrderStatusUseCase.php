<?php

namespace App\Application\ServiceOrder\UseCases;

use App\Application\ServiceOrder\DTOs\UpdateServiceOrderStatusDTO;
use App\Application\ServiceOrder\UseCases\Notification\OrderApprovalUseCase;
use App\Domain\ServiceOrder\Entites\ServiceOrderEntity;
use App\Domain\ServiceOrder\Exceptions\ServiceOrderNotFoundException;
use App\Domain\ServiceOrder\Repositories\ServiceOrderRepositoryInterface;
use App\Domain\Vehicule\Entites\VehiculeEntity;
use App\Domain\Vehicule\Repositories\VehiculeRepositoryInterface;
use App\Infrastructure\Observability\ServiceOrderEvents;
use Illuminate\Support\Facades\DB;
use Throwable;

final class UpdateServiceOrderStatusUseCase
{
    public function __construct(
        private readonly ServiceOrderRepositoryInterface $serviceOrderRepository,
        private readonly VehiculeRepositoryInterface $vehiculeRepository,
        private readonly OrderApprovalUseCase $orderApprovalUseCase,
        private readonly ServiceOrderEvents $events,
    ) {}

    public function execute(int $serviceOrderId, UpdateServiceOrderStatusDTO $dto): void
    {
        $serviceOrder = $this->serviceOrderRepository->findById($serviceOrderId);

        if (!$serviceOrder) {
            throw ServiceOrderNotFoundException::withId($serviceOrderId);
        }

        $vehicule = $this->vehiculeRepository->findByIdIgnoringStatus($serviceOrder->getVehiculesId());

        $average = DB::transaction(function () use ($dto, $serviceOrder, $vehicule) {
            $this->serviceOrderRepository->createStatusHistory(
                $serviceOrder->getId(),
                $dto->statusId,
                $vehicule->getCustomerId(),
                $serviceOrder->getUsersId(),
                $serviceOrder->getUsersRoleId(),
            );

            $average = $this->calculateAverageTime($serviceOrder->getId());

            $this->serviceOrderRepository->update($serviceOrder->getId(), [
                'time_average' => $average,
            ]);

            if ($dto->statusId === ServiceOrderEntity::STATUS_AGUARDANDO_APROVACAO) {
                $this->requestApproval($serviceOrder->getId(), $vehicule);
            }

            return $average;
        });

        // Depois do commit, de propósito: um evento emitido dentro da transação continuaria
        // no log mesmo se ela desfizesse tudo, e o painel contaria uma mudança que não houve.
        //
        // `time_average_minutes` é o mesmo número que a aplicação já gravava na tabela. Levá-lo
        // para o log é o que permite ao painel de "tempo médio por status" existir sem consultar
        // o banco — o Grafana Cloud não alcança o RDS.
        $this->events->statusChanged($serviceOrder->getId(), $dto->statusId, $average);
    }

    /**
     * A notificação é a única dependência externa do fluxo (SMTP) — é o "erro na integração"
     * que o requisito manda monitorar, então a falha precisa virar evento próprio.
     *
     * A exceção é relançada: o comportamento atual é desfazer a transação quando o cliente
     * não pôde ser avisado, e observabilidade não é lugar de mudar isso. Vale registrar que
     * é discutível — a mudança de status já aconteceu de fato, e o reenvio do aviso poderia
     * ser tratado à parte.
     */
    private function requestApproval(int $serviceOrderId, VehiculeEntity $vehicule): void
    {
        try {
            $this->orderApprovalUseCase->requestApproval(
                to: $vehicule->getCustomer()->getEmail(),
                serviceOrderId: $serviceOrderId,
                customerId: $vehicule->getCustomerId(),
            );
        } catch (Throwable $falha) {
            $this->events->approvalRequestFailed($serviceOrderId, $vehicule->getCustomerId(), $falha);

            throw $falha;
        }

        $this->events->approvalRequested($serviceOrderId, $vehicule->getCustomerId());
    }

    private function calculateAverageTime(int $orderId): float
    {
        $history = $this->serviceOrderRepository->findByIdIgnoringStatus($orderId)->getStatusHistory();

        if (count($history) < 2) {
            return 0;
        }

        $totalMinutes = 0;

        for ($i = 1; $i < count($history); $i++) {
            $previous = $history[$i - 1]->getCreatedAt();
            $current = $history[$i]->getCreatedAt();

            $totalMinutes += ($current->getTimestamp() - $previous->getTimestamp()) / 60;
        }

        return round($totalMinutes / (count($history) - 1), 2);
    }
}
