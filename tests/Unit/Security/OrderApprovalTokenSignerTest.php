<?php

namespace Tests\Unit\Security;

use App\Domain\ServiceOrder\Exceptions\InvalidApprovalTokenException;
use App\Domain\ServiceOrder\Security\OrderApprovalTokenSigner;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * O token de aprovação chega pela query string de um endpoint **público** — qualquer pessoa
 * pode chamar com qualquer coisa. Cada teste aqui é uma forma de token torto que antes
 * derrubava o endpoint com erro 500.
 */
class OrderApprovalTokenSignerTest extends TestCase
{
    private const SEGREDO = 'segredo-de-teste';

    private OrderApprovalTokenSigner $signer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->signer = new OrderApprovalTokenSigner(self::SEGREDO);
    }

    public function test_um_token_que_ele_mesmo_gerou_e_valido(): void
    {
        $payload = $this->signer->validate($this->signer->generate(42, 7));

        $this->assertSame(['service_order_id' => 42, 'customer_id' => 7], $payload);
    }

    /**
     * @dataProvider tokensMalformados
     */
    public function test_token_malformado_vira_erro_de_cliente(string $token, string $oQueTem): void
    {
        $this->expectException(InvalidApprovalTokenException::class);

        try {
            $this->signer->validate($token);
        } catch (InvalidApprovalTokenException $e) {
            $this->assertSame('malformed', $e->reason(), "token {$oQueTem}");
            throw $e;
        }
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function tokensMalformados(): array
    {
        return [
            // Este é o caso que acontecia em produção: sem ponto, o destructuring de um array
            // de um elemento só estourava com "Undefined array key 1" → HTTP 500.
            'sem separador' => ['qualquercoisa', 'sem ponto'],
            'vazio' => ['', 'string vazia'],
            'só o separador' => ['.', 'só o ponto'],
            'pontos demais' => ['a.b.c', 'três partes'],
            'base64 inválido' => ['???.assinatura', 'base64 inválido'],
            'payload com poucos campos' => [self::tokenAssinadoCom('42|7'), 'payload com 2 campos'],
            'payload com campos demais' => [self::tokenAssinadoCom('42|7|1|extra'), 'payload com 4 campos'],
        ];
    }

    public function test_assinatura_trocada_nao_passa(): void
    {
        // Token legítimo de outra oficina: o payload é válido, o segredo é que é outro.
        $outro = new OrderApprovalTokenSigner('outro-segredo');

        try {
            $this->signer->validate($outro->generate(42, 7));
            $this->fail('um token assinado com outro segredo não pode ser aceito');
        } catch (InvalidApprovalTokenException $e) {
            $this->assertSame('signature_mismatch', $e->reason());
        }
    }

    public function test_payload_adulterado_nao_passa(): void
    {
        // Trocar a ordem de serviço no payload invalida a assinatura.
        $adulterado = base64_encode('999|7|' . now()->addHour()->timestamp)
            . '.' . explode('.', $this->signer->generate(42, 7))[1];

        try {
            $this->signer->validate($adulterado);
            $this->fail('payload adulterado não pode ser aceito');
        } catch (InvalidApprovalTokenException $e) {
            $this->assertSame('signature_mismatch', $e->reason());
        }
    }

    public function test_token_expirado_e_distinguido_do_invalido(): void
    {
        // O cliente precisa saber que expirou, para pedir um link novo. A data já é legível
        // no payload (base64 de texto puro), então dizer isso não revela nada.
        Carbon::setTestNow(now()->subHours(25));
        $token = $this->signer->generate(42, 7);
        Carbon::setTestNow();

        try {
            $this->signer->validate($token);
            $this->fail('token de 25 horas atrás não pode ser aceito');
        } catch (InvalidApprovalTokenException $e) {
            $this->assertSame('expired', $e->reason());
        }
    }

    public function test_a_mensagem_nao_diz_se_o_problema_foi_o_formato_ou_a_assinatura(): void
    {
        // Diferenciar os dois contaria a quem tenta forjar um token o quão perto chegou.
        $this->assertSame(
            $this->mensagemDe(InvalidApprovalTokenException::malformed()),
            $this->mensagemDe(InvalidApprovalTokenException::signatureMismatch()),
        );
    }

    private function mensagemDe(InvalidApprovalTokenException $e): string
    {
        return $e->render()->getContent() ?: '';
    }

    private static function tokenAssinadoCom(string $payload): string
    {
        return base64_encode($payload) . '.' . hash_hmac('sha256', $payload, self::SEGREDO);
    }
}
