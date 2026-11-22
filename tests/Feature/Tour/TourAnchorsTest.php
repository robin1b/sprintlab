<?php

namespace Tests\Feature\Tour;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class TourAnchorsTest extends TestCase
{
    public function test_every_tour_target_has_an_anchor_in_the_frontend(): void
    {
        $root = dirname(__DIR__, 3).'/resources/js';
        $tours = (string) file_get_contents($root.'/tours.ts');

        preg_match_all("/target:\s*'([^']+)'/", $tours, $matches);
        $targets = array_unique($matches[1]);

        $this->assertNotEmpty($targets);

        $source = '';
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));

        foreach ($files as $file) {
            $path = $file->getPathname();

            if (str_contains($path, '/routes/') || str_contains($path, '/wayfinder/') || $path === $root.'/tours.ts') {
                continue;
            }

            if (preg_match('/\.(vue|ts)$/', $path)) {
                $source .= file_get_contents($path)."\n";
            }
        }

        foreach ($targets as $target) {
            $quoted = preg_quote($target, '/');
            $found = preg_match('/data-tour="'.$quoted.'"/', $source)
                || preg_match("/data-tour=\"[^\"]*'".$quoted."'/", $source)
                || preg_match("/tour:\s*'".$quoted."'/", $source);

            $this->assertTrue((bool) $found, "No data-tour anchor for '{$target}'.");
        }
    }
}
