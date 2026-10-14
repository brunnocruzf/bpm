<?php

use App\Http\Controllers\Api\V1\Admin\AuditController;
use App\Http\Controllers\Api\V1\Admin\DirectoryController;
use App\Http\Controllers\Api\V1\Admin\GroupMembershipController;
use App\Http\Controllers\Api\V1\Admin\IntegrationCatalogController;
use App\Http\Controllers\Api\V1\Admin\IntegrationLogController;
use App\Http\Controllers\Api\V1\Admin\NotificationController;
use App\Http\Controllers\Api\V1\Admin\RoleMembershipController;
use App\Http\Controllers\Api\V1\Admin\SettingsController;
use App\Http\Controllers\Api\V1\Admin\UserManagementController;
use App\Http\Controllers\Api\V1\AdminController;
use App\Http\Controllers\Api\V1\AttachmentController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\DefinitionController;
use App\Http\Controllers\Api\V1\FlowController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\InstanceController;
use App\Http\Controllers\Api\V1\LookupController;
use App\Http\Controllers\Api\V1\PublicController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\ResultsController;
use App\Http\Controllers\Api\V1\SessionController;
use App\Http\Controllers\Api\V1\StageNotificationController;
use App\Http\Controllers\Api\V1\TaskController;
use Illuminate\Support\Facades\Route;

