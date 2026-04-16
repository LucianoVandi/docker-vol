<?php

declare(strict_types=1);

namespace DockerVol\Tests\Unit\Helper;

use DockerVol\Helper\DockerHelperImage;
use DockerVol\Tests\TestCase;

class DockerHelperImageTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('DOCKERVOL_HELPER_IMAGE');

        parent::tearDown();
    }

    public function testUsesPinnedDefaultImage(): void
    {
        putenv('DOCKERVOL_HELPER_IMAGE');

        $this->assertSame('alpine:3.20', DockerHelperImage::name());
    }

    public function testUsesConfiguredImage(): void
    {
        putenv('DOCKERVOL_HELPER_IMAGE=busybox:1.36');

        $this->assertSame('busybox:1.36', DockerHelperImage::name());
    }
}
