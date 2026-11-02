# ADR-0003: Sondas de saúde e probes do Kubernetes

**Status:** Aceita
**Data:** 2026-11-02

## Contexto

Fase 3 do plano de observabilidade registrado no
[ADR-0002 do repositório `infra-k8s`](https://github.com/FIAP-TC/infra-k8s/blob/main/docs/adr/0002-observabilidade-com-grafana-cloud.md).
A fase 2 ([ADR-0002](0002-logs-estruturados-e-correlacao.md)) entregou log estruturado e
correlação; esta fase responde a uma pergunta diferente: **o Kubernetes sabe quando a
aplicação parou de funcionar?**

Não sabia. As probes eram:

- **API** (`php-fpm`): `tcpSocket` na porta 9000, em liveness e readiness. Isso confirma que
  algum processo aceita conexão TCP naquela porta — nada mais. Com o PHP incapaz de subir o
  Laravel (chave de aplicação ausente, autoload quebrado, configuração inválida) ou com o
  banco inalcançável, o pod continuava `Ready` e recebendo tráfego que respondia 500.
- **Nginx**: `httpGet` em `/nginx_status`. Pergunta ao Nginx se o Nginx está de pé. Nunca
  passa pela aplicação.

Ou seja: os dois sinais que o Kubernetes usa para decidir "reinicia" e "manda tráfego"
estavam medindo a coisa errada.

Restrição que determinou a forma da solução: **o container da API fala FastCGI, não HTTP.**
Uma probe `httpGet` não consegue conversar com a porta 9000 — foi por isso que o
`tcpSocket` estava ali.

## Decisão

**1. Duas sondas, com responsabilidades distintas.** `GET /api/health` (vida) e
`GET /api/health/ready` (prontidão).

A separação é o ponto central, e não detalhe de organização:

- **Liveness** responde a "este processo travou? reinicia ele". Por isso **não** checa banco.
  Se checasse, uma indisponibilidade do RDS faria o Kubernetes reiniciar todos os pods da API
  em laço — reiniciar não conserta um banco remoto, e os reinícios atrasam a recuperação
  quando o banco volta, porque cada pod novo paga o custo de subir o framework do zero.
- **Readiness** responde a "mando tráfego para este pod?". Essa **checa** o banco
  (`SELECT 1`), porque um pod que não alcança o banco não tem o que responder. Falhar aqui
  tira o pod do balanceamento sem matá-lo: quando a dependência volta, ele volta sozinho.

**2. Cada pod checa o que ele mesmo precisa.** O Nginx mantém probe que olha só o Nginx. Se
ele ficasse "não pronto" porque a API caiu, seus pods sairiam do Service, o LoadBalancer
ficaria sem destino e o cliente receberia falha de conexão — pior que um 502, que ao menos
diz o que aconteceu.

**3. Probes por `exec` com `cgi-fcgi`.** O pacote `fcgi` (131 KiB) entra na imagem e traz um
cliente FastCGI de linha de comando. A probe monta a requisição e fala com a porta 9000 no
protocolo que ela entende, exercitando PHP e Laravel de verdade. O `cgi-fcgi` sai com código
0 mesmo quando a aplicação responde 503, então quem decide se a sonda passou é um `grep` no
corpo da resposta.

**4. Sonda que passou não vira linha de log; sonda que falhou, sim.** São duas probes a cada
10 e 20 segundos em cada pod: registrar as que passaram encheria a cota de ingestão com
linhas dizendo "continua tudo bem". É o mesmo critério que tirou o `/nginx_status` do access
log na fase 2.

**5. A resposta não revela detalhe da falha.** O corpo diz qual checagem falhou
(`{"database": false}`), nunca a mensagem do driver — que traz host, usuário e porta. O
detalhe fica no log, mascarado.

## Alternativas consideradas

**Manter `tcpSocket` e checar a saúde só pelo Nginx.** Mais simples, mas a porta aberta é um
sinal fraco: ela continua aberta com o PHP-FPM incapaz de executar qualquer coisa. E uma
probe só no Nginx não distingue qual pod da API está ruim, que é justamente o que a readiness
existe para fazer.

**Uma sonda só, checando tudo.** É o erro clássico: junta "reinicia" com "não manda tráfego"
num sinal só, e transforma queda de banco em tempestade de reinícios.

**Usar o `ping.path` do próprio PHP-FPM.** Responde sem passar pelo Laravel, então não
detecta justamente a classe de falha que interessa (framework que não sobe).

**Sidecar HTTP no pod da API só para as probes.** Resolveria o problema do FastCGI, mas custa
um container a mais por pod num nó que aceita 17 no total, para o que 131 KiB de cliente
FastCGI resolvem.

## Consequências

**Fica mais fácil:**

- Um pod que não consegue atender sai do balanceamento sozinho, em vez de devolver erro ao
  cliente.
- Um processo travado é reiniciado por um sinal que de fato passou pelo PHP.
- O monitoramento sintético (de fora da AWS) tem um endereço estável e barato para consultar.

**Fica mais difícil / riscos:**

- O comando da probe é uma linha longa de shell dentro do manifest, com variáveis de ambiente
  do CGI. É frágil a mudanças de caminho (`/var/www/public`) e não é óbvio de ler.
- A readiness passa a depender do banco: um problema de rede entre o pod e o RDS tira o pod
  do ar, o que é o comportamento desejado, mas significa que uma instabilidade de rede vira
  indisponibilidade visível em vez de erro intermitente.
- `SELECT 1` confirma conexão, não que as tabelas existem ou que as migrations rodaram.
- As probes custam uma execução de PHP cada. É barato, mas não é zero: com `periodSeconds`
  menores a conta cresce.
