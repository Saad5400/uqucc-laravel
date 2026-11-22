<?php

namespace App\Support;

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Whether the firewall rule that cuts the code sandbox off the network is in
 * place.
 *
 * start-container.sh installs, as root and before anything else runs,
 *
 *   iptables  -I OUTPUT 1 -m owner --uid-owner <sandbox uid> -j REJECT
 *   ip6tables -I OUTPUT 1 -m owner --uid-owner <sandbox uid> -j REJECT
 *
 * so every packet a sandboxed program sends — TCP, UDP, DNS, loopback
 * included — is refused, while the app (root) keeps its network. That needs
 * the container's NET_ADMIN capability; without it, or without the iptables
 * binaries, the rule is missing and {@see CodeSandbox} refuses to run code at
 * all rather than run it with access to Redis, Postgres, the other containers
 * on the Docker network and the cloud metadata service.
 *
 * The check asks the kernel (`iptables -C`, exit 0 only when that exact rule
 * exists) on every run, so a rule flushed after boot is noticed too.
 */
class SandboxEgressGuard
{
    /** The rule both families carry; start-container.sh installs the same one. */
    public static function ruleSpec(int $uid): array
    {
        return ['OUTPUT', '-m', 'owner', '--uid-owner', (string) $uid, '-j', 'REJECT'];
    }

    public function isInstalled(): bool
    {
        $uid = (int) config('services.code_sandbox.uid');

        foreach (['iptables', 'ip6tables'] as $tool) {
            $binary = (new ExecutableFinder)->find($tool, null, ['/usr/sbin', '/sbin']);

            if ($binary === null) {
                return false;
            }

            $check = new Process([$binary, '-w', '5', '-C', ...self::ruleSpec($uid)], null, null, null, 10);
            $check->run();

            if (! $check->isSuccessful()) {
                return false;
            }
        }

        return true;
    }
}
