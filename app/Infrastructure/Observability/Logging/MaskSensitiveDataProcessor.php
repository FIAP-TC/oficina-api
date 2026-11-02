<?php

namespace App\Infrastructure\Observability\Logging;

use Monolog\Processor\ProcessorInterface;

/**
 * Processador do Monolog que mascara a mensagem e os dados estruturados do log.
 *
 * Roda antes da formatação, quando o registro ainda é um array PHP — é aqui que dá para
 * olhar o *nome* de cada campo e decidir o que fazer com ele.
 *
 * O que este processador não alcança: objetos dentro do contexto (uma exceção, por
 * exemplo), porque eles só são convertidos em texto depois, na formatação. Essa segunda
 * camada é o MaskingJsonFormatter.
 */
class MaskSensitiveDataProcessor implements ProcessorInterface
{
    public function __construct(private readonly SensitiveDataMasker $masker)
    {
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    public function __invoke(array $record): array
    {
        if (isset($record['message']) && is_string($record['message'])) {
            $record['message'] = $this->masker->maskString($record['message']);
        }

        if (isset($record['context']) && is_array($record['context'])) {
            $record['context'] = $this->masker->maskArray($record['context']);
        }

        if (isset($record['extra']) && is_array($record['extra'])) {
            $record['extra'] = $this->masker->maskArray($record['extra']);
        }

        return $record;
    }
}
