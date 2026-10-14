# ADR-0011 — Relatórios: `ReportRenderer`, renderer nativo e avaliação do Carbone

- **Status:** aceito (Carbone **não habilitado**: aguarda aprovação de licença por jurídico/segurança)

## Decisão

- **Laravel é dono dos dados e da autorização.** `InstanceReportBuilder` e `OperationalReports` montam um documento neutro (`ReportDocument`: blocos para PDF, tabela para CSV/XLSX e DTO JSON para modelos) já autorizado e mascarado. Nenhum renderer acessa banco, AX, Graph, Flowable ou storage.
- **Contrato `ReportRenderer`** desacopla controllers do fornecedor:
  - `NativeReportRenderer` (padrão): PDF por gerador próprio (`App\Reports\Pdf\PdfWriter` — A4, Helvetica WinAnsi, quebra por métrica AFM, tabelas com cabeçalho repetido, cabeçalho/rodapé com logo, selo de confidencialidade e "Página X de Y"); CSV (UTF-8 BOM, `;`, neutraliza fórmulas) e XLSX (SpreadsheetML mínimo via `ZipArchive`, células texto como `inlineStr`). Sem dependência de terceiros.
  - `CarboneReportRenderer`: `POST /render/template?download=true` (API v5, `carbone-version: 5`), modelo enviado em base64 a cada geração (não fica armazenado no Carbone), JWT service-to-service, timeout configurável, hash do modelo conferido antes de usar, erros mapeados (`template_invalid`, `render_timeout`, `too_large`...). Coberto por teste de contrato com HTTP falso.
- **Histórico:** o relatório da solicitação usa os **snapshots** (payload da submissão + schema da versão usada na época, autor e solicitante capturados, trilha de auditoria para atribuições/decisões). Dados atuais do diretório só aparecem para tarefas abertas, rotulados como "atual". Campos: "não preenchido", "não aplicável" (condicional), "•••• restrito" (sensível sem `data.sensitive`, exceto para o autor da submissão). Sensível = `properties.tmsaSensitive` (checkbox no builder) ou PII típica pela chave/tipo (CPF, CNPJ, telefone...).
- **Assíncrono:** `report_job` (`queued → rendering → ready | failed | expired`) na fila `reports`; limites de linhas, bytes e tempo; retry para falhas transitórias (até 3); artefato em storage privado com expiração configurável (`bpm:reports-expire`). Download/pré-visualização/impressão só via `GET /reports/{id}/download` (autorização revalidada, auditada, `Content-Security-Policy: sandbox`); nada de URL pública permanente.
- **Catálogo aprovado:** relatórios operacionais fixos (instâncias, aging por etapa, pendências, SLA, erros de integração, notificações) com filtros validados por formato — sem SQL livre nem script de usuário. Datas em dias de negócio (`America/Sao_Paulo`) convertidas para UTC.
- **Modelos versionados** (`report_template`): nativos (título, cabeçalho, confidencialidade) e Carbone (DOCX/ODT/XLSX/ODS até 5 MB, sem macros, objetos ativos, caminhos inválidos ou vínculos externos). Ativar uma versão substitui a anterior; tudo auditado. Permissão própria `reports.templates`.

## Carbone — avaliação de licença

A documentação oficial informa que a Community Edition roda quando não há licença (recursos Enterprise desabilitados) e o repositório indica o **"CCL Agreement"**, que não é licença permissiva e restringe oferecer o software como serviço de geração de documentos. **Não o tratamos como open source permissivo.** Antes de selecionar recursos ou a imagem é preciso confirmar com jurídico/segurança a compatibilidade com o uso interno e a forma de hospedagem do BPM TMSA. Até lá:

- `BPM_CARBONE_ENABLED=false`; a configuração só aceita `renderer=carbone` com aprovação registrada.
- Serviço `carbone` no Compose apenas no perfil `carbone`, imagem `carbone/carbone-ee:full-5.15.4` fixada por digest (inclui LibreOffice para PDF), rede `backend` interna, porta não publicada, limites de CPU/memória, healthcheck `/status`, `CARBONE_AUTHENTICATION=true` (chave pública montada; token do Laravel em arquivo). Licença Enterprise, se adquirida, entra por Docker secret — nunca na imagem ou no Git.
- Validar na POC: fontes/LibreOffice para os formatos escolhidos, consumo de memória, qualidade dos modelos DOCX.

## Alternativas avaliadas

| Opção | Formatos | Licença | Observações |
|---|---|---|---|
| Renderer nativo (adotado) | PDF, CSV, XLSX | código próprio | sem PDF/UA nem fontes externas; layout simples e controlado |
| Gotenberg (Chromium/LibreOffice em container) | PDF de HTML/Office | MIT | alta fidelidade, imagem grande e uso alto de memória; exige HTML seguro e rede interna |
| dompdf | PDF de HTML | LGPL-2.1 | exige revisão de LGPL; CSS limitado |
| Carbone | DOCX/ODT/XLSX → PDF | CCL Agreement / EE comercial | melhor para modelos de negócio editáveis em Word; pendente de aprovação |

## Consequências

Relatórios funcionam sem serviços externos e continuam disponíveis se o Carbone estiver fora do ar. Limitações do PDF nativo: somente Windows-1252 (caracteres fora são aproximados), sem tags de acessibilidade (PDF/UA) — a pré-visualização em PDF é complementada pelas telas acessíveis do sistema.
