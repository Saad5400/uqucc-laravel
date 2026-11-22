<?php

/*
| Guards the security headers the production web server (./Caddyfile) adds
| to every response: no cross-origin framing, and no full-URL Referer to
| other origins.
*/

function uquccCaddyfile(): string
{
    return (string) file_get_contents(dirname(__DIR__, 2).'/Caddyfile');
}

it('forbids cross-origin framing', function () {
    expect(uquccCaddyfile())
        ->toContain('header Content-Security-Policy "frame-ancestors \'self\'"')
        ->toContain('header X-Frame-Options "SAMEORIGIN"');
});

it('sends only the origin as Referer to other sites', function () {
    expect(uquccCaddyfile())->toContain('header Referrer-Policy "strict-origin-when-cross-origin"');
});
