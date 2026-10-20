# Matriz requisito → teste

Legenda: ✅ coberto por teste automatizado · 🟡 coberto parcialmente/manual · ⏳ pendente (depende de decisão/contrato).

| Requisito (seção da especificação) | Evidência | Status |
|---|---|---|
| §4.3 Novo fluxo cria fluxo + versão draft + Etapa 1 + formulário + Início→Etapa 1→Fim em uma transação | `FlowAuthoringTest::test_new_flow_creates_draft_first_human_stage_linked_form_and_initial_path_atomically`, `::test_flow_creation_rolls_back_completely_on_failure` | ✅ |
| §4.3 Adicionar etapa humana cria form; gateway/timer/integração não cria form órfão; manter ≥1 tarefa humana | `FlowAuthoringTest::test_adding_stages_links_forms_only_to_human_tasks` | ✅ |
| §4.2/§10 Builder sem JavaScript livre; HTML/URLs perigosos; fontes externas; keys válidas | `FormioSchemaSanitizerTest` (5 testes) | ✅ |
| §4.2 Condições em lista branca (sem JUEL/SpEL livre) e escape de literais | `ConditionCompilerTest` (9 casos) + validado no Flowable real | ✅ |
| §4.3 item 6 Validação: form válido, responsável, rotas, campos referenciados, caminho até o fim | `FlowAuthoringTest::test_validation_reports_actionable_problems_and_blocks_publication`, `::test_gateway_conditions_must_reference_existing_fields` | ✅ |
| §4.4 Publicação congela versão; artefatos imutáveis; nova versão por clone | `FlowAuthoringTest::test_form_is_sanitized_and_published_versions_are_immutable` | ✅ |
| §4.2 Import/export BPMN com recusa de elementos não suportados; preserva forms | `FlowAuthoringTest::test_bpmn_export_and_import_round_trip_preserves_forms_and_rejects_unsupported_elements` | ✅ |
| §3.3 Outbox/idempotência/reconciliação sem duplicar instância | `RuntimeTest::test_lost_start_response_is_reconciled_without_duplicating_the_instance` | ✅ |
| §3.3 Resposta perdida na conclusão reconciliada pelo histórico | `RuntimeTest::test_lost_complete_response_is_reconciled_from_history` | ✅ |
| §9 Idempotency-Key obrigatória e replay | `RuntimeTest::test_start_requires_idempotency_key_and_replays_same_response` | ✅ |
| §5 Número IRR sequencial/ano sem MAX+1 | `RuntimeTest::test_instance_numbers_are_sequential_per_year`, `SubmissionProcessorTest::test_sequence_is_allocated_once_and_kept_on_revision` | ✅ |
| §13 Concorrência: dois usuários na mesma tarefa → conflito controlado | `RuntimeTest::test_concurrent_claim_and_double_completion_are_rejected_with_conflict` | ✅ |
| §13 Usuário sem grupo não lista/abre/completa instância alheia por troca de ID | `RuntimeTest::test_users_outside_the_group_cannot_view_or_complete_tasks_by_id` | ✅ |
| §8.3 Validação servidor-side; condicionais obrigatórios; campos automáticos readOnly | `SubmissionProcessorTest` (7 testes) | ✅ |
| §7.3 Lookup AX: não encontrado/indisponível sem perder dados; snapshot da origem | `SubmissionProcessorTest::test_lookup_*` | ✅ |
| §8.3/§10 Anexos: extensão/MIME/permissão; URL assinada; anexo de outro usuário recusado | `RuntimeTest::test_attachments_validate_type_and_require_authorization_to_download` | ✅ |
| §6.1 JWT Entra: assinatura, audience, tenant, issuer, scope, expiração, alg confusion; oid+tid | `EntraAuthTest` (3 testes) | ✅ |
| §9 RFC 7807 + correlation ID; 401 sem redirecionamento | `RuntimeTest::test_problem_details_and_correlation_id_on_errors`, `::test_unauthenticated_api_requests_get_401_even_without_json_accept_header` | ✅ |
| §13 PO 159: abertura, 06 Sim→07/08→09→11, 24/26/27/28 com Comitê e revisão do RACF (histórico imutável), 29 paralelismo, 30 com múltiplas ações, validação negativa e reenvio, 30.1 bloqueio até concluir ações + evidência, 30.2 eficaz, 33 devolução, 45/46 sem NF espelho, integração AX (service task), 40, 41 FOB com timer, 44, sincronização e fim | `Po159EndToEndTest` contra Flowable 7.2 real (94 asserções) | ✅ |
| §13 Demais ramos 33–47 (garantia TMSA tipo 01/03/09, garantia fornecedor → 39, NF espelho Sim → 47, CIF) | Roteamento coberto pelo validador e pelo BPMN; cenário E2E dedicado pendente | 🟡 |
| §13 30.3 loop com limite (6 ciclos) e timer P30D | Modelado; teste com duração reduzida pendente | 🟡 |
| §13 Virada de ano, backup/restore, anexo infectado (ClamAV real), logs sem PII | Sequência por ano implementada; demais em homologação | ⏳ |
| §7 Contratos AX reais (AIF/WCF ou façade REST) | Transportes não configurados de propósito | ⏳ |
| §6.2 Autenticação do fornecedor externo | Decisão pendente | ⏳ |

