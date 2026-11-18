# Motored Core — Funcionalidades (para o dono da loja)

Plugin base da Motored Neumáticos. Tudo se configura em **WooCommerce > Ajustes > Motored** (os nomes das telas
aparecem em espanhol/es_AR). Nas páginas públicas só entram um script mínimo no checkout e outro na página do produto
(este só se o módulo de fotos estiver ligado).
Estado: código e testes automáticos prontos; **nada foi testado ainda numa loja real** (veja o fim do documento).

## Base
- **O que faz:** carrega cada módulo só onde precisa, guarda as opções de cada módulo em separado, cria as tabelas da
  Recuperação ao ativar, cancela as tarefas ao desativar e limpa os dados ao desinstalar (só se você marcar).
- **Onde:** Motored > General (apagar dados ao desinstalar: não por padrão; nível de log: warning) e Estado (diagnóstico).
- **Limite:** exige WooCommerce ativo; sem ele o plugin avisa e não faz nada.

## WhatsApp (Waha)
- **O que faz:** único canal de envio por WhatsApp (via o seu servidor WAHA). Todos os módulos enviam por aqui.
- **Onde:** Motored > WhatsApp. URL e chave do WAHA (melhor definir a chave como constante), sessão, código do país.
- **Fluxo:** toda mensagem entra numa fila (Action Scheduler) e nunca é enviada durante a visita do cliente. Antes de enviar:
  pausa do disjuntor, limite por minuto/hora, espaçamento entre mensagens, teto diário e checagem da sessão.
  Tenta de novo até 3 vezes (após 1 e 5 minutos).
- **Padrões:** 6 por minuto, 100 por hora, 20–60 s entre mensagens, 150 por dia (as de baixa prioridade usam até 60 %),
  disjuntor após 5 falhas seguidas por 15 min, país 54, simula "digitando" (máx. 3 s).
- **Prioridades:** avisos de pedido = alta; recuperação e pedidos de avaliação = baixa.
- **Limites:** só texto; não recebe respostas; botões de teste só no admin.

## Avisos de pedido (OrderNotifications)
- **O que faz:** WhatsApp ao cliente quando o pedido vai para *em espera*, *processando*, *concluído*, *reembolsado*, quando você
  adiciona uma *nota ao cliente* e (opcional) um aviso ao administrador por pedido novo.
- **Onde:** Motored > Mensajes. Ligar/desligar e editar o texto de cada evento. Variáveis: `{first_name}`, `{order_number}`,
  `{order_total}`, `{order_items}`, `{order_url}`, `{store_name}`, `{customer_note}`; variações `{Hola|Buenas}`.
- **Fluxo:** mudança de status → um job na fila por evento (uma vez por pedido e evento) → o texto é montado no envio (se o status
  mudou de novo, não envia) → nota privada no pedido ("WhatsApp sent / failed").
- **Padrões:** todos os eventos ligados, menos o do administrador; pedidos criados à mão não avisam.
- **Limites:** sem consentimento nem descadastro próprio (são avisos transacionais); uma nota ao cliente com o mesmo texto não se repete.

## Recuperação (carrinhos e pedidos sem pagar)
- **O que faz:** recupera carrinhos abandonados e pedidos pendentes, falhados ou em espera de pagamento.
- **Onde:** Motored > Recuperación (sequências, janela de horário, cupom) e WooCommerce > Carritos abandonados (lista, detalhe,
  envio manual, notas). E-mails: WooCommerce > Ajustes > Emails (lembrete de pagamento e de carrinho, "Motored").
- **Carrinho:** o checkout captura e-mail/telefone/nome (~1,5 s depois de digitar; vale para checkout clássico e de Blocos).
  Com 60 min sem atividade: passo 1 WhatsApp; com 24 h: passo 2 e-mail; o passo 3 vem desligado (72 h).
- **Pedidos:** pendente/falhado: WhatsApp aos 30 min, e-mail às 24 h; em espera: WhatsApp às 24 h, sem segundo passo.
  Se o pedido é pago antes de um passo, nada é enviado (fica *convertido*); se é pago depois de uma mensagem, fica *recuperado*.
  Cancelado: conforme o ajuste, restaura o carrinho ou encerra.
- **Regras de envio:** só entre 09:00 e 21:00 (fora da janela adia), máx. 1 mensagem por 24 h e 3 por semana por número,
  pula se não há estoque ou se já comprou, e-mail de reserva se o WhatsApp falhar (ligado).
- **Links:** o link de recuperação (7 dias) restaura o carrinho e abre o checkout. O link de descadastro mostra uma tela com botão;
  só ao confirmar (POST) suprime número/e-mail e para a sequência.
