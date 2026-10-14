<?php

/*
|--------------------------------------------------------------------------
| BPM TMSA — configuração do produto
|--------------------------------------------------------------------------
| Nenhum segredo real deve ser versionado aqui. Valores sensíveis vêm do
| ambiente (secret manager / Docker secrets em homologação e produção).
*/

return [

    /*
    | Autenticação da API (métodos simultâneos; ver ADR-0015 e App\Auth\AuthMethods).
    | - Entra ID: access token JWT validado por JWKS (sempre que houver configuração Entra).
    | - Visitante (demonstração): "Bearer dev:<username>" só para usuários fictícios. PROIBIDO em produção.
    |   BPM_AUTH_DRIVER=dev (legado) ou BPM_DEMO_LOGIN_ENABLED=true habilitam.
    | - SuperAdmin: usuário e senha locais (acesso de emergência), desabilitado por padrão.
    */
    'auth' => [
        'driver' => env('BPM_AUTH_DRIVER', 'entra'),

        'demo' => [
            'enabled' => (bool) env('BPM_DEMO_LOGIN_ENABLED', false),
            // Link "visitante" para um ambiente de demonstração SEPARADO (ex.: produção aponta para a demo).
            'url' => env('BPM_DEMO_URL'),
        ],

        // Acesso de emergência (break-glass). Senha definida apenas por `php artisan bpm:superadmin`.
        'superadmin' => [
            'enabled' => (bool) env('BPM_SUPERADMIN_LOGIN_ENABLED', false),
            // IPs/CIDRs permitidos (IP visto pelo Laravel via proxies confiáveis). Obrigatório em produção.
            'allowed_ips' => array_values(array_filter(array_map('trim', explode(',', (string) env('BPM_SUPERADMIN_ALLOWED_IPS', ''))))),
            'session_minutes' => (int) env('BPM_SUPERADMIN_SESSION_MINUTES', 240),
            'idle_minutes' => (int) env('BPM_SUPERADMIN_IDLE_MINUTES', 30),
            'max_sessions' => 3,
            // limite de tentativas por usuário+IP (janela em minutos) e bloqueio da conta por falhas consecutivas
            'max_attempts' => (int) env('BPM_SUPERADMIN_MAX_ATTEMPTS', 5),
            'attempt_window_minutes' => 15,
            'lockout_threshold' => (int) env('BPM_SUPERADMIN_LOCKOUT_THRESHOLD', 10),
            'lockout_minutes' => (int) env('BPM_SUPERADMIN_LOCKOUT_MINUTES', 30),
            'password_min_length' => 14,
            // papéis do SuperAdmin (dados sensíveis exigem concessão explícita: não incluído)
            'roles' => ['Bpm.Admin', 'Bpm.Designer', 'Bpm.Publisher', 'Bpm.ProcessAdmin', 'Bpm.Reporter', 'Bpm.Auditor'],
        ],

        'entra' => [
            'tenant_id' => env('BPM_ENTRA_TENANT_ID'),
            // Lista separada por vírgula de tenants aceitos (default: tenant_id).
            'allowed_tenants' => array_filter(explode(',', (string) env('BPM_ENTRA_ALLOWED_TENANTS', (string) env('BPM_ENTRA_TENANT_ID')))),
            // Audience esperada: client id da API ou "api://<client-id>".
            'audiences' => array_filter(explode(',', (string) env('BPM_ENTRA_AUDIENCE', ''))),
            'jwks_url' => env('BPM_ENTRA_JWKS_URL'), // default derivado do tenant
            'jwks_cache_seconds' => (int) env('BPM_ENTRA_JWKS_CACHE_SECONDS', 3600),
            'leeway_seconds' => 60,
            'area_claim' => env('BPM_ENTRA_AREA_CLAIM', 'department'),
            'required_scope' => env('BPM_ENTRA_REQUIRED_SCOPE', 'access_as_user'),
        ],
    ],

    'roles' => [
        'admin' => 'Bpm.Admin',
        'designer' => 'Bpm.Designer',
        'publisher' => 'Bpm.Publisher',
        'initiator' => 'Bpm.Initiator',
        'task_worker' => 'Bpm.TaskWorker',
        'auditor' => 'Bpm.Auditor',
        'process_admin' => 'Bpm.ProcessAdmin',
        'reporter' => 'Bpm.Reporter',
        'sensitive_reader' => 'Bpm.SensitiveDataReader',
    ],

    // Papéis considerados privilegiados: mapeamento a partir de grupo Entra exige confirmação explícita.
    'privileged_roles' => ['Bpm.Admin', 'Bpm.Auditor', 'Bpm.Publisher', 'Bpm.ProcessAdmin', 'Bpm.SensitiveDataReader'],

    /*
    | Permissões (Gates) → papéis. Autorização é sempre no servidor (App\Authorization\Permissions).
    */
    'permissions' => [
        'settings.manage' => ['admin'],            // configuração global (marca, Entra, e-mail, retenção)
        'processes.manage' => ['admin', 'process_admin'], // operação de processos: reatribuir, reprocessar, notificações
        'audit.read' => ['auditor', 'admin'],
        'audit.export' => ['auditor'],             // exportação separada da leitura (segregação de funções)
        'reports.instance' => ['initiator', 'task_worker', 'auditor', 'admin', 'reporter', 'process_admin'],
        'reports.operational' => ['reporter', 'auditor', 'admin', 'process_admin'],
        'reports.templates' => ['admin'],
        'data.sensitive' => ['sensitive_reader'],  // campos marcados como sensíveis em relatórios/respostas
    ],

    'organization' => [
        'key' => env('BPM_ORGANIZATION_KEY', 'tmsa'),
        'name' => env('BPM_ORGANIZATION_NAME', 'TMSA'),
    ],

    /*
    | Cofre de segredos da aplicação. Chave dedicada (32 bytes base64), separada da APP_KEY.
    | Em Compose/produção use BPM_SECRETS_KEY_FILE (Docker secret / secret manager montado).
    | Referências aceitas no domínio: db:<uuid> (criptografado) | file:<nome> (em secrets_dir).
    */
    'secrets' => [
        // A chave em arquivo é lida em tempo de execução (não entra no cache de configuração).
        'key' => env('BPM_SECRETS_KEY'),
        'key_file' => env('BPM_SECRETS_KEY_FILE'),
        'previous_keys' => array_filter(explode(',', (string) env('BPM_SECRETS_PREVIOUS_KEYS', ''))),
        'files_dir' => env('BPM_SECRETS_DIR', '/run/secrets'),
    ],

    'audit' => [
        'capture_ip' => (bool) env('BPM_AUDIT_CAPTURE_IP', true),
        'capture_user_agent' => (bool) env('BPM_AUDIT_CAPTURE_USER_AGENT', false),
        'export_max_rows' => (int) env('BPM_AUDIT_EXPORT_MAX_ROWS', 50000),
        'archive_disk' => env('BPM_AUDIT_ARCHIVE_DISK', env('BPM_ATTACHMENTS_DISK', 'attachments_local')),
    ],

    /*
    | E-mail: provedor configurado em Configurações → E-mail (tabela app_setting). Mailpit é aceito
    | apenas fora de produção. Destinos SMTP passam pela política de host (anti-SSRF).
    */
    'mail' => [
        'mailpit_host' => env('BPM_MAILPIT_HOST', env('MAIL_HOST', 'mailpit')),
        'mailpit_port' => (int) env('BPM_MAILPIT_PORT', 1025),
        'allowed_smtp_ports' => [25, 465, 587, 2525],
        // Relay privado interno: exceção deliberada da plataforma (não editável pela UI).
        'private_relay_hosts' => array_filter(explode(',', (string) env('BPM_MAIL_PRIVATE_RELAY_HOSTS', ''))),
        'max_attempts' => (int) env('BPM_MAIL_MAX_ATTEMPTS', 6),
        'timeout' => (int) env('BPM_MAIL_TIMEOUT', 15),
    ],

    'directory' => [
        'graph_base_url' => 'https://graph.microsoft.com/v1.0',
        'authority_hosts' => ['login.microsoftonline.com', 'login.microsoftonline.us', 'login.partner.microsoftonline.cn'],
        'max_retry_wait_seconds' => (int) env('BPM_GRAPH_MAX_RETRY_WAIT', 120),
        'max_attempts' => (int) env('BPM_GRAPH_MAX_ATTEMPTS', 5),
        'timeout' => (int) env('BPM_GRAPH_TIMEOUT', 30),
        // Trava de segurança: sincronização completa que desativaria/removeria acima deste percentual é bloqueada.
        'max_removal_ratio' => (float) env('BPM_DIRECTORY_MAX_REMOVAL_RATIO', 0.3),
    ],

    'reports' => [
        'disk' => env('BPM_REPORTS_DISK', env('BPM_ATTACHMENTS_DISK', 'attachments_local')),
        'max_rows' => (int) env('BPM_REPORTS_MAX_ROWS', 20000),
        'max_bytes' => (int) env('BPM_REPORTS_MAX_BYTES', 25 * 1024 * 1024),
        'timeout' => (int) env('BPM_REPORTS_TIMEOUT', 120),
        'carbone' => [
            // Desabilitado até aprovação jurídica/segurança da licença (ADR-0011).
            'enabled' => (bool) env('BPM_CARBONE_ENABLED', false),
            'base_url' => rtrim((string) env('BPM_CARBONE_URL', 'http://carbone:4000'), '/'),
            'token_file' => env('BPM_CARBONE_TOKEN_FILE'),
            'timeout' => (int) env('BPM_CARBONE_TIMEOUT', 60),
        ],
    ],

    /*
    | Engine BPMN — Flowable REST em rede interna. Somente Laravel/worker acessam.
    */
    'engine' => [
        'base_url' => rtrim((string) env('FLOWABLE_REST_URL', 'http://flowable-rest:8080/flowable-rest'), '/'),
        'username' => env('FLOWABLE_REST_USER'),
        'password' => env('FLOWABLE_REST_PASSWORD'),
        'connect_timeout' => (float) env('FLOWABLE_CONNECT_TIMEOUT', 3),
        'timeout' => (float) env('FLOWABLE_TIMEOUT', 15),
        'external_worker_topic' => 'tmsa-integration',
        'external_worker_lock' => 'PT5M',
        'outbox_max_attempts' => (int) env('BPM_OUTBOX_MAX_ATTEMPTS', 10),
    ],

    'publish' => [
        // Política corporativa opcional: publicador deve ser diferente do autor do rascunho.
        'require_distinct_publisher' => (bool) env('BPM_REQUIRE_DISTINCT_PUBLISHER', false),
    ],

    'sequences' => [
        'digits' => 6,
        // Apresentação do ano: 'yy' (ex.: /26) ou 'yyyy'. Máscara final pendente de aprovação.
        'year_format' => env('BPM_SEQUENCE_YEAR_FORMAT', 'yy'),
        'timezone' => env('BPM_BUSINESS_TIMEZONE', 'America/Sao_Paulo'),
    ],

    'attachments' => [
        'disk' => env('BPM_ATTACHMENTS_DISK', 'attachments_local'),
        'max_kb' => (int) env('BPM_ATTACHMENTS_MAX_KB', 20480),
        'allowed_extensions' => ['pdf', 'png', 'jpg', 'jpeg', 'xlsx', 'xls', 'docx', 'doc', 'csv', 'txt', 'xml', 'zip'],
        'allowed_mimes' => [
            'application/pdf', 'image/png', 'image/jpeg', 'text/plain', 'text/csv', 'application/xml', 'text/xml',
            'application/zip', 'application/x-zip-compressed',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/msword',
            'application/octet-stream',
        ],
        'signed_url_minutes' => 5,
        // Arquivos enviados antes de existir instância (formulário de abertura) expiram se não forem vinculados.
        'orphan_ttl_hours' => 24,
        'clamav' => [
            'host' => env('BPM_CLAMAV_HOST'),
            'port' => (int) env('BPM_CLAMAV_PORT', 3310),
        ],
    ],

    /*
    | Catálogo de integrações REST (Administração → Integrações e entregas; ADR-0016): conexões,
    | endpoints importados do OpenAPI/Swagger ou cadastrados à mão, vínculos com as operações do BPM.
    | Chamadas SOMENTE pelo servidor. Hosts internos (IP privado) e, em produção, todos os hosts
    | precisam estar declarados pela plataforma (não editável pela tela).
    */
    'integrations' => [
        'allowed_hosts' => array_values(array_filter(array_map(fn ($h) => strtolower(trim($h)), explode(',', (string) env('BPM_INTEGRATION_ALLOWED_HOSTS', ''))))),
        'max_response_bytes' => (int) env('BPM_INTEGRATION_MAX_RESPONSE_BYTES', 2 * 1024 * 1024),
        'max_openapi_bytes' => (int) env('BPM_INTEGRATION_MAX_OPENAPI_BYTES', 5 * 1024 * 1024),
        'default_timeout' => (int) env('BPM_INTEGRATION_TIMEOUT', 15),
        'token_ttl_minutes' => 50,
    ],

    /*
    | Integrações AX 2012 R3. Transporte por operação: MOCK | REST_FACADE | AIF_WCF | SQL_READONLY_VIEW.
    | Escritas (diário, OCDev, bloqueio) nunca usam SQL. Começar por MOCK até receber contratos.
    */
    'ax' => [
        'transports' => [
            'tpr.lookup' => env('BPM_AX_TPR_TRANSPORT', 'MOCK'),
            'item.lookup' => env('BPM_AX_ITEM_TRANSPORT', 'MOCK'),
            'purchaseOrder.lookup' => env('BPM_AX_PO_TRANSPORT', 'MOCK'),
            'supplier.lookup' => env('BPM_AX_SUPPLIER_TRANSPORT', 'MOCK'),
            'transferAndBlock' => env('BPM_AX_TRANSFER_TRANSPORT', 'MOCK'),
        ],
        'rest_facade' => [
            'base_url' => env('BPM_AX_REST_URL'),
            'timeout' => (float) env('BPM_AX_REST_TIMEOUT', 10),
        ],
    ],

    'designer' => [
        'max_components' => 300,
        'max_depth' => 6,
        'max_schema_bytes' => 512 * 1024,
        'max_condition_rules' => 10,
    ],

    'notifications' => [
        'from' => env('MAIL_FROM_ADDRESS', 'bpm@tmsa.local'),
    ],

    'branding' => [
        'defaults' => [
            'appName' => 'BPM TMSA',
            'orgName' => 'TMSA',
            'tagline' => 'Gestão de processos',
            'colors' => ['primary' => '#086b77', 'secondary' => '#075762', 'accent' => '#2b6cb0'],
        ],
        'limits' => [
            'logo_main' => ['max_kb' => 512, 'min_w' => 64, 'max_w' => 2048, 'min_h' => 16, 'max_h' => 1024],
            'logo_compact' => ['max_kb' => 256, 'min_w' => 32, 'max_w' => 1024, 'min_h' => 32, 'max_h' => 1024, 'square' => true],
            // .ico com vários tamanhos em BMP pode passar de 256 KB; o arquivo servido é regravado (PNG) e fica pequeno
            'favicon' => ['max_kb' => 512, 'min_w' => 16, 'max_w' => 512, 'min_h' => 16, 'max_h' => 512, 'square' => true],
        ],
    ],
];