## Customizações fase 2 (`.github/copilot/references/BPMN_TMSA_customs_1.md`)

| Requisito | Evidência | Status |
|---|---|---|
| §2 Menu Configurações só para autorizados; Gates por permissão (configuração, processos, auditoria leitura/exportação, relatórios) | `AuthorizationAndAuditTest::test_settings_require_settings_manage_permission_on_the_server`, `::test_audit_read_and_export_have_separate_permissions_and_no_write_routes` | ✅ |
| §3.1 Login: só parâmetros públicos, URIs seguras, tenant multi-tenant recusado | `EntraLoginSettingsTest::test_only_non_secret_parameters_with_safe_values_are_accepted` | ✅ |
| §3.1 Ativação somente após descoberta OIDC + teste de token (audience/expiração), config efetiva usada pelo resource server; alteração exige nova validação | `EntraLoginSettingsTest::test_activation_requires_real_discovery_and_token_test`, `::test_discovery_reports_unknown_tenant` | ✅ |
| §11 Token Entra: issuer/audience/tenant/expirado/claim inválida | `EntraAuthTest` + `EntraLoginSettingsTest` | ✅ |
| §3.2 Carga inicial paginada (nextLink), deltaLink só ao fim, escopo por grupos, guests/desabilitados excluídos, cursor não serializado | `DirectorySyncTest::test_initial_paginated_load_imports_scope_and_saves_delta_link_only_at_the_end` | ✅ |
| §3.2 Delta: tombstones (@removed) desativam sem apagar, membership removida, sem duplicatas (tenant+oid) | `DirectorySyncTest::test_delta_round_uses_delta_link_disables_removed_users_and_removes_memberships_without_deleting` | ✅ |
| §3.2 429/Retry-After · consentimento ausente · credencial inválida · cursor expirado (410) · falha no meio + retomada idempotente | `DirectorySyncTest::test_throttling_respects_retry_after`, `::test_missing_consent_and_invalid_credential_fail_without_advancing_cursor`, `::test_expired_delta_token_triggers_controlled_full_reload`, `::test_failure_mid_round_keeps_checkpoint_and_resume_is_idempotent` | ✅ |
| §3.2 Falha parcial não desativa em massa (trava); prévia sem persistência; repetição idempotente | `DirectorySyncTest::test_mass_disable_is_blocked_by_safety_threshold`, `::test_preview_computes_plan_without_persisting_anything`, `::test_requests_are_idempotent_while_a_run_is_active` | ✅ |
| §3.2 Grupo sem mapeamento não concede papel; privilegiado exige confirmação | `DirectorySyncTest::test_role_mapping_is_explicit_and_privileged_roles_need_confirmation` | ✅ |
| §3.2 Graph só no backend (links fora do Graph recusados; SPA sem Graph/token endpoint; CSP) | `DirectorySyncTest::test_graph_client_only_follows_links_inside_microsoft_graph`, `apps/web/src/security/boundaries.spec.ts` | ✅ |
| §3.2 Teste de conexão lê permissões reais do token; mock local | `DirectorySyncTest::test_connection_test_checks_real_token_permissions`, `::test_mock_directory_transport_works_for_local_demo` | ✅ |
| §4 Validação por provedor; Mailpit recusado em produção; anti-SSRF (metadata, privada, porta, sem TLS) | `EmailSettingsTest::test_validation_depends_on_provider_type`, `::test_mailpit_is_refused_in_production`, `::test_host_policy_blocks_ssrf_targets_and_plaintext` | ✅ |
| §4 Segredo criptografado, mascarado, ausente de resposta/log/auditoria/revisões | `EmailSettingsTest::test_secret_is_encrypted_masked_and_never_returned_or_logged`, `SupportUnitsTest::test_secret_store_encrypts_rotates_and_reads_mounted_files_only_inside_dir` | ✅ |
| §4 TLS failure com código sanitizado; teste de envio pela fila; retry/backoff; dead-letter; reprocessamento; idempotência; correlation ID | `EmailSettingsTest::test_connection_test_reports_tls_failure_with_sanitized_code`, `::test_test_email_goes_through_outbox_and_confirms_result`, `::test_transient_failures_retry_with_backoff_then_dead_letter_and_manual_retry`, `::test_permanent_failure_goes_straight_to_dead_letter` | ✅ |
| §4/§11 Mailpit em dev sem envio externo | Validado no Compose (Configurações → E-mail → Enviar teste; mensagem no Mailpit com Message-ID determinístico) | 🟡 manual |
| §7 Notifica só a etapa ativada pelo engine (gateway), nunca antes da confirmação, sem duplicar; toggle desligado não envia; falha de e-mail não afeta a tarefa; sem destinatário não vaza | `StageNotificationTest` (6 testes) | ✅ |
| §7 Template fechado (placeholders, sem código), prévia para designer, destinatários/teste só admin | `StageNotificationTest::test_template_rejects_unknown_placeholders_and_code`, `SupportUnitsTest::test_notification_template_is_closed_and_escaped` | ✅ |
| §5 Upload seguro (SVG/poliglota/tamanho/dimensão), recodificação, público só após aplicar; contraste WCAG; reset; diff auditado | `BrandingTest` (3 testes), `apps/web/src/composables/contrast.spec.ts` | ✅ |
| §6 Append-only (model + trigger), redação de segredos/tokens/PEM/deltatoken, catálogo tipado, diff e causalidade, retenção com arquivo | `AuthorizationAndAuditTest` (7 testes) + verificação no PostgreSQL do Compose | ✅ |
| §8 Relatório de uma etapa, várias e completo; snapshot; sensível mascarado; schema histórico | `ReportsTest::test_single_stage_multiple_stages_and_full_report`, `::test_snapshot_of_submission_and_markers_for_empty_hidden_and_sensitive_fields`, `::test_historical_schema_is_used_even_after_form_changes` | ✅ |
| §8 PDF (preview/impressão), CSV/XLSX, fuso de negócio, idempotência, artefato expirado, link não autorizado, download auditado | `ReportsTest::test_pdf_generation_preview_print_and_audited_download`, `::test_operational_reports_in_pdf_csv_xlsx_respect_permission_and_timezone`, `::test_expired_artifact_is_gone`, `::test_idempotent_request_and_validation`, `::test_csv_neutralizes_formula_injection` | ✅ |
| §8.1 Contrato Carbone (payload/headers), template inválido (macro, vínculo externo, hash), timeout; token de serviço por arquivo ou ambiente | `ReportsTest::test_carbone_contract_template_validation_and_timeout` | ✅ |
| §8.1 Carbone selecionável sem trava de licença; estado reflete o serviço da plataforma | `ReportsTest::test_carbone_can_be_selected_without_license_ceremony_and_the_status_reflects_the_platform` | ✅ |
| §8.1 Teste integrado com o container do Carbone | Verificação manual (perfil `carbone`, "Testar serviço Carbone") | 🟡 manual |
| §11 E2E no navegador (configurações, marca, e-mail, diretório mock, notificação no editor, relatório PDF, auditoria) | Executado com Playwright (MCP) contra o Compose; não há suíte Playwright automatizada no repositório | 🟡 manual |

