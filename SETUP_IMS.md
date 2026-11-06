# BPM IMS - Ambiente Configurado na Porta 8089

## Status: ✅ Ambiente rodando com sucesso

### Repositório
- **Branch**: hmg_ims
- **URL**: https://dev.azure.com/TMSADEV/Redmine/_git/bpmn
- **Localização**: `/docker/bpmn_ims`

### Acesso à Aplicação
- **URL Principal**: http://localhost:8089
- **Mailpit UI**: http://127.0.0.1:8027 (captura de e-mails em dev)

### Containers em Execução
✅ postgres - Base de dados (Port: 5432)
✅ flowable-rest - Motor de Workflows (Port: 8080 interno)
✅ storage - S3 Compatível/RustFS (Port: 9000 interno)
✅ api - API Laravel (Port: 9000 interno)
✅ api-web - Nginx para API (Port: 80 interno)
✅ web - Frontend (Port: 80 interno)
✅ reverse-proxy - Nginx Reverse Proxy (Port: 8089)
✅ mailpit - Captura de E-mails
✅ azurite - Emulador Azure Blob Storage
✅ queue-worker - Worker de Filas
✅ scheduler - Agendador de Tarefas

### Configurações de Acesso

#### Banco de Dados
- **Host**: postgres
- **Usuário**: bpm_app
- **Senha**: bpmapp123ims
- **Database**: bpm_app

#### API Flowable
- **URL**: http://flowable-rest:8080/flowable-rest
- **Usuário**: bpm-engine-client
- **Senha**: engine123ims

#### Storage S3
- **Endpoint**: http://storage:9000
- **Access Key**: bpmstorage
- **Secret Key**: storage123ims
- **Bucket**: bpm-attachments

#### SuperAdmin Login
- **Status**: Habilitado
- **Primeiros Passos**: 
  1. Acessar http://localhost:8089
  2. Fazer login via SuperAdmin
  3. Definir senha via: `docker compose -p bpm-ims exec api php artisan bpm:superadmin set-password`
  4. Configurar Entra ID, e-mail, identidade visual

### Variáveis de Ambiente (Produção)
Arquivo: `/docker/bpmn_ims/deploy/docker/.env.ims`

```
COMPOSE_PROJECT_NAME=bpm-ims
BPM_ORGANIZATION_KEY=ims
BPM_ORGANIZATION_NAME=IMS
BPM_HTTP_PORT=8089
APP_KEY=base64:K5Sto5Q8742c9uzhvrco8wmV4onoBRXc6GQN6tMmvfs=
```

### Comandos Úteis

#### Visualizar logs
```bash
cd /docker/bpmn_ims/deploy/docker
docker compose -p bpm-ims --env-file .env.ims logs -f
```

#### Parar o ambiente
```bash
cd /docker/bpmn_ims/deploy/docker
docker compose -p bpm-ims --env-file .env.ims down
```

#### Reiniciar
```bash
cd /docker/bpmn_ims/deploy/docker
docker compose -p bpm-ims --env-file .env.ims restart
```

#### Acessar API container
```bash
docker compose -p bpm-ims exec api bash
```

#### Rodar migrations manualmente
```bash
docker compose -p bpm-ims exec api php artisan migrate
```

### Segredos
- **Chave de segredos**: `/docker/bpmn_ims/deploy/docker/secrets/ims/bpm_secrets_key`
- Gerada automaticamente durante o setup
- Não versionar em git (está no .gitignore)

### Volumes Persistentes
- `bpm-ims_pgdata` - Dados do PostgreSQL
- `bpm-ims_storagedata` - Dados do Storage
- `bpm-ims_azuritedata` - Dados do Emulador Azure

### Próximos Passos
1. Acessar http://localhost:8089
2. Logar com SuperAdmin (quando configurado)
3. Configurar integrações e ambiente na tela administrativa
4. Consultar documentação em `/docker/bpmn_ims/docs`

---
**Nota**: Este é um ambiente de desenvolvimento. Para produção, ajustar senhas, certificados e configurações de segurança.
