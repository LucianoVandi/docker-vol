<?php

declare(strict_types=1);

namespace DockerVol\Tests\Unit\Helper;

use DockerVol\Helper\ArchiveNamer;
use DockerVol\Tests\TestCase;

class ArchiveNamerTest extends TestCase
{
    public function testBuildsImageArchivePathWithReversibleFilename(): void
    {
        $backupDir = '/tmp/backups';
        $imageReference = 'registry.example.com/my_org/my_app:release_2026';

        $this->assertSame(
            $backupDir . DIRECTORY_SEPARATOR . rawurlencode($imageReference) . '.tar.gz',
            ArchiveNamer::imageArchivePath($imageReference, $backupDir, true)
        );
    }

    public function testExtractsImageNameFromReversibleArchivePath(): void
    {
        $imageReference = 'registry.example.com/my_org/my_app:release_2026';

        $this->assertSame(
            $imageReference,
            ArchiveNamer::imageNameFromArchivePath('/tmp/' . rawurlencode($imageReference) . '.tar')
        );
    }

    public function testExtractsLegacyImageNameBestEffort(): void
    {
        $this->assertSame(
            'docker.io/library/nginx:latest',
            ArchiveNamer::imageNameFromArchivePath('/tmp/docker_io_library_nginx_latest.tar.gz')
        );
    }

    public function testExtractsVolumeNameFromArchivePath(): void
    {
        $this->assertSame('postgres-data', ArchiveNamer::volumeNameFromArchivePath('/tmp/postgres-data.tar.gz'));
    }
}
