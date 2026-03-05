<?php

declare(strict_types=1);

namespace DockerVol\Tests\Unit\Service;

use DockerVol\Contract\DockerServiceInterface;
use DockerVol\Exception\RestoreException;
use DockerVol\Service\VolumeRestoreService;
use DockerVol\Tests\TestCase;

class VolumeRestoreServiceTest extends TestCase
{
    private DockerServiceInterface $dockerService;
    private VolumeRestoreService $restoreService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dockerService = $this->createMock(DockerServiceInterface::class);
        $this->restoreService = new VolumeRestoreService($this->dockerService);
    }

    public function testRestoreCreatesMissingVolumeThroughDockerService(): void
    {
        $archivePath = $this->createTempTarArchive('.tar.gz');
        $volumeName = basename($archivePath, '.tar.gz');

        $this->dockerService
            ->expects($this->once())
            ->method('volumeExists')
            ->with($volumeName)
            ->willReturn(false)
        ;

        $this->dockerService
            ->expects($this->once())
            ->method('createVolume')
            ->with($volumeName)
            ->willReturn($this->createMockProcess(0, $volumeName))
        ;

        $this->dockerService
            ->expects($this->exactly(2))
            ->method('runContainer')
            ->willReturnOnConsecutiveCalls(
                $this->createMockProcess(0, 'Restore completed'),
                $this->createMockProcess(0, "12\t/volume\n")
            )
        ;

        $result = $this->restoreService->restoreSingleVolume($archivePath);

        $this->assertTrue($result->isSuccessful());
    }

    public function testRestoreSkipsExistingVolumeWithoutOverwrite(): void
    {
        $archivePath = $this->createTempTarArchive('.tar.gz');
        $volumeName = basename($archivePath, '.tar.gz');

        $this->dockerService
            ->expects($this->once())
            ->method('volumeExists')
            ->with($volumeName)
            ->willReturn(true)
        ;

        $this->dockerService
            ->expects($this->never())
            ->method('createVolume')
        ;

        $this->dockerService
            ->expects($this->never())
            ->method('runContainer')
        ;

        $result = $this->restoreService->restoreSingleVolume($archivePath);

        $this->assertTrue($result->isSkipped());
        $this->assertStringContainsString('Volume already exists', (string) $result->message);
    }

    public function testRestoreOverwritesExistingVolumeAfterCleaningIt(): void
    {
        $archivePath = $this->createTempTarArchive('.tar.gz');
        $volumeName = basename($archivePath, '.tar.gz');

        $this->dockerService
            ->expects($this->once())
            ->method('volumeExists')
            ->with($volumeName)
            ->willReturn(true)
        ;

        $this->dockerService
            ->expects($this->never())
            ->method('createVolume')
        ;

        $callCount = 0;
        $this->dockerService
            ->expects($this->exactly(3))
            ->method('runContainer')
            ->willReturnCallback(function (array $dockerArgs) use (&$callCount) {
                $callCount++;

                if ($callCount === 1) {
                    $this->assertContains('rm -rf /volume/* /volume/.[!.]* /volume/..?*', $dockerArgs);
                }

                return match ($callCount) {
                    3 => $this->createMockProcess(0, "12\t/volume\n"),
                    default => $this->createMockProcess(0, 'ok'),
                };
            })
        ;

        $result = $this->restoreService->restoreSingleVolume($archivePath, true);

        $this->assertTrue($result->isSuccessful());
    }

    public function testRestoreFailsWhenVolumeIsMissingAndCreationIsDisabled(): void
    {
        $archivePath = $this->createTempTarArchive('.tar.gz');
        $volumeName = basename($archivePath, '.tar.gz');

        $this->dockerService
            ->expects($this->once())
            ->method('volumeExists')
            ->with($volumeName)
            ->willReturn(false)
        ;

        $this->dockerService
            ->expects($this->never())
            ->method('createVolume')
        ;

        $this->dockerService
            ->expects($this->never())
            ->method('runContainer')
        ;

        $result = $this->restoreService->restoreSingleVolume($archivePath, false, false);

        $this->assertTrue($result->isFailed());
        $this->assertStringContainsString('--no-create-volume', (string) $result->message);
    }

    public function testValidateArchiveThrowsRestoreExceptionForInvalidArchiveExtension(): void
    {
        $archivePath = $this->createTempFile('not an archive', '.txt');
        $method = new \ReflectionMethod(VolumeRestoreService::class, 'validateArchive');
        $method->setAccessible(true);

        $this->expectException(RestoreException::class);
        $this->expectExceptionMessage('Invalid archive format');

        $method->invoke($this->restoreService, $archivePath);
    }

    public function testCreateVolumeThrowsRestoreExceptionWhenDockerCreateFails(): void
    {
        $this->dockerService
            ->expects($this->once())
            ->method('createVolume')
            ->with('broken-volume')
            ->willReturn($this->createMockProcess(1, '', 'create failed'))
        ;

        $method = new \ReflectionMethod(VolumeRestoreService::class, 'createVolume');
        $method->setAccessible(true);

        $this->expectException(RestoreException::class);
        $this->expectExceptionMessage("Failed to create volume 'broken-volume'");

        $method->invoke($this->restoreService, 'broken-volume');
    }

    public function testCleanVolumeThrowsRestoreExceptionWhenDockerCleanFails(): void
    {
        $this->dockerService
            ->expects($this->once())
            ->method('runContainer')
            ->willReturn($this->createMockProcess(1, '', 'clean failed'))
        ;

        $method = new \ReflectionMethod(VolumeRestoreService::class, 'cleanVolume');
        $method->setAccessible(true);

        $this->expectException(RestoreException::class);
        $this->expectExceptionMessage("Failed to clean volume 'broken-volume'");

        $method->invoke($this->restoreService, 'broken-volume');
    }

    public function testAvailableBackupsIgnoreDirectoriesWithArchiveExtensions(): void
    {
        $backupDir = $this->createTempDirectory();
        mkdir($backupDir . DIRECTORY_SEPARATOR . 'not-a-file.tar');
        $archivePath = $backupDir . DIRECTORY_SEPARATOR . 'real-volume.tar.gz';
        $this->writeTarArchive($archivePath);

        $backups = $this->restoreService->getAvailableBackups($backupDir);

        $this->assertCount(1, $backups);
        $this->assertArrayHasKey('real-volume', $backups);
        $this->assertSame($archivePath, $backups['real-volume']['path']);
    }
}
