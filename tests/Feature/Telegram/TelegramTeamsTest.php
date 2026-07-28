<?php

use App\Jobs\ProcessTelegramUpdate;
use App\Jobs\SendTelegramTeamMentions;
use App\Models\TelegramTeam;
use App\Models\TelegramTeamCategory;
use App\Models\TelegramTeamMember;
use Illuminate\Support\Facades\Queue;
use Telegram\Bot\Api;
use Tests\Fakes\FakeTelegramApi;

/**
 * A ProcessTelegramUpdate whose handlers talk to an injected fake Api, so the
 * team tests can observe what the bot would send without hitting Telegram.
 */
class TeamsRecordingProcessTelegramUpdate extends ProcessTelegramUpdate
{
    public function __construct(array $updateData, public FakeTelegramApi $fake)
    {
        parent::__construct($updateData);
    }

    protected function makeTelegram(): Api
    {
        return $this->fake;
    }
}

/**
 * @param  array<int|string, string>  $chatMemberStatuses
 */
function runTeamsUpdate(array $updateData, array $chatMemberStatuses = []): FakeTelegramApi
{
    $fake = new FakeTelegramApi;
    $fake->chatMemberStatuses = $chatMemberStatuses;
    (new TeamsRecordingProcessTelegramUpdate($updateData, $fake))->handle();

    return $fake;
}

const TEAMS_CHAT_ID = -100500;
const TEAMS_ADMIN_ID = 111;
const TEAMS_MEMBER_ID = 222;

function teamsGroupMessage(string $text, array $overrides = []): array
{
    return [
        'update_id' => random_int(1_000, 9_999_999),
        'message' => array_replace([
            'message_id' => random_int(1, 999_999),
            'date' => now()->getTimestamp(),
            'from' => ['id' => TEAMS_ADMIN_ID, 'is_bot' => false, 'first_name' => 'سعد'],
            'chat' => ['id' => TEAMS_CHAT_ID, 'type' => 'supergroup', 'title' => 'كلية الحاسبات'],
            'text' => $text,
        ], $overrides),
    ];
}

function teamsConsentReply(string $adminText, array $consentOverrides = []): array
{
    return teamsGroupMessage($adminText, [
        'reply_to_message' => array_replace([
            'message_id' => 77,
            'date' => now()->getTimestamp(),
            'from' => ['id' => TEAMS_MEMBER_ID, 'is_bot' => false, 'first_name' => 'سارة', 'username' => 'sara'],
            'chat' => ['id' => TEAMS_CHAT_ID, 'type' => 'supergroup', 'title' => 'كلية الحاسبات'],
            'text' => 'انضم العابدية',
        ], $consentOverrides),
    ]);
}

function makeTeam(string $name = 'العابدية'): TelegramTeam
{
    return TelegramTeam::factory()->create(['chat_id' => TEAMS_CHAT_ID, 'name' => $name]);
}

describe('help', function () {
    it('documents the member commands and the group-admin commands', function () {
        $fake = runTeamsUpdate(teamsGroupMessage('/help'));

        expect($fake->allTexts()[0])->toContain('👥 الفرق (في المجموعات):')
            ->and($fake->allTexts()[0])->toContain('منشن فريق')
            ->and($fake->allTexts()[0])->toContain('🛡️ إدارة الفرق (لمشرفي المجموعة):')
            ->and($fake->allTexts()[0])->toContain('حذف تصنيف');
    });
});

