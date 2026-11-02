<?php

namespace Tests\Unit\Observability;

use App\Infrastructure\Observability\Logging\JsonLineFormatter;
use App\Infrastructure\Observability\Logging\MaskSensitiveDataProcessor;
use App\Infrastructure\Observability\Logging\RequestContextProcessor;
use App\Infrastructure\Observability\Logging\SensitiveDataMasker;
use App\Infrastructure\Observability\RequestContext;
use DateTimeImmutable;
use Monolog\Logger;
use Tests\TestCase;

/**
 * Testa o caminho completo de uma linha de log: processadores e formatação, exatamente na
 * ordem em que o JsonLoggerFactory os monta.
 */
class JsonLogRecordTest extends TestCase
{
    private SensitiveDataMasker $masker;

    private RequestContext $context;

    protected function setUp(): void
    {
        parent::setUp();
        $this->masker = new SensitiveDataMasker();
        $this->context = new RequestContext();
    }

    public function test_a_linha_e_um_json_valido_com_request_id_e_dados_mascarados(): void
    {
        $this->context->startRequest('abc123def456');

        $line = $this->format([
            'message' => 'Cliente 123.456.789-01 criado',
            'context' => [
                'event' => 'customer_created',
                'identification_number' => 12345678901,
                'password' => 'senha-secreta',
            ],
        ]);

        $decoded = json_decode(trim($line), true);

        $this->assertIsArray($decoded, 'a linha precisa ser um JSON válido');
        $this->assertStringEndsWith("\n", $line, 'uma linha por registro');

        // Campos na raiz, com os mesmos nomes do access log do Nginx.
        $this->assertSame('abc123def456', $decoded['request_id']);
        $this->assertSame('info', $decoded['level']);
        $this->assertSame('api', $decoded['channel']);
        $this->assertSame('mecanica', $decoded['service']);
        $this->assertSame('testing', $decoded['env']);
        $this->assertSame('customer_created', $decoded['event']);

        $this->assertSame('Cliente ***.***.***-01 criado', $decoded['message']);
        $this->assertSame('***.***.***-01', $decoded['identification_number']);
        $this->assertSame(SensitiveDataMasker::REDACTED, $decoded['password']);
        $this->assertStringNotContainsString('12345678901', $line);
        $this->assertStringNotContainsString('senha-secreta', $line);
    }

    public function test_o_horario_tem_precisao_de_milissegundo(): void
    {
        $decoded = json_decode(trim($this->format(['message' => 'teste'])), true);

        $this->assertIsArray($decoded);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}[+-]\d{2}:\d{2}$/',
            $decoded['timestamp']
        );
    }

    public function test_o_contexto_nao_sobrescreve_os_campos_reservados_da_linha(): void
    {
        $decoded = json_decode(trim($this->format([
            'message' => 'mensagem de verdade',
            'context' => ['message' => 'tentativa de sobrescrever', 'level' => 'emergency'],
        ])), true);

        $this->assertIsArray($decoded);
        $this->assertSame('mensagem de verdade', $decoded['message']);
        $this->assertSame('info', $decoded['level']);
    }

    public function test_fora_de_uma_requisicao_a_linha_sai_sem_request_id(): void
    {
        $decoded = json_decode(trim($this->format(['message' => 'rodando migrations'])), true);

        $this->assertIsArray($decoded);
        $this->assertArrayNotHasKey('request_id', $decoded);
    }

    public function test_a_mensagem_de_uma_excecao_no_contexto_tambem_e_mascarada(): void
    {
        // Exceção com namespace de propósito: o nome da classe entra na linha com barras
        // invertidas, que o JSON precisa escapar. O mascaramento roda sobre a linha pronta,
        // então é aqui que uma substituição mal feita quebraria o JSON.
        $line = $this->format([
            'message' => 'Erro ao criar cliente',
            'context' => ['exception' => new ExcecaoDeTeste('CPF 123.456.789-01 duplicado')],
        ]);

        $decoded = json_decode(trim($line), true);

        $this->assertIsArray($decoded, 'a linha com exceção precisa continuar um JSON válido');
        $this->assertSame(ExcecaoDeTeste::class, $decoded['exception']['class']);
        $this->assertStringNotContainsString('123.456.789-01', $line);
        $this->assertSame('CPF ***.***.***-01 duplicado', $decoded['exception']['message']);
        // O stack trace entra como arquivo:linha, que é o que permite investigar o erro.
        $this->assertNotEmpty($decoded['exception']['trace']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function format(array $overrides): string
    {
        $record = array_merge([
            'message' => '',
            'context' => [],
            'level' => Logger::INFO,
            'level_name' => 'INFO',
            'channel' => 'api',
            'datetime' => new DateTimeImmutable(),
            'extra' => [],
        ], $overrides);

        $record = (new RequestContextProcessor($this->context, 'mecanica', 'testing'))($record);
        $record = (new MaskSensitiveDataProcessor($this->masker))($record);

        return (new JsonLineFormatter($this->masker))->format($record);
    }
}

/**
 * Exceção só para o teste acima: precisa ter namespace para o nome da classe sair com
 * barras invertidas na linha de JSON.
 */
class ExcecaoDeTeste extends \RuntimeException
{
}
