<?php

namespace Tests\Feature\Observability;

use App\Infrastructure\Observability\Logging\JsonLoggerFactory;
use App\Infrastructure\Observability\Logging\JsonLineFormatter;
use App\Infrastructure\Observability\Logging\MaskSensitiveDataProcessor;
use App\Infrastructure\Observability\Logging\RequestContextProcessor;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Tests\TestCase;

/**
 * Garante que o canal "json" do config/logging.php continua montado como a fase 2 espera.
 * Sem isto, uma mudança de configuração poderia silenciosamente voltar a gravar log em
 * arquivo (invisível para o agente de observabilidade) ou sem mascaramento.
 */
class JsonLogChannelTest extends TestCase
{
    public function test_o_canal_json_escreve_em_stderr_com_formato_json_mascarado(): void
    {
        $monolog = Log::channel('json')->getLogger();
        $this->assertInstanceOf(Logger::class, $monolog);

        $handlers = $monolog->getHandlers();
        $this->assertCount(1, $handlers);
        $this->assertInstanceOf(StreamHandler::class, $handlers[0]);
        $this->assertSame('php://stderr', $handlers[0]->getUrl());
        $this->assertInstanceOf(JsonLineFormatter::class, $handlers[0]->getFormatter());
    }

    public function test_o_mascaramento_e_o_ultimo_processador_da_fila(): void
    {
        $processors = Log::channel('json')->getLogger()->getProcessors();

        $this->assertInstanceOf(RequestContextProcessor::class, $processors[0]);
        $this->assertInstanceOf(MaskSensitiveDataProcessor::class, $processors[1]);
    }

    public function test_nivel_de_log_invalido_nao_derruba_a_aplicacao(): void
    {
        // "dev" esteve configurado como LOG_LEVEL neste projeto e não é um nível do Monolog.
        $logger = app(JsonLoggerFactory::class)(['level' => 'dev']);

        $this->assertSame(Logger::INFO, $logger->getHandlers()[0]->getLevel());
    }
}
