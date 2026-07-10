<?php

use App\Ai\Tools\FindTutorsTool;
use App\Settings\AiSettings;
use Database\Factories\PrivateTutor\PrivateTutorCourseFactory;
use Database\Factories\PrivateTutor\PrivateTutorFactory;
use Laravel\Ai\Tools\Request;

beforeEach(function () {
    $settings = app(AiSettings::class);
    $settings->ai_enabled = true;
    $settings->save();
});

it('finds tutors by course name', function () {
    $course = PrivateTutorCourseFactory::new()->create(['name' => 'برمجة الحاسب 1']);
    $tutor = PrivateTutorFactory::new()->create(['name' => 'أ. محمد', 'url' => 'https://wa.me/966500000000']);
    $course->tutors()->attach($tutor);

    $reply = (string) app(FindTutorsTool::class)->handle(new Request(['query' => 'برمجة']));

    expect($reply)->toContain('برمجة الحاسب 1')
        ->toContain('أ. محمد')
        ->toContain('https://wa.me/966500000000');
});

it('finds courses by tutor name', function () {
    $course = PrivateTutorCourseFactory::new()->create(['name' => 'هياكل البيانات']);
    $tutor = PrivateTutorFactory::new()->withoutUrl()->create(['name' => 'أ. خالد']);
    $tutor->courses()->attach($course);

    $reply = (string) app(FindTutorsTool::class)->handle(new Request(['query' => 'خالد']));

    expect($reply)->toContain('أ. خالد')
        ->toContain('هياكل البيانات');
});

it('reports a course that has no tutors yet', function () {
    PrivateTutorCourseFactory::new()->create(['name' => 'التحليل العددي']);

    $reply = (string) app(FindTutorsTool::class)->handle(new Request(['query' => 'التحليل']));

    expect($reply)->toContain('التحليل العددي')
        ->toContain('لا يوجد مدرسون');
});

it('reports when nothing matches', function () {
    PrivateTutorCourseFactory::new()->create(['name' => 'برمجة الحاسب 1']);

    $reply = (string) app(FindTutorsTool::class)->handle(new Request(['query' => 'كيمياء عضوية']));

    expect($reply)->toContain('لا توجد نتائج');
});

it('rejects a too-short query', function () {
    $reply = (string) app(FindTutorsTool::class)->handle(new Request(['query' => 'ب']));

    expect($reply)->toContain('حرفين على الأقل');
});

it('returns a disabled message when the master ai kill switch is off', function () {
    PrivateTutorCourseFactory::new()->create(['name' => 'برمجة الحاسب 1']);

    $settings = app(AiSettings::class);
    $settings->ai_enabled = false;
    $settings->save();

    $reply = (string) app(FindTutorsTool::class)->handle(new Request(['query' => 'برمجة']));

    expect($reply)->toContain('معطلة')
        ->not->toContain('برمجة الحاسب 1');
});
