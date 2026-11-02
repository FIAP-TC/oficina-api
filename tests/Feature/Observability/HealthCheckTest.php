<?php

namespace Tests\Feature\Observability;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

/**
 * Sondas de saúde usadas pelas probes do Kubernetes.
 *
 * As checagens de banco são simuladas de propósito: o que precisa ser testado é o
 * comportamento nos dois lados (banco respondendo e banco fora), e derrubar um MySQL de
 * verdade no meio da suíte não é viável.
 */
class HealthCheckTest extends TestCase
{
    public function test_liveness_responde_ok_sem_consultar_o_banco(): void
    {
        // Se a sonda de vida tocasse no banco, uma queda do RDS viraria reinício em laço
        // de todos os pods. Qualquer consulta aqui falha o teste.
        DB::shouldReceive('select')->never();

        $this->getJson('/api/health')
            ->assertOk()
            ->assertExactJson(['status' => 'ok']);
    }

    public function test_readiness_responde_ok_quando_o_banco_responde(): void
    {
        DB::shouldReceive('select')->once()->with('select 1')->andReturn([]);

        $this->getJson('/api/health/ready')
            ->assertOk()
            ->assertExactJson(['status' => 'ok', 'checks' => ['database' => true]]);
    }

    public function test_readiness_responde_503_quando_o_banco_esta_fora(): void
    {
        DB::shouldReceive('select')->once()->andThrow(new RuntimeException('connection refused'));

        $this->getJson('/api/health/ready')
            ->assertStatus(503)
            ->assertExactJson(['status' => 'unavailable', 'checks' => ['database' => false]]);
    }

    public function test_a_resposta_nao_vaza_detalhe_da_falha(): void
    {
        DB::shouldReceive('select')->once()->andThrow(new RuntimeException(
            'SQLSTATE[HY000] [2002] Connection refused for user root at 10.0.4.17'
        ));

        $corpo = $this->getJson('/api/health/ready')->content();

        $this->assertStringNotContainsString('10.0.4.17', $corpo);
        $this->assertStringNotContainsString('root', $corpo);
        $this->assertStringNotContainsString('SQLSTATE', $corpo);
    }

    public function test_sonda_que_passou_nao_vira_linha_de_log(): void
    {
        // A cada 10 segundos, em cada pod: registrar as que passaram encheria a cota de
        // ingestão com linhas dizendo "continua tudo bem".
        DB::shouldReceive('select')->andReturn([]);
        Log::shouldReceive('log')->never();

        $this->getJson('/api/health')->assertOk();
        $this->getJson('/api/health/ready')->assertOk();
    }

    public function test_sonda_que_falhou_vira_linha_de_log(): void
    {
        DB::shouldReceive('select')->andThrow(new RuntimeException('connection refused'));

        $capturado = null;
        Log::shouldReceive('log')->andReturnUsing(
            function (string $level, string $message, array $context) use (&$capturado): void {
                $capturado = [$level, $context];
            }
        );

        $this->getJson('/api/health/ready')->assertStatus(503);

        $this->assertNotNull($capturado, 'a falha da sonda precisa aparecer no log');
        $this->assertSame('error', $capturado[0]);
        $this->assertSame(503, $capturado[1]['status']);
        $this->assertSame('api/health/ready', $capturado[1]['route']);
    }
}
