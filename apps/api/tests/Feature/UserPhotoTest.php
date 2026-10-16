<?php

namespace Tests\Feature;

use App\Identity\DirectorySyncService;
use App\Identity\Graph\GraphException;
use App\Identity\Graph\MockGraphClient;
use App\Identity\UserPhotoService;
use App\Jobs\RefreshUserPhoto;
use App\Models\AuditEvent;
use App\Models\DirectorySyncRun;
use App\Models\User;
use App\Models\UserPhoto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\Support\FakeGraph;
use Tests\TestCase;

class UserPhotoTest extends TestCase
{
    use RefreshDatabase;

    private FakeGraph $graph;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('attachments_local');
        $this->seedIdentity();
        Sleep::fake();
        $this->graph = FakeGraph::install();
        $this->as('admin')->putJson('/api/v1/admin/settings/entra/directory-sync', [
            'enabled' => true, 'transport' => 'graph', 'tenantId' => FakeGraph::TENANT,
            'clientId' => 'cccccccc-0000-4000-8000-000000000001',
            'credentialType' => 'client_secret', 'clientSecret' => 'S3gredo-Do-App', 'clientSecretApproval' => 'SEC-2026-042',
            'groups' => [['id' => FakeGraph::G1, 'name' => 'BPM-CQ']],
        ])->assertOk();
    }

    private function jpeg(int $w = 300, int $h = 200, int $shade = 90): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, (int) imagecolorallocate($img, $shade, 120, 150));
        ob_start();
        imagejpeg($img);

        return (string) ob_get_clean();
    }

    /** @return array{0:User, 1:string} usuário Entra importado pelo sync e seu object ID */
    private function importedUser(): array
    {
        $oid = $this->graph->user('1');
        $this->graph->members[FakeGraph::G1] = [$oid];
        $id = $this->as('admin')->postJson('/api/v1/admin/entra/sync/run', ['mode' => 'delta'])->assertStatus(202)->json('id');
        app(DirectorySyncService::class)->execute($id);
        $this->assertSame(DirectorySyncRun::COMPLETED, DirectorySyncRun::find($id)->status);

        return [User::where('entra_oid', $oid)->firstOrFail(), $oid];
    }

    private function photoCalls(string $suffix = '/photo/$value'): int
    {
        return count(array_filter($this->graph->requests, fn ($r) => str_ends_with(urldecode($r), $suffix)));
    }

    public function test_photo_is_downloaded_resized_to_a_square_webp_and_stored_privately(): void
    {
        [$user, $oid] = $this->importedUser();
        $this->graph->photos[$oid] = ['bytes' => $this->jpeg(300, 200), 'etag' => '"v1"'];

        $this->assertSame(UserPhotoService::UPDATED, app(UserPhotoService::class)->refresh($user));
        $photo = UserPhoto::findOrFail($user->id);
        $this->assertSame(UserPhoto::PRESENT, $photo->status);
        $this->assertSame('"v1"', $photo->graph_etag);
        $this->assertSame('image/webp', $photo->content_type);
        Storage::disk('attachments_local')->assertExists($photo->object_key);
        $info = getimagesizefromstring((string) Storage::disk('attachments_local')->get($photo->object_key));
        $this->assertSame([128, 128], [$info[0], $info[1]]);
        $this->assertSame(IMAGETYPE_WEBP, $info[2]);
        $this->assertStringStartsWith('user-photos/'.$user->id.'/', $photo->object_key);
    }

    public function test_same_etag_does_not_download_again_and_a_new_etag_replaces_the_file(): void
    {
        [$user, $oid] = $this->importedUser();
        $this->graph->photos[$oid] = ['bytes' => $this->jpeg(), 'etag' => '"v1"'];
        $service = app(UserPhotoService::class);
        $service->refresh($user);
        $first = UserPhoto::findOrFail($user->id);
        $oldKey = $first->object_key;

        $this->assertSame(UserPhotoService::UNCHANGED, $service->refresh($user));
        $this->assertSame(1, $this->photoCalls(), 'etag igual: só os metadados são lidos');
        $this->assertSame(2, $this->photoCalls('/photo'));

        $this->graph->photos[$oid] = ['bytes' => $this->jpeg(200, 200, 30), 'etag' => '"v2"'];
        $this->assertSame(UserPhotoService::UPDATED, $service->refresh($user));
        $second = UserPhoto::findOrFail($user->id);
        $this->assertNotSame($first->sha256, $second->sha256);
        $this->assertNotSame($oldKey, $second->object_key);
        Storage::disk('attachments_local')->assertMissing($oldKey);
        Storage::disk('attachments_local')->assertExists($second->object_key);
    }

    public function test_user_without_photo_is_marked_absent_and_a_removed_photo_deletes_the_file(): void
    {
        [$user, $oid] = $this->importedUser();
        $service = app(UserPhotoService::class);
        $this->assertSame(UserPhotoService::UNCHANGED, $service->refresh($user));
        $this->assertSame(UserPhoto::ABSENT, UserPhoto::findOrFail($user->id)->status);
        $this->assertNull($service->version($user));

        $this->graph->photos[$oid] = ['bytes' => $this->jpeg(), 'etag' => '"v1"'];
        $service->refresh($user);
        $key = UserPhoto::findOrFail($user->id)->object_key;
        unset($this->graph->photos[$oid]);
        $this->assertSame(UserPhotoService::REMOVED, $service->refresh($user));
        Storage::disk('attachments_local')->assertMissing($key);
        $this->assertNull(UserPhoto::findOrFail($user->id)->object_key);
    }

    public function test_invalid_or_oversized_content_is_rejected_without_storing(): void
    {
        [$user, $oid] = $this->importedUser();
        $service = app(UserPhotoService::class);
        $this->graph->photos[$oid] = ['bytes' => 'isto não é uma imagem', 'etag' => '"bad"'];
        $this->assertSame(UserPhotoService::SKIPPED, $service->refresh($user));
        $this->assertSame('photo_invalid', UserPhoto::findOrFail($user->id)->error_code);
        $this->assertSame([], Storage::disk('attachments_local')->allFiles('user-photos'));

        config(['bpm.directory.photos.max_bytes' => 200]);
        $this->graph->photos[$oid] = ['bytes' => $this->jpeg(), 'etag' => '"big"'];
        $this->assertSame(UserPhotoService::SKIPPED, $service->refresh($user));
        $this->assertSame('photo_too_large', UserPhoto::findOrFail($user->id)->error_code);
        $this->assertSame([], Storage::disk('attachments_local')->allFiles('user-photos'));
    }

    public function test_batch_stops_on_throttling_and_only_picks_active_entra_users_of_the_sync_tenant(): void
    {
        [$user, $oid] = $this->importedUser();
        $user->forceFill(['last_seen_at' => now()])->save();
        $local = User::create(['name' => 'Local', 'email' => 'l@example.test', 'username' => 'local.x', 'auth_source' => User::SOURCE_LOCAL, 'is_active' => true, 'roles' => [], 'groups' => [], 'last_seen_at' => now()]);
        $this->graph->failures['/photo'] = [['status' => 429, 'headers' => ['Retry-After' => '900']]];

        $totals = app(UserPhotoService::class)->refreshBatch();
        $this->assertSame(1, $totals['errors']);
        $this->assertSame(0, $totals['updated']);
        $this->assertFalse(UserPhoto::where('user_id', $local->id)->exists());

        $this->graph->photos[$oid] = ['bytes' => $this->jpeg(), 'etag' => '"v1"'];
        $totals = app(UserPhotoService::class)->refreshBatch();
        $this->assertSame(1, $totals['updated']);
        $this->assertSame(0, app(UserPhotoService::class)->refreshBatch()['checked'], 'verificado há pouco: fora da próxima rodada');
    }

    public function test_preview_never_touches_photos_and_users_without_recent_activity_are_skipped(): void
    {
        $oid = $this->graph->user('1');
        $this->graph->members[FakeGraph::G1] = [$oid];
        $id = $this->as('admin')->postJson('/api/v1/admin/entra/sync/preview')->assertStatus(202)->json('id');
        app(DirectorySyncService::class)->execute($id);
        $this->assertSame(0, $this->photoCalls('/photo'));

        $this->importedUser();
        $this->assertSame(0, app(UserPhotoService::class)->refreshBatch()['checked'], 'nunca acessou o BPM: não busca foto');
    }

    public function test_login_refresh_is_only_queued_for_entra_users_when_enabled_and_stale(): void
    {
        Queue::fake();
        [$user] = $this->importedUser();
        $service = app(UserPhotoService::class);
        $this->assertTrue($service->queueRefresh($user));
        Queue::assertPushed(RefreshUserPhoto::class, fn ($job) => $job->userId === $user->id && $job->queue === 'directory');
        $this->assertFalse($service->queueRefresh($user), 'dedupe de 10 minutos');

        $local = User::create(['name' => 'Local', 'email' => 'l2@example.test', 'username' => 'local.y', 'auth_source' => User::SOURCE_LOCAL, 'is_active' => true, 'roles' => [], 'groups' => []]);
        $this->assertFalse($service->queueRefresh($local));

        $this->as('admin')->putJson('/api/v1/admin/settings/entra/directory-sync', ['photos' => ['enabled' => false]])->assertOk()->assertJsonPath('value.photos.enabled', false);
        \Cache::flush();
        $this->assertFalse($service->queueRefresh($user));
    }

    public function test_turning_the_feature_off_stops_serving_and_purges_stored_photos(): void
    {
        [$user, $oid] = $this->importedUser();
        $this->graph->photos[$oid] = ['bytes' => $this->jpeg(), 'etag' => '"v1"'];
        $service = app(UserPhotoService::class);
        $service->refresh($user);
        $key = UserPhoto::findOrFail($user->id)->object_key;
        $this->as('admin')->getJson('/api/v1/admin/managed-users/'.$user->id.'/photo')->assertOk();

        $this->as('admin')->putJson('/api/v1/admin/settings/entra/directory-sync', ['photos' => ['enabled' => false]])->assertOk();
        $this->as('admin')->getJson('/api/v1/admin/managed-users/'.$user->id.'/photo')->assertNotFound();
        $this->assertSame(1, $service->purgeUnneeded());
        Storage::disk('attachments_local')->assertMissing($key);

        $this->as('admin')->putJson('/api/v1/admin/settings/entra/directory-sync', ['photos' => ['enabled' => true]])->assertOk();
        $user->forceFill(['is_active' => false])->save();
        $service->refresh($user->fresh());
        $this->assertSame(UserPhotoService::SKIPPED, $service->refresh($user->fresh()), 'usuário inativo não é consultado');
    }

    public function test_me_photo_serves_only_the_own_photo_with_safe_headers_and_conditional_requests(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $bytes = 'RIFF....WEBPfake';
        Storage::disk('attachments_local')->put('user-photos/x/a.webp', $bytes);
        UserPhoto::create(['user_id' => $admin->id, 'disk' => 'attachments_local', 'object_key' => 'user-photos/x/a.webp', 'content_type' => 'image/webp', 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'status' => 'present', 'checked_at' => now()]);

        $me = $this->as('admin')->getJson('/api/v1/me')->assertOk()->assertJsonPath('photo.version', substr(hash('sha256', $bytes), 0, 12));
        $this->assertNotNull($me->json('photo'));
        $res = $this->as('admin')->get('/api/v1/me/photo')->assertOk();
        $this->assertSame($bytes, $res->getContent());
        $this->assertSame('image/webp', $res->headers->get('Content-Type'));
        $this->assertSame('nosniff', $res->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString("default-src 'none'", (string) $res->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString('private', (string) $res->headers->get('Cache-Control'));
        $etag = (string) $res->headers->get('ETag');
        $this->as('admin')->get('/api/v1/me/photo', ['If-None-Match' => $etag])->assertStatus(304);

        $this->as('cq')->getJson('/api/v1/me')->assertOk()->assertJsonPath('photo', null);
        $this->as('cq')->getJson('/api/v1/me/photo')->assertNotFound();
        $this->as('cq')->getJson('/api/v1/admin/managed-users/'.$admin->id.'/photo')->assertForbidden();
        $this->as('admin')->get('/api/v1/admin/managed-users/'.$admin->id.'/photo')->assertOk();
    }

    public function test_admin_refresh_is_authorized_audited_and_the_detail_exposes_photo_state(): void
    {
        Queue::fake();
        [$user, $oid] = $this->importedUser();
        $this->as('cq')->postJson('/api/v1/admin/managed-users/'.$user->id.'/photo/refresh')->assertForbidden();
        $this->as('admin')->postJson('/api/v1/admin/managed-users/'.$user->id.'/photo/refresh')->assertStatus(202)->assertJsonPath('queued', true);
        Queue::assertPushed(RefreshUserPhoto::class);
        $event = AuditEvent::where('action', 'directory.photo_refresh_requested')->firstOrFail();
        $this->assertSame($user->id, $event->entity_id);

        $local = User::create(['name' => 'Local', 'email' => 'l3@example.test', 'username' => 'local.z', 'auth_source' => User::SOURCE_LOCAL, 'is_active' => true, 'roles' => [], 'groups' => []]);
        $this->as('admin')->postJson('/api/v1/admin/managed-users/'.$local->id.'/photo/refresh')->assertStatus(422);

        $this->graph->photos[$oid] = ['bytes' => $this->jpeg(), 'etag' => '"v1"'];
        app(UserPhotoService::class)->refresh($user);
        $detail = $this->as('admin')->getJson('/api/v1/admin/managed-users/'.$user->id)->assertOk()->assertJsonPath('data.integration.entra.photo.status', 'present')->json('data');
        $this->assertNotNull($detail['identity']['photo']['version']);
        $row = collect($this->as('admin')->getJson('/api/v1/admin/managed-users?q='.urlencode($user->name))->json('data'))->firstWhere('id', $user->id);
        $this->assertSame($detail['identity']['photo']['version'], $row['photo']['version']);
        $this->assertStringNotContainsString(base64_encode($this->jpeg()), json_encode(AuditEvent::all()->toArray()));
    }

    public function test_mock_transport_serves_generated_photos_and_404_for_the_others(): void
    {
        $mock = new MockGraphClient;
        $with = '9a5d0000-0000-4000-8000-000000000001';
        $meta = $mock->get("users/$with/photo");
        $this->assertNotEmpty($meta['@odata.mediaEtag']);
        $file = $mock->getBinary("users/$with/photo/\$value", 1000000);
        $this->assertSame('image/jpeg', $file['contentType']);
        $this->assertNotFalse(getimagesizefromstring($file['bytes']));
        try {
            $mock->get('users/9a5d0000-0000-4000-8000-000000000002/photo');
            $this->fail('esperava not_found');
        } catch (GraphException $e) {
            $this->assertSame('not_found', $e->errorCode);
        }
    }
}