## Tela de login (`.github/copilot/references/login`) — Entra ID, visitante e SuperAdmin (ADR-0015)

| Requisito | Evidência | Status |
|---|---|---|
| Métodos simultâneos refletidos em `/public/auth-config`; `demoUrl` só http(s); visitante 404 quando desabilitado | `LoginMethodsTest::test_public_config_reflects_enabled_methods`, `apps/web/src/auth/auth.spec.ts` | ✅ |
| SuperAdmin: senha só por comando (hash), sessão opaca (só hash), `/me` com método, logout revoga, auditoria sem senha/token | `LoginMethodsTest::test_superadmin_login_session_and_logout_are_audited_without_secrets` | ✅ |
| Resposta genérica, bloqueio por falhas consecutivas, desbloqueio; tentativa atribuída a ator não autenticado | `LoginMethodsTest::test_wrong_password_is_generic_and_locks_the_account` | ✅ |
| Limite de tentativas (429) e usuário digitado não armazenado | `LoginMethodsTest::test_typed_unknown_username_is_never_stored_and_attempts_are_rate_limited` | ✅ |
| Método desabilitado invalida sessões; lista de IPs; expiração por inatividade e absoluta; troca de senha revoga; limite de sessões | `LoginMethodsTest::test_disabled_method_ip_allowlist_and_session_expiry`, `::test_new_password_revokes_sessions_and_session_limit_applies` | ✅ |
| Token de visitante nunca alcança SuperAdmin/Entra; perfis listados só fictícios | `LoginMethodsTest::test_demo_tokens_never_reach_superadmin_or_entra_accounts` | ✅ |
| Política de senha; conta de demonstração não é convertida; desativar | `LoginMethodsTest::test_command_validates_password_policy_and_username_collisions` | ✅ |
| Produção recusa visitante e SuperAdmin sem lista de IPs | `LoginMethodsTest::test_production_refuses_demo_and_requires_ip_allowlist_for_superadmin` | ✅ |
| Links institucionais só https/mailto | `LoginMethodsTest::test_login_page_links_accept_only_https_or_mailto` | ✅ |
| Fontes/imagens locais (sem Google Fonts/CDN), senha nunca em storage | `apps/web/src/security/boundaries.spec.ts` | ✅ |
| Layout do protótipo (desktop 1440×900 e mobile 390×844), visitante → app, SuperAdmin (erro genérico, entrada, faixa de sessão, sair) | Playwright (MCP) contra o Compose | 🟡 manual |
| Botão Microsoft com tenant real | Não exercitado (sem autorização para autenticar no tenant); MSAL inicializa com a configuração ativada | ⏳ |

