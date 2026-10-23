<?php

namespace App\Settings\Types;

use App\Models\AppSetting;
use App\Models\User;
use App\Settings\SettingType;
use App\Support\ApiProblem;

/**
 * Relatórios: renderer ativo e parâmetros. O Carbone pode ser selecionado livremente (o uso foi liberado pelo
 * responsável do projeto, que assumiu a decisão de licenciamento — ver ADR-0011); o serviço continua dependendo de
 * a plataforma habilitá-lo (BPM_CARBONE_ENABLED e perfil carbone do Compose). Padrão: renderer nativo.
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
            'confidentialityLabel' => 'Uso interno — '.config('bpm.organization.name'),
            'carbone' => ['edition' => 'community'],
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
        if (array_key_exists('edition', $c)) {
            $v['carbone']['edition'] = in_array($c['edition'], ['community', 'enterprise'], true) ? $c['edition'] : 'community';
        }
        // campos de aprovação de licença de versões anteriores deixam de existir
        unset($v['carbone']['licenseApproved'], $v['carbone']['approvalReference']);
        if ($errors) {
            throw ApiProblem::validation($errors);
        }

        return $v;
    }

    public function statusAfterSave(array $value): array
    {
        if ($value['renderer'] === 'carbone' && ! config('bpm.reports.carbone.enabled')) {
            return [AppSetting::ERROR, 'Carbone selecionado, mas o serviço não está habilitado pela plataforma (BPM_CARBONE_ENABLED=true e perfil carbone do Compose).'];
        }

        return $value['renderer'] === 'carbone'
            ? [AppSetting::TEST_PENDING, 'Teste o serviço Carbone antes de gerar relatórios.']
            : [AppSetting::CONFIGURED, 'Renderer nativo (PDF/CSV/XLSX gerados pela própria API).'];
    }
}
