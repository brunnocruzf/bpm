<?php

namespace Tests\Feature;

use App\Backup\BackupAgent;
use App\Backup\BackupCatalog;
use App\Backup\BackupDestinationProbe;
use App\Backup\BackupFailure;
use App\Backup\BackupPaths;
use App\Backup\BackupRunner;
use App\Backup\BackupSchedule;
use App\Backup\BackupScheduler;
use App\Backup\BackupTools;
use App\Backup\DockerGate;
use App\Backup\PlatformFiles;
use App\Backup\RecoveryKey;
use App\Backup\RestoreRunner;
use App\Backup\RuntimeState;
use App\Backup\TarStream;
use App\Engine\EngineCommandProcessor;
use App\Engine\ExternalJobWorker;
use App\Engine\FlowableRestClient;
use App\Models\AuditEvent;
use App\Models\BackupCatalogEntry;
use App\Models\BackupJob;
use App\Models\EngineCommand;
use App\Models\NotificationMessage;
use App\Models\StorageTarget;
use App\Notifications\NotificationSender;
use App\Settings\SettingsRepository;
use App\Settings\Types\BackupSettings;
use App\Support\Masker;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\Support\FakeAzureBlob;
use Tests\Support\FakeBackupTools;
use Tests\TestCase;

class BackupTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/admin/backups';

    private const PUBLIC_KEY = 'age1qyqszqgpqyqszqgpqyqszqgpqyqszqgpqyqszqgpqyqszqgpqyqs3ceyr6';

    private const PRIVATE_KEY = 'AGE-SECRET-KEY-1QYQSZQGPQYQSZQGPQYQSZQGPQYQSZQGPQYQSZQGPQYQSZQGPQYQS3CEYR6';

    private FakeBackupTools $tools;

    private string $tmp;

    private RuntimeState $state;

    private object $engine;

    private string $publicKey = self::PUBLIC_KEY;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('attachments_local');
        $this->seedIdentity();
        Sleep::fake();
        $this->tmp = sys_get_temp_dir().'/bpm-backup-test-'.uniqid();
        mkdir($this->tmp);
        config(['bpm.backup.state_dir' => "$this->tmp/state", 'bpm.backup.dir' => "$this->tmp/backups", 'bpm.backup.key_dir' => $this->tmp, 'bpm.organization.name' => 'TMSA']);
        $this->tools = new FakeBackupTools;
        $this->tools->validIdentity = self::PRIVATE_KEY;
        $this->app->instance(BackupTools::class, $this->tools);
        $this->state = app(RuntimeState::class);
        $this->engine = new class extends FlowableRestClient
        {
            public int $downChecks = 0;

            public function health(): bool
            {
                return $this->downChecks-- <= 0;
            }

            public function version(): ?string
            {
                return '7.2.0';
            }

            public function externalJobs(string $topic): array
            {
                return [];
            }
        };
        $this->app->instance(FlowableRestClient::class, $this->engine);
        // age-keygen devolve a chave de teste; com -y, a parte pública dela
        Process::fake(['*' => fn ($process) => Process::result(in_array('-y', (array) $process->command, true) ? $this->publicKey."\n" : "# public key: x\n".self::PRIVATE_KEY."\n")]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    private function agentOnline(): void
    {
        $this->state->write(['sealKey' => base64_encode(sodium_crypto_box_publickey($this->sealKeys()))]);
        touch($this->state->beatFile());
    }

    private function sealKeys(): string
    {
        static $keys = null;

        return $keys ??= sodium_crypto_box_keypair();
    }

    private function agent(): BackupAgent
    {
        $agent = app(BackupAgent::class);
        (new \ReflectionProperty($agent, 'sealKeys'))->setValue($agent, $this->sealKeys());

        return $agent;
    }

    private function confirmKey(): void
    {
        $this->as('admin')->postJson(self::URL.'/recovery-key/confirm', ['recoveryKey' => self::PRIVATE_KEY])->assertOk();
    }

    private function runNextJob(): BackupJob
    {
        $job = BackupJob::where('status', BackupJob::PENDING)->orderBy('created_at')->firstOrFail();
        $job->update(['status' => BackupJob::RUNNING, 'started_at' => now()]);
        $this->agent()->handle($job->fresh());

        return BackupJob::findOrFail($job->id);
    }

    /** @param array<string,mixed> $options */
    private function makeBackup(string $scope = BackupSettings::DATABASE, array $options = []): BackupCatalogEntry
    {
        return app(BackupRunner::class)->run([...$options, 'scope' => $scope, 'azure' => $options['azure'] ?? false, 'origin' => $options['origin'] ?? BackupCatalogEntry::MANUAL], fn () => null)['entry'];
    }

    private function fakeDocker(): object
    {
        $docker = new class extends DockerGate
        {
            /** @var list<string> */
            public array $calls = [];

            public function stop(string $service): void
            {
                $this->calls[] = "stop:$service";
            }

            public function start(string $service): void
            {
                $this->calls[] = "start:$service";
            }

            public function image(string $service): ?array
            {
                return $service === 'api' ? ['id' => 'c1', 'image' => 'sha256:'.str_repeat('a', 64), 'ref' => 'bpm-tmsa/api:dev'] : null;
            }

            public function exportImage(string $imageId, string $file): void
            {
                $this->calls[] = "export:$imageId";
                // conteúdo diferente a cada exportação, como o age gera de verdade
                file_put_contents($file, 'tar '.count($this->calls));
            }
        };
        $this->app->instance(DockerGate::class, $docker);

        return $docker;
    }

    private function restoreRequest(BackupCatalogEntry $entry, array $override = [])
    {
        return $this->as('admin')->postJson(self::URL."/{$entry->id}/restore", [...['confirmation' => 'tmsa', 'justification' => 'Banco corrompido na migração', 'recoveryKey' => self::PRIVATE_KEY], ...$override]);
    }

    private function restoreThroughAgent(BackupCatalogEntry $entry): BackupJob
    {
        $this->restoreRequest($entry)->assertStatus(202);

        return $this->runNextJob();
    }

    public function test_only_settings_managers_reach_backups_and_the_maintenance_status_is_public(): void
    {
        $this->as('cq')->getJson(self::URL)->assertForbidden();
        $this->as('cq')->postJson(self::URL.'/recovery-key')->assertForbidden();
        $this->as('admin')->getJson(self::URL)->assertOk();
        $this->getJson('/api/v1/maintenance')->assertOk()->assertJsonPath('mode', RuntimeState::NORMAL);
    }

    public function test_the_recovery_key_is_returned_once_and_only_its_public_part_is_stored(): void
    {
        $this->as('admin')->postJson(self::URL.'/recovery-key')->assertOk()->assertHeader('Cache-Control')->assertJsonPath('privateKey', self::PRIVATE_KEY);

        $this->as('admin')->postJson(self::URL.'/recovery-key/confirm', ['recoveryKey' => 'isto não é uma chave'])->assertStatus(422);
        $this->assertNull(app(SettingsRepository::class)->value(app(BackupSettings::class))['recoveryKey']);

        $this->confirmKey();
        $key = app(SettingsRepository::class)->value(app(BackupSettings::class))['recoveryKey'];
        $this->assertSame(self::PUBLIC_KEY, $key['publicKey']);
        $this->assertStringNotContainsString('AGE-SECRET-KEY', (string) json_encode($key));
        $this->assertSame(1, AuditEvent::where('action', 'backup.key_generated')->count());
        $this->assertStringNotContainsString('AGE-SECRET-KEY', (string) json_encode(AuditEvent::all()));
    }

    public function test_the_public_key_cannot_be_set_through_the_settings_endpoint(): void
    {
        $this->as('admin')->putJson('/api/v1/admin/settings/backups', ['recoveryKey' => ['publicKey' => 'age1atacante']])->assertOk();

        $this->assertNull(app(SettingsRepository::class)->value(app(BackupSettings::class))['recoveryKey']);
    }

    public function test_settings_validation(): void
    {
        $url = '/api/v1/admin/settings/backups';
        $this->as('admin')->putJson($url, ['azure' => ['folder' => '../fora']])->assertStatus(422)->assertJsonValidationErrors('azure.folder');
        $this->as('admin')->putJson($url, ['azure' => ['folder' => 'attachments/backups']])->assertStatus(422)->assertJsonValidationErrors('azure.folder');
        $this->as('admin')->putJson($url, ['azure' => ['enabled' => true]])->assertStatus(422)->assertJsonValidationErrors('azure.enabled');
        $this->as('admin')->putJson($url, ['localCopies' => 0])->assertStatus(422)->assertJsonValidationErrors('localCopies');
        $this->as('admin')->putJson($url, ['timezone' => 'Lua/Base'])->assertStatus(422)->assertJsonValidationErrors('timezone');
        $this->as('admin')->putJson($url, ['schedules' => [['frequency' => 'hourly', 'scope' => 'x', 'time' => '25:00', 'keep' => 0]]])->assertStatus(422)
            ->assertJsonValidationErrors(['schedules.0.frequency', 'schedules.0.scope', 'schedules.0.time', 'schedules.0.keep']);
        $this->as('admin')->putJson($url, ['schedules' => [['frequency' => 'weekly', 'scope' => 'data', 'time' => '03:00', 'keep' => 4, 'weekday' => 9]]])->assertStatus(422)->assertJsonValidationErrors('schedules.0.weekday');
        $this->as('admin')->putJson($url, ['schedules' => [['frequency' => 'monthly', 'scope' => 'full', 'time' => '04:00', 'keep' => 12, 'monthDay' => 31]]])->assertStatus(422)->assertJsonValidationErrors('schedules.0.monthDay');

        $saved = $this->as('admin')->putJson($url, ['schedules' => [['frequency' => 'monthly', 'scope' => 'full', 'time' => '04:00', 'keep' => 12, 'monthDay' => 'last']]])->assertOk();
        $this->assertSame('last', $saved->json('value.schedules.0.monthDay'));
        $this->assertSame('incomplete', $saved->json('status'));
    }

    public function test_slots_follow_the_configured_timezone_and_clamp_to_the_month(): void
    {
        $tz = 'America/Sao_Paulo';
        $daily = ['frequency' => 'daily', 'time' => '02:00'];
        // 03:30 UTC ainda é 00:30 em Brasília: o último horário é o de ontem
        $now = CarbonImmutable::parse('2026-11-06T03:30:00Z');
        $this->assertSame('2026-11-05T05:00:00+00:00', BackupSchedule::lastDue($daily, $tz, $now)?->toIso8601String());
        $this->assertSame('2026-11-06T05:00:00+00:00', BackupSchedule::next($daily, $tz, $now)->toIso8601String());

        $weekly = ['frequency' => 'weekly', 'time' => '03:00', 'weekday' => 0];
        $this->assertSame('2026-11-01T06:00:00+00:00', BackupSchedule::lastDue($weekly, $tz, $now)?->toIso8601String());
        $this->assertSame('2026-11-08T06:00:00+00:00', BackupSchedule::next($weekly, $tz, $now)->toIso8601String());

        $lastDay = ['frequency' => 'monthly', 'time' => '04:00', 'monthDay' => 'last'];
        $this->assertSame('2026-10-31T07:00:00+00:00', BackupSchedule::lastDue($lastDay, $tz, $now)?->toIso8601String());
        $this->assertSame('2026-11-30T07:00:00+00:00', BackupSchedule::next($lastDay, $tz, $now)->toIso8601String());
        $this->assertSame('2027-02-28T07:00:00+00:00', BackupSchedule::next($lastDay, $tz, CarbonImmutable::parse('2027-02-01T12:00:00Z'))->toIso8601String());

        // agendamento criado depois do horário não dispara por ele
        $this->assertNull(BackupSchedule::lastDue([...$daily, 'createdAt' => '2026-11-05T20:00:00Z'], $tz, $now));
    }

    public function test_the_tick_creates_each_slot_once_and_waits_for_the_recovery_key(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-06T12:00:00Z'));
        $repository = app(SettingsRepository::class);
        $schedule = ['frequency' => 'daily', 'time' => '02:00', 'scope' => 'database', 'keep' => 7, 'azure' => false];
        $repository->update(app(BackupSettings::class), ['schedules' => [$schedule]], $this->dev('admin'));
        $this->travelTo(CarbonImmutable::parse('2026-11-07T12:00:00Z'));

        $this->assertSame(0, app(BackupScheduler::class)->tick(), 'sem chave, nada roda');
        $this->confirmKey();
        $this->assertSame(1, app(BackupScheduler::class)->tick());
        $this->assertSame(0, app(BackupScheduler::class)->tick(), 'mesmo horário não roda duas vezes');

        $this->state->write(['mode' => RuntimeState::SAFE]);
        $this->travelTo(CarbonImmutable::parse('2026-11-08T12:00:00Z'));
        $this->assertSame(0, app(BackupScheduler::class)->tick(), 'modo seguro segura a agenda');
    }

    public function test_an_overdue_schedule_alerts_the_admins_once_a_day(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-01T12:00:00Z'));
        app(SettingsRepository::class)->update(app(BackupSettings::class), ['schedules' => [['frequency' => 'daily', 'time' => '02:00', 'scope' => 'database', 'keep' => 7]]], $this->dev('admin'));
        $this->confirmKey();
        $this->travelTo(CarbonImmutable::parse('2026-11-06T12:00:00Z'));

        app(BackupScheduler::class)->tick();
        app(BackupScheduler::class)->tick();

        $alerts = NotificationMessage::where('event', 'admin.alert')->where('subject', 'like', 'Backup atrasado%')->get();
        $this->assertGreaterThan(0, $alerts->count());
        $this->assertSame($alerts->count(), $alerts->pluck('recipient_user_id')->unique()->count());
    }

    public function test_a_backup_encrypts_every_part_and_writes_the_manifest_last(): void
    {
        $this->confirmKey();
        $entry = $this->makeBackup();

        $dir = "$this->tmp/backups/".BackupPaths::dir($entry->id);
        $this->assertSame(['bpm_app.dump.zst.age', 'flowable.dump.zst.age', 'globals.sql.zst.age'], array_column($entry->manifest['parts'], 'name'));
        $this->assertFileExists("$dir/manifest.json");
        $this->assertFileDoesNotExist("$dir/bpm_app.dump", 'dump aberto não fica no disco');
        $this->assertSame(['dump:bpm_app', 'dump:flowable'], array_slice($this->tools->calls, 0, 2), 'a aplicação vem antes do engine');
        foreach ($entry->manifest['parts'] as $part) {
            $this->assertSame($part['sha256'], hash_file('sha256', "$dir/{$part['name']}"));
        }
        $this->assertSame(RecoveryKey::fingerprint(self::PUBLIC_KEY), $entry->key_fingerprint);
        $this->assertSame(RuntimeState::NORMAL, $this->state->mode());
    }

    public function test_a_failed_dump_leaves_no_backup_and_releases_the_pause(): void
    {
        $this->confirmKey();
        $this->agentOnline();
        $this->tools->failOn = 'dump';
        $this->as('admin')->postJson(self::URL, ['scope' => 'database'])->assertStatus(202);

        $job = $this->runNextJob();

        $this->assertSame(BackupJob::FAILED, $job->status);
        $this->assertSame('tool_failed', $job->error_code);
        $this->assertSame(RuntimeState::NORMAL, $this->state->mode());
        $this->assertSame(0, BackupCatalogEntry::count());
        $this->assertSame([], glob("$this->tmp/backups/.staging/*") ?: []);
        $this->assertSame(1, AuditEvent::where('action', 'backup.failed')->count());
        $this->assertGreaterThan(0, NotificationMessage::where('event', 'admin.alert')->count());
    }

    public function test_data_scope_packs_the_platform_files_and_they_restore_without_deleting_newer_ones(): void
    {
        $this->confirmKey();
        $disk = Storage::disk('attachments_local');
        $disk->put('attachments/2026/11/a.pdf', '%PDF conteúdo');
        $disk->put('reports/2026/11/r.xlsx', str_repeat('x', 1500));
        $entry = $this->makeBackup(BackupSettings::DATA);

        $this->assertSame(2, collect($entry->manifest['parts'])->firstWhere('kind', 'files')['files']);

        $disk->delete('attachments/2026/11/a.pdf');
        $disk->put('attachments/2026/11/novo.pdf', 'depois do backup');
        $reader = fopen("$this->tmp/backups/".BackupPaths::part($entry->id, 'files.tar.zst.age'), 'rb');
        fgets($reader);
        $result = app(PlatformFiles::class)->restore($reader);
        fclose($reader);

        $this->assertSame('%PDF conteúdo', $disk->get('attachments/2026/11/a.pdf'));
        $this->assertSame(1, $result['files']);
        $this->assertSame(1, $result['skipped'], 'arquivo igual não é regravado');
        $this->assertTrue($disk->exists('attachments/2026/11/novo.pdf'), 'restaurar não apaga o que é mais novo');
    }

    public function test_tar_round_trip_keeps_names_sizes_and_detects_corruption(): void
    {
        $out = fopen('php://temp', 'w+b');
        $long = str_repeat('pasta/', 25).'arquivo.bin';
        foreach (['a.txt' => 'abc', $long => str_repeat('z', 1025), 'vazio' => ''] as $name => $content) {
            $in = fopen('php://memory', 'w+b');
            fwrite($in, $content);
            rewind($in);
            TarStream::write($out, $name, strlen($content), 1_790_000_000, $in);
        }
        TarStream::finish($out);
        rewind($out);

        $read = [];
        foreach (TarStream::read($out) as $entry) {
            $read[$entry['name']] = stream_get_contents($entry['stream']);
        }
        $this->assertSame(['a.txt', $long, 'vazio'], array_keys($read));
        $this->assertSame(1025, strlen($read[$long]));

        rewind($out);
        $bytes = (string) stream_get_contents($out);
        $bad = fopen('php://memory', 'w+b');
        fwrite($bad, substr_replace($bytes, 'X', 3, 1));
        rewind($bad);
        $this->expectExceptionMessage('corrompido');
        iterator_to_array(TarStream::read($bad));
    }

    public function test_full_scope_saves_config_and_images_once_per_digest(): void
    {
        $this->confirmKey();
        $docker = $this->fakeDocker();
        $first = $this->makeBackup(BackupSettings::FULL);
        $second = $this->makeBackup(BackupSettings::FULL);

        $this->assertSame(1, collect($docker->calls)->filter(fn ($c) => str_starts_with($c, 'export:'))->count(), 'a segunda cópia só cita a imagem');
        $this->assertSame($first->manifest['images'][0]['file'], $second->manifest['images'][0]['file']);
        $this->assertSame('bpm-tmsa/api:dev', $second->manifest['images'][0]['ref']);
        $this->assertNotNull(collect($first->manifest['parts'])->firstWhere('kind', 'config'));
    }

    public function test_a_skipped_image_takes_its_checksum_from_the_newest_manifest(): void
    {
        [$azure] = $this->azure();
        $this->confirmKey();
        $docker = $this->fakeDocker();
        $image = BackupPaths::image('sha256:'.str_repeat('a', 64));
        $blob = 'anexos/bpm/backups/'.$image;
        $local = "$this->tmp/backups/$image";

        $this->makeBackup(BackupSettings::FULL, ['azure' => true]);
        // a imagem se perdeu nos dois lugares: a cópia seguinte exporta de novo, com outro conteúdo
        unlink($local);
        unset($azure->blobs[$blob]);
        $this->travel(1)->minutes();
        $this->makeBackup(BackupSettings::FULL, ['azure' => true]);
        // agora só o Azure tem a imagem: a terceira cópia não exporta e confia no manifesto
        unlink($local);
        $this->travel(1)->minutes();
        $third = $this->makeBackup(BackupSettings::FULL, ['azure' => true]);

        $this->assertSame(2, collect($docker->calls)->filter(fn ($c) => str_starts_with($c, 'export:'))->count());
        $this->assertSame(hash('sha256', $azure->blobs[$blob]), $third->manifest['images'][0]['sha256'], 'o resumo descreve o arquivo que está no Azure');
    }

    public function test_retention_keeps_the_limit_and_never_the_only_copy_or_the_latest(): void
    {
        $this->confirmKey();
        $id = '11111111-1111-4111-8111-111111111111';
        app(SettingsRepository::class)->update(app(BackupSettings::class), ['schedules' => [['id' => $id, 'frequency' => 'daily', 'time' => '02:00', 'scope' => 'database', 'keep' => 2]]], $this->dev('admin'));
        $entries = [];
        foreach ([3, 2, 1] as $days) {
            $this->travelTo(now()->subDays($days));
            $entries[] = $this->makeBackup(options: ['origin' => BackupCatalogEntry::SCHEDULE, 'scheduleId' => $id]);
            $this->travelBack();
        }
        $manual = $this->makeBackup();

        $this->assertNull(BackupCatalogEntry::find($entries[0]->id), 'o mais antigo saiu');
        $this->assertDirectoryDoesNotExist("$this->tmp/backups/".BackupPaths::dir($entries[0]->id));
        $this->assertNotNull(BackupCatalogEntry::find($entries[2]->id));
        $this->assertNotNull(BackupCatalogEntry::find($manual->id), 'backup manual não entra na retenção');
    }

    public function test_the_latest_backup_cannot_be_deleted_through_the_api(): void
    {
        $this->confirmKey();
        $this->agentOnline();
        $older = $this->makeBackup();
        $this->travel(30)->seconds();
        $latest = $this->makeBackup();

        $this->as('admin')->deleteJson(self::URL."/{$latest->id}")->assertStatus(409)->assertJsonPath('code', 'backup_is_latest');
        $this->as('admin')->deleteJson(self::URL."/{$older->id}")->assertStatus(202);
        $this->assertSame(BackupJob::SUCCEEDED, $this->runNextJob()->status);
        $this->assertNull(BackupCatalogEntry::find($older->id));
    }

    public function test_azure_upload_failure_keeps_the_backup_on_the_server_and_can_be_retried(): void
    {
        [$azure] = $this->azure();
        $this->confirmKey();
        $this->agentOnline();
        $azure->failures = array_fill(0, 3, ['status' => 403, 'code' => 'AuthorizationFailure']);
        $this->as('admin')->postJson(self::URL, ['scope' => 'database', 'azure' => true])->assertStatus(202);

        $job = $this->runNextJob();

        $this->assertSame(BackupJob::SUCCEEDED, $job->status);
        $this->assertSame('azure_upload_failed', $job->error_code);
        $entry = BackupCatalogEntry::sole();
        $this->assertTrue($entry->in_local);
        $this->assertFalse($entry->in_azure);

        $azure->failures = [];
        $this->as('admin')->postJson(self::URL."/{$entry->id}/copy-to-azure")->assertStatus(202);
        $this->assertSame(BackupJob::SUCCEEDED, $this->runNextJob()->status);
        $this->assertTrue($entry->fresh()->in_azure);
        $this->assertArrayHasKey('anexos/bpm/backups/'.BackupPaths::part($entry->id, 'manifest.json'), $azure->blobs);
    }

    public function test_the_catalog_is_rebuilt_from_azure_when_the_database_forgot_it(): void
    {
        [$azure] = $this->azure();
        $this->confirmKey();
        $entry = $this->makeBackup(options: ['azure' => true]);
        File::deleteDirectory("$this->tmp/backups/".BackupPaths::dir($entry->id));
        BackupCatalogEntry::query()->delete();

        $result = app(BackupCatalog::class)->sync();

        $this->assertSame(1, $result['added']);
        $found = BackupCatalogEntry::findOrFail($entry->id);
        $this->assertTrue($found->in_azure);
        $this->assertFalse($found->in_local);
        $this->assertSame(hash('sha256', $azure->blobs['anexos/bpm/backups/'.BackupPaths::part($entry->id, 'manifest.json')]), $found->manifest_sha256);
    }

    public function test_the_destination_probe_exercises_blocks_listing_and_ranges(): void
    {
        [$azure] = $this->azure();
        $result = app(BackupDestinationProbe::class)->run();

        $this->assertTrue($result['ok'], (string) $result['message']);
        $this->assertSame('backup_destination_ok', $result['code']);
        $this->assertSame(5, count($result['checks']));
        $this->assertSame([], array_filter(array_keys($azure->blobs), fn ($k) => str_contains($k, '.probe')), 'o arquivo de prova some');
        $this->assertGreaterThan(3, count(array_filter($azure->requests, fn ($r) => str_starts_with($r, 'PUT'))));
    }

    public function test_the_destination_probe_reports_a_missing_target(): void
    {
        $result = app(BackupDestinationProbe::class)->run();

        $this->assertFalse($result['ok']);
        $this->assertSame('azure_unavailable', $result['code']);
    }

    public function test_restore_is_refused_without_confirmation_or_with_the_wrong_key(): void
    {
        $this->confirmKey();
        $this->agentOnline();
        $entry = $this->makeBackup();

        $this->restoreRequest($entry, ['confirmation' => 'outra'])->assertStatus(422)->assertJsonValidationErrors('confirmation');
        $this->restoreRequest($entry, ['justification' => 'curto'])->assertStatus(422)->assertJsonValidationErrors('justification');
        $this->restoreRequest($entry, ['recoveryKey' => 'nada'])->assertStatus(422)->assertJsonValidationErrors('recoveryKey');
        $this->publicKey = 'age1'.str_repeat('z', 58);
        $this->restoreRequest($entry)->assertStatus(422)->assertJsonValidationErrors('recoveryKey');
        $this->assertSame(0, BackupJob::where('kind', BackupJob::RESTORE)->count());
    }

    public function test_restore_requires_a_live_agent_and_no_other_operation(): void
    {
        $this->confirmKey();
        $entry = $this->makeBackup();
        $this->restoreRequest($entry)->assertStatus(409)->assertJsonPath('code', 'backup_agent_offline');

        $this->agentOnline();
        BackupJob::create(['kind' => BackupJob::BACKUP, 'params' => ['scope' => 'database']]);
        $this->restoreRequest($entry)->assertStatus(409)->assertJsonPath('code', 'backup_busy');
    }

    public function test_the_recovery_key_never_reaches_the_job_row_in_clear(): void
    {
        $this->confirmKey();
        $this->agentOnline();
        $entry = $this->makeBackup();
        $this->restoreRequest($entry)->assertStatus(202);

        $job = BackupJob::where('kind', BackupJob::RESTORE)->sole();
        $this->assertStringNotContainsString('AGE-SECRET-KEY', (string) json_encode($job->getAttributes()));
        $this->assertSame(self::PRIVATE_KEY, sodium_crypto_box_seal_open((string) base64_decode((string) $job->sealed_secret), $this->sealKeys()));
        $this->assertStringNotContainsString('AGE-SECRET-KEY', (string) json_encode(AuditEvent::all()));
    }

    public function test_a_load_failure_keeps_the_data_in_use_and_never_enters_maintenance(): void
    {
        $this->confirmKey();
        $this->agentOnline();
        $docker = $this->fakeDocker();
        $entry = $this->makeBackup();
        $this->tools->failOn = 'load';

        $job = $this->restoreThroughAgent($entry);

        $this->assertSame(BackupJob::FAILED, $job->status);
        $this->assertSame(RuntimeState::NORMAL, $this->state->mode());
        $this->assertSame([], $docker->calls, 'nenhum serviço foi parado');
        $this->assertSame(['bpm_app' => 'em uso', 'flowable' => 'em uso'], $this->tools->databases, 'bancos temporários apagados');
        $this->assertSame(1, AuditEvent::where('action', 'backup.restore_failed')->count());
    }

    public function test_a_corrupted_part_is_refused_before_anything_changes(): void
    {
        $this->confirmKey();
        $this->agentOnline();
        $docker = $this->fakeDocker();
        $entry = $this->makeBackup();
        file_put_contents("$this->tmp/backups/".BackupPaths::part($entry->id, 'bpm_app.dump.zst.age'), 'adulterado');

        $job = $this->restoreThroughAgent($entry);

        $this->assertSame('part_corrupted', $job->error_code);
        $this->assertSame([], $docker->calls);
    }

    public function test_a_changed_manifest_is_refused(): void
    {
        $this->confirmKey();
        $this->agentOnline();
        $this->fakeDocker();
        $entry = $this->makeBackup();
        file_put_contents("$this->tmp/backups/".BackupPaths::part($entry->id, 'manifest.json'), '{"forjado":true}');

        $this->assertSame('manifest_changed', $this->restoreThroughAgent($entry)->error_code);
    }

    public function test_a_backup_from_a_newer_version_or_another_organization_is_refused(): void
    {
        $this->confirmKey();
        $this->agentOnline();
        $this->fakeDocker();
        $entry = $this->makeBackup();
        $entry->update(['manifest' => [...$entry->manifest, 'lastMigration' => '2099_01_01_000000_do_futuro']]);
        $this->assertSame('backup_newer_than_app', $this->restoreThroughAgent($entry)->error_code);

        $entry->update(['manifest' => [...$entry->manifest, 'organizationKey' => 'ims']]);
        $this->assertSame('wrong_organization', $this->restoreThroughAgent($entry->fresh())->error_code);
    }

    public function test_restore_swaps_both_databases_and_lands_in_safe_mode(): void
    {
        $this->confirmKey();
        $this->agentOnline();
        $docker = $this->fakeDocker();
        $entry = $this->makeBackup();
        $command = EngineCommand::create(['command' => EngineCommand::START, 'state' => EngineCommand::PENDING, 'attempts' => 0, 'idempotency_key' => 'k1', 'payload' => []]);

        $job = $this->restoreThroughAgent($entry);

        $this->assertSame(BackupJob::SUCCEEDED, $job->status, (string) $job->error_detail);
        $this->assertSame(RuntimeState::SAFE, $this->state->mode());
        $this->assertSame('restaurado', $this->tools->databases['bpm_app']);
        $this->assertSame('restaurado', $this->tools->databases['flowable']);
        $this->assertCount(2, array_filter(array_keys($this->tools->databases), fn ($n) => str_contains($n, '_pre_')), 'os bancos antigos ficam para o desfazer');
        $this->assertSame(['stop:queue-worker', 'stop:scheduler', 'stop:flowable-rest', 'start:flowable-rest', 'start:queue-worker', 'start:scheduler'], $docker->calls);

        $command->refresh();
        $this->assertSame(EngineCommand::RECONCILIATION_REQUIRED, $command->state);
        $this->assertSame(1, $command->attempts, 'o próximo claim força a reconciliação antes de reenviar');
        $event = AuditEvent::where('action', 'backup.restored')->sole();
        $this->assertSame(1, $event->data['auditTail']['count']);
        $this->assertTrue(Storage::disk('attachments_local')->exists($event->data['auditTail']['key']));
        $this->assertSame(2, BackupCatalogEntry::count(), 'o backup de segurança aparece no catálogo');
        $this->assertSame(BackupCatalogEntry::PRE_RESTORE, BackupCatalogEntry::orderByDesc('backup_at')->first()->origin);
    }

    public function test_the_safety_copy_never_prunes_the_backup_being_restored(): void
    {
        $this->confirmKey();
        $this->agentOnline();
        $this->fakeDocker();
        $id = '22222222-2222-4222-8222-222222222222';
        $schedule = ['id' => $id, 'frequency' => 'daily', 'time' => '02:00', 'scope' => 'database', 'keep' => 3];
        app(SettingsRepository::class)->update(app(BackupSettings::class), ['schedules' => [$schedule]], $this->dev('admin'));
        $this->travelTo(now()->subDays(2));
        $old = $this->makeBackup(options: ['origin' => BackupCatalogEntry::SCHEDULE, 'scheduleId' => $id]);
        $this->travelBack();
        $this->travelTo(now()->subDay());
        $this->makeBackup(options: ['origin' => BackupCatalogEntry::SCHEDULE, 'scheduleId' => $id]);
        $this->travelBack();
        // o limite diminuiu depois: na próxima retenção, o backup antigo sairia
        app(SettingsRepository::class)->update(app(BackupSettings::class), ['schedules' => [[...$schedule, 'keep' => 1]]], $this->dev('admin'));

        $job = $this->restoreThroughAgent($old);

        $this->assertSame(BackupJob::SUCCEEDED, $job->status, (string) $job->error_detail);
        $this->assertSame('restaurado', $this->tools->databases['bpm_app']);
        $this->assertNotNull(BackupCatalogEntry::find($old->id));
    }

    public function test_the_restored_history_shows_the_job_that_made_the_backup_as_succeeded(): void
    {
        $this->confirmKey();
        $this->agentOnline();
        $this->fakeDocker();
        $this->as('admin')->postJson(self::URL, ['scope' => 'database'])->assertStatus(202);
        $origin = $this->runNextJob();
        $entry = BackupCatalogEntry::findOrFail($origin->backup_id);
        $this->assertSame($origin->id, $entry->manifest['jobId']);

        $this->restoreRequest($entry)->assertStatus(202);
        // como no banco copiado: o pedido ainda estava em execução quando o dump foi feito
        $origin->update(['status' => BackupJob::RUNNING, 'backup_id' => null]);
        $this->runNextJob();

        $origin->refresh();
        $this->assertSame(BackupJob::SUCCEEDED, $origin->status);
        $this->assertSame($entry->id, $origin->backup_id);
    }

    public function test_a_failure_after_the_swap_rolls_back_and_restarts_everything(): void
    {
        $this->confirmKey();
        $this->agentOnline();
        $docker = $this->fakeDocker();
        $entry = $this->makeBackup();
        // a primeira subida esgota as tentativas; a volta aos bancos antigos encontra o engine de pé
        $this->engine->downChecks = 61;

        $job = $this->restoreThroughAgent($entry);

        $this->assertSame(BackupJob::FAILED, $job->status);
        $this->assertSame('engine_not_ready', $job->error_code);
        $this->assertSame(RuntimeState::NORMAL, $this->state->mode());
        $this->assertSame('em uso', $this->tools->databases['bpm_app'], 'voltou ao banco antigo');
        $this->assertArrayNotHasKey('restore', $this->state->read());
        $this->assertTrue(in_array('start:queue-worker', $docker->calls, true), 'os serviços voltaram');
    }

    public function test_undo_returns_to_the_previous_databases(): void
    {
        $this->confirmKey();
        $this->agentOnline();
        $docker = $this->fakeDocker();
        $entry = $this->makeBackup();
        $this->restoreThroughAgent($entry);
        $docker->calls = [];

        $this->as('admin')->postJson(self::URL.'/safe-mode/undo')->assertStatus(202);
        $job = $this->runNextJob();

        $this->assertSame(BackupJob::SUCCEEDED, $job->status, (string) $job->error_detail);
        $this->assertSame(RuntimeState::NORMAL, $this->state->mode());
        $this->assertSame(['bpm_app' => 'em uso', 'flowable' => 'em uso'], $this->tools->databases);
        $this->assertSame(1, AuditEvent::where('action', 'backup.restore_undone')->count());
    }

    public function test_undo_clears_the_restore_job_that_came_back_with_the_old_database(): void
    {
        $this->confirmKey();
        $this->agentOnline();
        $this->fakeDocker();
        $entry = $this->makeBackup();
        $restore = $this->restoreThroughAgent($entry);
        // como no banco antigo real: o pedido ficou em execução quando a troca aconteceu
        $restore->update(['status' => BackupJob::RUNNING]);

        $this->as('admin')->postJson(self::URL.'/safe-mode/undo')->assertStatus(202);
        $this->runNextJob();

        $this->assertSame('restore_undone', $restore->fresh()->error_code);
        $this->assertSame(0, BackupJob::whereIn('status', [BackupJob::PENDING, BackupJob::RUNNING])->count(), 'nada bloqueia a próxima operação');
    }

    public function test_a_failed_undo_goes_back_to_safe_mode_instead_of_staying_in_maintenance(): void
    {
        $this->confirmKey();
        $this->agentOnline();
        $docker = $this->fakeDocker();
        $entry = $this->makeBackup();
        $this->restoreThroughAgent($entry);
        $this->tools->failOn = 'rename';

        $this->as('admin')->postJson(self::URL.'/safe-mode/undo')->assertStatus(202);
        $job = $this->runNextJob();

        $this->assertSame(BackupJob::FAILED, $job->status);
        $this->assertSame(RuntimeState::SAFE, $this->state->mode());
        $this->assertSame('restaurado', $this->tools->databases['bpm_app'], 'os dados restaurados continuam em uso');
        $this->assertSame('start:scheduler', end($docker->calls), 'os serviços voltaram');
    }

    public function test_release_decides_the_pending_emails_and_drops_the_old_databases(): void
    {
        $this->confirmKey();
        $this->agentOnline();
        $this->fakeDocker();
        $entry = $this->makeBackup();
        $this->restoreThroughAgent($entry);
        $pending = NotificationMessage::create(['idempotency_key' => 'n1', 'event' => 'task.assigned', 'recipient' => 'u', 'subject' => 'x', 'body' => 'y', 'state' => NotificationMessage::QUEUED]);

        $this->as('admin')->postJson(self::URL.'/safe-mode/release', ['notifications' => 'x'])->assertStatus(422);
        $this->as('admin')->postJson(self::URL.'/safe-mode/release', ['notifications' => 'discard'])->assertStatus(202);
        $job = $this->runNextJob();

        $this->assertSame(BackupJob::SUCCEEDED, $job->status, (string) $job->error_detail);
        $this->assertSame(RuntimeState::NORMAL, $this->state->mode());
        $this->assertSame(NotificationMessage::SKIPPED, $pending->fresh()->state);
        $this->assertSame([], array_filter(array_keys($this->tools->databases), fn ($n) => str_contains($n, '_pre_')));
        $this->assertSame(1, AuditEvent::where('action', 'backup.safe_mode_released')->count());
    }

    public function test_disaster_recovery_imports_a_downloaded_backup_and_cannot_be_undone(): void
    {
        $this->confirmKey();
        $this->agentOnline();
        $docker = $this->fakeDocker();
        $entry = $this->makeBackup();
        $dir = "$this->tmp/backups/".BackupPaths::dir($entry->id);
        $key = "$this->tmp/key.txt";
        file_put_contents($key, self::PRIVATE_KEY."\n");

        app(RestoreRunner::class)->import($dir, $key, fn () => null);

        $this->assertSame(RuntimeState::SAFE, $this->state->mode());
        $this->assertSame('restaurado', $this->tools->databases['bpm_app']);
        $this->assertSame([], $docker->calls, 'na recuperação, a stack ainda não está de pé');
        $this->assertSame(1, AuditEvent::where('action', 'backup.restored')->count());
        $this->as('admin')->getJson(self::URL.'/safe-mode')->assertOk()->assertJsonPath('canUndo', false);
        $this->as('admin')->postJson(self::URL.'/safe-mode/undo')->assertStatus(409)->assertJsonPath('code', 'undo_unavailable');
    }

    public function test_import_refuses_a_backup_of_another_organization(): void
    {
        $this->confirmKey();
        $entry = $this->makeBackup();
        $dir = "$this->tmp/backups/".BackupPaths::dir($entry->id);
        file_put_contents("$dir/manifest.json", (string) json_encode([...$entry->manifest, 'organizationKey' => 'ims']));

        try {
            app(RestoreRunner::class)->import($dir, "$this->tmp/key.txt", fn () => null);
            $this->fail('esperava recusa');
        } catch (BackupFailure $e) {
            $this->assertSame('wrong_organization', $e->errorCode);
        }
        $this->assertSame(RuntimeState::NORMAL, $this->state->mode());
    }

    public function test_a_restore_test_proves_the_key_without_touching_the_data_in_use(): void
    {
        $this->confirmKey();
        $this->agentOnline();
        $docker = $this->fakeDocker();
        $entry = $this->makeBackup();
        $this->as('admin')->postJson(self::URL."/{$entry->id}/restore-test", ['recoveryKey' => self::PRIVATE_KEY])->assertStatus(202);

        $job = $this->runNextJob();

        $this->assertSame(BackupJob::SUCCEEDED, $job->status, (string) $job->error_detail);
        $this->assertSame(['bpm_app' => 'em uso', 'flowable' => 'em uso'], $this->tools->databases);
        $this->assertSame([], $docker->calls);
        $this->assertTrue($entry->fresh()->verification['ok']);
        $this->assertSame(47, $entry->fresh()->verification['app']['tables']);
    }

    public function test_an_agent_restart_invalidates_the_sealed_key(): void
    {
        $this->confirmKey();
        $this->agentOnline();
        $this->fakeDocker();
        $entry = $this->makeBackup();
        $this->restoreRequest($entry)->assertStatus(202);
        $job = BackupJob::where('kind', BackupJob::RESTORE)->sole();
        $job->update(['status' => BackupJob::RUNNING]);

        $agent = app(BackupAgent::class);
        (new \ReflectionProperty($agent, 'sealKeys'))->setValue($agent, sodium_crypto_box_keypair());
        $agent->handle($job->fresh());

        $job->refresh();
        $this->assertSame('agent_restarted', $job->error_code);
        $this->assertNull($job->sealed_secret);
    }

    public function test_recover_after_a_crash_before_the_swap_restarts_services_and_cleans_up(): void
    {
        $docker = $this->fakeDocker();
        $this->tools->databases['bpm_app_restore'] = 'restaurado';
        $this->state->write(['mode' => RuntimeState::MAINTENANCE, 'restore' => ['jobId' => 'abc', 'phase' => 'stopping']]);

        app(RestoreRunner::class)->recover();

        $this->assertSame(RuntimeState::NORMAL, $this->state->mode());
        $this->assertArrayNotHasKey('bpm_app_restore', $this->tools->databases);
        $this->assertContains('start:flowable-rest', $docker->calls);
    }

    public function test_recover_after_a_crash_past_the_swap_goes_back_to_the_old_databases(): void
    {
        $this->fakeDocker();
        $names = 'cba';
        $this->tools->databases = ['bpm_app' => 'restaurado', 'flowable' => 'restaurado', "bpm_app_pre_$names" => 'em uso', "flowable_pre_$names" => 'em uso'];
        $this->state->write(['mode' => RuntimeState::MAINTENANCE, 'restore' => ['jobId' => 'abc', 'phase' => 'swapped']]);

        app(RestoreRunner::class)->recover();

        $this->assertSame(['bpm_app' => 'em uso', 'flowable' => 'em uso'], $this->tools->databases);
        $this->assertSame(RuntimeState::NORMAL, $this->state->mode());
    }

    public function test_recover_detects_a_swap_that_the_journal_did_not_record(): void
    {
        $this->fakeDocker();
        // a troca foi confirmada no banco, mas o agente caiu antes de registrar a fase no diário
        $this->tools->databases = ['bpm_app' => 'restaurado', 'flowable' => 'restaurado', 'bpm_app_pre_cba' => 'em uso', 'flowable_pre_cba' => 'em uso'];
        $this->state->write(['mode' => RuntimeState::MAINTENANCE, 'restore' => ['jobId' => 'abc', 'phase' => 'stopping']]);

        app(RestoreRunner::class)->recover();

        $this->assertSame(['bpm_app' => 'em uso', 'flowable' => 'em uso'], $this->tools->databases, 'sem isso, o sistema seguiria com os dados restaurados e sem revisão');
        $this->assertSame(RuntimeState::NORMAL, $this->state->mode());
    }

    public function test_the_rollback_can_run_again_after_the_old_databases_are_back(): void
    {
        $docker = $this->fakeDocker();
        // uma volta anterior já trocou os bancos, mas caiu antes de religar os serviços
        $this->tools->databases = ['bpm_app' => 'em uso', 'flowable' => 'em uso', 'bpm_app_discard_cba' => 'restaurado', 'flowable_discard_cba' => 'restaurado'];
        $this->state->write(['mode' => RuntimeState::MAINTENANCE, 'restore' => ['jobId' => 'abc', 'phase' => 'swapped']]);

        app(RestoreRunner::class)->recover();

        $this->assertSame(['bpm_app' => 'em uso', 'flowable' => 'em uso'], $this->tools->databases);
        $this->assertSame(RuntimeState::NORMAL, $this->state->mode());
        $this->assertContains('start:queue-worker', $docker->calls);
    }

    public function test_maintenance_answers_without_the_database_and_blocks_everything_else(): void
    {
        $this->state->write(['mode' => RuntimeState::MAINTENANCE, 'operation' => ['stage' => 'swapping', 'percent' => 75]]);

        $this->as('admin')->getJson('/api/v1/me')->assertStatus(503)->assertJsonPath('code', 'maintenance');
        $this->getJson('/api/v1/health')->assertOk();
        $this->getJson('/api/v1/maintenance')->assertOk()->assertJsonPath('mode', RuntimeState::MAINTENANCE)->assertJsonPath('percent', 75);
    }

    public function test_the_maintenance_status_exposes_only_progress(): void
    {
        $this->state->write(['mode' => RuntimeState::MAINTENANCE, 'sealKey' => 'segredo', 'restore' => ['backupId' => 'x', 'jobId' => 'y'], 'operation' => ['jobId' => 'y', 'stage' => 'swapping', 'percent' => 75]]);

        $body = $this->getJson('/api/v1/maintenance')->assertOk()->json();
        $this->assertEqualsCanonicalizing(['mode', 'stage', 'percent'], array_keys(array_filter($body, fn ($v) => $v !== null)));
    }

    public function test_safe_mode_lets_only_settings_managers_in(): void
    {
        $this->state->write(['mode' => RuntimeState::SAFE]);

        $this->as('cq')->getJson('/api/v1/me')->assertStatus(503)->assertJsonPath('code', 'safe_mode');
        $this->as('admin')->getJson('/api/v1/me')->assertOk()->assertJsonPath('safeMode', true);
    }

    public function test_the_pause_holds_engine_traffic_and_safe_mode_holds_external_effects(): void
    {
        Http::fake();
        $command = EngineCommand::create(['command' => EngineCommand::START, 'state' => EngineCommand::PENDING, 'attempts' => 0, 'idempotency_key' => 'k2', 'payload' => []]);

        $this->state->write(['mode' => RuntimeState::PAUSE]);
        $this->assertSame(0, app(EngineCommandProcessor::class)->dispatchDue());
        app(EngineCommandProcessor::class)->process($command->id);
        $this->assertSame(EngineCommand::PENDING, $command->fresh()->state);
        $this->assertSame(0, app(ExternalJobWorker::class)->run());

        $this->state->write(['mode' => RuntimeState::SAFE]);
        $this->assertSame(0, app(ExternalJobWorker::class)->run());
        $message = NotificationMessage::create(['idempotency_key' => 'n2', 'event' => 'task.assigned', 'recipient' => 'u', 'subject' => 'x', 'body' => 'y', 'state' => NotificationMessage::QUEUED]);
        $this->assertSame(0, app(NotificationSender::class)->dispatchDue());
        $this->assertSame(NotificationMessage::QUEUED, $message->fresh()->state);
        Http::assertNothingSent();
    }

    public function test_the_masker_removes_recovery_keys(): void
    {
        $this->assertStringNotContainsString('AGE-SECRET-KEY', Masker::error('age: erro com '.self::PRIVATE_KEY));
        $this->assertSame('***', Masker::mask(['recoveryKey' => self::PRIVATE_KEY])['recoveryKey']);
    }

    /** @return array{0:FakeAzureBlob} destino ativo e envio ligado */
    private function azure(): array
    {
        $azure = FakeAzureBlob::install();
        config(['bpm.integrations.allowed_hosts' => [FakeAzureBlob::ACCOUNT.'.blob.core.windows.net']]);
        $this->as('admin')->putJson('/api/v1/admin/settings/attachments-storage', ['provider' => 'azure_blob', 'azure' => ['accountName' => FakeAzureBlob::ACCOUNT, 'container' => 'anexos', 'pathPrefix' => 'bpm', 'accountKey' => FakeAzureBlob::KEY, 'approvalReference' => 'SEC-1']])->assertOk();
        $this->as('admin')->postJson('/api/v1/admin/settings/attachments-storage/test')->assertOk()->assertJsonPath('lastValidation.code', 'storage_ok');
        $this->as('admin')->postJson('/api/v1/admin/settings/attachments-storage/activate')->assertOk();
        $this->assertNotNull(StorageTarget::where('status', StorageTarget::ACTIVE)->first());
        $this->as('admin')->putJson('/api/v1/admin/settings/backups', ['azure' => ['enabled' => true, 'folder' => 'backups']])->assertOk();

        return [$azure];
    }
}
