<?php

namespace App\Backup;

use Illuminate\Support\Str;

/** Layout dos backups, igual no disco do servidor e no Azure: <org>/AAAA/MM/<id>/ e <org>/images/. */
final class BackupPaths
{
    public const MANIFEST = 'manifest.json';

    public static function newId(): string
    {
        return now('UTC')->format('Ymd\THis\Z').'-'.Str::lower(Str::random(8));
    }

    public static function isValidId(string $id): bool
    {
        return preg_match('/^\d{8}T\d{6}Z-[a-z0-9]{8}$/', $id) === 1;
    }

    public static function organization(): string
    {
        return (string) config('bpm.organization.key');
    }

    public static function dir(string $id): string
    {
        return self::organization().'/'.substr($id, 0, 4).'/'.substr($id, 4, 2).'/'.$id;
    }

    public static function part(string $id, string $name): string
    {
        return self::dir($id).'/'.$name;
    }

    public static function imagesDir(): string
    {
        return self::organization().'/images';
    }

    /** Uma imagem é guardada uma vez por digest: backups seguintes só citam o arquivo. */
    public static function image(string $imageId): string
    {
        return self::imagesDir().'/'.str_replace(':', '-', $imageId).'.tar.zst.age';
    }
}
