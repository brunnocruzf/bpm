<?php

namespace App\Support;

/**
 * Explicação dos códigos sanitizados das integrações (e-mail, login Entra, Graph/importação),
 * exibida nos logs de Configurações: o que significa e o que fazer. Nunca inclui dados do erro.
 */
final class IntegrationErrors
{
    /** @var array<string, array{0:string, 1:string, 2:string}> código => [título, causa provável, o que fazer] */
    private const CATALOG = [
        // ---------------------------------------------------------------- e-mail
        'not_configured' => ['Configuração incompleta', 'Provedor, credencial ou parâmetro obrigatório ausente (ou o segredo montado não pôde ser lido).', 'Preencha os campos obrigatórios e salve a credencial; se usar "file:<nome>", confira se o secret está montado no container da API.'],
        'host_blocked' => ['Destino bloqueado pela política de rede', 'O host/porta não passa na proteção anti-SSRF: rede interna/reservada, porta fora de 25/465/587/2525 ou SMTP sem criptografia fora de um relay declarado.', 'Use o host público do provedor com 587 (STARTTLS) ou 465 (TLS). Relays internos precisam ser declarados pela plataforma em BPM_MAIL_PRIVATE_RELAY_HOSTS.'],
        'connection_failed' => ['Não foi possível conectar', 'DNS não resolveu o host, a porta está fechada ou a saída do servidor (egress/firewall) está bloqueada.', 'Confira host e porta, a resolução DNS no servidor e a liberação de saída para o provedor. Falha transitória: a entrega é repetida automaticamente.'],
        'timeout' => ['Tempo esgotado', 'O servidor não respondeu dentro do tempo limite (rede lenta, firewall descartando pacotes ou servidor sobrecarregado).', 'Verifique a rota de rede e o firewall; se o servidor for lento, aumente o tempo limite. A entrega é repetida automaticamente.'],
        'tls_failure' => ['Falha de TLS/certificado', 'A negociação TLS falhou ou o certificado do servidor não é válido para o host (a validação não pode ser desligada).', 'Use 587 com STARTTLS ou 465 com TLS conforme o servidor e garanta um certificado válido emitido para o nome do host configurado.'],
        'auth_failed' => ['Autenticação recusada', 'Usuário/senha inválidos, senha expirada ou, no Microsoft 365, SMTP AUTH desabilitado na caixa / MFA / security defaults.', 'Confira a credencial; no Microsoft 365 habilite SMTP AUTH na caixa (ou use o Graph sendMail).'],
        'recipient_rejected' => ['Destinatário ou mensagem recusados', 'O servidor recusou o destinatário (inexistente, relay não permitido) ou a mensagem (política/tamanho).', 'Confira o endereço e se o remetente pode enviar para domínios externos (relay). Falha permanente: não é repetida.'],
        'throttled' => ['Limite de envio/requisições', 'O provedor pediu para esperar (4xx SMTP ou HTTP 429 do Graph).', 'Nada a fazer de imediato: o envio é repetido com espera crescente. Se for frequente, revise os limites da caixa/tenant.'],
        'provider_error' => ['Falha no provedor', 'Erro não classificado do provedor de e-mail ou indisponibilidade temporária.', 'Consulte o log do provedor usando o correlation ID ou o Message-ID; falhas transitórias são repetidas.'],
        'consent_missing' => ['Permissão ausente ou sem consentimento', 'O aplicativo não tem a permissão de aplicação exigida com admin consent (ex.: Mail.Send, User.Read.All, Group.Read.All) ou a caixa está fora da política de acesso.', 'Peça ao administrador do tenant para conceder a permissão com "Grant admin consent" e, para e-mail, incluir a caixa na Application Access Policy/RBAC for Applications.'],
        'credential_invalid' => ['Credencial do aplicativo inválida', 'Client secret expirado/errado, certificado não cadastrado no App Registration ou chave privada não corresponde.', 'Gere/renove a credencial no App Registration e substitua-a aqui (o valor antigo nunca é exibido).'],
        'sender_invalid' => ['Caixa de envio inexistente', 'A caixa de envio configurada não existe no Exchange Online ou não está licenciada.', 'Informe uma caixa compartilhada válida do tenant.'],
        'provider_disabled' => ['Envio desabilitado', 'O provedor está como "Desabilitado"; a notificação foi descartada sem envio.', 'Configure e teste um provedor; notificações descartadas não são reenviadas automaticamente.'],
        // ---------------------------------------------------------------- login Entra (descoberta / teste / tokens recusados)
        'incomplete' => ['Configuração incompleta', 'Faltam parâmetros obrigatórios (tenant, client ID, redirect URI, audience ou scope).', 'Preencha os campos obrigatórios e salve antes de validar.'],
        'unreachable' => ['Host de identidade inacessível', 'O servidor da API não conseguiu acessar o host de identidade (DNS, proxy ou egress bloqueado).', 'Libere a saída HTTPS do servidor para login.microsoftonline.com (ou o host de nuvem escolhido).'],
        'tenant_not_found' => ['Tenant não encontrado', 'O tenant informado não existe no host de identidade escolhido.', 'Confira o Tenant ID (GUID) e o host (global, US Gov ou China).'],
        'discovery_mismatch' => ['Documento OpenID inesperado', 'O documento de descoberta não confere (issuer, JWKS fora do host ou sem suporte a PKCE).', 'Confira tenant e host de identidade; contas multi-tenant ("common"/"organizations") não são aceitas.'],
        'discovery_required' => ['Descoberta pendente', 'O teste de token exige a descoberta OIDC desta versão da configuração.', 'Execute "Validar descoberta" e depois "Testar login".'],
        'token_expired' => ['Token expirado', 'O access token já venceu (ou o relógio do servidor está adiantado).', 'Entre novamente; se persistir, sincronize o relógio (NTP) do servidor.'],
        'not_yet_valid' => ['Token ainda não válido', 'O token tem início de validade no futuro: diferença de relógio entre servidor e Microsoft.', 'Sincronize o relógio (NTP) do servidor da API.'],
        'audience_mismatch' => ['Audience diferente da API', 'O token foi emitido para outro recurso — comum quando o SPA pede o scope errado ou envia o ID token.', 'Confira o Application ID URI da API (audience) e o scope pedido pelo SPA (api://<api>/access_as_user).'],
        'issuer_mismatch' => ['Issuer inesperado', 'O emissor não é o do tenant/host configurado; geralmente token v1 quando a API espera v2.', 'No manifesto da API defina accessTokenAcceptedVersion = 2 e confira o host de identidade.'],
        'tenant_not_allowed' => ['Tenant não autorizado', 'O token veio de um tenant que não está na lista de tenants aceitos.', 'Use uma conta do tenant configurado ou inclua o tenant em "Tenants aceitos".'],
        'scope_missing' => ['Scope ausente no token', 'O token não contém o scope delegado exigido (ex.: access_as_user) ou é um token app-only.', 'Peça o scope correto no SPA e conceda o consentimento ao scope da API.'],
        'signature_invalid' => ['Assinatura inválida', 'A assinatura do token não confere com as chaves (JWKS) do tenant: token adulterado ou de outro emissor.', 'Entre novamente pelo botão Microsoft; se persistir, confira tenant/host configurados.'],
        'unknown_signing_key' => ['Chave de assinatura desconhecida', 'O token foi assinado com uma chave que não está no JWKS do tenant configurado.', 'Confira se o token é do tenant configurado; o JWKS é renovado automaticamente pelo cache.'],
        'jwks_unavailable' => ['Chaves de assinatura indisponíveis', 'A API não conseguiu obter o JWKS do Entra ID (rede/egress) ou o endereço está fora do host configurado.', 'Libere a saída HTTPS para o host de identidade e tente novamente.'],
        'no_user_identity' => ['Token sem usuário', 'Token sem a claim oid (ex.: token app-only); o login exige um usuário.', 'Use o login interativo do SPA (Authorization Code + PKCE).'],
        'token_malformed' => ['Token malformado', 'O valor enviado não é um JWT válido (ex.: ID token truncado, cabeçalho Authorization incorreto).', 'Saia e entre novamente; confira se nada intermediário altera o cabeçalho Authorization.'],
        'token_invalid' => ['Token recusado', 'O token não passou na validação (assinatura, emissor ou formato).', 'Refaça o teste de login e confira tenant, audience e scope.'],
        // ---------------------------------------------------------------- Graph / importação de usuários e grupos
        'identity_unreachable' => ['Entra ID inacessível', 'O worker não conseguiu falar com o endpoint de token do Entra ID (rede/egress).', 'Libere a saída HTTPS do servidor para login.microsoftonline.com e graph.microsoft.com.'],
        'tenant_invalid' => ['Tenant inválido', 'O tenant informado no app de sincronização não existe (AADSTS90002/900023).', 'Confira o Tenant ID.'],
        'app_not_found' => ['Aplicativo não encontrado', 'O client ID não existe no tenant (AADSTS700016) — app removido ou de outro tenant.', 'Confira o client ID do app de sincronização no tenant correto.'],
        'credential_missing' => ['Credencial ausente', 'O certificado/secret do app de sincronização não está configurado ou o arquivo montado não pôde ser lido.', 'Envie o certificado (PEM) ou configure a referência file:<nome> do secret montado.'],
        'credential_unsupported' => ['Tipo de credencial não suportado', 'O tipo de credencial escolhido não está disponível neste ambiente.', 'Use certificado (recomendado) ou client secret com aprovação.'],
        'scope_invalid' => ['Scope inválido', 'O scope solicitado no token app-only é inválido.', 'O scope deve ser https://graph.microsoft.com/.default.'],
        'token_error' => ['Falha ao obter token', 'O Entra ID recusou o pedido de token app-only por outro motivo (ver código AADSTS na mensagem).', 'Pesquise o código AADSTS indicado e revise o App Registration.'],
        'excessive_permissions' => ['Permissões excessivas', 'O app de sincronização tem permissões além das necessárias (escrita no diretório ou Directory.Read.All).', 'Remova as permissões extras: o produto só precisa de User.Read.All e Group.Read.All (somente leitura).'],
        'delta_expired' => ['Cursor delta expirado', 'O Graph invalidou o cursor incremental (410/resyncRequired), por exemplo após muito tempo sem sincronizar.', 'Nada a fazer: a execução faz uma recarga completa controlada automaticamente.'],
        'not_found' => ['Objeto não encontrado', 'Usuário ou grupo não existe mais no diretório (excluído) ou o ID está errado.', 'Confira o Object ID do grupo; usuários excluídos são desativados no BPM sem apagar histórico.'],
        'graph_error' => ['Erro do Microsoft Graph', 'O Graph respondeu com erro não classificado (ver HTTP na mensagem).', 'Tente novamente; se persistir, consulte o status do Microsoft 365 e o correlation ID.'],
        'not_enabled' => ['Sincronização desabilitada', 'A execução foi pedida com a sincronização desligada nas configurações.', 'Marque "Sincronização habilitada" e salve (a prévia funciona mesmo desabilitada).'],
        'concurrent_run' => ['Execução simultânea', 'Já havia outra sincronização em andamento; esta não foi executada.', 'Aguarde a execução atual terminar.'],
        'removal_threshold' => ['Trava de segurança acionada', 'A execução removeria/desativaria mais do que o limite seguro (padrão 30%) — sinal típico de falha parcial ou grupo errado.', 'Confira a lista de grupos e o diretório; nada foi removido. Se a mudança for real, ajuste os grupos e rode uma recarga completa.'],
        'internal_error' => ['Erro interno', 'Falha inesperada no servidor durante a operação.', 'Informe o correlation ID ao suporte técnico (o log técnico tem o detalhe).'],
        'fetch_limit' => ['Limite de leituras atingido', 'Muitos membros novos precisaram de leitura individual nesta execução.', 'Nada a fazer: os restantes são lidos na próxima execução.'],
        'mock_groups' => ['Grupos do diretório fictício', 'Os Object IDs configurados são dos grupos de demonstração e não existem no tenant real.', 'Remova-os e informe os Object IDs dos grupos reais (Entra → Grupos → Visão geral → Object ID).'],
        'shared_app' => ['App compartilhado com outras permissões', 'O app usado na importação tem permissões de aplicação que o BPM não usa; o segredo dele fica guardado no BPM.', 'Crie um App Registration exclusivo para a importação, só com User.Read.All e Group.Read.All (Application) + admin consent.'],
        'mock_forbidden' => ['Diretório fictício em produção', 'O transporte fictício (mock) é proibido em produção.', 'Selecione o transporte Microsoft Graph.'],
        // ---------------------------------------------------------------- armazenamento de anexos (Azure Blob)
        'container_not_found' => ['Contêiner não encontrado', 'O contêiner informado não existe na conta de armazenamento (ou o nome está errado).', 'Crie o contêiner privado no portal do Azure (ou confira o nome) e teste de novo.'],
        'azure_unavailable' => ['Destino Azure indisponível', 'Não há destino Azure ativo em Armazenamento de anexos.', 'Ative o Azure Blob Storage em Armazenamento de anexos antes de enviar backups.'],
        'list_failed' => ['Listagem incompleta', 'O arquivo de prova foi gravado, mas não apareceu ao listar a pasta.', 'Confira a permissão de listagem da credencial e se a pasta está correta.'],
        'container_public' => ['Contêiner com acesso público', 'O contêiner permite leitura anônima; os anexos exigem contêiner privado.', 'No portal, defina o nível de acesso público do contêiner como "Privado".'],
        'storage_forbidden' => ['Permissão insuficiente no armazenamento', 'A chave é válida, mas a operação foi negada (firewall da conta, rede ou política).', 'Libere a rede do servidor no firewall da conta de armazenamento e confira se a chave pertence à conta informada.'],
        'shared_key_disabled' => ['Chave da conta desabilitada', 'A conta de armazenamento não permite autorização por chave (Shared Key), em geral por Azure Policy.', 'Peça à plataforma para permitir Shared Key nesta conta (ou use outra conta). SAS e Entra ID ficam como evolução.'],
        'clock_skew' => ['Relógio do servidor defasado', 'O Azure recusa chamadas assinadas com data diferente de mais de 15 minutos do horário real.', 'Sincronize o relógio do servidor (NTP) e teste de novo.'],
        'integrity_mismatch' => ['Conteúdo lido diferente do gravado', 'O arquivo de prova voltou diferente do enviado (proxy ou intermediário alterando o conteúdo).', 'Verifique proxies/inspeção de tráfego entre o servidor e o Azure.'],
        'emulator_forbidden' => ['Emulador não permitido', 'O emulador (Azurite) só existe em desenvolvimento/teste e é proibido em produção.', 'Selecione o endpoint público da conta de armazenamento.'],
        'storage_unavailable' => ['Armazenamento indisponível', 'Não foi possível gravar ou ler no destino de armazenamento configurado.', 'Verifique o teste de conexão em Configurações → Armazenamento de anexos; não há gravação em outro destino automaticamente.'],
        'storage_target_missing' => ['Destino do arquivo não registrado', 'O disco em que o anexo foi gravado não está mais no registro de destinos.', 'Restaure o registro de destinos ou a configuração original.'],
        // ---------------------------------------------------------------- catálogo de integrações REST
        'target_blocked' => ['Destino bloqueado pela política de rede', 'O host não é https, aponta para a rede interna sem estar declarado pela plataforma, ou é um destino proibido (metadata/loopback).', 'Peça à plataforma para declarar o host em BPM_INTEGRATION_ALLOWED_HOSTS (obrigatório para hosts internos e em produção).'],
        'forbidden' => ['Acesso negado pela API (403)', 'A credencial é válida, mas não tem permissão para este recurso.', 'Peça ao time da API a permissão para o usuário/token de serviço do BPM.'],
        'request_rejected' => ['Requisição recusada pela API', 'Dados enviados inválidos ou incompletos (HTTP 4xx).', 'Confira parâmetros, corpo e tipos no endpoint; use "Testar endpoint" com valores de homologação.'],
        'request_invalid' => ['Requisição incompleta', 'Falta um parâmetro obrigatório ou há credencial configurada como parâmetro.', 'Preencha os parâmetros obrigatórios; credenciais ficam só na autenticação da conexão.'],
        'redirect_blocked' => ['Redirecionamento não seguido', 'A API respondeu com redirecionamento (3xx); por segurança o servidor não segue para outro destino.', 'Use a URL final correta na conexão (https, caminho exato).'],
        'response_too_large' => ['Resposta grande demais', 'A resposta passou do limite configurado (BPM_INTEGRATION_MAX_RESPONSE_BYTES).', 'Use filtros/paginação do endpoint ou um endpoint mais específico.'],
        'write_not_allowed' => ['Escrita não liberada', 'A conexão está em somente leitura.', 'Libere escritas na conexão informando a referência da aprovação.'],
        'reconciliation_required' => ['Reconciliação pendente', 'Uma tentativa anterior de escrita teve resultado incerto.', 'Confira no sistema de destino e use Reconciliar em Execuções antes de repetir.'],
        'token_not_found' => ['Token não encontrado na resposta', 'O login/endpoint de token respondeu sem o token no lugar esperado.', 'Informe o caminho do token na conexão (ex.: data.token) ou confira o endpoint de login/token.'],
        'not_verified' => ['Credencial não verificada', 'A credencial está definida, mas nenhuma chamada real foi feita no teste.', 'Informe um caminho de verificação (GET leve) na conexão e teste de novo.'],
    ];

