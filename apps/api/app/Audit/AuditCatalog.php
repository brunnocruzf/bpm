<?php

namespace App\Audit;

/**
 * Catálogo de eventos de auditoria: nome estável → categoria e rótulo. Todo evento gravado
 * precisa estar aqui (teste de arquitetura garante); a tela de auditoria usa o catálogo nos filtros.
 */
final class AuditCatalog
{
    public const CATEGORIES = [
        'definition' => 'Definição de processos',
        'runtime' => 'Execução de processos',
        'engine' => 'Engine BPMN',
        'integration' => 'Integrações',
        'attachment' => 'Anexos',
        'notification' => 'Notificações',
        'identity' => 'Identidade e acesso',
        'settings' => 'Configurações',
        'report' => 'Relatórios',
        'audit' => 'Auditoria',
    ];

    /** @var array<string, array{0:string,1:string}> */
    public const EVENTS = [
        // Definição
        'flow.created' => ['definition', 'Fluxo criado'],
        'flow.updated' => ['definition', 'Dados do fluxo alterados'],
        'flow.deleted' => ['definition', 'Fluxo em rascunho excluído'],
        'version.draft_discarded' => ['definition', 'Rascunho descartado'],
        'stage.added' => ['definition', 'Etapa adicionada'],
        'stage.updated' => ['definition', 'Etapa alterada'],
        'stage.deleted' => ['definition', 'Etapa removida'],
        'stage.transitions_updated' => ['definition', 'Rotas da etapa alteradas'],
        'form.saved' => ['definition', 'Formulário salvo'],
        'version.draft_created' => ['definition', 'Rascunho criado'],
        'version.bpmn_imported' => ['definition', 'BPMN importado'],
        'version.published' => ['definition', 'Versão publicada'],
        // Engine
        'engine.deployed' => ['engine', 'Deploy confirmado pelo engine'],
        'engine.instance_started' => ['engine', 'Instância iniciada no engine'],
        'engine.task_completed' => ['engine', 'Conclusão confirmada pelo engine'],
        'engine.command_failed' => ['engine', 'Comando do engine falhou'],
        'engine.command_retry' => ['engine', 'Comando do engine reprocessado'],
        // Execução
        'instance.created' => ['runtime', 'Solicitação aberta'],
        'instance.ended' => ['runtime', 'Solicitação encerrada'],
        'instance.branch_taken' => ['runtime', 'Decisão: caminho escolhido'],
        'task.created' => ['runtime', 'Tarefa criada (entrada na etapa)'],
        'task.claimed' => ['runtime', 'Tarefa assumida'],
        'task.released' => ['runtime', 'Tarefa liberada'],
        'task.reassigned' => ['runtime', 'Tarefa reatribuída'],
        'task.completed' => ['runtime', 'Formulário submetido'],
        'task.cancelled' => ['runtime', 'Tarefa cancelada'],
        'supplier_action.completed' => ['runtime', 'Ação do plano concluída'],
        // Anexos
        'attachment.uploaded' => ['attachment', 'Anexo enviado'],
        'attachment.rejected_infected' => ['attachment', 'Anexo recusado pelo antivírus'],
        'attachment.download_url' => ['attachment', 'Link de download de anexo gerado'],
        // Integrações
        'integration.completed' => ['integration', 'Integração concluída'],
        'integration.failed' => ['integration', 'Integração falhou'],
        'integration.connection_saved' => ['integration', 'Conexão de integração salva'],
        'integration.connection_tested' => ['integration', 'Conexão de integração testada'],
        'integration.secret_updated' => ['integration', 'Credencial de integração substituída'],
        'integration.openapi_imported' => ['integration', 'Endpoints importados do OpenAPI/Swagger'],
        'integration.endpoint_contract_updated' => ['integration', 'Contrato do endpoint atualizado pela importação'],
        'integration.endpoint_saved' => ['integration', 'Endpoint de integração salvo'],
        'integration.endpoint_deleted' => ['integration', 'Endpoint de integração removido'],
        'integration.endpoint_tested' => ['integration', 'Endpoint de integração testado'],
        'integration.binding_saved' => ['integration', 'Vínculo de operação com endpoint salvo'],
        'integration.execution_reconciled' => ['integration', 'Escrita com resultado incerto reconciliada'],
        // Notificações
        'notification.queued' => ['notification', 'Notificação enfileirada'],
        'notification.sent' => ['notification', 'Notificação enviada'],
        'notification.retry_scheduled' => ['notification', 'Notificação reagendada'],
        'notification.failed' => ['notification', 'Notificação falhou (dead-letter)'],
        'notification.skipped' => ['notification', 'Notificação descartada (sem destinatário)'],
        'notification.manual_retry' => ['notification', 'Notificação reprocessada manualmente'],
        'notification.test_sent' => ['notification', 'E-mail de teste enviado'],
        // Identidade
        'auth.login' => ['identity', 'Acesso à aplicação'],
        'auth.login_blocked' => ['identity', 'Acesso bloqueado'],
        'auth.login_failed' => ['identity', 'Tentativa de acesso recusada'],
        'auth.token_rejected' => ['identity', 'Access token Entra recusado pela API'],
        'auth.logout' => ['identity', 'Saída da aplicação'],
        'auth.superadmin_locked' => ['identity', 'SuperAdmin bloqueado por tentativas'],
        'auth.superadmin_password_set' => ['identity', 'Senha do SuperAdmin definida'],
        'auth.superadmin_unlocked' => ['identity', 'SuperAdmin desbloqueado'],
        'auth.superadmin_disabled' => ['identity', 'SuperAdmin desativado'],
        'auth.sessions_revoked' => ['identity', 'Sessões revogadas'],
        'auth.roles_changed' => ['identity', 'Papéis/grupos do usuário alterados'],
        'group.updated' => ['identity', 'Grupo/raia TMSA alterado'],
        'group.created' => ['identity', 'Grupo/raia TMSA criado'],
        'directory.sync_requested' => ['identity', 'Sincronização do diretório solicitada'],
        'directory.sync_completed' => ['identity', 'Sincronização do diretório concluída'],
        'directory.sync_failed' => ['identity', 'Sincronização do diretório falhou'],
        'directory.sync_blocked' => ['identity', 'Sincronização bloqueada pela trava de segurança'],
        'directory.photo_refresh_requested' => ['identity', 'Atualização de foto do diretório solicitada'],
        'directory.user_created' => ['identity', 'Usuário importado do diretório'],
        'identity.local_user_created' => ['identity', 'Conta local provisionada'],
        'identity.user_category_changed' => ['identity', 'Tipo de usuário alterado'],
        'identity.local_user_activated' => ['identity', 'Conta local ativada'],
        'identity.local_user_disabled' => ['identity', 'Conta local desativada'],
        'identity.local_invitation_issued' => ['identity', 'Convite de conta local emitido'],
        'identity.local_password_reset_requested' => ['identity', 'Recuperação de senha local solicitada'],
        'identity.local_password_reset' => ['identity', 'Senha de conta local redefinida'],
        'identity.local_group_member_added' => ['identity', 'Membro adicionado manualmente ao grupo'],
        'identity.local_group_member_removed' => ['identity', 'Vínculo manual de grupo removido'],
        'identity.manual_role_assigned' => ['identity', 'Papel atribuído manualmente'],
        'identity.manual_role_removed' => ['identity', 'Atribuição manual de papel removida'],
        'directory.user_disabled' => ['identity', 'Usuário desativado pelo diretório'],
        'directory.user_roles_changed' => ['identity', 'Papéis do usuário alterados pelo diretório'],
        'directory.mapping_created' => ['identity', 'Mapeamento de grupo criado'],
        'directory.mapping_updated' => ['identity', 'Mapeamento de grupo alterado'],
        'directory.mapping_deleted' => ['identity', 'Mapeamento de grupo removido'],
        // Configurações
        'settings.updated' => ['settings', 'Configuração alterada'],
        'settings.validated' => ['settings', 'Configuração validada/testada'],
        'settings.activated' => ['settings', 'Configuração ativada'],
        'settings.disabled' => ['settings', 'Configuração desabilitada'],
        'settings.secret_updated' => ['settings', 'Segredo de integração substituído'],
        'branding.asset_uploaded' => ['settings', 'Imagem de marca enviada'],
        'branding.updated' => ['settings', 'Identidade visual aplicada'],
        'branding.reset' => ['settings', 'Identidade visual restaurada ao padrão'],
        // Relatórios
        'report.requested' => ['report', 'Relatório solicitado'],
        'report.generated' => ['report', 'Relatório gerado'],
        'report.failed' => ['report', 'Geração de relatório falhou'],
        'report.downloaded' => ['report', 'Relatório baixado/visualizado'],
        'report.printed' => ['report', 'Relatório enviado para impressão'],
        'report.expired' => ['report', 'Arquivo de relatório expirado'],
        'report_template.created' => ['report', 'Modelo de relatório criado'],
        'report_template.activated' => ['report', 'Modelo de relatório ativado'],
        // Auditoria
        'audit.exported' => ['audit', 'Trilha de auditoria exportada'],
        'audit.retention_purged' => ['audit', 'Expurgo de retenção executado'],
    ];

    public static function category(string $action): string
    {
        return self::EVENTS[$action][0] ?? 'runtime';
    }

    public static function label(string $action): string
    {
        return self::EVENTS[$action][1] ?? $action;
    }

    public static function known(string $action): bool
    {
        return isset(self::EVENTS[$action]);
    }

    /** @return list<array{key:string,label:string,category:string}> */
    public static function describe(): array
    {
        $out = [];
        foreach (self::EVENTS as $key => [$category, $label]) {
            $out[] = ['key' => $key, 'label' => $label, 'category' => $category];
        }

        return $out;
    }
}
