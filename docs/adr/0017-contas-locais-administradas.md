# ADR-0017 — Contas locais administradas

- **Status:** aceito
- **Relaciona:** ADR-0004 (identidade Entra), ADR-0013 (login × sincronização), ADR-0015 (métodos de login)

## Contexto

O produto mantém login corporativo pelo Entra ID e precisa permitir contas locais provisionadas por administradores.
As contas locais não são identidades de emergência e não devem depender do Microsoft Graph.

## Decisão

1. `users.auth_source = local` identifica uma conta local; `local_credentials` armazena somente o hash da senha. Contas
   são criadas inativas e pendentes até o titular definir a senha por um link aleatório, de uso único e validade de
   uma hora. Não existe cadastro público nem senha temporária revelada ao administrador.
2. Tokens de convite e recuperação são armazenados somente como SHA-256 em `local_identity_token`. O conteúdo do
   e-mail que contém o link fica cifrado no outbox existente e é decifrado somente pelo worker de envio. O envio usa
   o provedor já configurado; Mailpit é o padrão fora de produção.
3. A API mantém sua autenticação por sessão opaca. Para contas locais, o valor opaco fica em cookie `HttpOnly`,
   `SameSite=Strict`, com `Secure` em produção. O cookie CSRF auxiliar não é HttpOnly; operações que alteram dados
   exigem seu valor no cabeçalho `X-BPM-CSRF`. A sessão guarda no banco somente o hash SHA-256 do token, tem prazo
   absoluto e de inatividade, e é revogada no logout, desativação e redefinição de senha.
4. Entra e SuperAdmin continuam no fluxo existente por bearer token. A resolução dá precedência a um bearer token;
   uma falha do Entra nunca aciona autenticação local. Ao trocar de conta local para Entra/SuperAdmin, o SPA solicita
   revogação e remoção do cookie local antes de continuar.
5. Papéis efetivos continuam submetidos às mesmas Gates/Policies. A criação local não recebe papéis/grupos por
   padrão. Membership manual fica em tabela própria e se agrega ao acesso efetivo sem substituir as fontes do token
   ou da sincronização Graph.
6. Login, criação/ativação/desativação, convites, reset e membership manual são auditados. Senhas, hashes, tokens,
   cookies e links completos nunca entram em auditoria.
7. Toda conta local é classificada na criação como **fornecedor**, **cliente** ou **interno** (`users.user_category`:
   `supplier`, `customer`, `internal`). É uma classificação administrativa, separada do `user_type` do Entra
   (Member/Guest), **não concede papéis, grupos nem raias** e pode ser alterada por quem tem `settings.manage`
   (evento `identity.user_category_changed`). Contas anteriores ficam sem classificação até serem revisadas.
9. **Remover membros de grupos/raias** (`settings.manage`). O vínculo manual é apagado (`DELETE .../members/{userId}`). Acesso que vem do Entra
   (token ou sincronização) ou de mapeamento não pode ser apagado na origem — o Entra ID nunca é alterado —, então é **bloqueado
   localmente** (`PUT .../exclusions/{userId}`): `users.excluded_groups` (cache) + registro com autor e data em
   `bpm_group_membership` (`source = excluded`). O bloqueio entra em `User::recomputeEffectiveGroups`, usado por login e
   sincronização, e **vale sobre qualquer origem** até ser restaurado (`DELETE .../exclusions/{userId}`); adicionar o usuário
   manualmente também o desfaz. Remover de um grupo **não altera papéis**. A conta de emergência (SuperAdmin) não participa de
   grupos. Eventos auditados: `identity.group_member_excluded` e `identity.group_member_restored`.
8. A ativação manual exige que o titular tenha aceitado o convite (`local_credentials.activated_at`); reenviar um
   convite invalida os anteriores, mas nunca equivale a aceite.

## Consequências

- O login local funciona independentemente do Entra e não associa automaticamente contas com e-mail coincidente.
- O email deve estar configurado para o titular ativar a conta ou redefinir senha. A conta permanece sem acesso
  enquanto estiver pendente.
- MFA local não existe no domínio atual; deve ser definida como controle de homologação para contas privilegiadas.
- O modelo atual referencia `ProcessStageVersion.lane` a uma chave global de `bpm_groups`; não há associação usuário/raia
  escopada por processo/versão. Portanto, incluir alguém em um grupo afeta sua elegibilidade em todas as tarefas que
  referenciam aquela chave, inclusive tarefas abertas. A tela não cria vínculos de raia por processo nem reatribui
  tarefas existentes. Se uma mesma chave tiver significados diferentes por fluxo/versão, um modelo de assignment
  escopado deve ser projetado antes de administrar essas associações.
- `/admin/groups` é rota do Vue Router e é servida pelo fallback SPA do web server. Laravel neste repositório expõe
  API em `routes/api.php` e deliberadamente não registra uma página duplicada em `routes/web.php`.
