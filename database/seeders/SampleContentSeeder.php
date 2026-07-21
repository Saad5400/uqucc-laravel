<?php

namespace Database\Seeders;

use App\Models\Page;
use App\Services\NavigationService;
use Illuminate\Database\Seeder;

/**
 * Dev-only sample content so the public site renders (nav tree, homepage
 * sections, content pages). NOT wired into DatabaseSeeder — run explicitly:
 *   php artisan db:seed --class=SampleContentSeeder
 */
class SampleContentSeeder extends Seeder
{
    public function run(): void
    {
        $sections = [
            ['slug' => '/university', 'title' => 'الجامعة والاجراءات الأكاديمية', 'icon' => 'lucide:graduation-cap'],
            ['slug' => '/courses', 'title' => 'المقررات', 'icon' => 'lucide:book-open'],
            ['slug' => '/technology', 'title' => 'التقنية والاجهزة', 'icon' => 'lucide:cpu'],
            ['slug' => '/utilities', 'title' => 'ادوات', 'icon' => 'lucide:wrench'],
            ['slug' => '/community', 'title' => 'المجتمع', 'icon' => 'lucide:users'],
            ['slug' => '/club', 'title' => 'نادي الحاسبات', 'icon' => 'lucide:code-xml'],
            ['slug' => '/contributors', 'title' => 'المساهمون', 'icon' => 'lucide:heart-handshake'],
        ];

        Page::updateOrCreate(
            ['slug' => '/'],
            [
                'title' => 'دليل طالب كلية الحاسبات',
                'icon' => 'lucide:home',
                'order' => 0,
                'level' => 0,
                'parent_id' => null,
                'hidden' => false,
                'extension' => 'md',
                'html_content' => ['type' => 'doc', 'content' => []],
            ]
        );

        $order = 1;
        $created = [];

        foreach ($sections as $section) {
            $created[$section['slug']] = Page::updateOrCreate(
                ['slug' => $section['slug']],
                [
                    'title' => $section['title'],
                    'icon' => $section['icon'],
                    'order' => $order++,
                    'level' => 0,
                    'parent_id' => null,
                    'hidden' => false,
                    'extension' => 'md',
                    'html_content' => $this->intro($section['title']),
                ]
            );
        }

        $children = [
            ['parent' => '/university', 'slug' => '/university/registration', 'title' => 'التسجيل وحذف واضافة المقررات'],
            ['parent' => '/university', 'slug' => '/university/gpa', 'title' => 'حساب المعدل التراكمي'],
            ['parent' => '/courses', 'slug' => '/courses/intro-programming', 'title' => 'مقدمة في البرمجة'],
            ['parent' => '/technology', 'slug' => '/technology/laptops', 'title' => 'اختيار اللابتوب المناسب'],
            ['parent' => '/club', 'slug' => '/club/join', 'title' => 'كيف تنضم للنادي'],
        ];

        foreach ($children as $index => $child) {
            $parent = $created[$child['parent']];

            Page::updateOrCreate(
                ['slug' => $child['slug']],
                [
                    'title' => $child['title'],
                    'order' => $index + 1,
                    'level' => 1,
                    'parent_id' => $parent->id,
                    'hidden' => false,
                    'extension' => 'md',
                    'html_content' => $this->article($child['title']),
                ]
            );
        }

        app(NavigationService::class)->clearCache();
    }

    /**
     * Short intro doc for a top-level section landing page.
     *
     * @return array<string, mixed>
     */
    private function intro(string $title): array
    {
        return [
            'type' => 'doc',
            'content' => [
                $this->paragraph('هذا القسم يجمع كل ما تحتاجه حول '.$title.'. تصفّح المواضيع من القائمة الجانبية أو ابدأ بالمقالات الأكثر أهمية أدناه.'),
                $this->bulletList([
                    'خطوات عملية واضحة خطوة بخطوة',
                    'روابط للأدوات والأنظمة الرسمية',
                    'إجابات لأكثر الأسئلة تكراراً',
                ]),
            ],
        ];
    }

    /**
     * Fuller article doc exercising headings, lists and a callout blockquote.
     *
     * @return array<string, mixed>
     */
    private function article(string $title): array
    {
        return [
            'type' => 'doc',
            'content' => [
                $this->paragraph('يشرح هذا الدليل '.$title.' بأسلوب مبسّط للطلبة الجدد، مع أمثلة تطبيقية تساعدك على إنجاز الإجراء دون أخطاء شائعة.'),
                $this->heading('الخطوات الأساسية', 2),
                $this->paragraph('اتبع الخطوات التالية بالترتيب. كل خطوة مستقلة ويمكنك العودة إليها لاحقاً عند الحاجة.'),
                $this->bulletList([
                    'ادخل إلى النظام الأكاديمي باستخدام حسابك الجامعي.',
                    'تأكد من المواعيد المعلنة في التقويم الأكاديمي.',
                    'راجع المتطلبات السابقة قبل تنفيذ الإجراء.',
                ]),
                $this->blockquote('نصيحة: احتفظ بنسخة من إثبات إتمام الإجراء حتى نهاية الفصل الدراسي تفادياً لأي إشكال.'),
                $this->heading('أسئلة شائعة', 2),
                $this->paragraph('إذا واجهت مشكلة لم يغطها هذا الدليل، تواصل مع عمادة القبول والتسجيل أو اسأل المساعد الذكي.'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function heading(string $text, int $level = 1): array
    {
        return [
            'type' => 'heading',
            'attrs' => ['level' => $level],
            'content' => [['type' => 'text', 'text' => $text]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function paragraph(string $text): array
    {
        return [
            'type' => 'paragraph',
            'content' => [['type' => 'text', 'text' => $text]],
        ];
    }

    /**
     * @param  array<int, string>  $items
     * @return array<string, mixed>
     */
    private function bulletList(array $items): array
    {
        return [
            'type' => 'bulletList',
            'content' => array_map(fn (string $item): array => [
                'type' => 'listItem',
                'content' => [$this->paragraph($item)],
            ], $items),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function blockquote(string $text): array
    {
        return [
            'type' => 'blockquote',
            'content' => [$this->paragraph($text)],
        ];
    }
}