## Logs das integrações (e-mail e Entra ID)

| Requisito | Evidência | Status |
|---|---|---|
| Histórico de testes (não só o último), etapas com duração, cada tentativa de entrega com código/explicação, filtros, busca, sem senha/corpo | `IntegrationLogTest::test_email_log_keeps_every_test_and_each_delivery_attempt_with_explanations` | ✅ |
| Paginação exata entre fontes (sem perder/repetir) | `IntegrationLogTest::test_email_log_paginates_across_sources_without_losing_or_repeating_entries` | ✅ |
| Importação com log por etapa; falha com código + explicação; token Entra recusado auditado (deduplicado, sem token); filtro login × importação; somente settings.manage; segredos ausentes | `IntegrationLogTest::test_entra_log_shows_sync_steps_rejected_tokens_and_respects_permissions` | ✅ |
| Componente de log (lista, expandir explicação/tentativas, filtros) | `apps/web/src/components/settings/IntegrationLog.spec.ts` | ✅ |
| Telas E-mail, Entra login e Entra importação exibindo o log com dados do ambiente | Playwright (MCP) contra o Compose, somente leitura | 🟡 manual |

## Catálogo de integrações REST / API do AX (ADR-0016)

| Requisito | Evidência | Status |
|---|---|---|
| Importação do OpenAPI: prévia (novo/alterado/inalterado), alertas (resposta não documentada, senha na query), seleção, endpoints desabilitados, corpo e resposta documentada importados | `IntegrationCatalogTest::test_openapi_preview_apply_and_contract_drift` | ✅ |
| Reimportação preserva nome/constantes; `bpm:openapi-check` só marca contrato alterado | idem | ✅ |
| Credencial nunca como constante; parâmetro de caminho obrigatório; saída inferida de exemplo sem guardar valores; sensível exige confirmação para publicar | `IntegrationCatalogTest::test_endpoint_wizard_rules_and_response_inference_never_store_real_values` | ✅ |
| Consulta `api:<id>` e vínculo da consulta fixa (`supplier`) chamando a API com token de login em cache; log mascarado; vínculo com endpoint desligado falha (sem MOCK silencioso) | `IntegrationCatalogTest::test_published_lookup_and_bound_operation_call_the_api_with_cached_login_token` | ✅ |
| Escrita exige aprovação; idempotente (não repete POST); timeout após envio = incerto → reconciliação; teste de escrita só com confirmação e nunca em produção | `IntegrationCatalogTest::test_writes_need_approval_are_idempotent_and_uncertain_results_require_reconciliation` | ✅ |
| Anti-SSRF (IP interno não declarado, metadata, credencial na URL), redirecionamento não seguido, permissões | `IntegrationCatalogTest::test_target_policy_permissions_and_redirects` | ✅ |
| Métodos de autenticação (Bearer com/sem prefixo, chave em cabeçalho com prefixo, Basic, OAuth2 client credentials) e teste de conexão com verificação real (não verificado sem caminho; 401 → credencial recusada); nenhum segredo devolvido | `IntegrationCatalogTest::test_authentication_methods_send_the_right_header_and_the_connection_test_verifies_for_real` | ✅ |
| Excluir fluxo nunca publicado (confirmação, cascata, auditoria) e descartar rascunho de fluxo publicado; publicado nunca é excluído | `FlowAuthoringTest::test_draft_flow_can_be_deleted_and_draft_version_discarded_but_published_versions_never` | ✅ |
| Prévia com o Swagger real da API do AX (v1 68 operações, v2 6) | Execução de leitura no container (sem gravar) | 🟡 manual |
| Chamadas reais a endpoints do AX | Não executadas (sem autorização/credencial de homologação) | ⏳ |

