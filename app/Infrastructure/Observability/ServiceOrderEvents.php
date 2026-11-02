<?php

namespace App\Infrastructure\Observability;

use App\Domain\ServiceOrder\Entites\ServiceOrderEntity;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Eventos de negócio da ordem de serviço.
 *
 * Existe como classe, e não como `Log::info(...)` espalhado pelos casos de uso, por dois
 * motivos:
 *
 * 1. **Os nomes dos eventos são contrato.** Painel e alerta no Grafana filtram por
 *    `event="service_order_status_changed"`. Um erro de digitação num caso de uso não quebra
 *    teste nenhum — só faz um painel parar de contar, silenciosamente. Com os nomes num lugar
 *    só, e com teste em cima deles, isso não passa batido.
 * 2. **O Grafana Cloud não alcança o RDS** (o banco só aceita conexão de dentro da VPC), então
 *    os painéis de negócio são calculados a partir destes eventos, e não de SQL. O log aqui é
 *    a fonte de dados do dashboard, não um registro auxiliar.
 *
 * Regras que valem para todo evento daqui:
 *
 * - **Nenhum dado pessoal.** Só identificadores internos (`service_order_id`, `customer_id`).
 *   Nada de e-mail, CPF ou nome. As duas camadas de mascaramento da fase 2 cobrem o caso de
 *   escapar algo, mas o certo é não escrever.
 * - **Status vai por nome**, não por ID: a legenda do painel precisa ser legível.
 * - **Nunca lança exceção.** Observabilidade não pode derrubar a operação que observa.
 */
final class ServiceOrderEvents
{
    public const CREATED = 'service_order_created';
    public const STATUS_CHANGED = 'service_order_status_changed';
    public const APPROVED_BY_CUSTOMER = 'service_order_approved_by_customer';
    public const REJECTED_BY_CUSTOMER = 'service_order_rejected_by_customer';
    public const APPROVAL_REQUESTED = 'service_order_approval_requested';
    public const APPROVAL_REQUEST_FAILED = 'service_order_approval_request_failed';

    public function created(int $serviceOrderId, float $orderValue): void
    {
        $this->emit(self::CREATED, 'Ordem de serviço aberta', [
            'service_order_id' => $serviceOrderId,
            'order_value' => $orderValue,
        ]);
    }

    /**
     * A mudança de status é o evento mais importante do conjunto: é dele que saem o volume
     * diário de ordens e o tempo médio em cada etapa.
     *
     * `time_average_minutes` é o que a aplicação já calculava e gravava na tabela; levá-lo
     * para o log é o que permite ao painel mostrar tempo médio sem consultar o banco.
     */
    public function statusChanged(int $serviceOrderId, int $statusId, ?float $timeAverageMinutes = null): void
    {
        $this->emit(self::STATUS_CHANGED, 'Status da ordem de serviço alterado', [
            'service_order_id' => $serviceOrderId,
            'status' => ServiceOrderEntity::statusName($statusId),
            'status_id' => $statusId,
            'time_average_minutes' => $timeAverageMinutes,
        ]);
    }

    public function approvalRequested(int $serviceOrderId, int $customerId): void
    {
        $this->emit(self::APPROVAL_REQUESTED, 'Aprovação solicitada ao cliente', [
            'service_order_id' => $serviceOrderId,
            'customer_id' => $customerId,
        ]);
    }

    /**
     * Falha ao notificar o cliente — o "erro na integração" que o requisito pede para
     * monitorar. Registrado como `error` para o alerta poder disparar em cima do nível.
     *
     * Guarda a **classe** da exceção, não a mensagem: a mensagem de um erro de SMTP costuma
     * trazer endereço de e-mail do destinatário.
     */
    public function approvalRequestFailed(int $serviceOrderId, int $customerId, Throwable $failure): void
    {
        $this->emit(self::APPROVAL_REQUEST_FAILED, 'Falha ao solicitar aprovação ao cliente', [
            'service_order_id' => $serviceOrderId,
            'customer_id' => $customerId,
            'failure_class' => get_class($failure),
        ], 'error');
    }

    public function approvedByCustomer(int $serviceOrderId, int $customerId): void
    {
        $this->emit(self::APPROVED_BY_CUSTOMER, 'Ordem de serviço aprovada pelo cliente', [
            'service_order_id' => $serviceOrderId,
            'customer_id' => $customerId,
        ]);
    }

    public function rejectedByCustomer(int $serviceOrderId, int $customerId): void
    {
        $this->emit(self::REJECTED_BY_CUSTOMER, 'Ordem de serviço reprovada pelo cliente', [
            'service_order_id' => $serviceOrderId,
            'customer_id' => $customerId,
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function emit(string $event, string $message, array $context, string $level = 'info'): void
    {
        try {
            Log::log($level, $message, ['event' => $event] + $context);
        } catch (Throwable) {
            // Um log que falha não pode derrubar uma ordem de serviço.
        }
    }
}
