<?php

namespace App\Runtime;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Sequências anuais transacionais (IRR-000001/26, RACF-000001/26).
 * Nunca usa MAX(numero)+1 nem relógio do browser: linha por (chave, ano) com lock.
 */
class SequenceService
{
    /** @return array{year:int,value:int} */
    public function next(string $key, ?CarbonImmutable $at = null): array
    {
        $at ??= CarbonImmutable::now(config('bpm.sequences.timezone'));
        $year = (int) $at->format('Y');

        return DB::transaction(function () use ($key, $year) {
            $now = now('UTC');
            DB::table('sequence_counter')->insertOrIgnore([
                'sequence_key' => $key, 'year' => $year, 'value' => 0, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $row = DB::table('sequence_counter')->where('sequence_key', $key)->where('year', $year)->lockForUpdate()->first();
            $value = (int) $row->value + 1;
            DB::table('sequence_counter')->where('id', $row->id)->update(['value' => $value, 'updated_at' => $now]);

            return ['year' => $year, 'value' => $value];
        });
    }

    public function format(string $prefix, int $value, int $year): string
    {
        $digits = (int) config('bpm.sequences.digits', 6);
        $yearText = config('bpm.sequences.year_format') === 'yyyy' ? (string) $year : substr((string) $year, -2);

        return sprintf('%s-%s/%s', $prefix, str_pad((string) $value, $digits, '0', STR_PAD_LEFT), $yearText);
    }
}