## Contas locais e gestão de usuários (ADR-0017)

| Requisito | Evidência | Status |
|---|---|---|
| Conta local criada pendente, ativada pelo convite de uso único, sessão por cookie HttpOnly | `LocalIdentityTest::test_admin_invitation_activation_login_cookie_and_logout` | ✅ |
| Reenviar o convite não permite ativar conta que nunca o aceitou (`activated_at`); conta que aceitou pode ser desativada e reativada | `LocalIdentityTest::test_resending_the_invitation_does_not_allow_activating_an_account_that_never_accepted_it` · `::test_an_account_that_accepted_the_invitation_can_be_disabled_and_reactivated_by_the_admin` | ✅ |
| Remover membro de grupo/raia vindo do Entra/mapeamento (bloqueio local, auditado, só `settings.manage`, Entra intacto) e restaurar | `LocalIdentityTest::test_admin_can_remove_a_member_whose_access_comes_from_entra_and_the_block_survives_logins_and_can_be_restored` | ✅ |
| Remoção de vínculo manual + diretório, manual-only sem bloqueio residual, adicionar manualmente desfaz o bloqueio | `LocalIdentityTest::test_removing_a_manual_plus_directory_member_removes_both_and_a_manual_only_member_leaves_no_block` | ✅ |
| Membro legado/demonstração removível; seed de desenvolvimento respeita o bloqueio; SuperAdmin recusado; papéis intactos | `LocalIdentityTest::test_a_legacy_demo_member_can_be_removed_and_the_dev_seed_keeps_the_block_but_the_emergency_account_cannot` | ✅ |
| Bloqueio local sobrevive a sincronização completa e delta com recálculo real, sem escrita no Entra | `DirectorySyncTest::test_a_locally_blocked_group_membership_survives_full_and_delta_syncs_and_does_not_touch_the_directory_source` | ✅ |
| Diálogo de membros: origem legível, "Remover do grupo" para qualquer origem, lista de removidos com "Restaurar", sem permissão não há ações | `apps/web/src/views/admin/AdminGroupsView.spec.ts` | ✅ |
| Tipo de usuário (fornecedor/cliente/interno) obrigatório e validado na criação | `LocalIdentityTest::test_user_category_is_required_and_validated_on_creation` | ✅ |
| Filtro por tipo, alteração só em conta local, auditoria com diff e permissão `settings.manage` | `LocalIdentityTest::test_user_category_can_be_filtered_changed_for_local_accounts_only_and_is_audited` | ✅ |
| Facetas e filtros de integração (convite pendente/expirado, bloqueio, convidado, só login); detalhe da conta local sem IP/hash e da atividade paginada; 403 sem permissão | `LocalIdentityTest::test_facets_attention_filters_and_local_integration_details_without_sensitive_data` | ✅ |
| Detalhe Entra (grupos importados, o que cada um concede, estado do sync) lido só do banco, sem chamar o Graph | `DirectorySyncTest::test_user_detail_exposes_entra_integration_groups_grants_and_sync_state_from_the_database` | ✅ |
| Aba Usuários em 3 colunas: busca com debounce no servidor, seleção grava `?user`, painel de integração, tipo de usuário obrigatório, sem permissão não chama a API | `apps/web/src/components/admin/users/UsersWorkspace.spec.ts` | ✅ |
| Layout em 3 colunas, Drawer em telas médias/pequenas e foco/Esc | Verificação manual com Playwright (MCP) contra o Compose | 🟡 manual |