describe('team administration', function () {
    it('lets a group admin create a team', function () {
        $fake = runTeamsUpdate(teamsGroupMessage('فريق جديد العابدية'), [TEAMS_ADMIN_ID => 'administrator']);

        expect($fake->allTexts())->toContain('✅ تم إنشاء فريق «العابدية».'."\n".'ينضم له الأعضاء بإرسال: انضم العابدية')
            ->and(TelegramTeam::findByName(TEAMS_CHAT_ID, 'العابدية'))->not->toBeNull();
    });

    it('refuses team creation from a regular member', function () {
        $fake = runTeamsUpdate(teamsGroupMessage('فريق جديد العابدية'));

        expect($fake->allTexts())->toContain('هذا الأمر متاح لمشرفي المجموعة فقط. 🔒')
            ->and(TelegramTeam::query()->count())->toBe(0);
    });

    it('refuses team commands in a private chat', function () {
        $fake = runTeamsUpdate(teamsGroupMessage('فريق جديد العابدية', [
            'chat' => ['id' => TEAMS_ADMIN_ID, 'type' => 'private', 'first_name' => 'سعد'],
        ]), [TEAMS_ADMIN_ID => 'administrator']);

        expect($fake->allTexts())->toContain('أوامر الفرق تعمل داخل المجموعات فقط.');
    });

    it('creates a team inside an existing category', function () {
        TelegramTeamCategory::factory()->create(['chat_id' => TEAMS_CHAT_ID, 'name' => 'الفرع']);

        runTeamsUpdate(teamsGroupMessage('فريق جديد العابدية ضمن الفرع'), [TEAMS_ADMIN_ID => 'administrator']);

        expect(TelegramTeam::findByName(TEAMS_CHAT_ID, 'العابدية')?->category?->name)->toBe('الفرع');
    });

    it('rejects a team inside a category that does not exist', function () {
        $fake = runTeamsUpdate(teamsGroupMessage('فريق جديد العابدية ضمن الفرع'), [TEAMS_ADMIN_ID => 'administrator']);

        expect($fake->allTexts())->toContain('تصنيف «الفرع» غير موجود. أنشئه أولًا بالأمر: تصنيف جديد الفرع')
            ->and(TelegramTeam::query()->count())->toBe(0);
    });

    it('creates a category and recategorizes a team', function () {
        makeTeam();

        runTeamsUpdate(teamsGroupMessage('تصنيف جديد الفرع'), [TEAMS_ADMIN_ID => 'administrator']);
        runTeamsUpdate(teamsGroupMessage('صنف العابدية ضمن الفرع'), [TEAMS_ADMIN_ID => 'administrator']);

        expect(TelegramTeam::findByName(TEAMS_CHAT_ID, 'العابدية')?->category?->name)->toBe('الفرع');
    });

    it('deletes a category without touching its teams', function () {
        $category = TelegramTeamCategory::factory()->create(['chat_id' => TEAMS_CHAT_ID, 'name' => 'الفرع']);
        $team = makeTeam();
        $team->update(['category_id' => $category->id]);

        $fake = runTeamsUpdate(teamsGroupMessage('حذف تصنيف الفرع'), [TEAMS_ADMIN_ID => 'administrator']);

        expect($fake->allTexts())->toContain('✅ تم حذف تصنيف «الفرع». أصبحت فرقه (1) بدون تصنيف.')
            ->and($team->refresh()->category_id)->toBeNull();
    });

    it('asks for confirmation before deleting a team, then deletes on the admin button press', function () {
        $team = makeTeam();
        TelegramTeamMember::factory()->for($team, 'team')->create();

        $fake = runTeamsUpdate(teamsGroupMessage('حذف فريق العابدية'), [TEAMS_ADMIN_ID => 'administrator']);

        expect($fake->sentMessages)->toHaveCount(1)
            ->and($fake->sentMessages[0]['text'])->toContain('سيزيل عضوياته (1)')
            ->and($fake->sentMessages[0])->toHaveKey('reply_markup');

        $callbackFake = runTeamsUpdate([
            'update_id' => 999,
            'callback_query' => [
                'id' => 'cb1',
                'from' => ['id' => TEAMS_ADMIN_ID, 'is_bot' => false, 'first_name' => 'سعد'],
                'message' => ['message_id' => 10, 'chat' => ['id' => TEAMS_CHAT_ID, 'type' => 'supergroup']],
                'data' => 'tgteam:del:'.$team->id,
            ],
        ], [TEAMS_ADMIN_ID => 'administrator']);

        expect($callbackFake->editedMessages[0]['text'])->toContain('تم حذف فريق «العابدية»')
            ->and(TelegramTeam::query()->find($team->id))->toBeNull()
            ->and(TelegramTeamMember::query()->count())->toBe(0);
    });

    it('blocks the delete button for non-admin pressers', function () {
        $team = makeTeam();

        $fake = runTeamsUpdate([
            'update_id' => 998,
            'callback_query' => [
                'id' => 'cb2',
                'from' => ['id' => TEAMS_MEMBER_ID, 'is_bot' => false, 'first_name' => 'سارة'],
                'message' => ['message_id' => 10, 'chat' => ['id' => TEAMS_CHAT_ID, 'type' => 'supergroup']],
                'data' => 'tgteam:del:'.$team->id,
            ],
        ]);

        expect($fake->answeredCallbacks[0]['text'])->toContain('لمشرفي المجموعة فقط')
            ->and(TelegramTeam::query()->find($team->id))->not->toBeNull();
    });
});

