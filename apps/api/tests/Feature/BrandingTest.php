<?php

namespace Tests\Feature;

use App\Branding\Contrast;
use App\Branding\IcoCodec;
use App\Models\AuditEvent;
use App\Models\BrandingAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BrandingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('attachments_local');
        $this->seedIdentity();
    }

    private function png(int $w, int $h, string $name = 'logo.png', string $trailer = ''): UploadedFile
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, (int) imagecolorallocate($img, 8, 107, 119));
        ob_start();
        imagepng($img);
        $bytes = (string) ob_get_clean().$trailer;

        return UploadedFile::fake()->createWithContent($name, $bytes);
    }

    private static function pngBytes(int $w, int $h): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, (int) imagecolorallocate($img, 238, 117, 63));
        ob_start();
        imagepng($img);

        return (string) ob_get_clean();
    }

    /** @param list<array{0:int,1:int,2:string}> $frames [largura, altura, payload] */
    private static function ico(array $frames, string $trailer = ''): string
    {
        $out = pack('vvv', 0, 1, count($frames));
        $offset = 6 + 16 * count($frames);
        $data = '';
        foreach ($frames as [$w, $h, $payload]) {
            $out .= pack('CCCCvvVV', $w % 256, $h % 256, 0, 0, 1, 32, strlen($payload), $offset);
            $offset += strlen($payload);
            $data .= $payload;
        }

        return $out.$data.$trailer;
    }

    /** DIB 32 bits (BGRA) bottom-up + máscara AND; canto superior esquerdo transparente. */
    private static function dib32(int $side): string
    {
        $hdr = pack('VllvvVVllVV', 40, $side, $side * 2, 1, 32, 0, 0, 0, 0, 0, 0);
        $px = '';
        for ($y = $side - 1; $y >= 0; $y--) {
            for ($x = 0; $x < $side; $x++) {
                $px .= $x === 0 && $y === 0 ? "\x00\x00\x00\x00" : "\x3f\x75\xee\xff"; // BGRA
            }
        }

        return $hdr.$px.str_repeat("\x00", intdiv($side + 31, 32) * 4 * $side);
    }

    /** DIB 8 bits com paleta de 2 cores; máscara AND torna a 1ª coluna transparente. */
    private static function dib8(int $side): string
    {
        $hdr = pack('VllvvVVllVV', 40, $side, $side * 2, 1, 8, 0, 0, 0, 0, 2, 0);
        $palette = "\x3f\x75\xee\x00"."\xff\xff\xff\x00";
        $row = intdiv(8 * $side + 31, 32) * 4;
        $xor = str_repeat(str_pad(str_repeat("\x00", $side), $row, "\x00"), $side);
        $andRow = intdiv($side + 31, 32) * 4;
        $and = str_repeat(str_pad("\x80", $andRow, "\x00"), $side); // bit mais alto = coluna 0

        return $hdr.$palette.$xor.$and;
    }

    public function test_favicon_accepts_ico_and_regenerates_a_clean_png_based_ico(): void
    {
        // ICO com frame PNG + bytes anexados (descartados)
        $res = $this->upload(UploadedFile::fake()->createWithContent('favicon.ico', self::ico([[32, 32, self::pngBytes(32, 32)]], '<script>alert(1)</script>')), 'favicon')->assertCreated();
        $asset = BrandingAsset::find($res->json('id'));
        $this->assertSame('image/x-icon', $asset->content_type);
        $this->assertStringEndsWith('.ico', $asset->object_key);
        $stored = (string) Storage::disk('attachments_local')->get($asset->object_key);
        $this->assertStringStartsWith("\x00\x00\x01\x00", $stored);
        $this->assertStringNotContainsString('<script>', $stored);
        $frames = IcoCodec::decode($stored);
        $this->assertSame([16, 32], array_map(fn ($f) => $f['width'], $frames), 'regravado com 16 e 32 px (sem ampliar)');

        // ICO com bitmap 32 bits (alfa) e 8 bits (paleta + máscara AND)
        foreach (['32bpp' => [48, self::dib32(48)], '8bpp' => [16, self::dib8(16)]] as $label => [$side, $dib]) {
            $res = $this->upload(UploadedFile::fake()->createWithContent("fav-$label.ico", self::ico([[$side, $side, $dib]])), 'favicon')->assertCreated();
            $out = IcoCodec::decode((string) Storage::disk('attachments_local')->get(BrandingAsset::find($res->json('id'))->object_key));
            $big = IcoCodec::largest($out);
            $this->assertSame($side, $big['width'], $label);
            $corner = imagecolorsforindex($big['image'], imagecolorat($big['image'], 0, 0));
            $this->assertSame(127, $corner['alpha'], "$label: transparência preservada");
            $center = imagecolorsforindex($big['image'], imagecolorat($big['image'], intdiv($side, 2), intdiv($side, 2)));
            $this->assertSame([238, 117, 63, 0], [$center['red'], $center['green'], $center['blue'], $center['alpha']], "$label: cor preservada");
        }

        // aplicado, o favicon .ico é servido pela aplicação com o tipo correto
        $this->as('admin')->putJson('/api/v1/admin/settings/branding', ['logos' => ['favicon' => $asset->id]])->assertOk();
        $url = (string) $this->getJson('/api/v1/public/branding')->json('assets.favicon');
        $this->get(parse_url($url, PHP_URL_PATH))->assertOk()->assertHeader('Content-Type', 'image/x-icon');
    }

    public function test_invalid_or_misplaced_ico_is_refused(): void
    {
        $valid = self::ico([[32, 32, self::pngBytes(32, 32)]]);
        $this->upload(UploadedFile::fake()->createWithContent('logo.ico', $valid), 'logo_main')->assertStatus(422)->assertJsonFragment(['Arquivo .ico só é aceito para o favicon.']);
        // offset/tamanho declarados além do fim do arquivo
        $broken = substr_replace($valid, pack('V', 999999), 6 + 8, 4);
        $this->upload(UploadedFile::fake()->createWithContent('quebrado.ico', $broken), 'favicon')->assertStatus(422);
        // não quadrado
        $this->upload(UploadedFile::fake()->createWithContent('largo.ico', self::ico([[48, 24, self::pngBytes(48, 24)]])), 'favicon')->assertStatus(422);
        // BMP comprimido (não suportado)
        $rle = substr_replace(self::dib8(16), pack('V', 1), 16, 4);
        $this->upload(UploadedFile::fake()->createWithContent('rle.ico', self::ico([[16, 16, $rle]])), 'favicon')->assertStatus(422);
        // extensão .ico com conteúdo de outro tipo
        $this->upload(UploadedFile::fake()->createWithContent('falso.ico', self::pngBytes(32, 32)), 'favicon')->assertStatus(422);
    }

    private function upload(UploadedFile $file, string $kind = 'logo_main')
    {
        return $this->as('admin')->post('/api/v1/admin/settings/branding/assets', ['kind' => $kind, 'file' => $file], ['Accept' => 'application/json']);
    }

    public function test_upload_rejects_unsafe_types_sizes_and_dimensions(): void
    {
        $svg = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $this->upload($svg)->assertStatus(422)->assertJsonFragment(['SVG não é aceito (conteúdo ativo). Envie PNG ou WebP.']);
        $this->upload(UploadedFile::fake()->createWithContent('logo.png', '<?php echo 1; ?>'))->assertStatus(422);
        $this->upload(UploadedFile::fake()->createWithContent('logo.png', str_repeat('x', 10)), 'outro')->assertStatus(422);
        $this->upload($this->png(10, 10))->assertStatus(422); // pequeno demais para logo principal
        $this->upload($this->png(300, 100), 'favicon')->assertStatus(422); // favicon precisa ser quadrado
        $this->upload($this->png(400, 120, 'big.png', str_repeat("\0", 600 * 1024)))->assertStatus(422); // acima do limite
        $this->as('cq')->post('/api/v1/admin/settings/branding/assets', ['kind' => 'logo_main', 'file' => $this->png(200, 60)], ['Accept' => 'application/json'])->assertForbidden();
    }

    public function test_valid_png_is_reencoded_stored_and_served_only_after_applied(): void
    {
        // bytes extras após o IEND (poliglota) são descartados pela recodificação
        $res = $this->upload($this->png(240, 64, 'logo.png', '<script>alert(1)</script>'))->assertCreated();
        $asset = BrandingAsset::find($res->json('id'));
        $stored = (string) Storage::disk('attachments_local')->get($asset->object_key);
        $this->assertStringStartsWith("\x89PNG", $stored);
        $this->assertStringNotContainsString('<script>', $stored);

        $this->get('/api/v1/public/branding/assets/'.$asset->id)->assertNotFound(); // rascunho não é público
        $this->as('admin')->get('/api/v1/admin/settings/branding/assets/'.$asset->id)->assertOk()->assertHeader('Content-Type', 'image/png');

        $this->as('admin')->putJson('/api/v1/admin/settings/branding', ['appName' => 'BPM Qualidade', 'orgName' => 'TMSA', 'logos' => ['main' => $asset->id]])->assertOk();
        $public = $this->getJson('/api/v1/public/branding')->assertOk()->assertJsonPath('appName', 'BPM Qualidade');
        $url = (string) $public->json('assets.main');
        $this->assertStringContainsString($asset->id, $url);
        $this->get(parse_url($url, PHP_URL_PATH))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Content-Security-Policy', "default-src 'none'");

        $event = AuditEvent::where('action', 'settings.updated')->latest('occurred_at')->first();
        $this->assertSame(['from' => 'BPM TMSA', 'to' => 'BPM Qualidade'], $event->diff['appName']);
        $this->assertTrue(AuditEvent::where('action', 'branding.asset_uploaded')->exists());
    }

    public function test_colors_without_wcag_contrast_are_refused_and_reset_restores_default(): void
    {
        // tom médio: nem texto branco nem escuro atingem 4,5:1
        $this->as('admin')->putJson('/api/v1/admin/settings/branding', ['colors' => ['primary' => '#808080']])->assertStatus(422)->assertJsonValidationErrors('colors.primary');
        $this->as('admin')->putJson('/api/v1/admin/settings/branding', ['colors' => ['accent' => '#dddddd']])->assertStatus(422)->assertJsonValidationErrors('colors.accent');
        $this->as('admin')->putJson('/api/v1/admin/settings/branding', ['colors' => ['primary' => 'red']])->assertStatus(422);
        $this->as('admin')->putJson('/api/v1/admin/settings/branding', ['colors' => ['primary' => '#1d4ed8', 'secondary' => '#1e3a8a', 'accent' => '#b45309']])->assertOk()
            ->assertJsonPath('value.colors.primary', '#1d4ed8')->assertJsonPath('value.contrast.0.ok', true);
        $this->getJson('/api/v1/public/branding')->assertJsonPath('colors.primary', '#1d4ed8')->assertJsonPath('derived.onPrimary', '#ffffff');

        // primária clara (#EE753F): aceita com texto escuro nos botões e tom derivado para links/títulos
        $this->as('admin')->putJson('/api/v1/admin/settings/branding', ['colors' => ['primary' => '#EE753F']])->assertOk()
            ->assertJsonPath('value.colors.primary', '#ee753f')
            ->assertJsonPath('value.derived.onPrimary', Contrast::INK)
            ->assertJsonPath('value.derived.primaryText', '#ab542d');
        $this->assertGreaterThanOrEqual(4.5, Contrast::ratio('#ee753f', Contrast::INK));
        $this->assertGreaterThanOrEqual(4.5, Contrast::ratio('#ab542d', '#ffffff'));
        $this->getJson('/api/v1/public/branding')->assertJsonPath('derived.onPrimary', Contrast::INK);

        $this->as('admin')->postJson('/api/v1/admin/settings/branding/reset')->assertOk()->assertJsonPath('value.colors.primary', '#086b77');
        $this->assertTrue(AuditEvent::where('action', 'branding.reset')->exists());
        $this->assertSame(21.0, Contrast::ratio('#000000', '#ffffff'));
        $this->assertGreaterThan(4.5, Contrast::ratio('#086b77', '#ffffff'));
    }
}
