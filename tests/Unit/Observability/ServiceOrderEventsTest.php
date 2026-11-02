<?php

namespace Tests\Unit\Observability;

use App\Domain\ServiceOrder\Entites\ServiceOrderEntity;
use App\Infrastructure\Observability\ServiceOrderEvents;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

/**
 * Os nomes e os campos dos eventos de negócio são **contrato**: painel e alerta no Grafana
 * filtram por eles. Um erro de digitação num caso de uso não quebraria teste nenhum — só
 * faria um painel parar de contar, em silêncio. Estes testes são o que torna isso visível.
 */
class ServiceOrderEventsTest extends TestCase
{
    public function test_o_status_vai_por_nome_e_nao_so_por_id(): void
    {
        // Um painel de "tempo médio por status" com a legenda "3" não diz nada a quem olha.
        $contexto = $this->contextoDe(fn (ServiceOrderEvents $e) => $e->statusChanged(
            42,
            ServiceOrderEntity::STATUS_AGUARDANDO_APROVACAO,
            17.5,
        ));

        $this->assertSame(ServiceOrderEvents::STATUS_CHANGED, $contexto['event']);
        $this->assertSame('aguardando_aprovacao', $contexto['status']);
        $this->assertSame(3, $contexto['status_id']);
        $this->assertSame(42, $contexto['service_order_id']);
        $this->assertSame(17.5, $contexto['time_average_minutes']);
    }

    public function test_status_desconhecido_nao_derruba_a_operacao(): void
    {
        // Observabilidade não pode quebrar a ordem de serviço que ela observa.
        $contexto = $this->contextoDe(fn (ServiceOrderEvents $e) => $e->statusChanged(42, 99));

        $this->assertSame('desconhecido', $contexto['status']);
    }

    public function test_todo_status_do_dominio_tem_nome(): void
    {
        $constantes = (new \ReflectionClass(ServiceOrderEntity::class))->getConstants();

        foreach ($constantes as $nome => $valor) {
            if (!str_starts_with($nome, 'STATUS_') || !is_int($valor)) {
                continue;
            }

            $this->assertArrayHasKey(
                $valor,
                ServiceOrderEntity::STATUS_NAMES,
                "o status {$nome} não tem nome em STATUS_NAMES e sairia como 'desconhecido' no painel"
            );
        }
    }

    public function test_a_falha_de_integracao_sai_como_error_com_a_classe_da_excecao(): void
    {
        // A mensagem de um erro de SMTP costuma trazer o e-mail do destinatário; a classe não.
        $falha = new RuntimeException('Failed to send to cliente@exemplo.com.br');

        [$nivel, $contexto] = $this->registroDe(
            fn (ServiceOrderEvents $e) => $e->approvalRequestFailed(42, 7, $falha)
        );

        $this->assertSame('error', $nivel);
        $this->assertSame(ServiceOrderEvents::APPROVAL_REQUEST_FAILED, $contexto['event']);
        $this->assertSame(RuntimeException::class, $contexto['failure_class']);
        $this->assertArrayNotHasKey('failure_message', $contexto);
        $this->assertStringNotContainsString('cliente@exemplo.com.br', json_encode($contexto) ?: '');
    }

    public function test_nenhum_evento_carrega_dado_pessoal(): void
    {
        $eventos = [
            fn (ServiceOrderEvents $e) => $e->created(42, 1250.0),
            fn (ServiceOrderEvents $e) => $e->statusChanged(42, 1),
            fn (ServiceOrderEvents $e) => $e->approvalRequested(42, 7),
            fn (ServiceOrderEvents $e) => $e->approvedByCustomer(42, 7),
            fn (ServiceOrderEvents $e) => $e->rejectedByCustomer(42, 7),
        ];

        $permitidos = [
            'event', 'service_order_id', 'customer_id', 'order_value', 'status', 'status_id',
            'time_average_minutes',
        ];

        foreach ($eventos as $emitir) {
            foreach (array_keys($this->contextoDe($emitir)) as $campo) {
                $this->assertContains(
                    $campo,
                    $permitidos,
                    "o campo '{$campo}' não está na lista de campos permitidos em evento de negócio"
                );
            }
        }
    }

    /**
     * @param  callable(ServiceOrderEvents): void  $emitir
     * @return array<string, mixed>
     */
    private function contextoDe(callable $emitir): array
    {
        return $this->registroDe($emitir)[1];
    }

    /**
     * @param  callable(ServiceOrderEvents): void  $emitir
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function registroDe(callable $emitir): array
    {
        $registro = null;

        Log::shouldReceive('log')->once()->andReturnUsing(
            function (string $level, string $message, array $context) use (&$registro): void {
                $registro = [$level, $context];
            }
        );

        $emitir(new ServiceOrderEvents());

        $this->assertNotNull($registro, 'nenhum evento foi emitido');

        return $registro;
    }
}