describe('join and approval', function () {
    it('acknowledges a join request and explains the approval step', function () {
        makeTeam();

        $fake = runTeamsUpdate(teamsGroupMessage('انضم العابدية', [
            'from' => ['id' => TEAMS_MEMBER_ID, 'is_bot' => false, 'first_name' => 'سارة'],
        ]));

        expect($fake->allTexts()[0])->toContain('تم استلام طلبك يا سارة')
            ->and($fake->allTexts()[0])->toContain('«أضف»')
            ->and(TelegramTeamMember::query()->count())->toBe(0);
    });

    it('rejects a join request for teams that do not exist', function () {
        $fake = runTeamsUpdate(teamsGroupMessage('انضم العابدية'));

        expect($fake->allTexts()[0])->toContain('الفرق التالية غير موجودة: العابدية');
    });

    it('adds the member when an admin replies «أضف» to the consent message', function () {
        $team = makeTeam();

        $fake = runTeamsUpdate(teamsConsentReply('أضف'), [TEAMS_ADMIN_ID => 'administrator']);

        $member = TelegramTeamMember::query()->where('team_id', $team->id)->first();

        expect($member)->not->toBeNull()
            ->and($member->telegram_user_id)->toBe(TEAMS_MEMBER_ID)
            ->and($member->first_name)->toBe('سارة')
            ->and($member->consent_message_id)->toBe(77)
            ->and($member->added_by_telegram_id)->toBe(TEAMS_ADMIN_ID)
            ->and($fake->allTexts()[0])->toContain('tg://user?id='.TEAMS_MEMBER_ID);
    });

    it('refuses approval from a non-admin', function () {
        makeTeam();

        $fake = runTeamsUpdate(teamsConsentReply('أضف'));

        expect($fake->allTexts())->toContain('هذا الأمر متاح لمشرفي المجموعة فقط. 🔒')
            ->and(TelegramTeamMember::query()->count())->toBe(0);
    });

    it('rejects a consent message older than 24 hours', function () {
        makeTeam();

        $fake = runTeamsUpdate(teamsConsentReply('أضف', [
            'date' => now()->subHours(25)->getTimestamp(),
        ]), [TEAMS_ADMIN_ID => 'administrator']);

        expect($fake->allTexts()[0])->toContain('⌛ انتهت صلاحية رسالة الموافقة')
            ->and(TelegramTeamMember::query()->count())->toBe(0);
    });

    it('lets the admin approve a subset, but never teams outside the consent', function () {
        makeTeam('العابدية');
        makeTeam('علوم حاسب');
        makeTeam('1448');

        runTeamsUpdate(teamsConsentReply('أضف العابدية', [
            'text' => 'انضم العابدية، علوم حاسب',
        ]), [TEAMS_ADMIN_ID => 'administrator']);

        expect(TelegramTeamMember::query()->count())->toBe(1);

        $fake = runTeamsUpdate(teamsConsentReply('أضف 1448', [
            'text' => 'انضم العابدية، علوم حاسب',
        ]), [TEAMS_ADMIN_ID => 'administrator']);

        expect($fake->allTexts()[0])->toContain('هذه الفرق ليست في رسالة الموافقة: 1448')
            ->and(TelegramTeamMember::query()->count())->toBe(1);
    });

    it('hints when «أضف» is replied to a message that is not a consent', function () {
        $fake = runTeamsUpdate(teamsConsentReply('أضف', ['text' => 'صباح الخير']), [TEAMS_ADMIN_ID => 'administrator']);

        expect($fake->allTexts()[0])->toContain('رد بكلمة «أضف» على رسالة «انضم …»');
    });
});

