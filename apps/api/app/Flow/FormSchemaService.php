<?php

namespace App\Flow;

use App\Audit\AuditDiff;
use App\Forms\FormioSchema;
use App\Forms\FormioSchemaSanitizer;
use App\Models\FormSchemaVersion;
use App\Models\User;
use App\Support\ApiProblem;
use App\Support\AuditLogger;
use Illuminate\Support\Facades\DB;

/** Persistência de schemas Form.io (somente em rascunho; publicados são imutáveis). */
class FormSchemaService
{
    public function __construct(
        private readonly FormioSchemaSanitizer $sanitizer,
        private readonly StageService $stages,
    ) {}

    /** @param array<string,mixed> $schema */
    public function save(FormSchemaVersion $form, array $schema, ?string $title, User $user, ?int $lockVersion = null): FormSchemaVersion
    {
        $version = $form->definitionVersion;
        StageService::assertDraft($version, $lockVersion);
        $clean = $this->sanitizer->sanitize($schema);

        return DB::transaction(function () use ($form, $clean, $title, $user, $version) {
            $beforeFields = array_map(fn ($f) => $f['type'].': '.$f['label'], FormioSchema::fieldMap((array) $form->schema));
            $beforeTitle = $form->title;
            $beforeHash = $form->hash;
            $form->schema = $clean;
            $form->hash = FormioSchema::hash($clean);
            if ($title !== null && trim($title) !== '') {
                $form->title = mb_substr(strip_tags(trim($title)), 0, 160);
            }
            $form->save();
            $this->stages->touch($version, $user);
            AuditLogger::record('form.saved', 'form_schema_version', $form->id, [
                'formKey' => $form->form_key,
                'fields' => array_keys(FormioSchema::fieldMap($clean)),
                'hash' => $form->hash,
            ], diff: AuditDiff::between(
                ['title' => $beforeTitle, 'hash' => $beforeHash, 'fields' => $beforeFields],
                ['title' => $form->title, 'hash' => $form->hash, 'fields' => array_map(fn ($f) => $f['type'].': '.$f['label'], FormioSchema::fieldMap($clean))],
            ), context: ['object_name' => $form->title, 'form_schema_version_id' => $form->id]);

            return $form;
        });
    }

    /** "Usar como modelo": copia o schema de outro formulário, gerando schema independente. */
    public function copyFrom(FormSchemaVersion $target, FormSchemaVersion $source, User $user): FormSchemaVersion
    {
        if ($target->id === $source->id) {
            throw ApiProblem::validation(['source' => ['Selecione outro formulário como modelo.']]);
        }

        return $this->save($target, $source->schema, null, $user);
    }
}
