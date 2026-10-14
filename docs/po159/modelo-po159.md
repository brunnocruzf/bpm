# PO 159 — Item Reprovado no Recebimento: modelo de trabalho

Fonte: especificação funcional de 08/07/2026 (transcrita em §2.1 do plano). Seed versionado em
`apps/api/database/seeders/Blueprints/Po159.php` (chave `item_reprovado_recebimento`, prefixo `IRR`).
Este modelo é a **versão de trabalho para a oficina de validação**; itens marcados **[VALIDAR]** são premissas.

## Raias (grupos)

CQ/Recebimento · PPCPM · Comitê · GEF · Fornecedor · Suprimentos (SUP) · Fiscal · Qualidade · Expedição · Faturamento.
Associação aos grupos do Entra ID é feita por ambiente em *Administração → Usuários e grupos*.

## Mapeamento atividade → etapa

| Atividade(s) | Etapa no sistema | Raia | Formulário / regra |
|---|---|---|---|
| 01 | `a01_abertura` — Item reprovado no recebimento | CQ | Nº CAN, TPR (consulta AX → Cliente), código do item (AX → descrição), NF (número), OC (AX → fornecedor). É o formulário de **abertura** (botão Novo). |
| 05, 06, 07, 08 | `a05_analise_previa` | PPCPM | Tipo 01/03/09 (obrigatório); Urgente? Sim/Não; data prazo e justificativa obrigatórias quando Sim. SENF por tipo **não** é executada automaticamente. |
| 06 (gateway) | `g06_urgente` | PPCPM | Sim → 09; caso contrário → 11. |
| 09 | `a09_comite_urgencia` | Comitê | Avaliação, avaliador (usuário logado), data (automática). Notifica CQ e PPCPM. Segue para 11. **[VALIDAR]** se é aprovação, parecer ou registro. |
| 11 | `a11_gerar_racf` | GEF | Nº RACF sequencial/ano (`RACF-000001/26`, estável entre revisões), emissor/área/data automáticos, dados da 01 copiados (somente leitura), fornecedor e contato via AX, prazo de resposta, decisão imediata (Devolução/Retrabalho/Liberado condicional), responsável obrigatório se liberação condicional, reincidente, data de resposta, observações, descrição do problema. |
| 24 / 29 / 25 | `a24_analise_fornecedor` | Fornecedor | Procede? Sim/Não; justificativa obrigatória se Não. **[VALIDAR]** 24 e 29 modeladas como a mesma tarefa (reanálise após contestação). |
| — | `g24_procede` | Fornecedor | Não procede → 26; Procede → trilhas paralelas. |
| 26, 27, 28 | `a26_gef_np` | GEF | GEF concorda com a NP? Se Não: necessita Comitê? (obrigatório). |
| — | `g26_concorda` | GEF | Concorda → **fim "Encerrado — não procedente"** (notifica CQ e SUP); Não + Comitê → 10; Não sem Comitê → 11 (revisão do RACF, retorna ao fornecedor). |
| 10 | `a10_comite_np` | Comitê | Avaliação da não procedência, avaliador, data. Notifica CQ, PPCPM, GEF. **[VALIDAR]** retorno para 11 (ponto exato de entrada/retorno pendente na especificação). |
| 29 = Sim | `p_fork` (paralelo) | — | Abre trilha A (causa raiz/plano) e trilha B (destino do material), sincronizadas em `p_join` antes do fim. |
| 30 | `a30_analise_racf` | Fornecedor | Causa raiz + plano de ação (repetidor: ação, responsável, data-prazo, local, observações). Cada linha vira uma **ação individual** (`supplier_action`). Notifica GEF. |
| 31, 32 | `a31_valida_racf` + `g31_valida` | GEF | Valida análise? Sim → 30.1; Não → `a32_revisa_racf` (GEF revisa RACF; histórico preservado) → volta a 30 (plano anterior marcado "substituído"). |
| 30.1 | `a301_executa_plano` | Fornecedor | Cada ação concluída individualmente com evidência; **"Concluir etapa" bloqueado até todas concluídas** (validado no servidor). Ação preventiva (O quê, Quem, Quando, Onde, Observação) se Sim; justificativa se Não. **[VALIDAR]** posição da pergunta preventiva. |
| 30.2 | `a302_eficacia` + `g302_eficaz` | GEF | Eficaz → fim da trilha A; não eficaz → 30.3. |
| 30.3 | `a303_monitora` + `g303_monitora` + `t303_aguarda` | GEF | Registro por ciclo (contador automático). Encerra quando conforme **ou** após 6 ciclos; senão aguarda 30 dias (timer) e repete. **[VALIDAR]** SLA, limite, regra de encerramento manual. |
| 33, 34, 35 | `a33_garantia_devolucao` + `g33_tratativa` | Fornecedor | Garantia/Devolução; se Garantia: quem envia primeiro (TMSA/Fornecedor); se Fornecedor: NF + documento obrigatórios. Roteamento: Devolução → 45; Garantia+Fornecedor → 39; Garantia+TMSA+tipo 01 → 38; demais (tipos 03/09) → 37. |
| 37 | `a37_ppcpm_senf` | PPCPM | Nº SENF → conclui a trilha B. **[VALIDAR]** fim do ramo vs. fim do processo. |
| 38 | `a38_sup_senf` | SUP | Nº SENF → conclui a trilha B. |
| 39 | `a39_fiscal_ocdev` | Fiscal | Nº OCDev → separação. |
| 45, 46 | `a45_sup_ocdev` + `g46_nf_espelho` | SUP | Nº OCDev, PDF (obrigatório), NF espelho? Sim → 47; Não → separação. |
| 47 | `a47_nf_espelho` | Faturamento | Nº NF espelho + documento; notifica Fornecedor; conclui trilha B. |
| 40 (automático AX) | `s40_ax` (service task) | Qualidade | Diário de transferência, marcação OCDev na OC de origem e bloqueio do item via adaptador AX (MOCK). Resultado preenche a 40. |
| 40 | `a40_separacao` | Qualidade | Entregue por (usuário logado), nº diário (AX), OCDev marcada e item bloqueado (AX, somente leitura), confirmação da separação física. |
| 41, 42, 43 | `a41_frete` + `g41_frete` | Expedição | CIF: data de coleta, peso, volume; FOB: data de coleta; solicitar NF. FOB aguarda a data (timer) antes do faturamento. **[VALIDAR]** se FOB deve bloquear ou só avisar; fuso. |
| 44 | `a44_faturamento` | Faturamento | Nº NF; notifica PPCPM e Expedição; conclui trilha B. |
| — | `end_ok` | — | Fim "Concluído" após sincronizar as duas trilhas; notifica CQ e solicitante. |

SLAs (`slaDays`) definidos no seed são **provisórios** para demonstrar prazos e indicadores.

## Fornecedor como usuário

O MVP **não** abre login para fornecedores externos (premissa §14). O grupo `FORNECEDOR` deve ser composto por usuários internos que registram a resposta em nome do fornecedor até a decisão de autenticação externa (Entra External ID/B2B ou canal com token de uso único).

## Resultados de encerramento

`concluido`, `nao_procedente`, `cancelado`, `encerrado_sem_acao`, `encerramento_administrativo` (catálogo em `App\Flow\StageCatalog`).
