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
}
