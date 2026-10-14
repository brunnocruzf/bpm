<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Versão histórica de uma configuração (valores já redigidos). */
class AppSettingRevision extends Model
{
    public $timestamps = false;

    protected $table = 'app_setting_revision';

    protected $fillable = ['setting_key', 'version', 'value', 'changed_by', 'changed_by_name', 'created_at'];

    protected function casts(): array
    {
        return ['value' => 'array', 'created_at' => 'datetime'];
    }
}
