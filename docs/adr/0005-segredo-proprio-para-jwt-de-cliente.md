# ADR-0005: Segredo de verificação próprio para o JWT de cliente

**Status:** Aceita
**Data:** 2026-11-02

## Contexto

A [ADR-0004](0004-middleware-jwt-para-clientes.md) criou o `CustomerJwtMiddleware`, usando
o facade `Tymon\JWTAuth` só para decodificar o payload (`parseToken()->getPayload()`), sem
passar pelo guard `api`. Na implementação inicial, isso teve um efeito colateral não
percebido de imediato: o `Tymon\JWTAuth` verifica **qualquer** token (de cliente ou de
funcionário) contra **um único segredo**, configurado globalmente em
`config('jwt.secret')` (= `JWT_SECRET`). Não existe como pedir pro Tymon "verifique este
token com outra chave" sem reconfigurar a aplicação inteira.

Isso forçou, na prática, usar o **mesmo** `JWT_SECRET` tanto para assinar o login de
funcionário quanto para verificar o token da `auth-lambda` — ou seja, `JWT_SECRET` (aqui) e
`jwt_secret` (lá) precisariam ser literalmente o mesmo valor.

Isso é um problema de segurança, não só operacional: cliente e funcionário são dois
domínios de confiança diferentes (um é autenticado por CPF por um serviço externo, o outro
por usuário/senha dentro do próprio app). Com uma única chave simétrica para os dois, a
única coisa que impede um token de cliente de ser aceito como se fosse de funcionário (ou
vice-versa) é a checagem de aplicação do `iss` — a assinatura em si seria válida nos dois
casos, porque é a mesma chave. Um bug futuro (uma rota nova esquecendo de checar o `iss`,
ou aplicando o middleware errado) vira elevação de privilégio em vez de simplesmente
falhar. E se a chave vazar, os dois sistemas são comprometidos juntos.

A prática recomendada pela RFC 8725 (JWT Best Current Practices) para tokens de domínios de
confiança diferentes é usar chaves distintas, para que um token do domínio errado falhe
**na verificação da assinatura**, antes mesmo de qualquer lógica de aplicação rodar.

## Decisão

**1. Chave nova e independente: `AUTH_LAMBDA_JWT_SECRET`.** Configurada em
`config('services.auth_lambda.jwt_secret')`, precisa ser igual ao `jwt_secret` do
`terraform.tfvars` da `auth-lambda` — mas é um valor **diferente** do `JWT_SECRET` usado
pelo login de funcionário. As duas chaves não têm nenhuma relação entre si.

**2. `CustomerJwtMiddleware` para de usar o facade `Tymon\JWTAuth`.** Passa a verificar o
token manualmente com `lcobucci/jwt` — biblioteca já vendorizada, por ser dependência do
próprio `tymon/jwt-auth` — contra `AUTH_LAMBDA_JWT_SECRET`. Nenhuma lib nova entra no
projeto; o `tymon/jwt-auth` continua exatamente como estava para tudo do funcionário
(`JwtMiddleware`, `AuthService`, guard `api`).

**3. Ordem das checagens:** assinatura (`SignedWith`) → expiração (`isExpired`) → `iss` →
cliente existe e está ativo. A assinatura é verificada primeiro de propósito: um token
assinado com a chave errada (ex: um token de funcionário, assinado com `JWT_SECRET`) já
falha aqui, nunca chega a avaliar `iss` ou consultar o banco.

**4. Middleware passa o `Customer` já resolvido pro controller via
`$request->attributes`.** Evita o controller reabrir e reparsear o token (o que, aliás,
não seria mais possível pelo guard `api`, já que ele usaria a chave errada).

## Alternativas consideradas

**Manter o segredo compartilhado, documentando o risco (como a ADR-0004 original fazia).**
Mais simples, mas deixa a elevação de privilégio entre domínios descrita no Contexto como
risco aceito em vez de eliminado — e o custo de eliminar era baixo (uma lib já disponível,
um middleware que já ia ser escrito do zero mesmo).

**Configurar um guard/provider Laravel dedicado para `Customer`, com sua própria chave no
`config/jwt.php` (ex: publicando a config duas vezes com nomes diferentes).** O
`tymon/jwt-auth` não foi desenhado para múltiplas configurações de segredo ativas ao mesmo
tempo na mesma aplicação; forçar isso seria mais frágil do que usar `lcobucci/jwt`
diretamente para o caso específico que precisa de uma chave diferente.

## Consequências

**Fica mais fácil:**

- Um token do domínio errado (funcionário tentando usar rota de cliente, ou o inverso)
  falha na assinatura — não depende de nenhuma lógica de aplicação acertar o `iss` pra
  barrar o acesso indevido.
- Girar o `JWT_SECRET` de funcionário (ex: resposta a um incidente) não derruba a
  autenticação de cliente, e vice-versa — são rotações independentes.

**Fica mais difícil / riscos:**

- Duas chaves pra gerenciar em vez de uma: `JWT_SECRET` e `AUTH_LAMBDA_JWT_SECRET` no
  Kubernetes Secret deste repositório, e a segunda ainda precisa ficar sincronizada com o
  `jwt_secret` da `auth-lambda` — o risco de dessincronia da ADR-0004 continua existindo,
  só que agora isolado a uma chave em vez de afetar as duas autenticações ao mesmo tempo.
- O `CustomerJwtMiddleware` fala diretamente com `lcobucci/jwt` em vez de uma camada de
  abstração do framework (como o Tymon oferece pro funcionário) — qualquer mudança de
  formato do token da `auth-lambda` (novas claims, algoritmo diferente) precisa ser
  replicada aqui à mão, sem o conforto de configuração central do `config/jwt.php`.
