<?php

use App\Services\Telegram\Handlers\JavaExecutionHandler;
use App\Services\Telegram\Handlers\PythonExecutionHandler;
use App\Support\CodeSandbox;
use App\Support\CodeSandboxUnavailable;
use App\Support\SandboxEgressGuard;
use Symfony\Component\Process\Process;
use Telegram\Bot\Objects\Message;
use Tests\Fakes\FakeTelegramApi;

it('wraps the command in rlimits, an unprivileged uid and an empty environment', function () {
    config(['services.code_sandbox.path' => '/jdk/bin:/usr/bin:/bin']);

    $process = app(CodeSandbox::class)->process(['python3', '-I', 'main.py'], '/var/lib/code-sandbox/run-x', 5);
    $command = $process->getCommandLine();

    expect($command)
        ->toStartWith("'prlimit' '--nproc=128' '--as=1073741824'")
        ->toContain("'setpriv' '--reuid=47000' '--regid=47000' '--clear-groups' '--reset-env'")
        ->toContain("'env' '-i' 'PATH=/jdk/bin:/usr/bin:/bin' 'HOME=/var/lib/code-sandbox/run-x' 'TMPDIR=/var/lib/code-sandbox/run-x' 'LANG=C.UTF-8'")
        ->toEndWith("'python3' '-I' 'main.py'")
        ->and($process->getTimeout())->toBe(5.0)
        ->and($process->getWorkingDirectory())->toBe('/var/lib/code-sandbox/run-x');
});

it('drops every variable the worker would pass down', function () {
    putenv('SANDBOX_TEST_SECRET=hunter2');
    $_ENV['APP_KEY_SANDBOX_TEST'] = 'base64:secret';

    $env = app(CodeSandbox::class)->process(['true'], '/var/lib/code-sandbox/run-x', 5)->getEnv();

    expect($env['SANDBOX_TEST_SECRET'])->toBeFalse()
        ->and($env['APP_KEY_SANDBOX_TEST'])->toBeFalse()
        ->and(array_filter($env, fn ($value) => $value !== false))->toBe(['PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin']);

    putenv('SANDBOX_TEST_SECRET');
    unset($_ENV['APP_KEY_SANDBOX_TEST']);
});

it('only removes run dirs under the sandbox root', function () {
    $outside = sys_get_temp_dir().'/code-sandbox-test-'.uniqid();
    mkdir($outside);

    app(CodeSandbox::class)->removeRunDir($outside);

    expect(is_dir($outside))->toBeTrue();
    rmdir($outside);
});

it('refuses to start a run while the egress firewall rule is missing', function () {
    $this->instance(SandboxEgressGuard::class, new class extends SandboxEgressGuard
    {
        public function isInstalled(): bool
        {
            return false;
        }
    });

    app(CodeSandbox::class)->makeRunDir();
})->throws(CodeSandboxUnavailable::class);

it('tells the user run-code is off, and runs nothing, when the sandbox is unavailable', function (string $handler, string $text) {
    $this->instance(SandboxEgressGuard::class, new class extends SandboxEgressGuard
    {
        public function isInstalled(): bool
        {
            return false;
        }
    });
    $sandbox = Mockery::mock(CodeSandbox::class, [app(SandboxEgressGuard::class)])->makePartial();
    $sandbox->shouldNotReceive('process');
    $this->instance(CodeSandbox::class, $sandbox);

    $api = new FakeTelegramApi;
    (new $handler($api))->handle(new Message([
        'message_id' => 5,
        'from' => ['id' => 601, 'is_bot' => false, 'first_name' => 'طالب'],
        'chat' => ['id' => 601, 'type' => 'private', 'first_name' => 'طالب'],
        'text' => $text,
    ]));

    expect($api->sentMessages)->toHaveCount(1)
        ->and($api->sentMessages[0]['text'])->toContain('تشغيل الأكواد متوقف مؤقتاً');
})->with([
    'python' => [PythonExecutionHandler::class, 'شغل بايثون print(1)'],
    'java' => [JavaExecutionHandler::class, 'شغل جافا System.out.println(1);'],
]);

it('stops a program once its output passes the limit', function () {
    config(['services.code_sandbox.limits.output_bytes' => 4096]);

    $process = new Process(['yes'], null, null, null, 5);
    $started = microtime(true);

    // Stopped for its output, long before the 5s wall clock would have.
    expect(app(CodeSandbox::class)->run($process))->toBeTrue()
        ->and($process->isRunning())->toBeFalse()
        ->and(microtime(true) - $started)->toBeLessThan(2.0);
});

it('lets a quiet program finish normally', function () {
    $process = new Process(['echo', 'hello'], null, null, null, 5);

    expect(app(CodeSandbox::class)->run($process))->toBeFalse()
        ->and($process->isSuccessful())->toBeTrue()
        ->and(trim($process->getOutput()))->toBe('hello');
});

it('installs at boot exactly the rule the guard checks for', function () {
    $script = (string) file_get_contents(base_path('start-container.sh'));
    $rule = implode(' ', SandboxEgressGuard::ruleSpec(47000));

    // The script spells the uid as a variable; the rest must match word for word.
    $scripted = str_replace('47000', '"$SANDBOX_UID"', $rule);

    expect($script)->toContain('-C '.$scripted)
        ->toContain('-I '.str_replace('OUTPUT', 'OUTPUT 1', $scripted))
        ->toContain('block_sandbox_egress iptables && block_sandbox_egress ip6tables')
        ->toContain('SANDBOX_UID="${CODE_SANDBOX_UID:-47000}"');
});
