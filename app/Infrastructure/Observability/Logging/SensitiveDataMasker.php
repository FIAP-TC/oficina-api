<?php

namespace App\Infrastructure\Observability\Logging;

/**
 * Esconde dados pessoais e credenciais antes de a linha de log sair da aplicação.
 *
 * Por que isso existe: os logs saem da AWS e vão para o Grafana Cloud (ver o ADR-0002 do
 * repositório infra-k8s), então CPF, e-mail e token não podem ir junto. Mascarar na saída
 * do log é mais seguro do que confiar que ninguém vai registrar esses dados por engano —
 * um stack trace ou uma mensagem de exceção podem carregá-los sem que ninguém tenha pedido.
 *
 * São duas estratégias complementares:
 *
 * - maskArray(): olha o *nome* do campo. É o que pega `['password' => '...']` mesmo quando
 *   o valor não tem formato reconhecível.
 * - maskString(): olha o *formato* do valor. É o que pega um CPF solto no meio de uma
 *   mensagem de erro, onde não existe nome de campo nenhum.
 */
class SensitiveDataMasker
{
    public const REDACTED = '[REDACTED]';

    /**
     * Campos cujo valor nunca pode aparecer no log, nem parcialmente.
     *
     * @var array<int, string>
     */
    private const SECRET_KEYS = [
        'password',
        'passwordconfirmation',
        'currentpassword',
        'senha',
        'token',
        'accesstoken',
        'refreshtoken',
        'bearer',
        'authorization',
        'jwt',
        'jwtsecret',
        'secret',
        'approvalsecret',
        'apikey',
        'appkey',
    ];

    /**
     * Campos de documento: mantém apenas os dois últimos dígitos, o suficiente para o
     * suporte conferir um cadastro com o cliente ao telefone.
     *
     * @var array<int, string>
     */
    private const DOCUMENT_KEYS = [
        'cpf',
        'cnpj',
        'identificationnumber',
        'documento',
        'document',
    ];

    /**
     * @var array<int, string>
     */
    private const EMAIL_KEYS = [
        'email',
        'mail',
        'emailaddress',
    ];

    /**
     * Percorre um array (de qualquer profundidade) mascarando pelo nome do campo.
     *
     * Valores que não são texto nem array ficam como estão, com uma exceção: campos de
     * documento e de segredo são mascarados mesmo quando vêm como número, porque no banco
     * deste projeto o CPF é um campo inteiro.
     *
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public function maskArray(array $data): array
    {
        $masked = [];

        foreach ($data as $key => $value) {
            $normalizedKey = $this->normalizeKey($key);

            if (in_array($normalizedKey, self::SECRET_KEYS, true)) {
                $masked[$key] = self::REDACTED;
                continue;
            }

            if (in_array($normalizedKey, self::DOCUMENT_KEYS, true) && (is_string($value) || is_int($value))) {
                $masked[$key] = $this->maskDocument((string) $value);
                continue;
            }

            if (in_array($normalizedKey, self::EMAIL_KEYS, true) && is_string($value)) {
                $masked[$key] = $this->maskEmail($value);
                continue;
            }

            if (is_array($value)) {
                $masked[$key] = $this->maskArray($value);
                continue;
            }

            $masked[$key] = is_string($value) ? $this->maskString($value) : $value;
        }

        return $masked;
    }

    /**
     * Mascara pelo formato do valor: JWT, CNPJ, CPF e e-mail em qualquer lugar do texto.
     *
     * A ordem importa. O JWT vem primeiro porque é base64 e pode ter uma sequência de 11
     * dígitos dentro, que seria confundida com um CPF. O CNPJ (14 dígitos) vem antes do
     * CPF (11) pelo mesmo motivo.
     *
     * Nenhuma substituição insere aspas ou barra invertida, então esta função também é
     * segura de aplicar sobre uma linha de JSON já pronta (é o que MaskingJsonFormatter faz).
     */
    public function maskString(string $value): string
    {
        // Token JWT: três blocos base64url separados por ponto, começando sempre por "eyJ"
        // (é o '{"' do cabeçalho em base64).
        $value = (string) preg_replace(
            '/\beyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]*/',
            '[REDACTED_JWT]',
            $value
        );

        // CNPJ, com ou sem pontuação. O lookbehind recusa dígito e asterisco à esquerda
        // para não casar no meio de um número maior nem dentro de algo já mascarado.
        $value = (string) preg_replace(
            '/(?<![\d*])\d{2}[.\s]?\d{3}[.\s]?\d{3}[\/\s]?\d{4}[-\s]?(\d{2})(?!\d)/',
            '**.***.***/****-$1',
            $value
        );

        // CPF, com ou sem pontuação.
        $value = (string) preg_replace(
            '/(?<![\d*])\d{3}[.\s]?\d{3}[.\s]?\d{3}[-\s]?(\d{2})(?!\d)/',
            '***.***.***-$1',
            $value
        );

        // E-mail: mantém a primeira letra e o domínio, que é o que ajuda a investigar um
        // problema sem identificar a pessoa.
        return (string) preg_replace(
            '/(?<![\w.%+-])([\w.%+-])[\w.%+-]*(@[\w-]+(?:\.[\w-]+)+)/',
            '$1***$2',
            $value
        );
    }

    /**
     * Mantém só os dois últimos dígitos de um documento.
     */
    public function maskDocument(string $value): string
    {
        $digits = (string) preg_replace('/\D/', '', $value);

        if ($digits === '') {
            return self::REDACTED;
        }

        if (strlen($digits) <= 2) {
            return str_repeat('*', strlen($digits));
        }

        return match (strlen($digits)) {
            11 => '***.***.***-' . substr($digits, -2),
            14 => '**.***.***/****-' . substr($digits, -2),
            default => str_repeat('*', strlen($digits) - 2) . substr($digits, -2),
        };
    }

    private function maskEmail(string $value): string
    {
        $masked = $this->maskString($value);

        // Um valor que não parece e-mail nenhum (lixo, ou já mascarado) não deve passar
        // batido só porque o regex não casou.
        return $masked === $value && !str_contains($value, '@') ? self::REDACTED : $masked;
    }

    /**
     * "identification_number", "Identification-Number" e "identificationNumber" viram
     * todos "identificationnumber", para a comparação não depender de convenção de escrita.
     *
     * @param  array-key  $key
     */
    private function normalizeKey($key): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', strtolower((string) $key));
    }
}
