<?php

use App\Models\TelegramTeam;
use App\Models\TelegramTeamAlias;
use App\Models\TelegramTeamCategory;
use App\Models\TelegramTeamMember;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
});

describe('authorization', function () {
    it('redirects guests to the login page', function () {
        $this->get('/manage/telegram-teams')->assertRedirect(route('manage.login'));
    });

    it('returns 403 for users without a panel role', function () {
        $this->actingAs(User::factory()->create());

        $this->get('/manage/telegram-teams')->assertForbidden();
    });

    it('allows editors to open the page (any panel user, parity with the Telegram chats resource)', function () {
        $editor = User::factory()->create();
        $editor->assignRole('editor');

        $this->actingAs($editor)->get('/manage/telegram-teams')->assertOk();
    });
});

describe('index', function () {
    it('groups teams by chat with counts', function () {
        $team = TelegramTeam::factory()->create(['chat_id' => -100500, 'name' => 'العابدية']);
        TelegramTeam::factory()->create(['chat_id' => -100500, 'name' => 'علوم حاسب']);
        TelegramTeamMember::factory()->for($team, 'team')->count(2)->create();

        $this->actingAs($this->admin)
            ->get('/manage/telegram-teams')
            ->assertInertia(fn (Assert $page) => $page
                ->component('manage/telegram-teams/Index')
                ->count('chats', 1)
                ->where('chats.0.chat_id', '-100500')
                ->where('chats.0.teams_count', 2)
                ->where('chats.0.members_count', 2)
            );
    });
});

describe('show', function () {
    it('shows a chat\'s categories, teams and members', function () {
        $category = TelegramTeamCategory::factory()->create(['chat_id' => -100500, 'name' => 'الفرع']);
        $team = TelegramTeam::factory()->create(['chat_id' => -100500, 'name' => 'العابدية', 'category_id' => $category->id]);
        TelegramTeamMember::factory()->for($team, 'team')->create(['first_name' => 'سارة']);

        $this->actingAs($this->admin)
            ->get('/manage/telegram-teams/-100500')
            ->assertInertia(fn (Assert $page) => $page
                ->component('manage/telegram-teams/Show')
                ->where('chat.chat_id', '-100500')
                ->count('categories', 1)
                ->where('teams.0.name', 'العابدية')
                ->where('teams.0.members.0.name', 'سارة')
            );
    });

    it('returns 404 for a chat with no teams or categories', function () {
        $this->actingAs($this->admin)->get('/manage/telegram-teams/-42')->assertNotFound();
    });
});

describe('teams', function () {
    it('creates a team in a chat', function () {
        $this->actingAs($this->admin)
            ->post('/manage/telegram-teams/-100500/teams', ['name' => 'العابدية'])
            ->assertSessionHasNoErrors();

        expect(TelegramTeam::findByName(-100500, 'العابدية'))->not->toBeNull();
    });

    it('rejects a duplicate team name in the same chat with an Arabic message', function () {
        TelegramTeam::factory()->create(['chat_id' => -100500, 'name' => 'العابدية']);

        $this->actingAs($this->admin)
            ->post('/manage/telegram-teams/-100500/teams', ['name' => 'العابدية'])
            ->assertSessionHasErrors(['name' => 'هذا الاسم مستخدم بالفعل كفريق أو اختصار في هذه المجموعة.']);
    });

    it('allows the same team name in a different chat', function () {
        TelegramTeam::factory()->create(['chat_id' => -100500, 'name' => 'العابدية']);

        $this->actingAs($this->admin)
            ->post('/manage/telegram-teams/-100999/teams', ['name' => 'العابدية'])
            ->assertSessionHasNoErrors();
    });

    it('renames a team and moves it between categories', function () {
        $team = TelegramTeam::factory()->create(['chat_id' => -100500, 'name' => 'العابدية']);
        $category = TelegramTeamCategory::factory()->create(['chat_id' => -100500, 'name' => 'الفرع']);

        $this->actingAs($this->admin)
            ->put("/manage/telegram-teams/teams/{$team->id}", ['name' => 'الزاهر', 'category_id' => $category->id])
            ->assertSessionHasNoErrors();

        expect($team->refresh()->name)->toBe('الزاهر')
            ->and($team->category_id)->toBe($category->id);
    });

    it('rejects moving a team into another chat\'s category', function () {
        $team = TelegramTeam::factory()->create(['chat_id' => -100500]);
        $foreignCategory = TelegramTeamCategory::factory()->create(['chat_id' => -100999]);

        $this->actingAs($this->admin)
            ->put("/manage/telegram-teams/teams/{$team->id}", ['category_id' => $foreignCategory->id])
            ->assertSessionHasErrors(['category_id' => 'التصنيف غير موجود في هذه المجموعة.']);
    });

    it('deletes a team with its memberships', function () {
        $team = TelegramTeam::factory()->create();
        TelegramTeamMember::factory()->for($team, 'team')->create();

        $this->actingAs($this->admin)
            ->delete("/manage/telegram-teams/teams/{$team->id}")
            ->assertSessionHas('success');

        expect(TelegramTeam::query()->find($team->id))->toBeNull()
            ->and(TelegramTeamMember::query()->count())->toBe(0);
    });
});

