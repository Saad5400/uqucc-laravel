<?php

use App\Jobs\DeleteTelegramMessages;
use App\Jobs\ProcessTelegramUpdate;
use App\Models\TelegramTeam;
use App\Models\TelegramTeamCategory;
use App\Models\TelegramTeamMember;
use App\Services\Telegram\Handlers\TeamJoinPickerHandler;
use Illuminate\Support\Facades\Bus;
use Telegram\Bot\Api;
use Tests\Fakes\FakeTelegramApi;

/**
 * A ProcessTelegramUpdate wired to a fake Api, so the picker can be driven the
 * way Telegram drives it — a message in, then button presses as callback
 * queries — including the routing that sends «tgjoin:» presses here.
 */
class PickerRecordingProcessTelegramUpdate extends ProcessTelegramUpdate
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

const PICKER_CHAT_ID = -100700800;

const PICKER_MEMBER_ID = 222;

const PICKER_OTHER_ID = 333;

const PICKER_MESSAGE_ID = 555;

beforeEach(fn () => Bus::fake());

/**
 * @param  array<int|string, string>  $chatMemberStatuses
 */
function runPickerUpdate(array $updateData, array $chatMemberStatuses = []): FakeTelegramApi
{
    $fake = new FakeTelegramApi;
    $fake->chatMemberStatuses = $chatMemberStatuses;
    (new PickerRecordingProcessTelegramUpdate($updateData, $fake))->handle();

    return $fake;
}

function pickerCommand(string $text = 'انضم', array $overrides = []): array
{
    return [
        'update_id' => random_int(1_000, 9_999_999),
        'message' => array_replace([
            'message_id' => 42,
            'date' => now()->getTimestamp(),
            'from' => ['id' => PICKER_MEMBER_ID, 'is_bot' => false, 'first_name' => 'سارة', 'username' => 'sara'],
            'chat' => ['id' => PICKER_CHAT_ID, 'type' => 'supergroup', 'title' => 'كلية الحاسبات'],
            'text' => $text,
        ], $overrides),
    ];
}

/** A press on one of the picker's buttons, by default from its owner. */
function pickerPress(string $action, int $ownerId = PICKER_MEMBER_ID, int $presserId = PICKER_MEMBER_ID): array
{
    return [
        'update_id' => random_int(1_000, 9_999_999),
        'callback_query' => [
            'id' => 'cb'.random_int(1, 9_999),
            'from' => ['id' => $presserId, 'is_bot' => false, 'first_name' => 'سارة', 'username' => 'sara'],
            'message' => [
                'message_id' => PICKER_MESSAGE_ID,
                'chat' => ['id' => PICKER_CHAT_ID, 'type' => 'supergroup'],
            ],
            'data' => TeamJoinPickerHandler::CALLBACK_PREFIX.$ownerId.':'.$action,
        ],
    ];
}

function pickerTeam(string $name, ?TelegramTeamCategory $category = null): TelegramTeam
{
    return TelegramTeam::factory()->create([
        'chat_id' => PICKER_CHAT_ID,
        'name' => $name,
        'category_id' => $category?->id,
    ]);
}

function pickerCategory(string $name): TelegramTeamCategory
{
    return TelegramTeamCategory::factory()->create(['chat_id' => PICKER_CHAT_ID, 'name' => $name]);
}

/** The keyboard of the last thing the bot sent or edited, as JSON. */
function pickerKeyboard(FakeTelegramApi $fake): string
{
    $calls = array_merge($fake->sentMessages, $fake->editedMessages);

    return (string) json_encode(end($calls)['reply_markup'] ?? null, JSON_UNESCAPED_UNICODE);
}