## Foto do Entra ID (ADR-0013)

| Requisito | Evidência | Status |
|---|---|---|
| Miniatura quadrada em WebP, validada pelo conteúdo e gravada em disco privado | `UserPhotoTest::test_photo_is_downloaded_resized_to_a_square_webp_and_stored_privately` | ✅ |
| Etag igual não baixa de novo; etag novo troca o arquivo e apaga o antigo | `UserPhotoTest::test_same_etag_does_not_download_again_and_a_new_etag_replaces_the_file` | ✅ |
| Sem foto (404) → ausente; foto removida apaga o arquivo | `UserPhotoTest::test_user_without_photo_is_marked_absent_and_a_removed_photo_deletes_the_file` | ✅ |
| Conteúdo inválido ou grande demais é recusado sem gravar | `UserPhotoTest::test_invalid_or_oversized_content_is_rejected_without_storing` | ✅ |
| Lote para em throttling e só pega usuários Entra ativos do tenant; prévia do sync não toca em fotos | `UserPhotoTest::test_batch_stops_on_throttling_and_only_picks_active_entra_users_of_the_sync_tenant` · `::test_preview_never_touches_photos_and_users_without_recent_activity_are_skipped` | ✅ |
| Atualização no login só é enfileirada (Entra, recurso ligado, verificação vencida); nunca chama o Graph na requisição | `UserPhotoTest::test_login_refresh_is_only_queued_for_entra_users_when_enabled_and_stale` | ✅ |
| Desligar o recurso para de servir e apaga as fotos; usuário inativo não é consultado | `UserPhotoTest::test_turning_the_feature_off_stops_serving_and_purges_stored_photos` | ✅ |
| `/me/photo` só serve a própria foto, com cabeçalhos seguros e ETag; admin exige `settings.manage` | `UserPhotoTest::test_me_photo_serves_only_the_own_photo_with_safe_headers_and_conditional_requests` | ✅ |
| Atualização pelo administrador autorizada e auditada; detalhe e lista expõem o estado da foto, sem bytes na auditoria | `UserPhotoTest::test_admin_refresh_is_authorized_audited_and_the_detail_exposes_photo_state` | ✅ |
| Diretório fictício serve fotos geradas (nunca de pessoas reais) | `UserPhotoTest::test_mock_transport_serves_generated_photos_and_404_for_the_others` | ✅ |
| Avatar do topo: baixa a foto só quando a versão muda, revoga a URL e volta às iniciais | `apps/web/src/stores/session.spec.ts` | ✅ |