- **Padrões:** módulo **ligado** (atenção: assim que o Waha for configurado ele passa a agir); dados por 30 dias; cupom vazio.
- **Limites:** o link de recuperação aberto por um scanner de e-mail conta como "clique"; carrinhos sem pagamento não têm atribuição.

## Fotos nas avaliações (ReviewPhotos)
- **O que faz:** o cliente anexa fotos ao avaliar um produto; as miniaturas aparecem na avaliação aprovada.
- **Onde:** Motored > Reseñas. Ligar, máx. de fotos (3), máx. de MB por foto (5), mostrar fotos (sim). Desligado por padrão.
- **Fluxo:** o navegador valida (JPG/PNG/WebP) → o servidor revalida, reduz a 1600 px e remove EXIF/GPS → guarda na Biblioteca de
  mídia e na avaliação. Avaliação em spam não guarda fotos. Ao excluir a avaliação, as fotos são excluídas.
- **Limites:** 5 fotos por hora por IP; sem JavaScript a avaliação sai sem fotos; a exclusão por privacidade é feita pelo e-mail do autor.

## Pedido de avaliação (ReviewRequests)
- **O que faz:** um único WhatsApp após a compra pedindo para avaliar os produtos ainda não avaliados (e, opcional, a loja).
- **Onde:** Motored > Reseñas (status gatilho, dias, link da loja, máx. de produtos) e a caixa "Review request" no pedido (enviar agora).
- **Fluxo:** pedido *concluído* → job daqui a 7 dias (dentro da janela 09–21) → no envio pula se foi reembolsado, mudou de status,
  o número pediu descadastro, já avaliou tudo ou passou do limite por número. Inclui link de descadastro próprio.
- **Padrões:** desligado; 7 dias; até 3 produtos.
- **Limites:** um pedido de avaliação por pedido, para sempre; "já avaliou" é detectado por usuário ou e-mail de cobrança.

## Estado por funcionalidade
| Funcionalidade | Estado |
|---|---|
| Base: módulos por contexto, opções, migrações, ativar/desativar/desinstalar | implementado + coberto por testes |
| Tela de ajustes Motored (WooCommerce) | implementado, depende de staging |
| WhatsApp: fila, limites, disjuntor, tentativas, prioridade | implementado + coberto por testes |
| WhatsApp: conexão real com o WAHA (envio, "digitando", checagem do número) | implementado, depende de staging |
| Avisos de pedido (fila → texto → nota no pedido) | implementado + coberto por testes |
| Avisos de pedido com gateway real (Mercado Pago) | implementado, depende de staging |
| Recuperação de carrinhos: captura, sequência, expiração | implementado + coberto por testes |
| Captura no checkout clássico e de Blocos | implementado, depende de staging |
| Recuperação de pedidos pendentes/falhados/em espera | implementado + coberto por testes |
| Links de recuperação e de descadastro | implementado, depende de staging |
| E-mails de recuperação (templates do WooCommerce) | implementado, depende de staging |
| Fotos nas avaliações: validação, limites, exclusão | implementado + coberto por testes |
| Fotos nas avaliações: formulário e miniaturas em cada tema | implementado, depende de staging |
| Pedido de avaliação | implementado + coberto por testes |
| Receber respostas dos clientes; WhatsApp com imagem/botões | pendente |
| Caixa de consentimento (decisão: não nesta fase) | pendente |

## O que testar primeiro na staging (docs/VERIFY.md, do mais ao menos arriscado)
1. **#55** — status finais do Mercado Pago (aprovado/pendente/em análise/recusado/abandonado): base de tudo na recuperação.
2. **#49** e **#50** — criação do pedido (status inicial, hooks) e o que conta como "pago"/link de pagamento.
3. **#51** — cancelamento automático por cron vs. cancelamento manual (para a sequência só no manual).
4. **#37** — hooks de criação de pedido no checkout clássico e de Blocos (convertem o carrinho).
5. **#38** — sessão do cliente estável da captura até o pedido.
6. **#56** — consultas por evento de pedido (orçamento de desempenho) e captura pulada com pedido ativo.
7. **#22** e **#28** — endpoints e erros reais do WAHA (envio, sessão parada, 5xx): definem retentativa e disjuntor.
8. **#45** — links de recuperação/descadastro (um scanner não deve descadastrar ninguém).
9. **#58** — chave `wp_salt` estável (tokens dos links) após limpar o cache.
10. **#62** e **#63** — fotos: envio multipart pelo JS e remoção de EXIF/limites de upload (PHP e nginx).
