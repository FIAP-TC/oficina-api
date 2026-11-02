<?php

namespace App\Domain\ServiceOrder\Security;

use App\Domain\ServiceOrder\Exceptions\InvalidApprovalTokenException;
use Carbon\Carbon;

/**
 * Assina e valida os tokens de aprovação de Ordem de Serviço enviados por e-mail.
 *
 * Payload: service_order_id|customer_id|expires_at, assinado com HMAC-SHA256.
 */
class OrderApprovalTokenSigner
{
    public function __construct(
        private readonly string $secretKey,
    ) {}

    public function generate(int $serviceOrderId, int $customerId): string
    {
        $expires = now()->addHours(24)->timestamp;

        $payload = implode('|', [
            $serviceOrderId,
            $customerId,
            $expires,
        ]);

        $signature = hash_hmac('sha256', $payload, $this->secretKey);

        return base64_encode($payload) . '.' . $signature;
    }

    /**
     * Valida o token recebido pelo link de aprovação.
     *
     * Tudo aqui vem de fora, de um endpoint público: o token chega na query string de quem
     * quiser chamar. Por isso cada etapa confere o formato antes de usar o resultado — antes,
     * um token sem o separador fazia o destructuring estourar com "Undefined array key 1" e
     * o cliente recebia erro 500 em vez de "link inválido".
     *
     * @return array{service_order_id: int, customer_id: int}
     *
     * @throws InvalidApprovalTokenException
     */
    public function validate(string $token): array
    {
        $partes = explode('.', $token);

        if (count($partes) !== 2) {
            throw InvalidApprovalTokenException::malformed();
        }

        [$payloadCodificado, $signature] = $partes;

        // Um token "." tem duas partes, as duas vazias: passa na contagem acima e o
        // base64_decode de string vazia devolve string vazia, não false. Sem este corte ele
        // seguiria até a comparação de assinatura e seria classificado como assinatura
        // incorreta — a resposta ao cliente seria a mesma, mas o log diria a coisa errada.
        if ($payloadCodificado === '' || $signature === '') {
            throw InvalidApprovalTokenException::malformed();
        }

        // `strict: true` faz o base64_decode devolver false em vez de ignorar caracteres
        // inválidos em silêncio — sem isso, lixo vira um payload qualquer e segue adiante.
        $payload = base64_decode($payloadCodificado, true);

        if ($payload === false) {
            throw InvalidApprovalTokenException::malformed();
        }

        $expected = hash_hmac('sha256', $payload, $this->secretKey);

        if (!hash_equals($expected, $signature)) {
            throw InvalidApprovalTokenException::signatureMismatch();
        }

        // Só depois de a assinatura conferir é que o conteúdo do payload é confiável. Ainda
        // assim o formato é checado: um token assinado por uma versão antiga do código poderia
        // ter outro número de campos.
        $campos = explode('|', $payload);

        if (count($campos) !== 3) {
            throw InvalidApprovalTokenException::malformed();
        }

        [$serviceOrderId, $customerId, $expires] = $campos;

        if (now()->isAfter(Carbon::createFromTimestamp((int) $expires))) {
            throw InvalidApprovalTokenException::expired();
        }

        return [
            'service_order_id' => (int) $serviceOrderId,
            'customer_id' => (int) $customerId,
        ];
    }
}
