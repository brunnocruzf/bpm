<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Usuários fictícios do acesso de visitante / demonstração (nunca em produção).
 * Em homologação/produção, usuários vêm do Entra ID (oid + tid) no primeiro acesso.
 */
class DevUserSeeder extends Seeder
{
    public function run(): void
    {
        $worker = ['Bpm.Initiator', 'Bpm.TaskWorker'];
        $users = [
            ['admin', 'Ana Administradora', 'TI', ['Bpm.Admin', 'Bpm.Designer', 'Bpm.Publisher', 'Bpm.Auditor', 'Bpm.ProcessAdmin', 'Bpm.Reporter'], []],
            ['gestor', 'Gisele — Gestão de processos', 'Processos', ['Bpm.ProcessAdmin', 'Bpm.Reporter', 'Bpm.Initiator'], []],
            ['designer', 'Diego Designer', 'Processos', ['Bpm.Designer', 'Bpm.Initiator'], []],
            ['publisher', 'Paula Publicadora', 'Processos', ['Bpm.Publisher', 'Bpm.Designer'], []],
            ['cq', 'Carla — CQ Recebimento', 'Qualidade — Recebimento', $worker, ['CQ']],
            ['ppcpm', 'Pedro — PPCPM', 'PPCPM', $worker, ['PPCPM']],
            ['comite', 'Clara — Comitê', 'Comitê de Qualidade', $worker, ['COMITE']],
            ['gef', 'Gabriel — GEF', 'Gestão de Fornecedores', $worker, ['GEF']],
            ['fornecedor', 'Fernanda — Fornecedor (demo)', 'Fornecedor Alfa (fictício)', $worker, ['FORNECEDOR']],
            ['sup', 'Sérgio — Suprimentos', 'Suprimentos', $worker, ['SUP']],
            ['fiscal', 'Fábio — Fiscal', 'Fiscal', $worker, ['FISCAL']],
            ['qualidade', 'Quésia — Qualidade', 'Qualidade', $worker, ['QUALIDADE']],
            ['expedicao', 'Eduardo — Expedição', 'Expedição', $worker, ['EXPEDICAO']],
            ['faturamento', 'Fátima — Faturamento', 'Faturamento', $worker, ['FATURAMENTO']],
            ['auditor', 'Augusto — Auditor', 'Auditoria interna', ['Bpm.Auditor'], []],
        ];

        foreach ($users as [$username, $name, $area, $roles, $groups]) {
            // vínculos bloqueados pelo administrador continuam bloqueados quando o seed roda de novo
            $excluded = (array) (User::where('username', $username)->value('excluded_groups') ?? []);
            $groups = array_values(array_diff($groups, $excluded));
            User::updateOrCreate(['username' => $username], [
                'name' => $name,
                'area' => $area,
                'email' => $username.'@bpm.tmsa.local',
                'roles' => $roles,
                'groups' => $groups,
                'is_active' => true,
                'auth_source' => User::SOURCE_DEMO,
            ]);
        }
    }
}
