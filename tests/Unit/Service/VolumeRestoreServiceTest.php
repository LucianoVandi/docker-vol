<?php

declare(strict_types=1);

namespace DockerVol\Tests\Unit\Service;

use DockerVol\Contract\DockerServiceInterface;
use DockerVol\Exception\DockerCommandException;
use DockerVol\Exception\RestoreException;
use DockerVol\Helper\ArchiveMetadata;
use DockerVol\Service\VolumeRestoreService;
use DockerVol\Tests\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

#[AllowMockObjectsWithoutExpectations]
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

    public function testValidateArchiveThrowsRestoreExceptionForUnsafeTarEntry(): void
    {
        $archivePath = $this->createTempTarArchive('.tar', '../evil.txt');
        $method = new \ReflectionMethod(VolumeRestoreService::class, 'validateArchive');
        $method->setAccessible(true);

        $this->expectException(RestoreException::class);
        $this->expectExceptionMessage('Unsafe archive contents');

        $method->invoke($this->restoreService, $archivePath);
    }

    public function testRestoreUsesPortableBindMountForWindowsStyleHostPath(): void
    {
        $_ENV['DOCKERVOL_DEV_MODE'] = '1';
        $_ENV['CONTAINER_PROJECT_DIR'] = sys_get_temp_dir();
        $_ENV['HOST_PROJECT_DIR'] = 'C:/Users/me/project';

        $archivePath = $this->createTempTarArchive('.tar.gz');
        $volumeName = basename($archivePath, '.tar.gz');
        $expectedHostBackupDir = 'C:/Users/me/project' . substr(dirname($archivePath), strlen(sys_get_temp_dir()));

        try {
            $this->dockerService
                ->method('volumeExists')
                ->willReturn(false)
            ;

            $this->dockerService
                ->method('createVolume')
                ->willReturn($this->createMockProcess(0, $volumeName))
            ;

            $callCount = 0;
            $this->dockerService
                ->expects($this->exactly(2))
                ->method('runContainer')
                ->willReturnCallback(function (array $dockerArgs) use (&$callCount, $expectedHostBackupDir) {
                    $callCount++;

                    if ($callCount === 1) {
                        $this->assertContains(
                            "type=bind,source={$expectedHostBackupDir},target=/backup,readonly",
                            $dockerArgs
                        );
                        $this->assertNotContains("{$expectedHostBackupDir}:/backup:ro", $dockerArgs);
                    }

                    return $callCount === 2
                        ? $this->createMockProcess(0, "12\t/volume\n")
                        : $this->createMockProcess(0, 'ok');
                })
            ;

            $result = $this->restoreService->restoreSingleVolume($archivePath);

            $this->assertTrue($result->isSuccessful());
        } finally {
            unset($_ENV['DOCKERVOL_DEV_MODE'], $_ENV['CONTAINER_PROJECT_DIR'], $_ENV['HOST_PROJECT_DIR']);
        }
    }

    public function testCreateVolumeThrowsRestoreExceptionWhenDockerCreateFails(): void
    {
        $this->dockerService
            ->expects($this->once())
            ->method('createVolume')
            ->with('broken-volume')
            ->willThrowException(new DockerCommandException('create failed'))
        ;

        $method = new \ReflectionMethod(VolumeRestoreService::class, 'createVolume');
        $method->setAccessible(true);

        $this->expectException(DockerCommandException::class);
        $this->expectExceptionMessage('create failed');

        $method->invoke($this->restoreService, 'broken-volume');
    }

    public function testCleanVolumeThrowsRestoreExceptionWhenDockerCleanFails(): void
    {
        $this->dockerService
            ->expects($this->once())
            ->method('runContainer')
            ->willThrowException(new DockerCommandException('clean failed'))
        ;

        $method = new \ReflectionMethod(VolumeRestoreService::class, 'cleanVolume');
        $method->setAccessible(true);

        $this->expectException(DockerCommandException::class);
        $this->expectExceptionMessage('clean failed');

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

    public function testGetAvailableBackupsListsBothTarAndTarGzWithoutGlobBrace(): void
    {
        $backupDir = $this->createTempDirectory();
        $tarPath = $backupDir . DIRECTORY_SEPARATOR . 'alpha.tar';
        $tarGzPath = $backupDir . DIRECTORY_SEPARATOR . 'beta.tar.gz';
        $this->writeTarArchive($tarPath);
        $this->writeTarArchive($tarGzPath);

        $backups = $this->restoreService->getAvailableBackups($backupDir);

        $this->assertCount(2, $backups);
        $this->assertArrayHasKey('alpha', $backups);
        $this->assertArrayHasKey('beta', $backups);
        $this->assertFalse($backups['alpha']['compressed']);
        $this->assertTrue($backups['beta']['compressed']);
    }

    public function testRestoreFailsOnChecksumMismatch(): void
    {
        $archivePath = $this->createTempTarArchive('.tar.gz');
        $volumeName = basename($archivePath, '.tar.gz');

        // Write sidecar with wrong checksum
        $sidecarPath = ArchiveMetadata::sidecarPath($archivePath);
        file_put_contents($sidecarPath, json_encode([
            'checksum_sha256' => str_repeat('0', 64),
        ]));

        $this->dockerService
            ->expects($this->never())
            ->method('volumeExists')
        ;

        $result = $this->restoreService->restoreSingleVolume($archivePath);

        $this->assertTrue($result->isFailed());
        $this->assertStringContainsString('Checksum mismatch', $result->message);
    }

    public function testRestoreSkipsChecksumCheckWhenNoSidecar(): void
    {
        $archivePath = $this->createTempTarArchive('.tar.gz');
        $volumeName = basename($archivePath, '.tar.gz');

        // No sidecar at all - should not throw on checksum
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
                $this->createMockProcess(0, 'ok'),
                $this->createMockProcess(0, "12\t/volume\n")
            )
        ;

        $result = $this->restoreService->restoreSingleVolume($archivePath);

        $this->assertTrue($result->isSuccessful());
    }
}
