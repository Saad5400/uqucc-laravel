<?php

use App\Support\CodeSandbox;

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
