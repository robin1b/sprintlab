<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class IntroVideoTest extends TestCase
{
    use RefreshDatabase;

    private function media(string $file): string
    {
        return base_path('resources/media/'.$file);
    }

    public function test_the_three_media_files_exist(): void
    {
        foreach (['intro.mp4', 'intro-poster.jpg', 'intro.vtt'] as $file) {
            $this->assertFileExists($this->media($file));
        }
    }

    public function test_the_video_is_larger_than_100_kb_and_at_most_6_mb(): void
    {
        $size = filesize($this->media('intro.mp4'));

        $this->assertGreaterThan(100 * 1024, $size);
        $this->assertLessThanOrEqual(6 * 1024 * 1024, $size);
    }

    public function test_the_poster_is_at_most_300_kb(): void
    {
        $this->assertLessThanOrEqual(300 * 1024, filesize($this->media('intro-poster.jpg')));
    }

    public function test_the_captions_start_with_webvtt_and_have_at_least_10_cues(): void
    {
        $vtt = file_get_contents($this->media('intro.vtt'));

        $this->assertStringStartsWith('WEBVTT', ltrim($vtt, "\xEF\xBB\xBF"));
        $this->assertGreaterThanOrEqual(10, substr_count($vtt, '-->'));
    }

    public function test_htaccess_does_not_deny_video_caption_or_poster_files(): void
    {
        $htaccess = file_get_contents(base_path('.htaccess'));

        $this->assertSame(1, preg_match('~<FilesMatch "([^"\n]*\\\\\.\(([^)]*)\)\$[^"\n]*)">\s*<IfModule mod_authz_core\.c>\s*Require all denied~', $htaccess, $matches));

        $denied = explode('|', $matches[2]);

        foreach (['mp4', 'vtt', 'jpg'] as $extension) {
            $this->assertNotContains($extension, $denied);
        }
    }

    public function test_the_landing_page_renders_welcome_for_a_guest(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Welcome'));
    }

    public function test_the_content_security_policy_allows_same_origin_media(): void
    {
        $response = $this->get('/');

        $policy = $response->headers->get('Content-Security-Policy')
            ?? $response->headers->get('Content-Security-Policy-Report-Only');

        $this->assertNotNull($policy);
        $this->assertStringContainsString("default-src 'self'", $policy);
        $this->assertStringNotContainsString("media-src 'none'", $policy);

        if (preg_match('~media-src ([^;]*)~', $policy, $matches)) {
            $this->assertStringContainsString("'self'", $matches[1]);
        }
    }
}