    /** Explicações específicas por contexto (email | login | directory), quando a genérica é ambígua. */
    private const CONTEXT = [
        'storage:credential_invalid' => ['Conta ou chave recusadas pelo Azure', 'O nome da conta ou a chave estão errados, a chave foi rotacionada ou a assinatura não confere.', 'Copie de novo a chave em Contas de armazenamento → Chaves de acesso e salve (a chave é somente escrita).'],
        'storage:target_blocked' => ['Destino bloqueado pela política de rede', 'O host da conta não está declarado pela plataforma (em produção todo host precisa estar em BPM_INTEGRATION_ALLOWED_HOSTS) ou é um destino proibido.', 'Peça à plataforma para declarar <conta>.blob.core.windows.net em BPM_INTEGRATION_ALLOWED_HOSTS.'],
        'storage:connection_failed' => ['Não foi possível conectar ao Azure', 'DNS não resolveu o host da conta ou a saída do servidor (egress/firewall) está bloqueada.', 'Libere a saída HTTPS do servidor para <conta>.blob.core.windows.net e confira o firewall da conta.'],
        'directory:consent_missing' => ['Permissão de APLICAÇÃO ausente (Delegated não serve)', 'A importação usa o token do próprio aplicativo (sem usuário logado): só permissões do tipo Application aparecem nele. User.Read.All/Group.Read.All como "Delegated" — mesmo com admin consent — não valem.', 'Peça ao administrador do tenant: App Registration do app de sincronização → API permissions → Microsoft Graph → Application permissions → User.Read.All e Group.Read.All → "Grant admin consent". Depois rode "Testar conexão e permissões".'],
        'email:consent_missing' => ['Mail.Send sem consentimento ou caixa fora da política', 'O app de e-mail não tem Mail.Send (Application) com admin consent ou a caixa de envio está fora da Application Access Policy / RBAC for Applications do Exchange.', 'Conceda Mail.Send com "Grant admin consent" e inclua a caixa de envio na política de acesso do aplicativo no Exchange Online.'],
        'directory:credential_invalid' => ['Credencial do app de sincronização inválida', 'Client secret expirado/errado ou certificado não cadastrado no App Registration do app de sincronização.', 'Renove a credencial em Certificates & secrets do app de sincronização e substitua-a nesta tela.'],
        'rest:credential_invalid' => ['Credencial recusada pela API', 'Token, senha ou chave inválidos/expirados (HTTP 401) — ou o prefixo do cabeçalho não é o esperado.', 'Substitua a credencial na conexão (somente escrita) e confira o tipo de autenticação/prefixo (ex.: Authorization: Bearer <token>); use o caminho de verificação para testar.'],
        'email:credential_invalid' => ['Credencial do app de e-mail inválida', 'Client secret expirado/errado ou certificado não cadastrado no App Registration usado para o envio.', 'Renove a credencial do app de e-mail e substitua-a nesta tela.'],
    ];

    /** @return array{title:string, cause:string, action:string}|null */
    public static function explain(?string $code, ?string $context = null): ?array
    {
        if ($code === null || $code === '') {
            return null;
        }
        if ($context && isset(self::CONTEXT["$context:$code"])) {
            [$title, $cause, $action] = self::CONTEXT["$context:$code"];

            return ['title' => $title, 'cause' => $cause, 'action' => $action];
        }
        if (str_starts_with($code, 'discovery_http_')) {
            return ['title' => 'Descoberta OIDC respondeu erro HTTP', 'cause' => 'O host de identidade respondeu '.substr($code, 15).' ao documento OpenID do tenant.', 'action' => 'Confira tenant e host de identidade; tente novamente se for indisponibilidade temporária.'];
        }
        $row = self::CATALOG[$code] ?? null;

        return $row ? ['title' => $row[0], 'cause' => $row[1], 'action' => $row[2]] : null;
    }
}
