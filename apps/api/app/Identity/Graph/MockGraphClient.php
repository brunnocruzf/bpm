<?php

namespace App\Identity\Graph;

/**
 * Diretório FICTÍCIO para desenvolvimento/homologação sem tenant (transporte "mock").
 * Responde no formato do Graph v1.0 (paginação com @odata.nextLink, @odata.deltaLink,
 * members@delta, @removed). Proibido em produção (validação da configuração).
 */
class MockGraphClient implements GraphClient
{
    public const TENANT = '00000000-0000-4000-8000-00000000a001';

    /** Object IDs dos grupos do diretório fictício (não existem em nenhum tenant real). */
    public static function isFictitiousGroup(string $id): bool
    {
        return str_starts_with(strtolower(trim($id)), '6f1e0c1a-0000-4000-8000-');
    }

    private const BASE = 'https://graph.microsoft.com/v1.0';

    private const PAGE = 2;

    /** @return array<string, array<string,mixed>> */
    public static function groups(): array
    {
        return [
            '6f1e0c1a-0000-4000-8000-0000000000c1' => ['id' => '6f1e0c1a-0000-4000-8000-0000000000c1', 'displayName' => 'TMSA-BPM-CQ (fictício)', 'description' => 'Controle de qualidade', 'mail' => 'bpm-cq@tmsa.example', 'securityEnabled' => true,
                'members' => ['9a5d0000-0000-4000-8000-000000000001', '9a5d0000-0000-4000-8000-000000000002', '9a5d0000-0000-4000-8000-000000000006']],
            '6f1e0c1a-0000-4000-8000-0000000000c2' => ['id' => '6f1e0c1a-0000-4000-8000-0000000000c2', 'displayName' => 'TMSA-BPM-GEF (fictício)', 'description' => 'Gestão de fornecedores', 'mail' => 'bpm-gef@tmsa.example', 'securityEnabled' => true,
                'members' => ['9a5d0000-0000-4000-8000-000000000003', '9a5d0000-0000-4000-8000-000000000004', '9a5d0000-0000-4000-8000-000000000005']],
            '6f1e0c1a-0000-4000-8000-0000000000c3' => ['id' => '6f1e0c1a-0000-4000-8000-0000000000c3', 'displayName' => 'TMSA-BPM-Administradores (fictício)', 'description' => 'Administração do BPM', 'mail' => null, 'securityEnabled' => true,
                'members' => ['9a5d0000-0000-4000-8000-000000000001']],
        ];
    }

    /** @return array<string, array<string,mixed>> */
    public static function users(): array
    {
        $u = fn (string $n, string $name, string $dept, bool $enabled = true, string $type = 'Member') => [
            'id' => "9a5d0000-0000-4000-8000-00000000000$n", 'displayName' => $name, 'userPrincipalName' => "usuario$n@tmsa.example",
            'mail' => "usuario$n@tmsa.example", 'department' => $dept, 'accountEnabled' => $enabled, 'userType' => $type,
        ];

        $users = collect([
            $u('1', 'Usuária Um (fictícia)', 'Qualidade'),
            $u('2', 'Usuário Dois (fictício)', 'Qualidade'),
            $u('3', 'Usuária Três (fictícia)', 'Gestão de Fornecedores'),
            $u('4', 'Usuário Quatro (fictício, desabilitado)', 'Gestão de Fornecedores', false),
            $u('5', 'Convidado Cinco (fictício)', 'Externo', true, 'Guest'),
            $u('6', 'Usuária Seis (fictícia)', 'Qualidade'),
        ])->keyBy('id')->all();
        $managerId = '9a5d0000-0000-4000-8000-000000000001';
        $users['9a5d0000-0000-4000-8000-000000000002']['manager'] = ['id' => $managerId];

        return $users;
    }

    public function get(string $pathOrUrl): array
    {
        $url = str_starts_with($pathOrUrl, 'https://') ? $pathOrUrl : self::BASE.'/'.ltrim($pathOrUrl, '/');
        $path = (string) parse_url($url, PHP_URL_PATH);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

        if (str_ends_with($path, '/users/delta')) {
            $users = array_map(function (array $user) {
                if (isset($user['manager'])) {
                    $user['manager@delta'] = [$user['manager'] + ['@odata.type' => '#microsoft.graph.user']];
                    unset($user['manager']);
                }

                return $user;
            }, array_values(self::users()));

            return $this->delta('users', $users, $q);
        }
        if (str_ends_with($path, '/groups/delta')) {
            $ids = $this->filterIds((string) ($q['$filter'] ?? ''), $q);
            $groups = array_values(array_filter(self::groups(), fn ($g) => $ids === null || in_array($g['id'], $ids, true)));
            $rows = array_map(fn ($g) => [
                'id' => $g['id'], 'displayName' => $g['displayName'], 'description' => $g['description'], 'mail' => $g['mail'], 'securityEnabled' => $g['securityEnabled'],
                'members@delta' => array_map(fn ($m) => ['@odata.type' => '#microsoft.graph.user', 'id' => $m], $g['members']),
            ], $groups);

            return $this->delta('groups', $rows, $q);
        }
        if (preg_match('#/users/([0-9a-f\-]{36})$#', $path, $m)) {
            return self::users()[$m[1]] ?? throw new GraphException('not_found', 'Objeto não encontrado no diretório.', false, 404);
        }
        if (preg_match('#/groups/([0-9a-f\-]{36})$#', $path, $m)) {
            $g = self::groups()[$m[1]] ?? throw new GraphException('not_found', 'Objeto não encontrado no diretório.', false, 404);
            unset($g['members']);

            return $g;
        }
        if (str_ends_with($path, '/users') || str_ends_with($path, '/groups')) {
            return ['value' => []];
        }

        throw new GraphException('graph_error', 'Recurso não suportado pelo diretório fictício.');
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @param  array<string,mixed>  $q
     * @return array<string,mixed>
     */
    private function delta(string $resource, array $rows, array $q): array
    {
        if (isset($q['$deltatoken'])) {
            // diretório fictício estático: sem mudanças desde a última rodada
            return ['value' => [], '@odata.deltaLink' => self::BASE."/$resource/delta?\$deltatoken=mock-$resource-1"];
        }
        $page = (int) ($q['$skiptoken'] ?? 0);
        $slice = array_slice($rows, $page * self::PAGE, self::PAGE);
        $more = ($page + 1) * self::PAGE < count($rows);
        $filter = isset($q['$filter']) ? '&$filter='.rawurlencode((string) $q['$filter']) : '';

        return [
            'value' => $slice,
            $more ? '@odata.nextLink' : '@odata.deltaLink' => $more
                ? self::BASE."/$resource/delta?\$skiptoken=".($page + 1).$filter
                : self::BASE."/$resource/delta?\$deltatoken=mock-$resource-1",
        ];
    }

    /**
     * @param  array<string,mixed>  $q
     * @return list<string>|null
     */
    private function filterIds(string $filter, array $q): ?array
    {
        if ($filter === '') {
            return null;
        }
        preg_match_all("/id eq '([0-9a-f\-]{36})'/", $filter, $m);

        return $m[1];
    }
}
