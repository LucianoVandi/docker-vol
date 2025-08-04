<?php

declare(strict_types=1);

namespace DockerBackup\Tests\Unit\Service;

use DockerBackup\Contract\DockerServiceInterface;
use DockerBackup\Service\ImageRestoreService;
use DockerBackup\Tests\TestCase;

class ImageRestoreServiceTest extends TestCase
{
    private ImageRestoreService $restoreService;

    protected function setUp(): void
    {
        parent::setUp();

        $dockerService = $this->createMock(DockerServiceInterface::class);
        $this->restoreService = new ImageRestoreService($dockerService);
    }

    public function testAvailableBackupsDecodeReversibleImageReferenceFilename(): void
    {
        $backupDir = $this->createTempDirectory();
        $imageReference = 'registry.example.com/my_org/my_app:release_2026';
        $archivePath = $backupDir . DIRECTORY_SEPARATOR . rawurlencode($imageReference) . '.tar';
        touch($archivePath);

        $backups = $this->restoreService->getAvailableBackups($backupDir);

        $this->assertCount(1, $backups);
        $this->assertSame($imageReference, $backups[0]['name']);
    }

    public function testAvailableBackupsDecodeLegacyUnderscoreFilenameBestEffort(): void
    {
        $backupDir = $this->createTempDirectory();
        touch($backupDir . DIRECTORY_SEPARATOR . 'docker_io_library_nginx_latest.tar.gz');

        $backups = $this->restoreService->getAvailableBackups($backupDir);

        $this->assertCount(1, $backups);
        $this->assertSame('docker.io/library/nginx:latest', $backups[0]['name']);
    }
}