## Armazenamento de anexos em Azure Blob (ADR-0018)

| Requisito | Evidência | Status |
|---|---|---|
| Assinatura Shared Key conforme a especificação (exemplo documentado, `Content-Length` zero, emulador) | `SharedKeySignerTest` | ✅ |
| Validação (conta, contêiner, prefixo, chave base64), anti-SSRF e emulador/produção | `AttachmentStorageSettingsTest::test_validation_ssrf_and_production_guards` | ✅ |
| Chave somente escrita, nunca em resposta, revisão, auditoria ou log; estado por completude | `AttachmentStorageSettingsTest::test_status_follows_completeness_and_secret_is_write_only_and_never_logged` | ✅ |
| Teste real grava, lê e apaga e relata cada etapa; falhas viram códigos estáveis; limpeza após falha | `AttachmentStorageSettingsTest::test_connection_test_really_writes_reads_and_deletes_and_reports_each_step` · `::test_connection_test_failures_become_stable_codes_and_no_secret_leaks` | ✅ |
| Ativação exige teste aprovado na mesma versão; editar não troca o destino em vigor | `AttachmentStorageSettingsTest::test_activation_requires_a_passing_test_on_the_same_version_and_editing_keeps_the_active_target` | ✅ |
| Novos anexos vão ao Azure, antigos seguem legíveis, voltar ao padrão mantém os do Azure | `AttachmentStorageSettingsTest::test_new_uploads_go_to_azure_old_ones_stay_readable_and_deactivating_returns_to_the_platform_disk` | ✅ |
| Rotação mantém o destino; outro contêiner cria destino novo | `AttachmentStorageSettingsTest::test_key_rotation_keeps_the_target_and_a_different_container_creates_a_new_one` | ✅ |
| Falha de upload = 503 sem fallback; limpeza de órfãos sobrevive a destino quebrado | `AttachmentStorageSettingsTest::test_upload_failure_is_a_503_without_falling_back_and_cleanup_survives_a_broken_target` | ✅ |
| Assinatura, adaptador e prefixo contra o Azurite real | `tests/Integration/AzuriteBlobTest.php` (com `AZURITE_IT_ENDPOINT`) | ✅ |
| Tela: chave nunca preenchida, "Ativar" depende do teste da versão; SPA não fala com o Azure | `AttachmentStorageSettings.spec.ts` · `boundaries.spec.ts` | ✅ |
| Teste/ativação com conta real do Azure | Não executado (sem autorização/credencial) | ⏳ |

Comandos: `php artisan test` (API) · `FLOWABLE_IT_URL=... php artisan test --testsuite=Integration` · `npm test` (web).
