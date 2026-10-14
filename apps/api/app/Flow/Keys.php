<?php

namespace App\Flow;

use App\Models\ProcessDefinitionVersion;
use App\Models\ProcessStageVersion;
use App\Models\ProcessTransitionVersion;
use Illuminate\Support\Str;

/** Geração de chaves técnicas estáveis (NCName válidos para BPMN e Flowable). */
final class Keys
{
    private const STOPWORDS = ['de', 'do', 'da', 'dos', 'das', 'no', 'na', 'nos', 'nas', 'e', 'o', 'a', 'os', 'as', 'em', 'para', 'por', 'com'];

    public static function slug(string $text, int $max = 48): string
    {
        $slug = Str::of(Str::ascii($text))->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->limit($max, '')->trim('_')->toString();
        if ($slug === '' || ! preg_match('/^[a-z]/', $slug)) {
            $slug = 'f_'.$slug;
        }

        return rtrim($slug, '_');
    }

    public static function instancePrefix(string $name): string
    {
        $words = array_filter(preg_split('/\s+/', Str::ascii($name)) ?: [], fn ($w) => $w !== '' && ! in_array(strtolower($w), self::STOPWORDS, true));
        $prefix = strtoupper(implode('', array_map(fn ($w) => $w[0], $words)));
        $prefix = preg_replace('/[^A-Z]/', '', $prefix) ?: 'BPM';

        return substr(strlen($prefix) < 2 ? strtoupper(substr(preg_replace('/[^A-Za-z]/', '', Str::ascii($name)) ?: 'BPM', 0, 3)) : $prefix, 0, 6);
    }

    public static function uniqueStageKey(ProcessDefinitionVersion $version, string $base): string
    {
        $base = self::slug($base, 40);
        $existing = ProcessStageVersion::where('definition_version_id', $version->id)->pluck('stage_key')->all();
        $key = $base;
        $i = 2;
        while (in_array($key, $existing, true)) {
            $key = $base.'_'.$i++;
        }

        return $key;
    }

    public static function flowId(ProcessDefinitionVersion $version): string
    {
        do {
            $id = 'flow_'.Str::lower(Str::random(8));
        } while (ProcessTransitionVersion::where('definition_version_id', $version->id)->where('element_id', $id)->exists());

        return $id;
    }

    public static function formKeyFor(string $stageKey): string
    {
        return substr('f_'.$stageKey, 0, 64);
    }
}
