# 🔐 BPM IMS - Acesso via Rede Externa

## Status: ✅ Configurado para Acesso via DNS

### Acesso Principal
- **URL**: http://bpm.ims.ind.br:8089
- **DNS**: bpm.ims.ind.br
- **Porta**: 8089
- **Protocolo**: HTTP (será HTTPS com certificado/proxy reverso corporativo)

### Credenciais SuperAdmin (Login de Emergência)
- **Usuário**: superadmin
- **Senha**: BPMIms@2024senha123
- **Status**: ✅ Ativo e configurado

> **⚠️ IMPORTANTE**: Esta é apenas a credencial de acesso de emergência. Após o primeiro login:
> 1. Configure Entra ID ou autenticação corporativa
> 2. Crie usuários padrão na tela administrativa
> 3. Desative o login SuperAdmin em produção

### Como Acessar

#### 1. Via Navegador (Acesso Web)
```
http://bpm.ims.ind.br:8089
```
- Login: superadmin
- Senha: BPMIms@2024senha123

#### 2. Via API (Localhost)
```bash
curl -I http://localhost:8089/
```

#### 3. Verificar Status dos Containers
```bash
cd /docker/bpmn_ims/deploy/docker
docker compose -p bpm-ims ps
```

### Acessar o Container da API (Conforme Solicitado)
```bash
cd /docker/bpmn_ims/deploy/docker
docker compose -p bpm-ims exec api bash
```

Comandos úteis dentro do container:
```bash
# Ver versão do Laravel
php artisan --version

# Rodar migrations manualmente
php artisan migrate

# Listar tabelas do banco
php artisan tinker
>>> \DB::table('information_schema.tables')->where('table_schema', '=', 'public')->get();

# Resetar SuperAdmin
php artisan bpm:superadmin status
php artisan bpm:superadmin set-password --generate

# Limpar cache
php artisan cache:clear
php artisan config:clear
```

### Ver Logs em Tempo Real
```bash
cd /docker/bpmn_ims/deploy/docker

# Todos os containers
docker compose -p bpm-ims logs -f

# Apenas API
docker compose -p bpm-ims logs -f api

# Apenas Nginx
docker compose -p bpm-ims logs -f reverse-proxy

# Apenas Flowable
docker compose -p bpm-ims logs -f flowable-rest
```

### Configurações de Rede Atualizadas

#### Arquivo `.env.ims`
```ini
PUBLIC_URL=http://bpm.ims.ind.br
BPM_HTTP_BIND=0.0.0.0          # Aceita conexões de qualquer interface
BPM_HTTP_PORT=8089
COMPOSE_PROJECT_NAME=bpm-ims
```

#### Nginx Reverse Proxy
- **Escuta em**: 0.0.0.0:8089
- **Roteia para**:
  - `/api/*` → API Laravel
  - `/*` → Frontend Vue.js
  - `/flowable-rest/*` → Bloqueado (sem acesso público)

### Endereços dos Serviços Internos

| Serviço | URL Interna | Uso |
|---------|------------|-----|
| PostgreSQL | postgres:5432 | Banco de dados |
| Flowable REST | http://flowable-rest:8080/flowable-rest | Motor de Workflows |
| Storage S3 | http://storage:9000 | S3 compatível |
| Mailpit SMTP | mailpit:1025 | Captura de e-mails (dev) |
| Azurite Blob | http://azurite:10000 | Emulador Azure (dev) |

### Mailpit - Captura de E-mails (Dev)
- **URL**: http://127.0.0.1:8027
- **Porta SMTP**: 1026
- Todos os e-mails enviados pela aplicação são capturados aqui (sem entrega real)

### Volumes Persistentes
```bash
# Ver volumes
docker volume ls | grep bpm-ims

# Inspecionar volume específico
docker volume inspect bpm-ims_pgdata

# Backup do banco de dados
docker compose -p bpm-ims exec postgres pg_dump -U postgres bpm_app > backup.sql

# Restaurar banco de dados
cat backup.sql | docker compose -p bpm-ims exec -T postgres psql -U postgres
```

### Troubleshooting

#### Porta já está em uso?
```bash
# Encontrar qual processo está usando a porta 8089
lsof -i :8089
ss -tuln | grep 8089

# Liberar a porta
kill -9 <PID>
```

#### Container não responde?
```bash
# Verificar logs
docker compose -p bpm-ims logs api

# Reiniciar container específico
docker compose -p bpm-ims restart api

# Rebuild de uma imagem
docker compose -p bpm-ims build --no-cache api
```

#### DNS não resolve?
```bash
# Adicionar entrada local ao /etc/hosts
echo "127.0.0.1 bpm.ims.ind.br" >> /etc/hosts

# Ou no Docker (adicionar ao .env.ims se necessário)
# Usar --add-host no docker-compose
```

### Próximos Passos Recomendados

1. **Configurar HTTPS**
   - Gerar certificado SSL
   - Configurar proxy reverso corporativo
   - Atualizar PUBLIC_URL para https://bpm.ims.ind.br

2. **Integração com Entra ID**
   - Configurar BPM_ENTRA_TENANT_ID
   - Configurar BPM_ENTRA_AUDIENCE
   - Desabilitar SuperAdmin em produção

3. **Backup e Recuperação**
   - Configurar snapshots de volumes
   - Testar restauração regular
   - Implementar logs centralizados

4. **Monitoramento**
   - Integrar com Prometheus
   - Configurar alertas
   - Rastrear performance

### Comandos de Gerenciamento Rápido

```bash
# Parar ambiente
docker compose -p bpm-ims --env-file .env.ims down

# Reiniciar ambiente
docker compose -p bpm-ims --env-file .env.ims restart

# Reconstruir imagens
docker compose -p bpm-ims --env-file .env.ims up -d --build

# Limpar volumes (⚠️ PERDA DE DADOS)
docker compose -p bpm-ims down -v

# Executar comando na API
docker compose -p bpm-ims exec api <comando>
```

---

**Configurado em**: 2026-10-23 20:21 UTC
**Status**: ✅ Pronto para acesso via rede externa
**Ambiente**: Desenvolvimento/Homologação

