<?php

declare(strict_types=1);

namespace DockerVol\Tests\Unit\Helper;

use DockerVol\Helper\ArchiveInspector;
use DockerVol\Tests\TestCase;

class ArchiveInspectorTest extends TestCase
{
    public function testReadsImageRepoTagsFromManifest(): void
    {
        $manifest = json_encode([
            ['RepoTags' => ['nginx:latest', 'nginx:1.25']],
        ], JSON_THROW_ON_ERROR);
        $archivePath = $this->createTempFile($this->createTarContent('manifest.json', $manifest), '.tar');

        $this->assertSame(['nginx:latest', 'nginx:1.25'], ArchiveInspector::imageRepoTags($archivePath));
    }

    public function testReadsImageRepoTagsFromCompressedManifest(): void
    {
        $manifest = json_encode([
            ['RepoTags' => ['registry.example.com/app:stable']],
        ], JSON_THROW_ON_ERROR);
        $archivePath = $this->createTempFile(gzencode($this->createTarContent('manifest.json', $manifest)), '.tar.gz');

        $this->assertSame(['registry.example.com/app:stable'], ArchiveInspector::imageRepoTags($archivePath));
    }
}
