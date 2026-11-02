<?php

namespace Tests\Unit\Observability;

use App\Infrastructure\Observability\Logging\SensitiveDataMasker;
use Tests\TestCase;

class SensitiveDataMaskerTest extends TestCase
{
    private SensitiveDataMasker $masker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->masker = new SensitiveDataMasker();
    }

    public function test_mascara_cpf_com_e_sem_pontuacao_no_meio_do_texto(): void
    {
        $this->assertSame(
            'Cliente ***.***.***-01 não encontrado',
            $this->masker->maskString('Cliente 123.456.789-01 não encontrado')
        );

        $this->assertSame(
            'Cliente ***.***.***-01 não encontrado',
            $this->masker->maskString('Cliente 12345678901 não encontrado')
        );
    }

    public function test_mascara_cnpj(): void
    {
        $this->assertSame(
            'CNPJ **.***.***/****-34',
            $this->masker->maskString('CNPJ 12.345.678/9012-34')
        );

        $this->assertSame(
            'CNPJ **.***.***/****-34',
            $this->masker->maskString('CNPJ 12345678901234')
        );
    }

    public function test_mascara_token_jwt(): void
    {
        $jwt = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dBjftJeZ4CVPmB92K27uhbUJU1p1r_wW1gFWFOEjXk';

        $this->assertSame('Bearer [REDACTED_JWT]', $this->masker->maskString("Bearer {$jwt}"));
    }

    public function test_mascara_email_mantendo_a_primeira_letra_e_o_dominio(): void
    {
        $this->assertSame(
            'Falha ao notificar j***@exemplo.com.br',
            $this->masker->maskString('Falha ao notificar joao.silva@exemplo.com.br')
        );
    }

    public function test_mascara_pelo_nome_do_campo_inclusive_em_array_aninhado(): void
    {
        $masked = $this->masker->maskArray([
            'event' => 'customer_created',
            'password' => 'senha-super-secreta',
            'customer' => [
                // No banco deste projeto o documento é um campo inteiro.
                'identification_number' => 12345678901,
                'email' => 'maria@exemplo.com',
                'name' => 'Maria',
            ],
        ]);

        $this->assertSame('customer_created', $masked['event']);
        $this->assertSame(SensitiveDataMasker::REDACTED, $masked['password']);
        $this->assertSame('***.***.***-01', $masked['customer']['identification_number']);
        $this->assertSame('m***@exemplo.com', $masked['customer']['email']);
        $this->assertSame('Maria', $masked['customer']['name']);
    }

    public function test_nao_mascara_numeros_comuns_de_log(): void
    {
        $masked = $this->masker->maskArray([
            'status' => 200,
            'duration_ms' => 34.7,
            'user_id' => 42,
            'path' => '/api/customers/42',
            'order_total' => '123456789.01',
        ]);

        $this->assertSame(200, $masked['status']);
        $this->assertSame(34.7, $masked['duration_ms']);
        $this->assertSame(42, $masked['user_id']);
        $this->assertSame('/api/customers/42', $masked['path']);
        $this->assertSame('123456789.01', $masked['order_total']);
    }

    public function test_mascarar_uma_linha_de_json_mantem_o_json_valido(): void
    {
        $line = json_encode([
            'message' => 'Cliente 123.456.789-01 criado',
            'context' => ['email' => 'joao@exemplo.com'],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $masked = $this->masker->maskString((string) $line);
        $decoded = json_decode($masked, true);

        $this->assertIsArray($decoded);
        $this->assertSame('Cliente ***.***.***-01 criado', $decoded['message']);
        $this->assertSame('j***@exemplo.com', $decoded['context']['email']);
    }
}
