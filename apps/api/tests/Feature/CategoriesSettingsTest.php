<?php

namespace Tests\Feature;

use App\Settings\Types\CategoriesSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoriesSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/admin/settings/categories';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedIdentity();
    }

    public function test_default_categories_include_fallback_and_are_exposed_in_meta(): void
    {
        $this->as('cq')->getJson('/api/v1/meta')->assertOk()->assertJsonPath('categories', ['Qualidade', 'Compras', 'Operações', 'Financeiro', 'Outros']);
    }

    public function test_admin_can_add_a_new_category(): void
    {
        $this->as('admin')->putJson(self::URL, ['items' => ['Qualidade', 'Compras', 'Operações', 'Financeiro', 'Logística']])
            ->assertOk()->assertJsonPath('value.items', ['Qualidade', 'Compras', 'Operações', 'Financeiro', 'Logística', CategoriesSettings::FALLBACK]);

        $this->as('cq')->getJson('/api/v1/meta')->assertOk()->assertJsonPath('categories', ['Qualidade', 'Compras', 'Operações', 'Financeiro', 'Logística', 'Outros']);
    }

    public function test_rejects_empty_list(): void
    {
        $this->as('admin')->putJson(self::URL, ['items' => []])->assertStatus(422);
    }

    public function test_requires_settings_manage_permission(): void
    {
        $this->as('cq')->putJson(self::URL, ['items' => ['Logística']])->assertForbidden();
    }

    public function test_deduplicates_and_keeps_fallback_fixed(): void
    {
        $this->as('admin')->putJson(self::URL, ['items' => ['Logística', 'logística', 'Outros']])
            ->assertOk()->assertJsonPath('value.items', ['Logística', CategoriesSettings::FALLBACK]);
    }
}
