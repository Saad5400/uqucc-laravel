<?php

use App\Ai\Copilot\CopilotDisabledException;
use App\Ai\Copilot\PageCopilot;
use App\Ai\Copilot\PageCopilotAgent;
use App\Settings\AiSettings;
use Laravel\Ai\Prompts\AgentPrompt;

function configureAdminCopilot(bool $aiEnabled = true, bool $copilotEnabled = true, bool $withKey = true): void
{
    $settings = app(AiSettings::class);
    $settings->ai_enabled = $aiEnabled;
    $settings->admin_copilot_enabled = $copilotEnabled;
    $settings->save();

    if ($withKey) {
        config()->set('ai.providers.openrouter.key', 'test-key');
    } else {
        config()->set('ai.providers.openrouter.key', '');
    }
}

describe('improveText', function () {
    it('returns the improved markdown from a single generation call', function () {
        configureAdminCopilot();
        PageCopilotAgent::fake(["## نص محسّن\n\nفقرة أوضح وأسلس."]);

        $improved = app(PageCopilot::class)->improveText("## قسم\n\nنص أصلي.", 'اجعل الأسلوب رسمياً');

        expect($improved)->toBe("## نص محسّن\n\nفقرة أوضح وأسلس.");

        PageCopilotAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->contains('نص أصلي.')
            && $prompt->contains('اجعل الأسلوب رسمياً'));
    });

    it('omits the instruction line when no instruction is given', function () {
        configureAdminCopilot();
        PageCopilotAgent::fake(['نص محسّن']);

        app(PageCopilot::class)->improveText('نص أصلي');

        PageCopilotAgent::assertPrompted(fn (AgentPrompt $prompt): bool => ! $prompt->contains('تعليمات إضافية'));
    });

    it('uses the chat model from settings', function () {
        configureAdminCopilot();

        $settings = app(AiSettings::class);
        $settings->chat_model = 'openai/gpt-test';
        $settings->save();

        PageCopilotAgent::fake(['نص محسّن']);

        app(PageCopilot::class)->improveText('نص أصلي');

        PageCopilotAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->model === 'openai/gpt-test');
    });
});

describe('draftSection', function () {
    it('drafts a section grounded in the topic and page context', function () {
        configureAdminCopilot();
        PageCopilotAgent::fake(["## شروط التحويل\n\nمحتوى القسم الجديد."]);

        $section = app(PageCopilot::class)->draftSection('شروط التحويل', "# التخصصات\n\nمحتوى الصفحة.");

        expect($section)->toBe("## شروط التحويل\n\nمحتوى القسم الجديد.");

        PageCopilotAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->contains('شروط التحويل')
            && $prompt->contains('محتوى الصفحة.'));
    });
});

describe('generateSeoMeta', function () {
    it('parses the model json into title and description', function (string $payload) {
        configureAdminCopilot();
        PageCopilotAgent::fake([$payload]);

        $meta = app(PageCopilot::class)->generateSeoMeta('التخصصات', "# التخصصات\n\nمحتوى.");

        expect($meta)->toBe([
            'title' => 'تخصصات كلية الحاسبات',
            'description' => 'تعرف على تخصصات كلية الحاسبات وشروط كل تخصص.',
        ]);
    })->with([
        'plain json' => '{"title": "تخصصات كلية الحاسبات", "description": "تعرف على تخصصات كلية الحاسبات وشروط كل تخصص."}',
        'fenced json' => "```json\n{\"title\": \"تخصصات كلية الحاسبات\", \"description\": \"تعرف على تخصصات كلية الحاسبات وشروط كل تخصص.\"}\n```",
    ]);

    it('throws an arabic error when the model returns unparseable output', function (string $payload) {
        configureAdminCopilot();
        PageCopilotAgent::fake([$payload]);

        app(PageCopilot::class)->generateSeoMeta('التخصصات', 'محتوى');
    })->throws(RuntimeException::class, 'أعاد النموذج ناتجاً غير صالح لوصف SEO — حاول مرة أخرى.')->with([
        'not json' => 'وصف عادي بدون JSON',
        'missing keys' => '{"headline": "عنوان"}',
        'empty values' => '{"title": "", "description": ""}',
    ]);
});

describe('gating', function () {
    it('throws when the admin copilot feature is disabled', function () {
        configureAdminCopilot(copilotEnabled: false);
        PageCopilotAgent::fake(['يجب ألا يُستدعى']);

        expect(fn (): string => app(PageCopilot::class)->improveText('نص'))
            ->toThrow(CopilotDisabledException::class, 'مساعد الكتابة الذكي معطل من إعدادات الذكاء الاصطناعي.');

        PageCopilotAgent::assertNeverPrompted();
    });

    it('honours the master ai kill switch even when the feature flag is on', function () {
        configureAdminCopilot(aiEnabled: false, copilotEnabled: true);
        PageCopilotAgent::fake(['يجب ألا يُستدعى']);

        expect(fn (): array => app(PageCopilot::class)->generateSeoMeta('عنوان', 'محتوى'))
            ->toThrow(CopilotDisabledException::class);

        PageCopilotAgent::assertNeverPrompted();
    });

    it('throws when the openrouter key is missing', function () {
        configureAdminCopilot(withKey: false);
        PageCopilotAgent::fake(['يجب ألا يُستدعى']);

        expect(fn (): string => app(PageCopilot::class)->draftSection('موضوع'))
            ->toThrow(RuntimeException::class, 'مفتاح OpenRouter غير مضبوط — لا يمكن استخدام مساعد الكتابة.');

        PageCopilotAgent::assertNeverPrompted();
    });
});