describe('opt-out and removal', function () {
    it('lets a member opt out and blocks re-approving the old consent message', function () {
        makeTeam();

        $consentDate = now()->subMinutes(5)->getTimestamp();

        runTeamsUpdate(teamsConsentReply('أضف', ['date' => $consentDate]), [TEAMS_ADMIN_ID => 'administrator']);
        expect(TelegramTeamMember::query()->count())->toBe(1);

        $leaveFake = runTeamsUpdate(teamsGroupMessage('انسحب العابدية', [
            'from' => ['id' => TEAMS_MEMBER_ID, 'is_bot' => false, 'first_name' => 'سارة'],
        ]));

        expect($leaveFake->allTexts()[0])->toContain('✅ تم انسحابك من: العابدية')
            ->and(TelegramTeamMember::query()->count())->toBe(0);

        $replayFake = runTeamsUpdate(teamsConsentReply('أضف', ['date' => $consentDate]), [TEAMS_ADMIN_ID => 'administrator']);

        expect($replayFake->allTexts()[0])->toContain('انسحب العضو بعد هذا الطلب')
            ->and(TelegramTeamMember::query()->count())->toBe(0);
    });

    it('opts a member out of everything with «انسحب من الكل»', function () {
        $abidiyah = makeTeam('العابدية');
        $cs = makeTeam('علوم حاسب');
        TelegramTeamMember::factory()->for($abidiyah, 'team')->create(['telegram_user_id' => TEAMS_MEMBER_ID]);
        TelegramTeamMember::factory()->for($cs, 'team')->create(['telegram_user_id' => TEAMS_MEMBER_ID]);

        $fake = runTeamsUpdate(teamsGroupMessage('انسحب من الكل', [
            'from' => ['id' => TEAMS_MEMBER_ID, 'is_bot' => false, 'first_name' => 'سارة'],
        ]));

        expect($fake->allTexts()[0])->toContain('تم انسحابك من كل الفرق')
            ->and(TelegramTeamMember::query()->count())->toBe(0);
    });

    it('lets an admin remove a member by replying «أزل» to their message', function () {
        $team = makeTeam();
        TelegramTeamMember::factory()->for($team, 'team')->create(['telegram_user_id' => TEAMS_MEMBER_ID, 'first_name' => 'سارة']);

        $fake = runTeamsUpdate(teamsConsentReply('أزل العابدية', ['text' => 'أي رسالة عادية']), [TEAMS_ADMIN_ID => 'administrator']);

        expect($fake->allTexts()[0])->toContain('تمت إزالة سارة من: العابدية')
            ->and(TelegramTeamMember::query()->count())->toBe(0);
    });

    it('refuses «أزل» from a non-admin', function () {
        $team = makeTeam();
        TelegramTeamMember::factory()->for($team, 'team')->create(['telegram_user_id' => TEAMS_MEMBER_ID]);

        $fake = runTeamsUpdate(teamsConsentReply('أزل العابدية', ['text' => 'أي رسالة عادية']));

        expect($fake->allTexts())->toContain('هذا الأمر متاح لمشرفي المجموعة فقط. 🔒')
            ->and(TelegramTeamMember::query()->count())->toBe(1);
    });
});

describe('listing', function () {
    it('lists teams grouped by category with member counts', function () {
        $category = TelegramTeamCategory::factory()->create(['chat_id' => TEAMS_CHAT_ID, 'name' => 'الفرع']);
        $abidiyah = makeTeam('العابدية');
        $abidiyah->update(['category_id' => $category->id]);
        makeTeam('لجنة التصوير');
        TelegramTeamMember::factory()->for($abidiyah, 'team')->count(2)->create();

        $fake = runTeamsUpdate(teamsGroupMessage('الفرق'));

        expect($fake->allTexts()[0])->toContain('<b>الفرع</b> — العابدية (2)')
            ->and($fake->allTexts()[0])->toContain('<b>بدون تصنيف</b> — لجنة التصوير (0)');
    });

    it('teaches how to start when there are no teams', function () {
        $fake = runTeamsUpdate(teamsGroupMessage('الفرق'));

        expect($fake->allTexts()[0])->toContain('ينشئها مشرفو المجموعة بالأمر: فريق جديد اسم الفريق');
    });

    it('lists a team\'s members without pinging them', function () {
        $team = makeTeam();
        TelegramTeamMember::factory()->for($team, 'team')->create(['first_name' => 'سارة', 'username' => 'sara']);

        $fake = runTeamsUpdate(teamsGroupMessage('أعضاء العابدية'));

        expect($fake->allTexts()[0])->toContain('سارة')
            ->and($fake->allTexts()[0])->not->toContain('@sara')
            ->and($fake->allTexts()[0])->not->toContain('tg://user');
    });

    it('shows the sender their own teams with «فرقي»', function () {
        $team = makeTeam();
        TelegramTeamMember::factory()->for($team, 'team')->create(['telegram_user_id' => TEAMS_MEMBER_ID]);

        $fake = runTeamsUpdate(teamsGroupMessage('فرقي', [
            'from' => ['id' => TEAMS_MEMBER_ID, 'is_bot' => false, 'first_name' => 'سارة'],
        ]));

        expect($fake->allTexts()[0])->toContain('👤 فرقك: العابدية');
    });
});

