<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Tests don't run `npm run build`, so the Vite manifest is missing.
        // Without this, every view that pulls in `partials/head.blade.php`
        // 500s with `ViteManifestNotFoundException`. `withoutVite()` swaps
        // the container binding to a stub that returns empty asset markup
        // — view assertions stop depending on a built frontend.
        $this->withoutVite();
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
