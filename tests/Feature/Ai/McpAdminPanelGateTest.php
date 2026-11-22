<?php

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Laravel\Passport\Passport;

use function Pest\Laravel\postJson;

/*
 * /mcp/admin runs the same panel gate as /manage (`manage.access`): a valid
 * Passport token is not enough on its own.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function panelGateRpc(): array
{
    return [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => ['name' => 'list_pages', 'arguments' => []],
    ];
}

it('refuses a valid token whose account has no panel access', function () {
    Passport::actingAs(User::factory()->create());

    postJson('/mcp/admin', panelGateRpc())->assertForbidden();
});

it('still serves an editor through the panel gate', function () {
    $editor = User::factory()->create();
    $editor->assignRole('editor');
    Passport::actingAs($editor);

    postJson('/mcp/admin', panelGateRpc())->assertOk();
});
