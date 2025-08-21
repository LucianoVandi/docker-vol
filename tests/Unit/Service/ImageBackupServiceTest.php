<?php

declare(strict_types=1);

namespace DockerBackup\Tests\Unit\Service;

use DockerBackup\Contract\DockerServiceInterface;
use DockerBackup\Service\ImageBackupService;
use DockerBackup\Tests\TestCase;

class ImageBackupServiceTest extends TestCase
{
    private DockerServiceInterface $dockerService;
    private ImageBackupService $backupService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dockerService = $this->createMock(DockerServiceInterface::class);
        $this->backupService = new ImageBackupService($this->dockerService);
    }

    public function testBackupUsesReversibleImageReferenceFilename(): void
    {
        $imageReference = 'registry.example.com/my_org/my_app:release_2026';
        $backupDir = $this->createTempDirectory();
        $expectedArchivePath = $backupDir . DIRECTORY_SEPARATOR . rawurlencode($imageReference) . '.tar';

        $this->dockerService
            ->expects($this->once())
            ->method('imageExists')
            ->with($imageReference)
            ->willReturn(true)
        ;

        $this->dockerService
            ->expects($this->once())
            ->method('saveImage')
            ->with($imageReference, $expectedArchivePath)
            ->willReturnCallback(function (string $imageReference, string $outputPath) {
                touch($outputPath);

                return $this->createMockProcess(0, 'Backup completed');
            })
        ;

        $result = $this->backupService->backupSingleImage($imageReference, $backupDir, false);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame($expectedArchivePath, $result->filePath);
    }

    public function testCompressedBackupStreamsDockerSaveIntoGzipArchive(): void
    {
        $imageReference = 'nginx:latest';
        $backupDir = $this->createTempDirectory();
        $expectedArchivePath = $backupDir . DIRECTORY_SEPARATOR . rawurlencode($imageReference) . '.tar.gz';

        $this->dockerService
            ->expects($this->once())
            ->method('imageExists')
            ->with($imageReference)
            ->willReturn(true)
        ;

        $this->dockerService
            ->expects($this->never())
            ->method('saveImage')
        ;

        $this->dockerService
            ->expects($this->once())
            ->method('streamSavedImage')
            ->with($imageReference, $this->isType('callable'))
            ->willReturnCallback(function (string $imageReference, callable $onChunk) {
                $onChunk('tar-content');

                return $this->createMockProcess(0, 'Backup completed');
            })
        ;

        $result = $this->backupService->backupSingleImage($imageReference, $backupDir);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame($expectedArchivePath, $result->filePath);
        $this->assertSame('tar-content', gzdecode((string) file_get_contents($expectedArchivePath)));
    }
}
