<?php

namespace App\Infrastructure\Observability\Logging;

use DateTimeInterface;
use Monolog\Formatter\JsonFormatter;

/**
 * Formata o registro como **uma linha de JSON plana**, e passa o resultado pelo mascarador.
 *
 * Por que plana: no Loki, `| json` transforma cada campo em um rótulo, e um campo aninhado
 * vira rótulo com prefixo — `{"extra":{"request_id":"x"}}` seria consultado como
 * `extra_request_id`. O access log do Nginx escreve `request_id` na raiz. Se cada fonte
 * usasse um nome diferente para a mesma coisa, nenhuma consulta serviria para as duas, e a
 * correlação — que é o objetivo desta fase — ficaria desconfortável. Então o `context` e o
 * `extra` do Monolog são promovidos para a raiz, e os nomes dos campos são os mesmos que o
 * Nginx usa (`timestamp`, `level`, `channel`, `request_id`, `method`, `path`, `status`).
 *
 * Os campos reservados (`timestamp`, `level`, `channel`, `message`) sempre ganham: um campo
 * de mesmo nome vindo do contexto é descartado, para a linha não mentir sobre si mesma.
 *
 * Esta é também a segunda camada de mascaramento. A primeira
 * (MaskSensitiveDataProcessor) trabalha sobre o array e não vê o conteúdo de objetos; aqui o
 * registro já virou texto, inclusive a mensagem de cada exceção. Mascarar o JSON pronto é
 * seguro porque nenhuma substituição do mascarador insere aspas ou barra invertida.
 */
class JsonLineFormatter extends JsonFormatter
{
    /**
     * Nomes que a própria linha usa e que o contexto não pode sobrescrever.
     */
    public const RESERVED_FIELDS = ['timestamp', 'level', 'channel', 'message'];

    public function __construct(private readonly SensitiveDataMasker $masker)
    {
        parent::__construct(
            batchMode: self::BATCH_MODE_JSON,
            appendNewline: true,
            ignoreEmptyContextAndExtra: true,
            // Guarda arquivo e linha de cada frame da exceção. O Monolog não inclui os
            // argumentos das chamadas, então o stack trace não carrega dados do cliente.
            includeStacktraces: true,
        );

        // O padrão do Monolog é segundo cheio. Com milissegundo dá para ordenar as linhas
        // de uma mesma requisição, que normalmente acontecem dentro do mesmo segundo.
        $this->setDateFormat(DateTimeInterface::RFC3339_EXTENDED);
    }

    /**
     * @param  array<string, mixed>  $record
     */
    public function format(array $record): string
    {
        $normalized = $this->normalize($record);

        $reserved = [
            'timestamp' => $normalized['datetime'] ?? null,
            // Texto em minúsculas ("info", "warning", "error"): é o que o Grafana reconhece
            // para colorir a severidade, e o mesmo valor que o Nginx escreve.
            'level' => strtolower((string) ($normalized['level_name'] ?? 'info')),
            'channel' => $normalized['channel'] ?? null,
            'message' => $normalized['message'] ?? '',
        ];

        $extra = is_array($normalized['extra'] ?? null) ? $normalized['extra'] : [];
        $context = is_array($normalized['context'] ?? null) ? $normalized['context'] : [];

        $line = array_merge(
            $reserved,
            array_diff_key($extra, $reserved),
            array_diff_key($context, $reserved),
        );

        return $this->masker->maskString($this->toJson($line, true)) . "\n";
    }
}
