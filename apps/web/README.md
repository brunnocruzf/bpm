# BPM TMSA — SPA (Vue 3)

Vue 3 + TypeScript + Vite + PrimeVue 4 (MIT) + Pinia + Vue Router + Form.io (builder/renderer) + bpmn-js + MSAL Browser.

| Tela | Rota |
|---|---|
| Catálogo de fluxos (Novo, Acompanhar, Respostas, Editar) | `/` |
| Editor do fluxo (Etapas · Formulários · Diagrama BPMN · Configurações) | `/flows/:flowKey/edit` |
| Nova solicitação (formulário de abertura) | `/flows/:flowKey/start` |
| Minhas tarefas / do grupo / abertas por mim / concluídas | `/tasks` |
| Execução de tarefa | `/tasks/:id` |
| Solicitação (status, diagrama, linha do tempo, respostas, anexos, plano de ação) | `/instances/:id` |
| Acompanhar (grid, indicadores) | `/monitor` |
| Resultados/Respostas por fluxo | `/flows/:flowKey/results` |
| Administração (grupos/Entra, integrações/outbox) | `/admin/*` |

Variáveis (`.env.*`, nenhuma é segredo): `VITE_API_BASE_URL`, `VITE_AUTH_MODE` (`dev`|`entra`), `VITE_ENTRA_CLIENT_ID`, `VITE_ENTRA_TENANT_ID`, `VITE_ENTRA_API_SCOPE`, `VITE_ENV_LABEL`.
O build de produção recusa `VITE_AUTH_MODE=dev`.

```bash
npm ci
npm run dev        # proxy /api → http://127.0.0.1:8000 (ou VITE_DEV_API_TARGET)
npm test           # Vitest
npm run build      # vue-tsc + vite build (mode production)
```
