<?php

namespace Tests\Support;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Emulador do Entra (token endpoint) e do Microsoft Graph v1.0 para testes do sync:
 * paginação com @odata.nextLink, @odata.deltaLink, members@delta, @removed, falhas injetáveis.
 */
class FakeGraph
{
    public const TENANT = '11111111-2222-4333-8444-555555555555';

    public const G1 = 'aaaaaaaa-0000-4000-8000-000000000001';

    public const G2 = 'aaaaaaaa-0000-4000-8000-000000000002';

    /** @var array<string, array<string,mixed>> */
    public array $users = [];

    /** @var array<string, list<string>> membros por grupo */
    public array $members = [];

    /** mudanças devolvidas na próxima rodada delta */
    public array $userChanges = [];

    public array $groupChanges = [];

    /** @var array<string, list<array{status:int, body?:array, headers?:array}>> falhas por padrão de URL (FIFO) */
    public array $failures = [];

    /** @var list<string> */
    public array $requests = [];

    public int $round = 1;

    /** @var list<string> */
    public array $roles = ['User.Read.All', 'Group.Read.All'];

    public static function install(): self
    {
        $fake = new self;
        $fake->members = [self::G1 => [], self::G2 => []];
        Http::fake(fn (Request $r) => $fake->handle($r));

        return $fake;
    }

    public function user(string $n, array $attrs = []): string
    {
        $id = sprintf('bbbbbbbb-0000-4000-8000-%012d', (int) $n);
        $this->users[$id] = ['id' => $id, 'displayName' => "Usuário $n", 'userPrincipalName' => "u$n@tmsa.example", 'mail' => "u$n@tmsa.example", 'department' => 'Qualidade', 'accountEnabled' => true, 'userType' => 'Member', ...$attrs];

        return $id;
    }

    public static function token(array $roles): string
    {
        $b = fn (array $v) => rtrim(strtr(base64_encode((string) json_encode($v)), '+/', '-_'), '=');

        return $b(['alg' => 'none', 'typ' => 'JWT']).'.'.$b(['roles' => $roles, 'aud' => 'https://graph.microsoft.com', 'appid' => 'cccccccc-0000-4000-8000-000000000001', 'app_displayname' => 'BPM Sync (teste)', 'tid' => self::TENANT]).'.c2ln';
    }

    public function handle(Request $request)
    {
        $url = $request->url();
        $this->requests[] = $request->method().' '.$url;
        foreach ($this->failures as $pattern => $queue) {
            if ($queue && str_contains(urldecode($url), $pattern)) {
                $f = array_shift($this->failures[$pattern]);

                return Http::response($f['body'] ?? ['error' => ['code' => 'x']], $f['status'], $f['headers'] ?? []);
            }
        }
        if (str_contains($url, '/oauth2/v2.0/token')) {
            return Http::response(['access_token' => self::token($this->roles), 'expires_in' => 3600, 'token_type' => 'Bearer']);
        }
        $path = (string) parse_url($url, PHP_URL_PATH);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

        if (str_ends_with($path, '/groups/delta')) {
            if (isset($q['$deltatoken'])) {
                $changes = $this->groupChanges;
                $this->groupChanges = [];

                return Http::response(['value' => $changes, '@odata.deltaLink' => $this->link('groups', ['$deltatoken' => 'g-'.(++$this->round)])]);
            }
            preg_match_all("/id eq '([0-9a-f\-]{36})'/", (string) ($q['$filter'] ?? ''), $m);
            // sem $filter (escopo "todo o diretório"): todos os grupos conhecidos
            $ids = empty($q['$filter']) ? array_keys($this->members) : $m[1];
            $rows = array_map(fn ($g) => ['id' => $g, 'displayName' => "Grupo $g", 'mail' => null, 'securityEnabled' => true,
                'members@delta' => array_map(fn ($u) => ['@odata.type' => '#microsoft.graph.user', 'id' => $u], $this->members[$g] ?? [])], $ids);
            // grupo 2 vem em uma segunda página (nextLink)
            if (! isset($q['$skiptoken'])) {
                return Http::response(['value' => array_slice($rows, 0, 1), '@odata.nextLink' => $this->link('groups', ['$skiptoken' => 'p2', '$filter' => $q['$filter'] ?? ''])]);
            }

            return Http::response(['value' => array_slice($rows, 1), '@odata.deltaLink' => $this->link('groups', ['$deltatoken' => 'g-'.$this->round])]);
        }
        if (str_ends_with($path, '/users/delta')) {
            if (isset($q['$deltatoken'])) {
                $changes = $this->userChanges;
                $this->userChanges = [];

                return Http::response(['value' => $changes, '@odata.deltaLink' => $this->link('users', ['$deltatoken' => 'u-'.(++$this->round)])]);
            }
            $all = array_values($this->users);
            $page = (int) ($q['$skiptoken'] ?? 0);
            $slice = array_slice($all, $page * 3, 3);
            if (($page + 1) * 3 < count($all)) {
                return Http::response(['value' => $slice, '@odata.nextLink' => $this->link('users', ['$skiptoken' => (string) ($page + 1)])]);
            }

            return Http::response(['value' => $slice, '@odata.deltaLink' => $this->link('users', ['$deltatoken' => 'u-'.$this->round])]);
        }
        if (preg_match('#/users/([0-9a-f\-]{36})$#', $path, $mm)) {
            return isset($this->users[$mm[1]]) ? Http::response($this->users[$mm[1]]) : Http::response(['error' => ['code' => 'Request_ResourceNotFound']], 404);
        }
        if (preg_match('#/groups/([0-9a-f\-]{36})$#', $path, $mm)) {
            return Http::response(['id' => $mm[1], 'displayName' => 'Grupo']);
        }
        if (str_ends_with($path, '/users') || str_ends_with($path, '/groups')) {
            return Http::response(['value' => []]);
        }

        return Http::response(['error' => ['code' => 'NotSupported']], 400);
    }

    private function link(string $resource, array $query): string
    {
        return "https://graph.microsoft.com/v1.0/$resource/delta?".http_build_query($query);
    }

    public function calledWith(string $needle): bool
    {
        foreach ($this->requests as $r) {
            if (str_contains(urldecode($r), $needle)) {
                return true;
            }
        }

        return false;
    }
}