describe('opening the picker', function () {
    it('offers the chat\'s categories as buttons', function (string $trigger) {
        $branch = pickerCategory('الفرع');
        $major = pickerCategory('التخصص');
        pickerTeam('العابدية', $branch);
        pickerTeam('الزاهر', $branch);
        pickerTeam('علوم الحاسب', $major);

        $fake = runPickerUpdate(pickerCommand($trigger));

        expect($fake->sentMessages)->toHaveCount(1)
            ->and($fake->sentMessages[0]['text'])->toContain('اختر فرقك يا سارة')
            ->toContain('فرقك الآن: لا شيء بعد.');

        $keyboard = pickerKeyboard($fake);

        expect($keyboard)->toContain('الفرع (2)')
            ->toContain('التخصص (1)')
            ->toContain(TeamJoinPickerHandler::CALLBACK_PREFIX.PICKER_MEMBER_ID.':c:'.$branch->id)
            // No team buttons yet — the categories come first.
            ->not->toContain('العابدية');
    })->with(['انضم', 'دخول مجموعة', '/join']);

    it('skips the category menu when the chat sorts its teams one way', function () {
        $branch = pickerCategory('الفرع');
        $abidiyah = pickerTeam('العابدية', $branch);
        pickerTeam('الزاهر', $branch);

        $fake = runPickerUpdate(pickerCommand());

        expect(pickerKeyboard($fake))->toContain('العابدية')
            ->toContain(TeamJoinPickerHandler::CALLBACK_PREFIX.PICKER_MEMBER_ID.':t:'.$abidiyah->id)
            // A one-entry menu is a tap that teaches nothing, so there is no
            // «back to categories» button either.
            ->not->toContain(':menu');
    });

    it('marks the teams the member is already in', function () {
        $team = pickerTeam('العابدية');
        TelegramTeamMember::factory()->for($team, 'team')->create(['telegram_user_id' => PICKER_MEMBER_ID]);

        $fake = runPickerUpdate(pickerCommand());

        expect($fake->sentMessages[0]['text'])->toContain('فرقك الآن: العابدية')
            ->and(pickerKeyboard($fake))->toContain('✅ العابدية');
    });

    it('teaches how teams are created when the chat has none', function () {
        $fake = runPickerUpdate(pickerCommand());

        expect($fake->allTexts()[0])->toContain('لا توجد فرق في هذه المجموعة بعد.')
            ->and($fake->sentMessages[0])->not->toHaveKey('reply_markup');
    });

    it('works in groups only', function () {
        pickerTeam('العابدية');

        $fake = runPickerUpdate(pickerCommand('انضم', [
            'chat' => ['id' => PICKER_MEMBER_ID, 'type' => 'private', 'first_name' => 'سارة'],
        ]));

        expect($fake->allTexts())->toContain('أوامر الفرق تعمل داخل المجموعات فقط.');
    });

    it('leaves the typed join request to the approval flow', function () {
        pickerTeam('العابدية');

        $fake = runPickerUpdate(pickerCommand('انضم العابدية'));

        expect($fake->allTexts()[0])->toContain('يرد أحد مشرفي المجموعة')
            ->and($fake->sentMessages[0])->not->toHaveKey('reply_markup');
    });

    it('schedules the picker and the command that opened it for cleanup', function () {
        pickerTeam('العابدية');

        runPickerUpdate(pickerCommand());

        Bus::assertDispatched(
            DeleteTelegramMessages::class,
            fn (DeleteTelegramMessages $job): bool => $job->chatId === PICKER_CHAT_ID && in_array(42, $job->messageIds, true),
        );
    });
});

