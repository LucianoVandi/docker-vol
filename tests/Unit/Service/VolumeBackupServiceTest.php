<?php

declare(strict_types=1);

namespace DockerVol\Tests\Unit\Service;

use DockerVol\Contract\DockerServiceInterface;
use DockerVol\Exception\DockerCommandException;
use DockerVol\Service\VolumeBackupService;
use DockerVol\Tests\TestCase;

class VolumeBackupServiceTest extends TestCase
{
    private DockerServiceInterface $dockerService;
    private VolumeBackupService $backupService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dockerService = $this->createMock(DockerServiceInterface::class);
        $this->backupService = new VolumeBackupService($this->dockerService);
    }

    public function testBackupSingleVolumeWhenVolumeExists(): void
    {
        $volumeName = 'test-volume';
        $backupDir = $this->createTempDirectory();

        // Ensure that the archive file does not exist before the test
        $expectedArchivePath = $backupDir . DIRECTORY_SEPARATOR . $volumeName . '.tar.gz';
        if (file_exists($expectedArchivePath)) {
            unlink($expectedArchivePath);
        }

        $this->dockerService
            ->expects($this->once())
            ->method('volumeExists')
            ->with($volumeName)
            ->willReturn(true)
        ;

        $this->dockerService
            ->expects($this->once())
            ->method('runContainer')
            ->willReturnCallback(function () use ($expectedArchivePath) {
                touch($expectedArchivePath);

                return $this->createMockProcess(0, 'Backup completed');
            })
        ;

        $result = $this->backupService->backupSingleVolume($volumeName, $backupDir);

        $this->assertTrue($result->isSuccessful());
    }

    public function testBackupSingleVolumeWhenVolumeDoesNotExist(): void
    {
        $volumeName = 'non-existent';
        $backupDir = $this->createTempDirectory();

        $this->dockerService
            ->expects($this->once())
            ->method('volumeExists')
            ->with($volumeName)
            ->willReturn(false)
        ;

        $this->dockerService
            ->expects($this->never())
            ->method('runContainer')
        ;

        $result = $this->backupService->backupSingleVolume($volumeName, $backupDir);

        $this->assertFalse($result->isSuccessful());
    }

    public function testBackupMultipleVolumes(): void
    {
        $backupDir = $this->createTempDirectory();
        $volumeNames = ['volume1', 'volume2'];

        // Clean up any existing archives
        foreach ($volumeNames as $volumeName) {
            $archivePath = $backupDir . DIRECTORY_SEPARATOR . $volumeName . '.tar.gz';
            if (file_exists($archivePath)) {
                unlink($archivePath);
            }
        }

        $this->dockerService
            ->expects($this->exactly(2))
            ->method('volumeExists')
            ->willReturn(true)
        ;

        $callCount = 0;
        $this->dockerService
            ->expects($this->exactly(2))
            ->method('runContainer')
            ->willReturnCallback(function () use ($backupDir, $volumeNames, &$callCount) {
                $archivePath = $backupDir . DIRECTORY_SEPARATOR . $volumeNames[$callCount] . '.tar.gz';
                touch($archivePath);
                $callCount++;

                return $this->createMockProcess(0, 'Backup completed');
            })
        ;

        $results = $this->backupService->backupVolumes($volumeNames, $backupDir);

        $this->assertCount(2, $results);
        $this->assertTrue($results[0]->isSuccessful());
        $this->assertTrue($results[1]->isSuccessful());
    }

    public function testBackupMultipleVolumesWithCompressionDisabled(): void
    {
        $backupDir = $this->createTempDirectory();
        $volumeNames = ['volume1', 'volume2'];

        $this->dockerService
            ->expects($this->exactly(2))
            ->method('volumeExists')
            ->willReturn(true)
        ;

        $callCount = 0;
        $this->dockerService
            ->expects($this->exactly(2))
            ->method('runContainer')
            ->willReturnCallback(function () use ($backupDir, $volumeNames, &$callCount) {
                $archivePath = $backupDir . DIRECTORY_SEPARATOR . $volumeNames[$callCount] . '.tar';
                touch($archivePath);
                $callCount++;

                return $this->createMockProcess(0, 'Backup completed');
            })
        ;

        $results = $this->backupService->backupVolumes($volumeNames, $backupDir, false);

        $this->assertCount(2, $results);
        $this->assertTrue($results[0]->isSuccessful());
        $this->assertSame($backupDir . DIRECTORY_SEPARATOR . 'volume1.tar', $results[0]->filePath);
        $this->assertTrue($results[1]->isSuccessful());
        $this->assertSame($backupDir . DIRECTORY_SEPARATOR . 'volume2.tar', $results[1]->filePath);
    }

    public function testGetAvailableVolumes(): void
    {
        $volumes = [
            $this->createTestVolume('vol1'),
            $this->createTestVolume('vol2'),
        ];

        $this->dockerService
            ->expects($this->once())
            ->method('listVolumes')
            ->willReturn($volumes)
        ;

        $result = $this->backupService->getAvailableVolumes();

        $this->assertCount(2, $result);
        $this->assertEquals('vol1', $result[0]->name);
        $this->assertEquals('vol2', $result[1]->name);
    }

    public function testBackupWithCompressionDisabled(): void
    {
        $volumeName = 'test-volume';
        $backupDir = $this->createTempDirectory();

        // Clean up any existing archives with .tar extension
        $expectedArchivePath = $backupDir . DIRECTORY_SEPARATOR . $volumeName . '.tar';
        if (file_exists($expectedArchivePath)) {
            unlink($expectedArchivePath);
        }

        $this->dockerService
            ->method('volumeExists')
            ->willReturn(true)
        ;

        $this->dockerService
            ->method('runContainer')
            ->willReturnCallback(function () use ($expectedArchivePath) {
                touch($expectedArchivePath);

                return $this->createMockProcess(0, 'Backup completed');
            })
        ;

        $result = $this->backupService->backupSingleVolume($volumeName, $backupDir, false);

        $this->assertTrue($result->isSuccessful());
    }

    public function testBackupSkipsWhenArchiveAlreadyExists(): void
    {
        $volumeName = 'test-volume';
        $backupDir = $this->createTempDirectory();
        touch($backupDir . DIRECTORY_SEPARATOR . $volumeName . '.tar.gz');

        $this->dockerService
            ->expects($this->once())
            ->method('volumeExists')
            ->with($volumeName)
            ->willReturn(true)
        ;

        $this->dockerService
            ->expects($this->never())
            ->method('runContainer')
        ;

        $result = $this->backupService->backupSingleVolume($volumeName, $backupDir);

        $this->assertTrue($result->isSkipped());
        $this->assertStringContainsString('File already exists', (string) $result->message);
    }

    public function testBackupMapsContainerPathFromConfiguredProjectDirectories(): void
    {
        $_ENV['DOCKER_BACKUP_DEV_MODE'] = '1';
        $_ENV['CONTAINER_PROJECT_DIR'] = sys_get_temp_dir();
        $_ENV['HOST_PROJECT_DIR'] = '/host/project';

        $volumeName = 'test-volume';
        $backupDir = $this->createTempDirectory();
        $expectedArchivePath = $backupDir . DIRECTORY_SEPARATOR . $volumeName . '.tar.gz';
        $expectedHostBackupDir = '/host/project' . substr($backupDir, strlen(sys_get_temp_dir()));

        try {
            $this->dockerService
                ->method('volumeExists')
                ->willReturn(true)
            ;

            $this->dockerService
                ->expects($this->once())
                ->method('runContainer')
                ->with($this->callback(function (array $dockerArgs) use ($expectedArchivePath, $expectedHostBackupDir): bool {
                    touch($expectedArchivePath);

                    return in_array("{$expectedHostBackupDir}:/backup", $dockerArgs, true);
                }))
                ->willReturn($this->createMockProcess(0, 'Backup completed'))
            ;

            $result = $this->backupService->backupSingleVolume($volumeName, $backupDir);

            $this->assertTrue($result->isSuccessful());
        } finally {
            unset($_ENV['DOCKER_BACKUP_DEV_MODE'], $_ENV['CONTAINER_PROJECT_DIR'], $_ENV['HOST_PROJECT_DIR']);
        }
    }

    public function testBackupFailsWhenDockerCommandFails(): void
    {
        $volumeName = 'test-volume';
        $backupDir = $this->createTempDirectory();

        $this->dockerService
            ->method('volumeExists')
            ->willReturn(true)
        ;

        $this->dockerService
            ->method('runContainer')
            ->willThrowException(new DockerCommandException('Docker error'))
        ;

        $result = $this->backupService->backupSingleVolume($volumeName, $backupDir);

        $this->assertFalse($result->isSuccessful());
    }
}
