<?php

namespace App\Settings\Types;

use App\Models\AppSetting;
use App\Models\User;
use App\Settings\SettingType;
use App\Support\ApiProblem;

/**
 * Retenção da trilha de auditoria e de artefatos operacionais. O produto NÃO define prazo legal:
 * sem prazo aprovado (privacidade/jurídico/segurança) nada é expurgado. Expurgo sempre arquiva
 * antes (JSONL + manifesto SHA-256 no storage) e registra o próprio evento de auditoria.
 */
class AuditRetentionSettings extends SettingType
{
    public const KEY = 'audit.retention';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Auditoria e retenção';
    }

    public function defaults(): array
    {
        return [
            'retentionDays' => null,
            'approvalReference' => '',
            'approvedBy' => '',
            'archiveBeforePurge' => true,
            'notificationRetentionDays' => null,
            'syncRunRetentionDays' => null,
            'reportArtifactHours' => 24,
        ];
    }

    public function normalize(array $input, array $current, User $by): array
    {
        $errors = [];
        $v = $current;
        foreach (['retentionDays' => [30, 36500], 'notificationRetentionDays' => [7, 3650], 'syncRunRetentionDays' => [7, 3650]] as $f => [$min, $max]) {
            if (array_key_exists($f, $input)) {
                $days = $input[$f] === null || $input[$f] === '' ? null : (int) $input[$f];
                if ($days !== null && ($days < $min || $days > $max)) {
                    $errors[$f][] = "Informe entre $min e $max dias, ou deixe vazio (sem expurgo).";
                }
                $v[$f] = $days;
            }
        }
        if (array_key_exists('reportArtifactHours', $input)) {
            $h = (int) $input['reportArtifactHours'];
            if ($h < 1 || $h > 720) {
                $errors['reportArtifactHours'][] = 'Informe entre 1 e 720 horas.';
            }
            $v['reportArtifactHours'] = $h;
        }
        foreach (['approvalReference' => 200, 'approvedBy' => 200] as $f => $max) {
            if (array_key_exists($f, $input)) {
                $v[$f] = mb_substr(trim(strip_tags((string) $input[$f])), 0, $max);
            }
        }
        $v['archiveBeforePurge'] = true; // não desligável: expurgo sem arquivo não é permitido
        if ($v['retentionDays'] !== null && (mb_strlen($v['approvalReference']) < 5 || mb_strlen($v['approvedBy']) < 3)) {
            $errors['approvalReference'][] = 'Prazo de retenção exige referência da aprovação (privacidade/jurídico/segurança) e o aprovador.';
        }
        if ($errors) {
            throw ApiProblem::validation($errors);
        }

        return $v;
    }

    public function statusAfterSave(array $value): array
    {
        return $value['retentionDays'] === null
            ? [AppSetting::DISABLED, 'Retenção indefinida: nenhum evento é expurgado até haver prazo aprovado.']
            : [AppSetting::CONFIGURED, "Eventos com mais de {$value['retentionDays']} dias são arquivados e expurgados diariamente."];
    }
}
