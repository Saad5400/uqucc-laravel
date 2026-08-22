<?php

use App\Models\StudentGroup\GroupSupervisor;

describe('telegram handles', function () {
    it('reduces every accepted way of writing a handle to the bare username', function (string $input, string $expected) {
        expect(GroupSupervisor::normalizeUsername($input))->toBe($expected);
    })->with([
        'bare' => ['ysf_arr', 'ysf_arr'],
        'at prefix' => ['@ysf_arr', 'ysf_arr'],
        'https url' => ['https://t.me/ysf_arr', 'ysf_arr'],
        'http url' => ['http://t.me/ysf_arr', 'ysf_arr'],
        'protocol-less url' => ['t.me/ysf_arr', 'ysf_arr'],
        'www url' => ['https://www.t.me/ysf_arr', 'ysf_arr'],
        'telegram.me url' => ['https://telegram.me/ysf_arr', 'ysf_arr'],
        'uppercase host' => ['HTTPS://T.ME/ysf_arr', 'ysf_arr'],
        'trailing slash' => ['https://t.me/ysf_arr/', 'ysf_arr'],
        'start parameter' => ['https://t.me/ysf_arr?start=hello', 'ysf_arr'],
        'surrounding whitespace' => ["  @ysf_arr\n", 'ysf_arr'],
        'empty' => ['', ''],
    ]);

    it('leaves the case of the username itself alone', function () {
        expect(GroupSupervisor::normalizeUsername('@Ysf_Arr'))->toBe('Ysf_Arr');
    });
});

describe('whatsapp numbers', function () {
    it('stores every way a Saudi mobile is written as one dialable form', function (string $input, string $expected) {
        expect(GroupSupervisor::normalizeWhatsapp($input))->toBe($expected);
    })->with([
        'local' => ['0507487697', '966507487697'],
        'spaced local' => ['050 748 7697', '966507487697'],
        'dashed local' => ['050-748-7697', '966507487697'],
        'plus international' => ['+966507487697', '966507487697'],
        'spaced international' => ['+966 50 748 7697', '966507487697'],
        'double-zero international' => ['00966507487697', '966507487697'],
        'bare national' => ['507487697', '966507487697'],
        'already normalized' => ['966507487697', '966507487697'],
        'parenthesised' => ['(050) 748 7697', '966507487697'],
        'empty' => ['', ''],
    ]);

    it('leaves a non-Saudi number as its own international digits', function () {
        expect(GroupSupervisor::normalizeWhatsapp('+201234567890'))->toBe('201234567890');
    });
});

describe('contact presentation', function () {
    it('shows a Saudi number the way a Saudi reader writes it', function () {
        $supervisor = new GroupSupervisor(['whatsapp_number' => '+966 50 748 7697']);

        expect($supervisor->whatsapp_number)->toBe('966507487697')
            ->and($supervisor->whatsappDisplay())->toBe('0507487697')
            ->and($supervisor->whatsappUrl())->toBe('https://wa.me/966507487697');
    });

    it('falls back to a plain international form outside +966', function () {
        $supervisor = new GroupSupervisor(['whatsapp_number' => '+201234567890']);

        expect($supervisor->whatsappDisplay())->toBe('+201234567890');
    });

    it('stores a blank contact as null rather than an empty string', function () {
        $supervisor = new GroupSupervisor(['telegram_username' => '  ', 'whatsapp_number' => '']);

        expect($supervisor->telegram_username)->toBeNull()
            ->and($supervisor->whatsapp_number)->toBeNull()
            ->and($supervisor->telegramUrl())->toBeNull()
            ->and($supervisor->whatsappUrl())->toBeNull()
            ->and($supervisor->contacts())->toBe([]);
    });

    it('lists Telegram before WhatsApp when someone publishes both', function () {
        $supervisor = new GroupSupervisor([
            'telegram_username' => '@Rori_chan_0',
            'whatsapp_number' => '0581138300',
        ]);

        expect($supervisor->contacts())->toBe([
            ['kind' => 'telegram', 'handle' => '@Rori_chan_0', 'url' => 'https://t.me/Rori_chan_0'],
            ['kind' => 'whatsapp', 'handle' => '0581138300', 'url' => 'https://wa.me/966581138300'],
        ]);
    });
});
