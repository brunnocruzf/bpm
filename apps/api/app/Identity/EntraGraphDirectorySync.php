<?php

namespace App\Identity;

use App\Identity\Graph\GraphClient;
use Closure;

/**
 * Adapter do Microsoft Graph para o diretório: monta as consultas (delta de grupos selecionados ou de todos
 * com membros, delta de usuários, leitura pontual) e itera páginas. Não conhece banco nem regras
 * de negócio — o DirectorySyncService decide o que aplicar e quando gravar checkpoints.
 */
class EntraGraphDirectorySync
{
    public const USER_PROPERTIES = 'id,displayName,userPrincipalName,mail,department,accountEnabled,userType';

    public const USER_SELECT = self::USER_PROPERTIES.',manager';

    public const GROUP_SELECT = 'id,displayName,description,mail,securityEnabled';

    public function __construct(private readonly GraphClient $graph) {}

    /**
     * Delta de grupos com members@delta: filtro por object ID (até 50) ou, com null, todos os grupos do tenant.
     *
     * @param  list<string>|null  $groupIds
     * @param  Closure(list<array<string,mixed>>, ?string):void  $onPage  recebe itens e o nextLink da página
     * @return string deltaLink da rodada concluída
     */
    public function groupsDelta(?array $groupIds, ?string $startLink, Closure $onPage): string
    {
        $initial = 'groups/delta?$select='.self::GROUP_SELECT.',members';
        if ($groupIds !== null) {
            $filter = implode(' or ', array_map(fn ($id) => "id eq '$id'", $groupIds));
            $initial = 'groups/delta?$filter='.rawurlencode($filter).'&$select='.self::GROUP_SELECT.',members';
        }

        return $this->paginate($startLink ?: $initial, $onPage);
    }

    /**
     * @param  Closure(list<array<string,mixed>>, ?string):void  $onPage
     */
    public function usersDelta(?string $startLink, Closure $onPage): string
    {
        return $this->paginate($startLink ?: 'users/delta?$select='.self::USER_SELECT, $onPage);
    }

    /** @return array<string,mixed> */
    public function user(string $id): array
    {
        return $this->graph->get('users/'.rawurlencode($id).'?$select='.self::USER_PROPERTIES.'&$expand=manager($select=id)');
    }

    /** @return array<string,mixed> */
    public function group(string $id): array
    {
        return $this->graph->get('groups/'.rawurlencode($id).'?$select='.self::GROUP_SELECT);
    }

    /** @return array<string,mixed> metadados da foto (inclui @odata.mediaEtag); not_found quando não há foto */
    public function userPhotoMeta(string $id): array
    {
        return $this->graph->get('users/'.rawurlencode($id).'/photo');
    }

    /** @return array{bytes:string, contentType:string} */
    public function userPhoto(string $id, int $maxBytes): array
    {
        return $this->graph->getBinary('users/'.rawurlencode($id).'/photo/$value', $maxBytes);
    }

    /**
     * Verificações reais de permissão para o teste de conexão (leitura mínima).
     *
     * @param  list<string>|null  $groupIds  null = escopo "todo o diretório" (lê um grupo qualquer)
     * @return list<array{name:string, ok:bool, code:?string, message:?string}>
     */
    public function probe(?array $groupIds, bool $photos = false): array
    {
        $checks = [];
        $run = function (string $name, Closure $fn) use (&$checks) {
            try {
                $fn();
                $checks[] = ['name' => $name, 'ok' => true, 'code' => null, 'message' => null];
            } catch (Graph\GraphException $e) {
                $checks[] = ['name' => $name, 'ok' => false, 'code' => $e->errorCode, 'message' => $e->getMessage()];
            }
        };
        $firstUser = null;
        $run('Leitura de usuários (User.Read.All)', function () use (&$firstUser) {
            $firstUser = $this->graph->get('users?$top=1&$select=id')['value'][0]['id'] ?? null;
        });
        if ($photos && is_string($firstUser)) {
            // 200 ou 404 (usuário sem foto) provam a permissão; 403 vira consent_missing
            $run('Leitura de foto de perfil (User.Read.All)', function () use ($firstUser) {
                try {
                    $this->userPhotoMeta($firstUser);
                } catch (Graph\GraphException $e) {
                    if ($e->errorCode !== 'not_found') {
                        throw $e;
                    }
                }
            });
        }
        if ($groupIds === null) {
            $run('Leitura de grupos (Group.Read.All)', fn () => $this->graph->get('groups?$top=1&$select=id'));
        }
        foreach (array_slice($groupIds ?? [], 0, 3) as $id) {
            $run("Leitura do grupo $id (Group.Read.All)", fn () => $this->group($id));
        }

        return $checks;
    }

    /**
     * @param  Closure(list<array<string,mixed>>, ?string):void  $onPage
     */
    private function paginate(string $link, Closure $onPage): string
    {
        for ($guard = 0; $guard < 100000; $guard++) {
            $page = $this->graph->get($link);
            $next = isset($page['@odata.nextLink']) ? (string) $page['@odata.nextLink'] : null;
            $onPage(array_values((array) ($page['value'] ?? [])), $next);
            if ($next) {
                $link = $next;

                continue;
            }

            return (string) ($page['@odata.deltaLink'] ?? '');
        }

        return '';
    }
}
