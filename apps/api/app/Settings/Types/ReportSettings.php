<?php

namespace App\Settings\Types;

use App\Models\AppSetting;
use App\Models\User;
use App\Settings\SettingType;
use App\Support\ApiProblem;

/**
 * Relatórios: renderer ativo e parâmetros. O Carbone só pode ser selecionado com aprovação
 * registrada da licença (Community Edition sob "CCL Agreement"; recursos Enterprise exigem licença)
 * e com o serviço habilitado pela plataforma (BPM_CARBONE_ENABLED). Padrão: renderer nativo.
 */
class ReportSettings extends SettingType
{
    public const KEY = 'reports';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Relatórios e modelos';
    }

    public function defaults(): array
    {
        return [
            'renderer' => 'native',
            'confidentialityLabel' => 'Uso interno — TMSA',
            'carbone' => ['licenseApproved' => false, 'approvalReference' => '', 'edition' => 'community'],
        ];
    }

    public function normalize(array $input, array $current, User $by): array
    {
        $errors = [];
        $v = $current;
        if (array_key_exists('renderer', $input)) {
            $r = (string) $input['renderer'];
            if (! in_array($r, ['native', 'carbone'], true)) {
                $errors['renderer'][] = 'Renderer inválido.';
            }
            $v['renderer'] = $r;
        }
        if (array_key_exists('confidentialityLabel', $input)) {
            $v['confidentialityLabel'] = mb_substr(trim(strip_tags((string) $input['confidentialityLabel'])), 0, 80);
        }
        $c = (array) ($input['carbone'] ?? []);
        if (array_key_exists('licenseApproved', $c)) {
            $v['carbone']['licenseApproved'] = (bool) $c['licenseApproved'];
        }
        if (array_key_exists('approvalReference', $c)) {
            $v['carbone']['approvalReference'] = mb_substr(trim(strip_tags((string) $c['approvalReference'])), 0, 200);
        }
        if (array_key_exists('edition', $c)) {
            $v['carbone']['edition'] = in_array($c['edition'], ['community', 'enterprise'], true) ? $c['edition'] : 'community';
        }
        if ($v['renderer'] === 'carbone' && (! $v['carbone']['licenseApproved'] || mb_strlen($v['carbone']['approvalReference']) < 5)) {
            $errors['renderer'][] = 'Carbone exige aprovação registrada da licença (jurídico/segurança) antes de ser selecionado.';
        }
        if ($errors) {
            throw ApiProblem::validation($errors);
        }

        return $v;
    }

    public function statusAfterSave(array $value): array
    {
        if ($value['renderer'] === 'carbone' && ! config('bpm.reports.carbone.enabled')) {
            return [AppSetting::ERROR, 'Carbone aprovado, mas o serviço não está habilitado pela plataforma (BPM_CARBONE_ENABLED).'];
        }

        return $value['renderer'] === 'carbone'
            ? [AppSetting::TEST_PENDING, 'Teste o serviço Carbone antes de gerar relatórios.']
            : [AppSetting::CONFIGURED, 'Renderer nativo (PDF/CSV/XLSX gerados pela própria API).'];
    }
}
