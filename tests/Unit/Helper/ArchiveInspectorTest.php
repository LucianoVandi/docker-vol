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

    public function testImageManifestFailureReasonReturnsNullForValidManifest(): void
    {
        $manifest = json_encode([
            ['Config' => 'config.json', 'RepoTags' => ['nginx:latest'], 'Layers' => ['layer.tar']],
        ], JSON_THROW_ON_ERROR);
        $archivePath = $this->createTempFile($this->createTarContent('manifest.json', $manifest), '.tar');

        $this->assertNull(ArchiveInspector::imageManifestFailureReason($archivePath));
    }

    public function testImageManifestFailureReasonWhenManifestMissing(): void
    {
        $archivePath = $this->createTempFile($this->createTarContent('layer.tar', 'data'), '.tar');

        $this->assertSame(
            'Archive does not contain manifest.json',
            ArchiveInspector::imageManifestFailureReason($archivePath)
        );
    }

    public function testImageManifestFailureReasonWhenJsonInvalid(): void
    {
        $archivePath = $this->createTempFile($this->createTarContent('manifest.json', 'not-json{'), '.tar');

        $this->assertSame(
            'manifest.json contains invalid JSON',
            ArchiveInspector::imageManifestFailureReason($archivePath)
        );
    }

    public function testImageManifestFailureReasonWhenManifestIsEmptyArray(): void
    {
        $archivePath = $this->createTempFile($this->createTarContent('manifest.json', '[]'), '.tar');

        $this->assertSame(
            'manifest.json must be a non-empty JSON array',
            ArchiveInspector::imageManifestFailureReason($archivePath)
        );
    }

    public function testImageManifestFailureReasonWhenConfigMissing(): void
    {
        $manifest = json_encode([
            ['RepoTags' => ['nginx:latest'], 'Layers' => ['layer.tar']],
        ], JSON_THROW_ON_ERROR);
        $archivePath = $this->createTempFile($this->createTarContent('manifest.json', $manifest), '.tar');

        $this->assertStringContainsString(
            "missing required 'Config' field",
            (string) ArchiveInspector::imageManifestFailureReason($archivePath)
        );
    }

    public function testImageManifestFailureReasonWhenLayersMissing(): void
    {
        $manifest = json_encode([
            ['Config' => 'config.json', 'RepoTags' => ['nginx:latest']],
        ], JSON_THROW_ON_ERROR);
        $archivePath = $this->createTempFile($this->createTarContent('manifest.json', $manifest), '.tar');

        $this->assertStringContainsString(
            "missing required 'Layers' field",
            (string) ArchiveInspector::imageManifestFailureReason($archivePath)
        );
    }

    public function testImageManifestFailureReasonWorksWithCompressedArchive(): void
    {
        $tarContent = gzencode($this->createTarContent('layer.tar', 'data'));
        $archivePath = $this->createTempFile($tarContent, '.tar.gz');

        $this->assertSame(
            'Archive does not contain manifest.json',
            ArchiveInspector::imageManifestFailureReason($archivePath)
        );
    }
}
