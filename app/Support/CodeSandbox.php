<?php

namespace App\Support;

use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Runs code a Telegram user sent (the bot's «شغل جافا» / «شغل بايثون»
 * commands) without handing it the app.
 *
 * The queue workers run as root with every secret in their environment, and a
 * child process inherits both by default: one `System.getenv()` used to print
 * the database password, APP_KEY and every API key. So each command runs as:
 *
 *   prlimit <caps> -- setpriv --reuid=U --regid=U --clear-groups --reset-env
 *     -- env -i PATH=… HOME=<run dir> LANG=C.UTF-8 TMPDIR=<run dir> <command>
 *
 * - an unprivileged uid of its own (config services.code_sandbox.uid), which
 *   owns nothing but the run dir, so it can read no secret on disk: the app,
 *   bootstrap/cache and storage are root-only, /proc/<pid>/environ of every
 *   root process is 0400, and start-container.sh closes every world-writable
 *   directory, so the run dir is the only place it can write;
 * - no_new_privs (setpriv --no-new-privs): no setuid/setcap binary in the
 *   image can lift the run back to root or add capabilities;
 * - an empty environment: Symfony Process is told to drop every inherited
 *   variable, and `env -i` builds the child's from scratch;
 * - rlimits: processes/threads (a fork bomb stops at the cap), address space,
 *   CPU seconds, file size, open files, no core dumps. The wall-clock limit
 *   stays with the caller's Process timeout.
 *
 * The uid is deliberately not 65534: RLIMIT_NPROC counts every process of the
 * uid on the HOST, and other containers already run processes as nobody.
 *
 * - no network: start-container.sh firewalls the uid off (iptables/ip6tables
 *   owner match), and no run starts unless {@see SandboxEgressGuard} confirms
 *   the rule is installed — fail closed, never "run without the firewall";
 * - bounded output: {@see run()} stops a program once stdout+stderr pass
 *   services.code_sandbox.limits.output_bytes, so a print loop cannot fill
 *   the worker's memory within its wall-clock window.
 */
class CodeSandbox
{
    public function __construct(private readonly SandboxEgressGuard $egress) {}

    /**
     * Create a fresh run dir owned by the sandbox uid (mode 0700) and return
     * its path. Also reaps anything a previous run left behind.
     */
    public function makeRunDir(): string
    {
        if (! $this->egress->isInstalled()) {
            throw new CodeSandboxUnavailable('code sandbox: the egress firewall rule is not installed (NET_ADMIN / iptables missing?); refusing to run code with network access');
        }

        $this->reapStragglers();

        $root = (string) config('services.code_sandbox.root');

        if (! is_dir($root)) {
            mkdir($root, 0711, true);
        }

        $dir = $root.'/run-'.Str::random(32);
        mkdir($dir, 0700);

        if (! chown($dir, $this->uid()) || ! chgrp($dir, $this->uid())) {
            $this->removeRunDir($dir);

            throw new RuntimeException('code sandbox: cannot hand the run dir to the sandbox uid (not running as root?)');
        }

        return $dir;
    }

    /**
     * A Process that runs $command inside the sandbox, in $runDir.
     *
     * @param  list<string>  $command
     * @param  array<string, string>  $env  extra variables for the child (on top of PATH, HOME, LANG, TMPDIR)
     */
    public function process(array $command, string $runDir, float $timeout, array $env = []): Process
    {
        $uid = (string) $this->uid();
        $limits = config('services.code_sandbox.limits');

        $childEnv = array_merge([
            'PATH' => (string) config('services.code_sandbox.path'),
            'HOME' => $runDir,
            'TMPDIR' => $runDir,
            'LANG' => 'C.UTF-8',
        ], $env);

        $wrapped = [
            'prlimit',
            '--nproc='.$limits['nproc'],
            '--as='.$limits['address_space'],
            '--cpu='.$limits['cpu_seconds'],
            '--fsize='.$limits['file_size'],
            '--nofile='.$limits['open_files'],
            '--core=0',
            '--',
            'setpriv', '--reuid='.$uid, '--regid='.$uid, '--clear-groups', '--no-new-privs', '--reset-env',
            '--',
            'env', '-i',
            ...array_map(fn (string $key, string $value): string => $key.'='.$value, array_keys($childEnv), $childEnv),
            ...$command,
        ];

        // Drop every variable the worker would otherwise pass down (Symfony
        // Process inherits $_SERVER, $_ENV and getenv() by default), so even
        // the root-side prlimit/setpriv wrappers start clean.
        $inherited = array_keys(array_merge($_SERVER, $_ENV, getenv()));
        $cleared = array_fill_keys(array_filter($inherited, 'is_string'), false);
        $cleared['PATH'] = '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin';

        return new Process($wrapped, $runDir, $cleared, null, $timeout);
    }

