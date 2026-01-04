<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

class RobotsController extends Controller
{
    public function __invoke(): Response
    {
        $content = 'User-agent: *
Allow: /
Disallow: /admin
Disallow: /filament

Sitemap: '.url('sitemap.xml');

        return response($content, 200)
            ->header('Content-Type', 'text/plain');
    }
}
