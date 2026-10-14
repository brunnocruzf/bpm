<?php

namespace App\Settings;

use App\Audit\AuditDiff;
use App\Auth\EntraAuthConfig;
use App\Models\AppSetting;
use App\Models\AppSettingRevision;
use App\Models\SettingValidation;
use App\Models\User;
use App\Settings\Types\EntraAuthSettings;
use App\Support\AuditLogger;
use App\Support\Correlation;
use App\Support\Masker;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Persistência versionada das configurações: cada gravação incrementa a versão, guarda uma
 * revisão redigida e registra auditoria com diff seguro, na mesma transação.
 */
class SettingsRepository
{
    public function get(string $key): AppSetting
    {
        return AppSetting::firstOrNew(['key' => $key]);
    }

    /** @return array<string,mixed> valor efetivo (padrões + gravado) */
    public function value(SettingType $type): array
    {
        return Cache::remember('bpm:setting:'.$type->key(), 30, fn () => array_replace_recursive($type->defaults(), $this->get($type->key())->value ?? []));
    }

    /** @param array<string,mixed> $input */
    public function update(SettingType $type, array $input, User $by): AppSetting
    {
        return DB::transaction(function () use ($type, $input, $by) {
            $setting = AppSetting::where('key', $type->key())->lockForUpdate()->first() ?? new AppSetting(['key' => $type->key()]);
            $before = array_replace_recursive($type->defaults(), $setting->value ?? []);
            $after = $type->normalize($input, $before, $by);
            [$status, $detail] = $type->statusAfterSave($after);

            $setting->fill([
                'value' => $after,
                'version' => $setting->version + 1,
                'status' => $status,
                'status_detail' => $detail,
                'updated_by' => $by->id,
                'updated_by_name' => $by->name,
            ]);
            if ($type->requiresActivation() && $before !== $after) {
                $setting->activated_at = null; // mudança exige nova validação/ativação
            }
            $setting->save();

            AppSettingRevision::create([
                'setting_key' => $type->key(),
                'version' => $setting->version,
                'value' => Masker::mask($type->redactedForHistory($after)),
                'changed_by' => $by->id,
                'changed_by_name' => $by->name,
                'created_at' => now('UTC'),
            ]);
            AuditLogger::record('settings.updated', 'app_setting', $setting->id, ['setting' => $type->key(), 'version' => $setting->version, 'status' => $status], null, $by,
                diff: AuditDiff::between($type->redactedForHistory($before), $type->redactedForHistory($after)),
                context: ['object_name' => $type->label()]);
            $this->forget($type);

            return $setting;
        });
    }

    /**
     * Registra o resultado de uma validação/teste real (nunca um sucesso fixo).
     *
     * @param  array<string,mixed>  $result
     */
    public function recordValidation(SettingType $type, bool $ok, array $result, User $by, ?int $expectedVersion = null): AppSetting
    {
        return DB::transaction(function () use ($type, $ok, $result, $by, $expectedVersion) {
            $setting = AppSetting::where('key', $type->key())->lockForUpdate()->first();
            if (! $setting) {
                // validação de uma configuração nunca gravada: parte do estado calculado pelos padrões
                [$status, $detail] = $type->statusAfterSave($type->defaults());
                $setting = new AppSetting(['key' => $type->key(), 'value' => $type->defaults(), 'status' => $status, 'status_detail' => $detail]);
            }
            $safe = Masker::mask([...$result, 'ok' => $ok, 'by' => $by->name, 'version' => $setting->version]);
            $setting->last_validation = $safe;
            $setting->last_validated_at = now();
            if ($expectedVersion === null || $expectedVersion === $setting->version) {
                if (! $ok) {
                    $setting->status = AppSetting::ERROR;
                    $setting->status_detail = mb_substr((string) ($result['message'] ?? 'Validação falhou.'), 0, 500);
                } elseif ($setting->status !== AppSetting::DISABLED && $setting->status !== AppSetting::INCOMPLETE) {
                    $setting->status = ($result['complete'] ?? true) ? AppSetting::CONFIGURED : AppSetting::TEST_PENDING;
                    $setting->status_detail = $result['detail'] ?? null;
                }
            }
            $setting->save();
            // histórico (a última validação continua em app_setting; aqui fica cada execução)
            SettingValidation::create([
                'setting_key' => $type->key(), 'step' => isset($safe['step']) ? mb_substr((string) $safe['step'], 0, 30) : null, 'ok' => $ok,
                'code' => isset($safe['code']) ? mb_substr((string) $safe['code'], 0, 80) : null,
                'message' => isset($safe['message']) ? mb_substr((string) $safe['message'], 0, 500) : null,
                'checks' => array_slice(array_values((array) ($safe['checks'] ?? [])), 0, 50),
                'details' => array_diff_key($safe, array_flip(['ok', 'step', 'code', 'message', 'checks', 'by', 'version', 'durationMs', 'provider'])) ?: null,
                'provider' => isset($safe['provider']) ? mb_substr((string) $safe['provider'], 0, 40) : (isset($safe['transport']) ? mb_substr((string) $safe['transport'], 0, 40) : null),
                'duration_ms' => isset($safe['durationMs']) && is_numeric($safe['durationMs']) ? (int) $safe['durationMs'] : null,
                'setting_version' => $setting->version, 'actor_id' => $by->id, 'actor_name' => $by->name, 'correlation_id' => Correlation::id(), 'created_at' => now(),
            ]);
            AuditLogger::record('settings.validated', 'app_setting', $setting->id, ['setting' => $type->key(), 'ok' => $ok, 'code' => $result['code'] ?? null, 'step' => $result['step'] ?? null], null, $by,
                outcome: $ok ? 'success' : 'failure', context: ['object_name' => $type->label()]);
            $this->forget($type);

            return $setting;
        });
    }

    public function activate(SettingType $type, User $by): AppSetting
    {
        return DB::transaction(function () use ($type, $by) {
            $setting = AppSetting::where('key', $type->key())->lockForUpdate()->firstOrFail();
            $setting->update(['activated_at' => now()]);
            AuditLogger::record('settings.activated', 'app_setting', $setting->id, ['setting' => $type->key(), 'version' => $setting->version], null, $by, context: ['object_name' => $type->label()]);
            $this->forget($type);

            return $setting;
        });
    }

    public function deactivate(SettingType $type, User $by): AppSetting
    {
        return DB::transaction(function () use ($type, $by) {
            $setting = AppSetting::where('key', $type->key())->lockForUpdate()->firstOrFail();
            $setting->update(['activated_at' => null]);
            AuditLogger::record('settings.disabled', 'app_setting', $setting->id, ['setting' => $type->key()], null, $by, context: ['object_name' => $type->label()]);
            $this->forget($type);

            return $setting;
        });
    }

    public function forget(SettingType $type): void
    {
        Cache::forget('bpm:setting:'.$type->key());
        if ($type instanceof EntraAuthSettings) {
            EntraAuthConfig::forget();
        }
    }
}