    /**
     * Run a sandbox process to completion, stopping it once its combined
     * stdout + stderr pass the output limit. The Process timeout still
     * applies (ProcessTimedOutException, as with Process::run()).
     *
     * @return bool whether the program was stopped for producing too much output
     */
    public function run(Process $process): bool
    {
        $limit = (int) config('services.code_sandbox.limits.output_bytes');
        $seen = 0;

        $process->start();

        while ($process->isRunning()) {
            $process->checkTimeout();

            // Only the new bytes: Process spools its buffer to a temp file past
            // 1MB, and the full output is never pulled into memory here.
            $seen += strlen($process->getIncrementalOutput()) + strlen($process->getIncrementalErrorOutput());

            if ($seen > $limit) {
                $process->stop(0);

                return true;
            }

            usleep(10_000);
        }

        $process->wait();

        return $seen + strlen($process->getIncrementalOutput()) + strlen($process->getIncrementalErrorOutput()) > $limit;
    }

    /**
     * Delete a run dir and everything the sandboxed code left in it.
     */
    public function removeRunDir(string $dir): void
    {
        $root = (string) config('services.code_sandbox.root');

        if (! str_starts_with($dir, $root.'/run-') || ! is_dir($dir)) {
            return;
        }

        (new Process(['rm', '-rf', '--one-file-system', $dir]))->run();
    }

    /**
     * Kill sandbox-uid processes older than the longest run may last.
     *
     * A Process timeout kills the command it started, but a program that
     * forked and detached leaves its children behind, still holding slots of
     * the uid's process cap. No run lasts longer than 12s (javac's 10s timeout
     * plus java's 2s, each killed at its own timeout), so anything of that uid
     * alive 20s after it started is such a leftover.
     */
    public function reapStragglers(): void
    {
        $uptime = (float) explode(' ', (string) @file_get_contents('/proc/uptime'))[0];
        $ticks = 100; // USER_HZ, fixed at 100 on Linux
        $killed = [];

        foreach (glob('/proc/[0-9]*', GLOB_NOSORT) ?: [] as $proc) {
            if (! $this->ownedBySandbox($proc)) {
                continue;
            }

            $stat = (string) @file_get_contents($proc.'/stat');
            // Field 22 (starttime) counted after the ")" that closes the command name.
            $fields = explode(' ', trim(substr($stat, (int) strrpos($stat, ')') + 2)));
            $started = isset($fields[19]) ? (int) $fields[19] / $ticks : $uptime;

            if ($uptime - $started > 20) {
                @posix_kill((int) basename($proc), SIGKILL);
                $killed[] = $proc;
            }
        }

        // A killed process keeps its slot under the cap until it is reaped
        // (supervisord, PID 1, reaps orphans), so give that a moment before
        // the caller forks the next run.
        for ($i = 0; $i < 20 && array_filter($killed, $this->ownedBySandbox(...)) !== []; $i++) {
            usleep(50_000);
        }
    }

    private function ownedBySandbox(string $proc): bool
    {
        clearstatcache(true, $proc);

        // The process may exit between the glob and the stat.
        return file_exists($proc) && @fileowner($proc) === $this->uid();
    }

    private function uid(): int
    {
        return (int) config('services.code_sandbox.uid');
    }
}
