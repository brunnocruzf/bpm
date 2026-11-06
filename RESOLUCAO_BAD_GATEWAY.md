# 🚨 Resolução do Erro "Bad Gateway" ao Acessar bpm.ims.ind.br

## 🔍 Diagnóstico do Problema

### ❌ O que está acontecendo?

Ao acessar `http://bpm.ims.ind.br/` você recebe **502 Bad Gateway**.

### 🎯 Causa Raiz

**Mismatch entre DNS e IP do servidor:**

- **IP Real do Servidor**: `10.50.20.98`
- **DNS Apontando Para**: `10.0.2.20`
- **Resultado**: Conexão recusada → Bad Gateway

---

## ✅ SOLUÇÕES IMEDIATAS

### 🔧 SOLUÇÃO 1: Usar IP Correto (Imediato ✓)

**Acesse via IP direto em vez de DNS:**

```
http://10.50.20.98:8089
```

✅ **Teste agora:**
```bash
curl -I http://10.50.20.98:8089/
# Resposta esperada: HTTP/1.1 200 OK
```

---

### 🔧 SOLUÇÃO 2: Corrigir Entrada DNS (Recomendado)

**Contacte o administrador de rede/DNS e solicite:**

Alterar registro DNS:
```
bpm.ims.ind.br → 10.50.20.98 (em vez de 10.0.2.20)
```

Após a correção, aguarde propagação DNS (até 24h) e teste:
```bash
nslookup bpm.ims.ind.br
# Deve retornar: Address: 10.50.20.98
```

---

### 🔧 SOLUÇÃO 3: Usar /etc/hosts (Local)

Se quiser usar o DNS localmente enquanto a correção é feita:

```bash
sudo nano /etc/hosts
```

Adicione a linha:
```
10.50.20.98 bpm.ims.ind.br
```

Salve e teste:
```bash
curl -I http://bpm.ims.ind.br:8089/
```

---

## 📊 Configuração de Rede Atual

| Parâmetro | Valor |
|-----------|-------|
| **IP do Servidor** | 10.50.20.98 |
| **Porta** | 8089 |
| **URL Correta** | http://10.50.20.98:8089 |
| **DNS (atual)** | bpm.ims.ind.br → 10.0.2.20 ❌ |
| **DNS (desejado)** | bpm.ims.ind.br → 10.50.20.98 ✅ |

---

## 🧪 Verificação de Conectividade

### Teste 1: Conectividade via IP (✅ Funciona)
```bash
curl -v http://10.50.20.98:8089/
```

### Teste 2: Resolução DNS (❌ Incorreta)
```bash
nslookup bpm.ims.ind.br
# Retorna: 10.0.2.20 (ERRADO)
```

### Teste 3: Via localhost (✅ Funciona)
```bash
curl -v http://localhost:8089/
```

---

## 🎯 Acesso Temporário (Enquanto DNS é corrigido)

### Opção A: IP Direto
```
http://10.50.20.98:8089
User: superadmin
Pass: BPMIms@2024senha123
```

### Opção B: Localhost
```
http://localhost:8089
User: superadmin
Pass: BPMIms@2024senha123
```

### Opção C: /etc/hosts (recomendado para rede interna)
Editar `/etc/hosts` em cada máquina que acessa:
```
10.50.20.98 bpm.ims.ind.br
```

---

## 🔐 Configurações do Servidor

### Nginx Reverse Proxy
✅ Rodando em: `0.0.0.0:8089`
✅ Todos os containers saudáveis
✅ Resolvendo DNS interno corretamente

### Docker Compose
```
- Project: bpm-ims
- Reverse Proxy: UP e respondendo
- API: UP e saudável
- PostgreSQL: UP e saudável
```

---

## 📝 Para o Administrador de Rede

**Informações para correção de DNS:**

```
Nome do Host: bpm.ims.ind.br
IP Antigo: 10.0.2.20
IP Novo (correto): 10.50.20.98
Porta: 8089
Protocolo: HTTP (atualmente) / HTTPS (planejado)
```

**Ações necessárias:**
1. Atualizar registro A do DNS
2. Flush cache DNS se necessário
3. Aguardar propagação (TTL)
4. Confirmar resolução com `nslookup bpm.ims.ind.br`

---

## ✨ Resumo das Opções

| Opção | URL | Funciona? | Permanente? |
|-------|-----|-----------|-------------|
| IP Direto | http://10.50.20.98:8089 | ✅ Sim | ✅ Permanente |
| DNS (bpm.ims.ind.br) | http://bpm.ims.ind.br:8089 | ❌ Não | ⏳ Aguardando correção |
| Localhost | http://localhost:8089 | ✅ Sim | ✅ Permanente (local) |
| /etc/hosts | http://bpm.ims.ind.br:8089 | ✅ Sim | ✅ Permanente (essa máquina) |

---

## 🚀 Próximas Ações

1. **IMEDIATO**: Use `http://10.50.20.98:8089` para acessar
2. **CURTO PRAZO**: Contacte admin de rede para corrigir DNS
3. **MÉDIO PRAZO**: Configurar HTTPS com certificado
4. **LONGO PRAZO**: Integrar com Entra ID corporativo

---

**Última atualização**: 2026-10-23 20:23 UTC
**Status**: 🔴 DNS incorreto | 🟢 Servidor OK | 🟢 Aplicação OK

