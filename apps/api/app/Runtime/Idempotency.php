<?php

namespace App\Runtime;

use App\Models\IdempotencyRecord;
use App\Models\User;
use App\Support\ApiProblem;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Mutações críticas com Idempotency-Key: a mesma chave + mesmo conteúdo devolve a
 * mesma resposta sem executar novamente (ex.: duplo clique, retry do cliente).
 */
final class Idempotency
{
    /** @param callable(): array{0:int,1:array<string,mixed>} $operation */
    public static function run(Request $request, User $user, string $scope, callable $operation, bool $required = false): JsonResponse
    {
        $key = (string) $request->header('Idempotency-Key', '');
        if ($key === '') {
            if ($required) {
                throw new ApiProblem(400, 'Idempotency-Key obrigatória', 'Envie o cabeçalho Idempotency-Key nesta operação.', 'idempotency_key_required');
            }
            [$status, $body] = $operation();

            return response()->json($body, $status);
        }
        if (! preg_match('/^[A-Za-z0-9\-_:.]{8,160}$/', $key)) {
            throw new ApiProblem(400, 'Idempotency-Key inválida', null, 'idempotency_key_invalid');
        }
        $hash = hash('sha256', json_encode($request->all()));

        $existing = IdempotencyRecord::where(['idempotency_key' => $key, 'user_id' => $user->id, 'scope' => $scope])->first();
        if ($existing) {
            return self::replay($existing, $hash);
        }

        try {
            return DB::transaction(function () use ($key, $user, $scope, $hash, $operation) {
                $record = IdempotencyRecord::create([
                    'idempotency_key' => $key, 'user_id' => $user->id, 'scope' => $scope, 'request_hash' => $hash,
                    'response_status' => 0, 'response_body' => [], 'created_at' => now(),
                ]);
                [$status, $body] = $operation();
                $record->update(['response_status' => $status, 'response_body' => $body]);

                return response()->json($body, $status)->header('Idempotency-Replayed', 'false');
            });
        } catch (QueryException $e) {
            // requisição concorrente com a mesma chave
            $existing = IdempotencyRecord::where(['idempotency_key' => $key, 'user_id' => $user->id, 'scope' => $scope])->first();
            if ($existing) {
                return self::replay($existing, $hash);
            }
            throw $e;
        }
    }

    private static function replay(IdempotencyRecord $record, string $hash): JsonResponse
    {
        if ($record->request_hash !== $hash) {
            throw new ApiProblem(422, 'Idempotency-Key reutilizada', 'A mesma chave foi usada com conteúdo diferente.', 'idempotency_key_reused');
        }
        if ($record->response_status === 0) {
            throw ApiProblem::conflict('Solicitação ainda em processamento.', 'idempotency_in_progress');
        }

        return response()->json($record->response_body, $record->response_status)->header('Idempotency-Replayed', 'true');
    }
}
