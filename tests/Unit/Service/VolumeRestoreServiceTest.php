<?php

declare(strict_types=1);

namespace DockerBackup\Tests\Unit\Service;

use DockerBackup\Contract\DockerServiceInterface;
use DockerBackup\Service\VolumeRestoreService;
use DockerBackup\Tests\TestCase;

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
        $archivePath = $this->createTempFile('backup content', '.tar.gz');
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
            ->expects($this->exactly(3))
            ->method('runContainer')
            ->willReturnOnConsecutiveCalls(
                $this->createMockProcess(0, "file.txt\n"),
                $this->createMockProcess(0, 'Restore completed'),
                $this->createMockProcess(0, "12\t/volume\n")
            )
        ;

        $result = $this->restoreService->restoreSingleVolume($archivePath);

        $this->assertTrue($result->isSuccessful());
    }

    public function testRestoreSkipsExistingVolumeWithoutOverwrite(): void
    {
        $archivePath = $this->createTempFile('backup content', '.tar.gz');
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
            ->expects($this->once())
            ->method('runContainer')
            ->willReturn($this->createMockProcess(0, "file.txt\n"))
        ;

        $result = $this->restoreService->restoreSingleVolume($archivePath);

        $this->assertTrue($result->isSkipped());
        $this->assertStringContainsString('Volume already exists', (string) $result->message);
    }

    public function testRestoreOverwritesExistingVolumeAfterCleaningIt(): void
    {
        $archivePath = $this->createTempFile('backup content', '.tar.gz');
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
            ->expects($this->exactly(4))
            ->method('runContainer')
            ->willReturnCallback(function (array $dockerArgs) use (&$callCount) {
                $callCount++;

                if ($callCount === 2) {
                    $this->assertContains('rm -rf /volume/* /volume/.[!.]* /volume/..?*', $dockerArgs);
                }

                return match ($callCount) {
                    1 => $this->createMockProcess(0, "file.txt\n"),
                    4 => $this->createMockProcess(0, "12\t/volume\n"),
                    default => $this->createMockProcess(0, 'ok'),
                };
            })
        ;

        $result = $this->restoreService->restoreSingleVolume($archivePath, true);

        $this->assertTrue($result->isSuccessful());
    }

    public function testRestoreFailsWhenVolumeIsMissingAndCreationIsDisabled(): void
    {
        $archivePath = $this->createTempFile('backup content', '.tar.gz');
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
            ->expects($this->once())
            ->method('runContainer')
            ->willReturn($this->createMockProcess(0, "file.txt\n"))
        ;

        $result = $this->restoreService->restoreSingleVolume($archivePath, false, false);

        $this->assertTrue($result->isFailed());
        $this->assertStringContainsString('--no-create-volume', (string) $result->message);
    }
}
