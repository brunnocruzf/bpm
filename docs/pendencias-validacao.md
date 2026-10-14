# Pendências de validação funcional e técnica (antes do aceite)

Transcrição do §2.2 da especificação e decisões que ficaram como premissa nesta entrega.

## Negócio / processo (oficina com donos das raias)

1. BPMN legível/export original do `document.send.php.pdf`: confirmar gateways, subprocessos, retornos e anotações laterais.
2. Lista completa de funções/grupos por raia e responsáveis por cada grupo Entra.
3. Atividade 09: aprovação, parecer ou registro? Atividade 10: ponto exato de entrada e retorno (modelado: retorna à 11).
4. 24 × 29: confirmar se são a mesma decisão do fornecedor (modelado como uma tarefa).
5. 30 × 30.1: posição da pergunta "Necessita ação preventiva?" (modelada na 30.1).
6. 30.3: SLA, critério de encerramento, limite de ciclos (modelado: 6 ciclos, intervalo de 30 dias), encerramento manual/cancelamento.
7. 37/38: SENF finaliza o ramo ou o processo? (modelado: finaliza o ramo; o processo termina após a trilha da causa raiz).
8. FOB: o timer deve bloquear o faturamento até a data ou apenas avisar? Fuso e calendário.
9. Mensagens/notificações exatas, destinatários e canais (hoje: e-mail ao grupo e ao solicitante, conteúdo mínimo sem PII).
10. SLAs, horários, feriados e calendário corporativo (SLAs do seed são provisórios).
11. Regras de duplicidade de CAN/TPR/NF/OC.
12. Máscara final do número (IRR-000001/26) e política de virada de ano.
13. Meta de lead time — não assumida.

## Segurança, identidade e dados

14. Registro das aplicações SPA/API no Entra ID, app roles `Bpm.*`, grupos por raia e claim de área.
15. Fornecedor como usuário externo: Entra External ID/B2B, federação ou canal autenticado com token de uso único (MFA, expiração, segregação por instância).
16. Anexos: tamanho, tipos, retenção, classificação, antivírus obrigatório.
17. Retenção legal, LGPD (bases legais, descarte, acesso do titular) e política de logs/auditoria — DPO/jurídico.
18. Aprovação de publicação por usuário distinto do autor? (`BPM_REQUIRE_DISTINCT_PUBLISHER`).

24. **Login Entra:** registrar SPA (plataforma SPA, Redirect URIs por ambiente) e API (Application ID URI, scope `access_as_user`, App Roles `Bpm.*` incl. `Bpm.ProcessAdmin`, `Bpm.Reporter`, `Bpm.SensitiveDataReader`), conceder consentimento; executar descoberta + teste de login em homologação e ativar.
25. **Sincronização Graph:** app separado com `User.Read.All` + `Group.Read.All` (Application) e admin consent; credencial por certificado (rotação e expiração), grupos a importar, política para guests/desabilitados, trava de remoção (30%), periodicidade e alerta de atraso; avaliar `GroupMember.Read.All` e grupos aninhados na POC.
26. **Mapeamentos de papéis privilegiados** (Admin, Auditor, Publisher, ProcessAdmin, SensitiveDataReader): aprovação formal e revisão periódica.
27. **E-mail corporativo:** SMTP/relay (host, TLS, credencial) ou Exchange Online (Graph `Mail.Send` restrito à caixa ou SMTP OAuth); allowlist de egress; caixa de envio; textos das notificações por etapa; política de expansão de grupo (membros × caixa do grupo).
28. **Retenção:** prazos aprovados (auditoria, outbox de notificações, execuções de sincronização, arquivos de relatório), armazenamento imutável (WORM) do arquivo de auditoria, captura de IP/user agent.
29. **Carbone:** parecer jurídico/segurança sobre o "CCL Agreement"/licença Enterprise e forma de hospedagem; se aprovado, POC de fontes/LibreOffice e modelos DOCX.
30. **Campos sensíveis:** classificação dos campos dos formulários (marcação "Campo sensível") e quem recebe `data.sensitive`.
31. **Identidade visual:** logos oficiais (PNG/WebP), cores aprovadas (contraste AA) e nome exibido.
33. **Integrações REST / API do AX (ADR-0016):** (a) revogar/rotacionar o token Bearer visível no print do SoftExpert (`.claude/rest/Captura de tela 2026-10-14 0033172.png`) e retirar/tarjar os prints (o de saída mostra dados pessoais); (b) usuário de serviço do BPM na API do AX (não reaproveitar o do SoftExpert) e ambiente de homologação da API; (c) pedir ao time da API anotar os tipos de retorno no Swagger (`ProducesResponseType`) e os campos obrigatórios, e retirar senha da query em `/Access`; (d) aprovação formal para liberar escritas (`CriarOrdemTransferencia`, `CriarOrdemDeVenda`, `CriarFornecedor*`) e confirmar qual operação implementa o "diário de transferência + OCDev + bloqueio" do PO 159; (e) idempotência do lado da API (aceitar chave de idempotência ou consulta por referência) para eliminar a reconciliação manual.
32. **Tela de login (ADR-0015):** aprovar o uso do acesso SuperAdmin (break-glass) em produção — quem guarda a senha (cofre), quais IPs/rede, procedimento de habilitação temporária e revisão pós-uso; URL do ambiente de demonstração separado (`BPM_DEMO_URL`); links de suporte/privacidade/ajuda; validação da foto do painel (Unsplash) e do texto do botão Microsoft com marca/jurídico; teste do botão Microsoft no tenant de homologação.

## Integrações AX 2012 R3

19. Build/CU, AIF/WCF ou façade REST disponível, autenticação, certificados, ambientes de teste.
20. Contratos por operação (TPR, item, OC, fornecedor, diário, OCDev, marcação na OC, bloqueio) e regras de idempotência/reconciliação.
21. Views read-only aprovadas (se houver) e owner técnico de cada operação.

## Plataforma

22. Alvo de produção (Kubernetes/OpenShift/plataforma corporativa), banco gerenciado/HA, storage redundante, secrets manager, backup/restore testado, observabilidade (OpenTelemetry/Prometheus/Grafana).
23. Estratégia para alteração de processo com instâncias em andamento (migração entre versões — fora do MVP).