describe('pressing the buttons', function () {
    it('opens a category\'s teams', function () {
        $branch = pickerCategory('الفرع');
        pickerCategory('التخصص');
        pickerTeam('العابدية', $branch);
        pickerTeam('علوم الحاسب');

        $fake = runPickerUpdate(pickerPress('c:'.$branch->id));

        expect($fake->editedMessages)->toHaveCount(1)
            ->and($fake->editedMessages[0]['message_id'])->toBe(PICKER_MESSAGE_ID)
            ->and(pickerKeyboard($fake))->toContain('العابدية')
            ->toContain(':menu')
            ->not->toContain('علوم الحاسب');
    });

    it('joins the team on the first press, with no admin approval in the way', function () {
        $team = pickerTeam('العابدية');

        $fake = runPickerUpdate(pickerPress('t:'.$team->id));

        $member = TelegramTeamMember::query()->where('team_id', $team->id)->first();

        expect($member)->not->toBeNull()
            ->and($member->telegram_user_id)->toBe(PICKER_MEMBER_ID)
            ->and($member->first_name)->toBe('سارة')
            ->and($member->username)->toBe('sara')
            // The press is the consent, and it is the member's own.
            ->and($member->added_by_telegram_id)->toBe(PICKER_MEMBER_ID)
            ->and($member->consent_message_id)->toBe(PICKER_MESSAGE_ID)
            ->and($fake->answeredCallbacks[0]['text'])->toContain('انضممت إلى «العابدية»')
            ->and(pickerKeyboard($fake))->toContain('✅ العابدية');
    });

    it('leaves the team on a second press', function () {
        $team = pickerTeam('العابدية');
        TelegramTeamMember::factory()->for($team, 'team')->create(['telegram_user_id' => PICKER_MEMBER_ID]);

        $fake = runPickerUpdate(pickerPress('t:'.$team->id));

        expect(TelegramTeamMember::query()->count())->toBe(0)
            ->and($fake->answeredCallbacks[0]['text'])->toContain('خرجت من «العابدية»')
            ->and(pickerKeyboard($fake))->not->toContain('✅ العابدية');
    });

    it('blocks an old «انضم» message from putting a leaver back', function () {
        $team = pickerTeam('العابدية');
        TelegramTeamMember::factory()->for($team, 'team')->create([
            'telegram_user_id' => PICKER_MEMBER_ID,
            'consented_at' => now()->subMinutes(5),
        ]);

        runPickerUpdate(pickerPress('t:'.$team->id));

        // The same replay block the typed opt-out sets: «أضف» on a consent
        // message sent before the member left must not re-add them.
        $fake = runPickerUpdate([
            'update_id' => 12_345,
            'message' => [
                'message_id' => 99,
                'date' => now()->subMinutes(5)->getTimestamp(),
                'from' => ['id' => 111, 'is_bot' => false, 'first_name' => 'سعد'],
                'chat' => ['id' => PICKER_CHAT_ID, 'type' => 'supergroup'],
                'text' => 'أضف',
                'reply_to_message' => [
                    'message_id' => 77,
                    'date' => now()->subMinutes(5)->getTimestamp(),
                    'from' => ['id' => PICKER_MEMBER_ID, 'is_bot' => false, 'first_name' => 'سارة'],
                    'chat' => ['id' => PICKER_CHAT_ID, 'type' => 'supergroup'],
                    'text' => 'انضم العابدية',
                ],
            ],
        ], [111 => 'administrator']);

        expect(TelegramTeamMember::query()->count())->toBe(0)
            ->and($fake->allTexts()[0])->toContain('انسحب العضو بعد هذا الطلب');
    });

    it('refuses a press from someone else, and tells them how to get their own', function () {
        $team = pickerTeam('العابدية');

        $fake = runPickerUpdate(pickerPress('t:'.$team->id, presserId: PICKER_OTHER_ID));

        expect(TelegramTeamMember::query()->count())->toBe(0)
            ->and($fake->answeredCallbacks[0]['text'])->toContain('أرسل «انضم» لتفتح قائمتك أنت')
            ->and($fake->answeredCallbacks[0]['show_alert'])->toBeTrue()
            ->and($fake->editedMessages)->toBeEmpty();
    });

    it('closes into a plain summary that cannot be pressed again', function () {
        $team = pickerTeam('العابدية');
        TelegramTeamMember::factory()->for($team, 'team')->create(['telegram_user_id' => PICKER_MEMBER_ID]);

        $fake = runPickerUpdate(pickerPress('done'));

        expect($fake->editedMessages[0]['text'])->toContain('فرقك: العابدية')
            ->and($fake->editedMessages[0])->not->toHaveKey('reply_markup');
    });

    it('survives a team deleted while the picker was open', function () {
        $team = pickerTeam('العابدية');
        $id = $team->id;
        $team->delete();

        $fake = runPickerUpdate(pickerPress('t:'.$id));

        expect($fake->answeredCallbacks[0]['text'])->toContain('لم يعد موجودًا')
            ->and(TelegramTeamMember::query()->count())->toBe(0);
    });
});
