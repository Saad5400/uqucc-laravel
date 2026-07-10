<?php

namespace App\Filament\Resources\Pages\Actions;

use App\Ai\Copilot\CopilotDisabledException;
use App\Ai\Copilot\PageCopilot;
use App\Ai\Copilot\TipTapContent;
use App\Settings\AiSettings;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Throwable;

/**
 * The admin-copilot actions on the Pages form, registered as hint actions in
 * {@see \App\Filament\Resources\Pages\Schemas\PageForm} (one line per field).
 *
 * Each action runs one {@see PageCopilot} generation inside its modal and
 * FILLS the target form field with the result — never saves — so the admin
 * reviews the suggestion in the editor and confirms by saving the page.
 * Every action disappears entirely while the admin_copilot feature (or the
 * master AI switch) is off in {@see AiSettings}.
 */
class PageCopilotActions
{
    /**
     * «تحسين النص» — rewrite the main content for clarity, with an optional
     * steering instruction, and fill the editor with the improved version.
     */
    public static function improveText(): Action
    {
        return Action::make('improveText')
            ->label('تحسين النص')
            ->icon(Heroicon::OutlinedSparkles)
            ->color('info')
            ->visible(fn (): bool => self::isCopilotEnabled())
            ->modalHeading('تحسين النص بالذكاء الاصطناعي')
            ->modalDescription('يعيد المساعد صياغة المحتوى الحالي ويملأ الحقل بالنتيجة لمراجعتها قبل الحفظ. ملاحظة: قد تتحول العناصر المخصصة (تنبيه/قابل للطي) إلى نص عادي.')
            ->modalSubmitActionLabel('تحسين')
            ->schema([
                Textarea::make('instruction')
                    ->label('تعليمات إضافية (اختياري)')
                    ->placeholder('مثال: اجعل الأسلوب أكثر رسمية، أو لخّص الفقرات الطويلة')
                    ->rows(3),
            ])
            ->action(function (array $data, Get $get, Set $set): void {
                $markdown = TipTapContent::toMarkdown($get('html_content'));

                if (trim($markdown) === '') {
                    self::notifyWarning('لا يوجد محتوى لتحسينه', 'اكتب محتوى الصفحة أولاً ثم أعد المحاولة.');

                    return;
                }

                try {
                    $improved = app(PageCopilot::class)->improveText($markdown, trim((string) ($data['instruction'] ?? '')));

                    $set('html_content', TipTapContent::toDocument($improved));

                    self::notifySuccess('تم تحسين النص', 'راجع النتيجة في المحرر ثم احفظ الصفحة لاعتمادها.');
                } catch (Throwable $exception) {
                    self::notifyFailure('تعذر تحسين النص', $exception);
                }
            });
    }

    /**
     * «مسودة قسم» — draft a new markdown section about a given topic and
     * append it to the main content.
     */
    public static function draftSection(): Action
    {
        return Action::make('draftSection')
            ->label('مسودة قسم')
            ->icon(Heroicon::OutlinedDocumentPlus)
            ->color('info')
            ->visible(fn (): bool => self::isCopilotEnabled())
            ->modalHeading('مسودة قسم جديد')
            ->modalDescription('يكتب المساعد قسماً جديداً عن الموضوع المحدد ويضيفه إلى نهاية المحتوى لمراجعته قبل الحفظ.')
            ->modalSubmitActionLabel('توليد المسودة')
            ->schema([
                TextInput::make('topic')
                    ->label('موضوع القسم')
                    ->placeholder('مثال: شروط التحويل بين التخصصات')
                    ->required()
                    ->maxLength(200),
            ])
            ->action(function (array $data, Get $get, Set $set): void {
                try {
                    $currentContent = $get('html_content');

                    $context = trim('# '.trim((string) $get('title'))."\n\n".TipTapContent::toMarkdown($currentContent));

                    $section = app(PageCopilot::class)->draftSection(trim((string) $data['topic']), $context);

                    $set('html_content', TipTapContent::append($currentContent, $section));

                    self::notifySuccess('تمت إضافة مسودة القسم', 'أُضيف القسم إلى نهاية المحتوى — راجعه ثم احفظ الصفحة لاعتماده.');
                } catch (Throwable $exception) {
                    self::notifyFailure('تعذر توليد مسودة القسم', $exception);
                }
            });
    }

    /**
     * «توليد وصف SEO» — generate a meta title + description from the page
     * content and fill the quick-response message field, which is the first
     * source {@see \App\Support\Seo} uses for the page's meta description.
     */
    public static function generateSeoMeta(): Action
    {
        return Action::make('generateSeoMeta')
            ->label('توليد وصف SEO')
            ->icon(Heroicon::OutlinedGlobeAlt)
            ->color('info')
            ->visible(fn (): bool => self::isCopilotEnabled())
            ->requiresConfirmation()
            ->modalHeading('توليد وصف SEO')
            ->modalDescription('يولّد المساعد وصفاً موجزاً من محتوى الصفحة ويملأ به هذا الحقل — وهو المصدر الأول لوصف SEO للصفحة — لمراجعته قبل الحفظ.')
            ->modalSubmitActionLabel('توليد')
            ->action(function (Get $get, Set $set): void {
                $content = TipTapContent::toMarkdown($get('html_content'));

                if (trim($content) === '') {
                    self::notifyWarning('لا يوجد محتوى لتوليد الوصف منه', 'اكتب محتوى الصفحة أولاً ثم أعد المحاولة.');

                    return;
                }

                try {
                    $meta = app(PageCopilot::class)->generateSeoMeta(trim((string) $get('title')), $content);

                    $set('quick_response_message', '<p>'.e($meta['description']).'</p>');

                    self::notifySuccess('تم توليد وصف SEO', 'العنوان المقترح: '.$meta['title'].' — راجع الوصف ثم احفظ الصفحة لاعتماده.');
                } catch (Throwable $exception) {
                    self::notifyFailure('تعذر توليد وصف SEO', $exception);
                }
            });
    }

    private static function isCopilotEnabled(): bool
    {
        return app(AiSettings::class)->isFeatureEnabled('admin_copilot');
    }

    private static function notifySuccess(string $title, string $body): void
    {
        Notification::make()
            ->success()
            ->title($title)
            ->body($body)
            ->send();
    }

    private static function notifyWarning(string $title, string $body): void
    {
        Notification::make()
            ->warning()
            ->title($title)
            ->body($body)
            ->send();
    }

    /**
     * Surface the failure to the admin in Arabic; unexpected (non-domain)
     * exceptions are also reported for the operator.
     */
    private static function notifyFailure(string $title, Throwable $exception): void
    {
        if (! $exception instanceof CopilotDisabledException) {
            report($exception);
        }

        Notification::make()
            ->danger()
            ->title($title)
            ->body($exception->getMessage())
            ->send();
    }
}
