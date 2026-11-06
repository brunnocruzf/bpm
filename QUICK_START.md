# 🚀 BPM IMS - QUICK START

## 📋 Resumo Executivo

| Item | Valor |
|------|-------|
| **Status** | ✅ Operacional |
| **URL** | http://bpm.ims.ind.br:8089 |
| **Usuário** | superadmin |
| **Senha** | BPMIms@2024senha123 |
| **Containers** | 11 rodando |
| **Banco de Dados** | PostgreSQL ✅ |
| **Workflow Engine** | Flowable 7.2.0 ✅ |
| **Localização** | /docker/bpmn_ims |

---

## 🔐 Acesso Rápido

### Login SuperAdmin
```
URL: http://bpm.ims.ind.br:8089
Usuário: superadmin
Senha: BPMIms@2024senha123
```

### Acessar Container API
```bash
cd /docker/bpmn_ims/deploy/docker
docker compose -p bpm-ims exec api bash
```

### Ver Logs (Tempo Real)
```bash
cd /docker/bpmn_ims/deploy/docker
docker compose -p bpm-ims logs -f
```

### Resetar Senha SuperAdmin
```bash
cd /docker/bpmn_ims/deploy/docker
echo "NovaSenha@123456" | docker compose -p bpm-ims exec -T api php artisan bpm:superadmin set-password --stdin
```

---

## 📊 Status dos Serviços

| Serviço | Porta | Status |
|---------|-------|--------|
| Reverse Proxy (Nginx) | 8089 | 🟢 UP |
| API (Laravel) | 9000 (interno) | 🟢 UP |
| Banco de Dados (PostgreSQL) | 5432 (interno) | 🟢 UP |
| Workflow Engine (Flowable) | 8080 (interno) | 🟢 UP |
| Storage S3 (RustFS) | 9000 (interno) | 🟢 UP |
| Mailpit (E-mails) | 8027 | 🟢 UP |

---

## 🛠️ Comandos Essenciais

```bash
# Parar o ambiente
docker compose -p bpm-ims --env-file .env.ims down

# Reiniciar o ambiente
docker compose -p bpm-ims --env-file .env.ims restart

# Ver status dos containers
docker compose -p bpm-ims ps

# Executar artisan command
docker compose -p bpm-ims exec api php artisan <comando>

# Backup do banco de dados
docker compose -p bpm-ims exec postgres pg_dump -U postgres bpm_app > /backup/bpmn_ims_$(date +%Y%m%d_%H%M%S).sql

# Restaurar banco de dados
cat backup.sql | docker compose -p bpm-ims exec -T postgres psql -U postgres
```

---

## 📁 Estrutura de Arquivos

```
/docker/bpmn_ims/
├── apps/
│   ├── api/           # Backend Laravel
│   ├── web/           # Frontend Vue.js
│   └── flowable/      # Dockerfile Flowable
├── deploy/
│   └── docker/
│       ├── docker-compose.yml  # Orquestração
│       ├── .env.ims            # Variáveis de ambiente
│       ├── nginx/              # Configurações Nginx
│       └── secrets/
│           └── ims/
│               └── bpm_secrets_key
├── docs/              # Documentação técnica
├── SETUP_IMS.md       # Setup inicial
├── ACESSO_REDE_EXTERNA.md  # Acesso via rede
└── QUICK_START.md     # Este arquivo
```

---

## 🔧 Configurações Importantes

### Variáveis Críticas (.env.ims)
- `PUBLIC_URL=http://bpm.ims.ind.br` — URL pública
- `BPM_HTTP_PORT=8089` — Porta de acesso
- `DB_PASSWORD=bpmapp123ims` — Senha do BD
- `APP_KEY=base64:...` — Chave de criptografia

### Credenciais Internas
- PostgreSQL: bpm_app / bpmapp123ims
- Flowable: bpm-engine-client / engine123ims
- Storage S3: bpmstorage / storage123ims

---

## 📞 Suporte Técnico

### Verificar Saúde
```bash
# Verificar se tudo está ok
curl -s http://localhost:8089/ | head -20

# Verificar container específico
docker inspect bpm-ims-api-1

# Verificar logs de erro
docker compose -p bpm-ims logs api | grep -i error
```

### Troubleshooting

| Problema | Solução |
|----------|---------|
| Porta 8089 em uso | `ss -tuln \| grep 8089` e `kill -9 <PID>` |
| Container não inicia | `docker compose logs <container>` |
| Banco de dados locked | `docker compose restart postgres` |
| Cache problema | `docker compose -p bpm-ims exec api php artisan cache:clear` |

---

## ✅ Próximos Passos

1. **Primeiro Acesso**
   - [ ] Acessar http://bpm.ims.ind.br:8089
   - [ ] Logar com superadmin / BPMIms@2024senha123
   - [ ] Configurar usuários padrão

2. **Configuração de Segurança**
   - [ ] Configurar Entra ID
   - [ ] Configurar SMTP real (fora de mailpit)
   - [ ] Gerar certificado HTTPS

3. **Customização**
   - [ ] Branding corporativo
   - [ ] Integrações REST
   - [ ] Workflows personalizados

---

**Última atualização**: 2026-10-23 20:21 UTC
**Documentação completa**: Ver ACESSO_REDE_EXTERNA.md

