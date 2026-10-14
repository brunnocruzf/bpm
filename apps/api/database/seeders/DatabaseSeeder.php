<?php

namespace Database\Seeders;

use App\Flow\FlowBlueprint;
use App\Flow\PublishService;
use App\Models\BpmGroup;
use App\Models\ProcessCatalog;
use App\Models\User;
use App\Reports\ReportTemplateService;
use Database\Seeders\Blueprints\DemoFlows;
use Database\Seeders\Blueprints\Po159;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /** Raias do PO 159. Associação aos grupos Entra é feita por ambiente (Administração → Grupos). */
    public const GROUPS = [
        'CQ' => ['CQ / Recebimento', 'Controle de qualidade no recebimento'],
        'PPCPM' => ['PPCPM', 'Planejamento e controle da produção e materiais'],
        'COMITE' => ['Comitê', 'Comitê de avaliação (urgência e não procedência)'],
        'GEF' => ['GEF', 'Gestão de fornecedores'],
        'FORNECEDOR' => ['Fornecedor', 'Resposta do fornecedor (acesso externo pendente de decisão)'],
        'SUP' => ['Suprimentos', 'Suprimentos / compras'],
        'FISCAL' => ['Fiscal', 'Fiscal — OCDev no AX'],
        'QUALIDADE' => ['Qualidade', 'Separação, entrega e transferência'],
        'EXPEDICAO' => ['Expedição', 'Definição de frete e coleta'],
        'FATURAMENTO' => ['Faturamento', 'Emissão de NF e NF espelho'],
    ];

    public function run(): void
    {
        foreach (self::GROUPS as $key => [$name, $description]) {
            BpmGroup::updateOrCreate(['key' => $key], ['name' => $name, 'description' => $description, 'email' => strtolower($key).'@bpm.tmsa.local']);
        }

        if (! app()->environment('production')) {
            $this->call(DevUserSeeder::class);
        }
        app(ReportTemplateService::class)->ensureDefaults();

        $owner = User::where('username', 'designer')->first() ?? User::first();
        if (! $owner) {
            $this->command?->warn('Sem usuário para registrar autoria dos fluxos seed.');

            return;
        }
        $publisher = User::where('username', 'publisher')->first() ?? $owner;

        if (! ProcessCatalog::where('key', 'item_reprovado_recebimento')->exists()) {
            FlowBlueprint::create(Po159::spec(), $owner, false);
            app(PublishService::class)->publish(ProcessCatalog::where('key', 'item_reprovado_recebimento')->first()->latestVersion(), $publisher);
            $this->command?->info('PO 159 criado e publicado (deploy agendado no outbox).');
        }
        if (! ProcessCatalog::where('key', 'homologacao_fornecedor')->exists()) {
            FlowBlueprint::create(DemoFlows::homologacaoFornecedor(), $owner, false);
            app(PublishService::class)->publish(ProcessCatalog::where('key', 'homologacao_fornecedor')->first()->latestVersion(), $publisher);
        }
        if (! ProcessCatalog::where('key', 'aprovacao_frete_especial')->exists()) {
            FlowBlueprint::create(DemoFlows::freteEspecial(), $owner, false);
        }
    }
}