/*
| API pública do produto (única superfície exposta). Base: /api/v1
| O Flowable REST nunca é exposto: somente Laravel/worker o acessam em rede interna.
*/
Route::prefix('v1')->group(function () {
    Route::get('health', [HealthController::class, 'live']);
    Route::get('ready', [HealthController::class, 'ready']);
    Route::get('dev/users', [SessionController::class, 'devUsers'])->middleware('throttle:30,1');
    Route::get('openapi.yaml', fn () => response()->file(base_path('openapi.yaml'), ['Content-Type' => 'application/yaml; charset=UTF-8']));

    // Público (antes do login): identidade visual aplicada e parâmetros públicos do login. Sem segredos.
    Route::middleware('throttle:120,1')->group(function () {
        Route::get('public/branding', [PublicController::class, 'branding']);
        Route::get('public/branding/assets/{id}', [PublicController::class, 'brandingAsset'])->whereUuid('id');
        Route::get('public/auth-config', [PublicController::class, 'authConfig']);
    });

    // Login SuperAdmin (acesso de emergência): limite por rota + limite/bloqueio por usuário e IP no serviço.
    Route::post('auth/superadmin/login', [AuthController::class, 'superAdminLogin'])->middleware('throttle:20,1');
    Route::post('auth/local/login', [AuthController::class, 'localLogin'])->middleware('throttle:20,1');
    Route::post('auth/local/logout', [AuthController::class, 'localCookieLogout'])->middleware('throttle:20,1');
    Route::post('auth/local/forgot-password', [AuthController::class, 'requestLocalPasswordReset'])->middleware('throttle:10,1');
    Route::post('auth/local/activate', [AuthController::class, 'activateLocalAccount'])->middleware('throttle:10,1');
    Route::post('auth/local/reset-password', [AuthController::class, 'resetLocalPassword'])->middleware('throttle:10,1');

    // Download por URL assinada de curta duração (sem bearer).
    Route::get('files/{attachment}', [AttachmentController::class, 'download'])->middleware('signed')->name('attachments.download');

    Route::middleware(['auth:api', 'throttle:api'])->group(function () {
        Route::get('me', [SessionController::class, 'me']);
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('meta', [SessionController::class, 'meta']);

        // Catálogo e fluxos
        Route::get('catalog/processes', [FlowController::class, 'catalog']);
        Route::post('flows', [FlowController::class, 'store']);
        Route::get('flows/{key}', [FlowController::class, 'show']);
        Route::patch('flows/{key}', [FlowController::class, 'update']);
        Route::delete('flows/{key}', [FlowController::class, 'destroy'])->middleware('throttle:20,1');
        Route::get('flows/{key}/start-form', [InstanceController::class, 'startForm']);
        Route::get('flows/{key}/submissions', [ResultsController::class, 'index']);
        Route::get('form-library', [DefinitionController::class, 'formLibrary']);

        // Definições / versões (designer)
        Route::get('definitions/{key}/versions', [DefinitionController::class, 'versions']);
        Route::post('definitions/{key}/versions', [DefinitionController::class, 'createDraft']);
        Route::prefix('definitions/{key}/versions/{version}')->whereNumber('version')->group(function () {
            Route::get('/', [DefinitionController::class, 'show']);
            Route::delete('/', [DefinitionController::class, 'discardDraft'])->middleware('throttle:20,1');
            Route::post('stages', [DefinitionController::class, 'addStage']);
            Route::put('stages-order', [DefinitionController::class, 'reorderStages']);
            Route::patch('stages/{stageKey}', [DefinitionController::class, 'updateStage']);
            Route::delete('stages/{stageKey}', [DefinitionController::class, 'deleteStage']);
            Route::put('stages/{stageKey}/transitions', [DefinitionController::class, 'setTransitions']);
            Route::get('forms/{formKey}', [DefinitionController::class, 'showForm']);
            Route::put('forms/{formKey}', [DefinitionController::class, 'saveForm']);
            Route::post('forms/{formKey}/copy-from', [DefinitionController::class, 'copyForm']);
            Route::get('bpmn', [DefinitionController::class, 'bpmn']);
            Route::post('bpmn/import', [DefinitionController::class, 'importBpmn']);
            Route::post('validate', [DefinitionController::class, 'validateVersion']);
            Route::post('publish', [DefinitionController::class, 'publish']);
            Route::get('stages/{stageKey}/notification-preview', [StageNotificationController::class, 'preview']);
            Route::post('stages/{stageKey}/notification-test', [StageNotificationController::class, 'test'])->middleware('throttle:10,1');
        });

        // Instâncias
        Route::post('process-instances', [InstanceController::class, 'store'])->middleware('throttle:30,1');
        Route::get('process-instances', [InstanceController::class, 'index']);
        Route::get('process-instances/metrics', [InstanceController::class, 'metrics']);
        Route::get('process-instances/{id}', [InstanceController::class, 'show'])->whereUuid('id');
        Route::get('process-instances/{id}/timeline', [InstanceController::class, 'timeline'])->whereUuid('id');
        Route::get('submissions/{id}', [ResultsController::class, 'show'])->whereUuid('id');
        Route::post('process-instances/{id}/reports', [ReportController::class, 'storeForInstance'])->whereUuid('id')->middleware('throttle:20,1');

        // Relatórios (geração assíncrona; download sempre autorizado pelo Laravel)
        Route::get('reports/catalog', [ReportController::class, 'catalog']);
        Route::get('reports', [ReportController::class, 'index']);
        Route::post('reports', [ReportController::class, 'store'])->middleware('throttle:20,1');
        Route::get('reports/{id}', [ReportController::class, 'show'])->whereUuid('id');
        Route::get('reports/{id}/download', [ReportController::class, 'download'])->whereUuid('id')->middleware('throttle:60,1');

        // Tarefas
        Route::get('tasks', [TaskController::class, 'index']);
        Route::prefix('tasks/{id}')->whereUuid('id')->group(function () {
            Route::get('/', [TaskController::class, 'show']);
            Route::post('claim', [TaskController::class, 'claim']);
            Route::post('release', [TaskController::class, 'release']);
            Route::post('reassign', [TaskController::class, 'reassign']);
            Route::put('draft', [TaskController::class, 'saveDraft']);
            Route::post('complete', [TaskController::class, 'complete']);
            Route::post('actions/{actionId}/complete', [TaskController::class, 'completeAction'])->whereUuid('actionId');
        });

        // Anexos e consultas AX
        Route::post('attachments', [AttachmentController::class, 'store'])->middleware('throttle:60,1');
        Route::get('attachments/{id}/url', [AttachmentController::class, 'url'])->whereUuid('id');
        Route::get('lookups/{source}', [LookupController::class, 'show'])->middleware('throttle:120,1');

        // Administração
        Route::get('admin/integrations', [AdminController::class, 'integrations']);

        // Catálogo de integrações REST (settings.manage): conexões, importação OpenAPI, endpoints, vínculos, execuções
        Route::prefix('admin/integrations')->controller(IntegrationCatalogController::class)->group(function () {
            Route::get('connections', 'connections');
            Route::post('connections', 'storeConnection')->middleware('throttle:20,1');
            Route::put('connections/{id}', 'updateConnection')->whereUuid('id')->middleware('throttle:30,1');
            Route::post('connections/{id}/test', 'testConnection')->whereUuid('id')->middleware('throttle:10,1');
            Route::post('connections/{id}/openapi/preview', 'openApiPreview')->whereUuid('id')->middleware('throttle:10,1');
            Route::post('connections/{id}/openapi/apply', 'openApiApply')->whereUuid('id')->middleware('throttle:10,1');
            Route::get('endpoints', 'endpoints');
            Route::post('endpoints', 'storeEndpoint')->middleware('throttle:30,1');
            Route::post('endpoints/infer', 'inferResponse')->middleware('throttle:30,1');
            Route::get('endpoints/{id}', 'showEndpoint')->whereUuid('id');
            Route::put('endpoints/{id}', 'updateEndpoint')->whereUuid('id')->middleware('throttle:60,1');
            Route::delete('endpoints/{id}', 'deleteEndpoint')->whereUuid('id');
            Route::post('endpoints/{id}/test', 'testEndpoint')->whereUuid('id')->middleware('throttle:20,1');
            Route::get('bindings', 'bindings');
            Route::put('bindings/{operation}', 'saveBinding')->where('operation', '[A-Za-z][A-Za-z0-9_.]{1,59}');
            Route::get('executions', 'executions');
            Route::post('executions/{id}/reconcile', 'reconcileExecution')->whereUuid('id');
        });
        Route::get('admin/engine-commands', [AdminController::class, 'commands']);
        Route::post('admin/engine-commands/{id}/retry', [AdminController::class, 'retryCommand'])->whereUuid('id');
        Route::get('admin/groups', [AdminController::class, 'groups']);
        Route::post('admin/groups', [AdminController::class, 'storeGroup']);
        Route::patch('admin/groups/{key}', [AdminController::class, 'updateGroup']);
        Route::get('admin/users', [AdminController::class, 'users']);
        Route::get('admin/managed-users', [UserManagementController::class, 'index']);
        Route::post('admin/managed-users', [UserManagementController::class, 'store']);
        Route::get('admin/managed-users/{id}', [UserManagementController::class, 'show'])->whereUuid('id');
        Route::patch('admin/managed-users/{id}', [UserManagementController::class, 'update'])->whereUuid('id');
        Route::post('admin/managed-users/{id}/invitation', [UserManagementController::class, 'resendInvitation'])->whereUuid('id');
        Route::get('admin/roles', [UserManagementController::class, 'roles']);
        Route::post('admin/roles/{roleKey}/users', [RoleMembershipController::class, 'store']);
        Route::delete('admin/roles/{roleKey}/users/{userId}', [RoleMembershipController::class, 'destroy'])->whereUuid('userId');
        Route::get('admin/groups/{key}/members', [GroupMembershipController::class, 'index']);
        Route::post('admin/groups/{key}/members', [GroupMembershipController::class, 'store']);
        Route::delete('admin/groups/{key}/members/{userId}', [GroupMembershipController::class, 'destroy'])->whereUuid('userId');

        // Configurações (settings.manage). Segredos são somente escrita.
        Route::prefix('admin/settings')->group(function () {
            $slug = 'branding|entra/auth|entra/directory-sync|email|audit-retention|reports';
            Route::get('/', [SettingsController::class, 'index']);
            Route::post('branding/assets', [SettingsController::class, 'uploadAsset'])->middleware('throttle:20,1');
            Route::get('branding/assets/{id}', [SettingsController::class, 'previewAsset'])->whereUuid('id');
            Route::post('branding/reset', [SettingsController::class, 'resetBranding']);
            Route::post('entra/auth/validate', [SettingsController::class, 'validateEntraAuth'])->middleware('throttle:10,1');
            Route::post('entra/auth/token-test', [SettingsController::class, 'tokenTest'])->middleware('throttle:10,1');
            Route::post('entra/auth/activate', [SettingsController::class, 'activateEntraAuth']);
            Route::post('entra/auth/deactivate', [SettingsController::class, 'deactivateEntraAuth']);
            Route::post('entra/directory-sync/test', [SettingsController::class, 'testDirectory'])->middleware('throttle:10,1');
            Route::post('email/test-connection', [SettingsController::class, 'testEmailConnection'])->middleware('throttle:10,1');
            Route::post('email/preview-template', [SettingsController::class, 'previewEmailTemplate'])->middleware('throttle:30,1');
            Route::post('email/test', [SettingsController::class, 'sendTestEmail'])->middleware('throttle:10,1');
            Route::get('email/test/{id}', [SettingsController::class, 'testEmailStatus'])->whereUuid('id');
            Route::post('reports/carbone-health', [SettingsController::class, 'carboneHealth'])->middleware('throttle:10,1');
            // log das integrações: testes, configuração, entregas de e-mail, importação e acessos Entra
            Route::get('logs/{area}', [IntegrationLogController::class, 'index'])->where('area', 'email|entra')->middleware('throttle:60,1');
            Route::get('{slug}', [SettingsController::class, 'get'])->where('slug', $slug);
            Route::put('{slug}', [SettingsController::class, 'update'])->where('slug', $slug)->middleware('throttle:30,1');
        });

        // Sincronização do diretório Entra (execução no worker, nunca no browser)
        Route::prefix('admin/entra')->group(function () {
            Route::post('sync/preview', [DirectoryController::class, 'preview'])->middleware('throttle:10,1');
            Route::post('sync/run', [DirectoryController::class, 'run'])->middleware('throttle:10,1');
            Route::get('sync/runs', [DirectoryController::class, 'runs']);
            Route::get('sync/runs/{id}', [DirectoryController::class, 'showRun'])->whereUuid('id');
            Route::get('groups', [DirectoryController::class, 'groups']);
            Route::get('mappings', [DirectoryController::class, 'mappings']);
            Route::post('mappings', [DirectoryController::class, 'storeMapping']);
            Route::patch('mappings/{id}', [DirectoryController::class, 'updateMapping'])->whereUuid('id');
            Route::delete('mappings/{id}', [DirectoryController::class, 'deleteMapping'])->whereUuid('id');
        });

        // Auditoria (audit.read / audit.export). Não há rotas de alteração ou exclusão.
        Route::get('admin/audit-events', [AuditController::class, 'index']);
        Route::get('admin/audit-events/catalog', [AuditController::class, 'catalog']);
        Route::get('admin/audit-events/export', [AuditController::class, 'export'])->middleware('throttle:5,1');
        Route::get('admin/audit-events/{id}', [AuditController::class, 'show'])->whereUuid('id');

        // Notificações (processes.manage): entregas, dead-letter e reprocessamento
        Route::get('admin/notifications', [NotificationController::class, 'index']);
        Route::post('admin/notifications/{id}/retry', [NotificationController::class, 'retry'])->whereUuid('id');

        // Modelos de relatório (reports.templates)
        Route::get('admin/report-templates', [ReportController::class, 'templates']);
        Route::post('admin/report-templates', [ReportController::class, 'storeTemplate'])->middleware('throttle:10,1');
        Route::post('admin/report-templates/{id}/activate', [ReportController::class, 'activateTemplate'])->whereUuid('id');
    });
});