describe('mention blast', function () {
    it('mentions every team member with clickable tg://user links', function () {
        $team = makeTeam();
        TelegramTeamMember::factory()->for($team, 'team')->create(['telegram_user_id' => 501, 'first_name' => 'سارة']);
        TelegramTeamMember::factory()->for($team, 'team')->create(['telegram_user_id' => 502, 'first_name' => 'خالد']);

        $fake = runTeamsUpdate(teamsGroupMessage('منشن فريق العابدية'));

        expect($fake->allTexts()[0])->toContain('📣 نداء لأعضاء فريق «العابدية» (2):')
            ->and($fake->allTexts()[0])->toContain('<a href="tg://user?id=501">سارة</a>')
            ->and($fake->allTexts()[0])->toContain('<a href="tg://user?id=502">خالد</a>');
    });

    it('treats multiple teams as an intersection', function () {
        $abidiyah = makeTeam('العابدية');
        $cs = makeTeam('علوم حاسب');
        TelegramTeamMember::factory()->for($abidiyah, 'team')->create(['telegram_user_id' => 501, 'first_name' => 'سارة']);
        TelegramTeamMember::factory()->for($cs, 'team')->create(['telegram_user_id' => 501, 'first_name' => 'سارة']);
        TelegramTeamMember::factory()->for($abidiyah, 'team')->create(['telegram_user_id' => 502, 'first_name' => 'خالد']);

        $fake = runTeamsUpdate(teamsGroupMessage('منشن فرق العابدية + علوم حاسب'));

        expect($fake->allTexts()[0])->toContain('(1):')
            ->and($fake->allTexts()[0])->toContain('tg://user?id=501')
            ->and($fake->allTexts()[0])->not->toContain('tg://user?id=502');
    });

    it('stays silent when the command is buried inside a sentence', function () {
        $team = makeTeam();
        TelegramTeamMember::factory()->for($team, 'team')->create();

        $fake = runTeamsUpdate(teamsGroupMessage('أحد يساعدني؟ منشن فريق العابدية'));

        expect($fake->sentMessages)->toBeEmpty();
    });

    it('replies gently when nobody matches', function () {
        makeTeam();

        $fake = runTeamsUpdate(teamsGroupMessage('منشن فريق العابدية'));

        expect($fake->allTexts()[0])->toContain('لا يوجد أعضاء مطابقون لهذا النداء حاليًا.');
    });

    it('cools down repeated blasts of the same team and notifies only once', function () {
        $team = makeTeam();
        TelegramTeamMember::factory()->for($team, 'team')->create();

        runTeamsUpdate(teamsGroupMessage('منشن فريق العابدية'));

        $second = runTeamsUpdate(teamsGroupMessage('منشن فريق العابدية'));
        expect($second->allTexts()[0])->toContain('⏳ تم النداء على «العابدية» قبل قليل.');

        $third = runTeamsUpdate(teamsGroupMessage('منشن فريق العابدية'));
        expect($third->sentMessages)->toBeEmpty();
    });

    it('sends the first batch inline and queues the rest', function () {
        Queue::fake();

        $team = makeTeam();
        TelegramTeamMember::factory()->for($team, 'team')->count(20)->create();

        $fake = runTeamsUpdate(teamsGroupMessage('منشن فريق العابدية'));

        expect(substr_count($fake->allTexts()[0], 'tg://user'))->toBe(15);

        Queue::assertPushed(SendTelegramTeamMentions::class, function (SendTelegramTeamMentions $job): bool {
            return count($job->htmlChunks) === 1
                && substr_count($job->htmlChunks[0], 'tg://user') === 5;
        });
    });
});
