<?php

declare(strict_types=1);

namespace DockerVol\Tests\Unit\Service;

use DockerVol\Contract\DockerServiceInterface;
use DockerVol\Service\ImageRestoreService;
use DockerVol\Tests\TestCase;

class ImageRestoreServiceTest extends TestCase
{
    private DockerServiceInterface $dockerService;
    private ImageRestoreService $restoreService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dockerService = $this->createMock(DockerServiceInterface::class);
        $this->restoreService = new ImageRestoreService($this->dockerService);
    }

    public function testAvailableBackupsDecodeReversibleImageReferenceFilename(): void
    {
        $backupDir = $this->createTempDirectory();
        $imageReference = 'registry.example.com/my_org/my_app:release_2026';
        $archivePath = $backupDir . DIRECTORY_SEPARATOR . rawurlencode($imageReference) . '.tar';
        $this->writeTarArchive($archivePath);

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

    public function testRestoreSkipsExistingImageWithUnderscoreInName(): void
    {
        $backupDir = $this->createTempDirectory();
        $imageReference = 'registry.example.com/my_org/my_app:release_2026';
        $archivePath = $backupDir . DIRECTORY_SEPARATOR . rawurlencode($imageReference) . '.tar';
        $this->writeTarArchive($archivePath);

        $this->dockerService->expects($this->never())->method('runContainer');

        $this->dockerService
            ->expects($this->once())
            ->method('imageExists')
            ->with($imageReference)
            ->willReturn(true)
        ;

        $this->dockerService
            ->expects($this->never())
            ->method('loadImage')
        ;

        $result = $this->restoreService->restoreSingleImage($archivePath);

        $this->assertTrue($result->isSkipped());
        $this->assertStringContainsString('Image already exists', (string) $result->message);
    }

    public function testRestoreUsesLegacyFilenameFallbackWhenCheckingExistingImage(): void
    {
        $backupDir = $this->createTempDirectory();
        $archivePath = $backupDir . DIRECTORY_SEPARATOR . 'docker_io_library_nginx_latest.tar';
        $this->writeTarArchive($archivePath);

        $this->dockerService->expects($this->never())->method('runContainer');

        $this->dockerService
            ->expects($this->once())
            ->method('imageExists')
            ->with('docker.io/library/nginx:latest')
            ->willReturn(true)
        ;

        $this->dockerService
            ->expects($this->never())
            ->method('loadImage')
        ;

        $result = $this->restoreService->restoreSingleImage($archivePath);

        $this->assertTrue($result->isSkipped());
    }

    public function testAvailableBackupsIgnoreDirectoriesWithArchiveExtensions(): void
    {
        $backupDir = $this->createTempDirectory();
        mkdir($backupDir . DIRECTORY_SEPARATOR . 'not-a-file.tar.gz');
        $imageReference = 'nginx:latest';
        $archivePath = $backupDir . DIRECTORY_SEPARATOR . rawurlencode($imageReference) . '.tar.gz';
        $this->writeTarArchive($archivePath);

        $backups = $this->restoreService->getAvailableBackups($backupDir);

        $this->assertCount(1, $backups);
        $this->assertSame($imageReference, $backups[0]['name']);
        $this->assertSame($archivePath, $backups[0]['path']);
    }
}