describe('categories', function () {
    it('creates and renames a category', function () {
        $this->actingAs($this->admin)
            ->post('/manage/telegram-teams/-100500/categories', ['name' => 'الفرع'])
            ->assertSessionHasNoErrors();

        $category = TelegramTeamCategory::findByName(-100500, 'الفرع');
        expect($category)->not->toBeNull();

        $this->actingAs($this->admin)
            ->put("/manage/telegram-teams/categories/{$category->id}", ['name' => 'التخصص'])
            ->assertSessionHasNoErrors();

        expect($category->refresh()->name)->toBe('التخصص');
    });

    it('deletes a category and leaves its teams uncategorized', function () {
        $category = TelegramTeamCategory::factory()->create();
        $team = TelegramTeam::factory()->create(['chat_id' => $category->chat_id, 'category_id' => $category->id]);

        $this->actingAs($this->admin)
            ->delete("/manage/telegram-teams/categories/{$category->id}")
            ->assertSessionHas('success');

        expect(TelegramTeamCategory::query()->find($category->id))->toBeNull()
            ->and($team->refresh()->category_id)->toBeNull();
    });
});

describe('aliases', function () {
    it('adds an alias to a team', function () {
        $team = TelegramTeam::factory()->create(['chat_id' => -100500, 'name' => 'علوم الحاسب']);

        $this->actingAs($this->admin)
            ->post("/manage/telegram-teams/teams/{$team->id}/aliases", ['name' => 'cs'])
            ->assertSessionHas('success');

        expect(TelegramTeam::findByName(-100500, 'cs')?->id)->toBe($team->id);
    });

    it('rejects an alias already claimed by a team or another alias', function () {
        $cs = TelegramTeam::factory()->create(['chat_id' => -100500, 'name' => 'علوم الحاسب']);
        $se = TelegramTeam::factory()->create(['chat_id' => -100500, 'name' => 'هندسة البرمجيات']);
        TelegramTeamAlias::factory()->forTeam($cs)->create(['name' => 'cs']);

        $this->actingAs($this->admin)
            ->post("/manage/telegram-teams/teams/{$se->id}/aliases", ['name' => 'CS'])
            ->assertSessionHasErrors(['name' => 'هذا الاسم مستخدم بالفعل كفريق أو اختصار في هذه المجموعة.']);

        $this->actingAs($this->admin)
            ->post("/manage/telegram-teams/teams/{$se->id}/aliases", ['name' => 'علوم الحاسب'])
            ->assertSessionHasErrors('name');
    });

    it('deletes an alias', function () {
        $alias = TelegramTeamAlias::factory()->create();

        $this->actingAs($this->admin)
            ->delete("/manage/telegram-teams/aliases/{$alias->id}")
            ->assertSessionHas('success');

        expect(TelegramTeamAlias::query()->find($alias->id))->toBeNull();
    });

    it('rejects a team name that collides with an existing alias', function () {
        $team = TelegramTeam::factory()->create(['chat_id' => -100500, 'name' => 'علوم الحاسب']);
        TelegramTeamAlias::factory()->forTeam($team)->create(['name' => 'cs']);

        $this->actingAs($this->admin)
            ->post('/manage/telegram-teams/-100500/teams', ['name' => 'cs'])
            ->assertSessionHasErrors('name');
    });

    it('exposes a team\'s aliases to the page', function () {
        $team = TelegramTeam::factory()->create(['chat_id' => -100500, 'name' => 'علوم الحاسب']);
        TelegramTeamAlias::factory()->forTeam($team)->create(['name' => 'cs']);

        $this->actingAs($this->admin)
            ->get('/manage/telegram-teams/-100500')
            ->assertInertia(fn (Assert $page) => $page
                ->component('manage/telegram-teams/Show')
                ->where('teams.0.aliases.0.name', 'cs')
                ->etc()
            );
    });
});

describe('members', function () {
    it('removes a membership', function () {
        $member = TelegramTeamMember::factory()->create();

        $this->actingAs($this->admin)
            ->delete("/manage/telegram-teams/members/{$member->id}")
            ->assertSessionHas('success');

        expect(TelegramTeamMember::query()->find($member->id))->toBeNull();
    });
});
