<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class AuthorSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $authors = [
            ['username' => 'we1vle', 'name' => '📊👩🏻‍💻Ds.retaj', 'url' => null],
            ['username' => 'oiixp1', 'name' => 'أثير الكناني', 'url' => null],
            ['username' => 'Dina-x', 'name' => 'دينا يوسف', 'url' => null],
            ['username' => 'BananArab', 'name' => 'بَنان عرب', 'url' => null],
            ['username' => 'fatimah-alqurashi-60b496330', 'name' => 'فاطمة القرشي', 'url' => 'https://www.linkedin.com/in/fatimah-alqurashi-60b496330'],
            ['username' => 'mashae1_cs', 'name' => 'مشاعل شاكر', 'url' => null],
            ['username' => 'o_hi_xl', 'name' => 'Ohoud', 'url' => null],
            ['username' => 'حنين-cs-2a4313363', 'name' => 'حنين', 'url' => 'https://www.linkedin.com/in/%D8%AD%D9%86%D9%8A%D9%86-cs-2a4313363'],
            ['username' => 'Kld-ai', 'name' => 'خالد الدمح', 'url' => null],
            ['username' => 'rito_4s', 'name' => 'ريتاج الصليمي', 'url' => null],
            ['username' => 'muiopv', 'name' => 'يارا السلمي', 'url' => null],
            ['username' => 'Zartz14', 'name' => 'زياد إمام', 'url' => null],
            ['username' => 'Evani', 'name' => 'محمد الشريف', 'url' => null],
            ['username' => 'Nour', 'name' => 'نور عبدالعزيز', 'url' => null],
            ['username' => 'khaled', 'name' => 'خالد النافع', 'url' => null],
            ['username' => 'Shadi', 'name' => 'شادي رزق', 'url' => null],
            ['username' => 'maziad', 'name' => 'مزيد العبدالعزيز', 'url' => null],
            ['username' => 'Bader', 'name' => 'بدر الياسي', 'url' => null],
        ];

        foreach ($authors as $author) {
            \App\Models\Author::updateOrCreate(
                ['username' => $author['username']],
                $author
            );
        }
    }
}
