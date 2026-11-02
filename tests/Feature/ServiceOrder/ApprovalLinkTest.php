<?php

namespace Tests\Feature\ServiceOrder;

use App\Domain\ServiceOrder\Security\OrderApprovalTokenSigner;
use Tests\TestCase;

/**
 * O endpoint de aprovação é público e recebe o token pela query string: qualquer pessoa pode
 * chamar com qualquer coisa.
 *
 * Este é o teste de regressão do erro encontrado em produção — um token sem ponto fazia o
 * endpoint responder **500**, erro de servidor, quando o certo é dizer ao cliente que o link
 * não vale. Não usa banco de propósito: a validação do token acontece antes de qualquer
 * consulta, e é exatamente isso que o teste precisa provar.
 */
class ApprovalLinkTest extends TestCase
{
    /**
     * @dataProvider tokensQueNaoValem
     */
    public function test_token_invalido_responde_400_com_pagina_e_nao_500(string $token): void
    {
        $resposta = $this->get('/api/service-orders/approve?token=' . urlencode($token));

        $resposta->assertStatus(400);
        $resposta->assertSee('Não foi possível abrir este link');
        $resposta->assertDontSee('Server Error');
    }

    public function test_o_mesmo_vale_para_a_reprovacao(): void
    {
        $this->get('/api/service-orders/reject?token=sem-ponto-nenhum')
            ->assertStatus(400)
            ->assertSee('Não foi possível abrir este link');
    }

    public function test_sem_token_nenhum_tambem_nao_quebra(): void
    {
        // `$request->query('token')` devolve null, e o controller tipa string.
        $this->get('/api/service-orders/approve')->assertStatus(400);
    }

    public function test_a_pagina_nao_vaza_detalhe_tecnico(): void
    {
        $corpo = $this->get('/api/service-orders/approve?token=???.xxx')->content();

        $this->assertStringNotContainsString('OrderApprovalTokenSigner', $corpo);
        $this->assertStringNotContainsString('hash_hmac', $corpo);
        $this->assertStringNotContainsString('Undefined array key', $corpo);
    }

    public function test_token_expirado_orienta_a_pedir_um_novo(): void
    {
        $signer = app(OrderApprovalTokenSigner::class);

        $this->travel(-25)->hours();
        $token = $signer->generate(42, 7);
        $this->travelBack();

        $this->get('/api/service-orders/approve?token=' . urlencode($token))
            ->assertStatus(400)
            ->assertSee('expirou', false);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function tokensQueNaoValem(): array
    {
        return [
            'sem separador' => ['qualquercoisa'],
            'vazio' => [''],
            'só o separador' => ['.'],
            'base64 inválido' => ['???.assinatura'],
            'assinatura inventada' => [base64_encode('42|7|9999999999') . '.assinaturafalsa'],
        ];
    }
}
