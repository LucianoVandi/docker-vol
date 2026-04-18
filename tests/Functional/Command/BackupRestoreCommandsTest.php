<?php

declare(strict_types=1);

namespace DockerVol\Tests\Functional\Command;

use DockerVol\Command\BackupImagesCommand;
use DockerVol\Command\BackupVolumesCommand;
use DockerVol\Command\RestoreImagesCommand;
use DockerVol\Command\RestoreVolumesCommand;
use DockerVol\Contract\DockerServiceInterface;
use DockerVol\Service\ImageBackupService;
use DockerVol\Service\ImageRestoreService;
use DockerVol\Service\VolumeBackupService;
use DockerVol\Service\VolumeRestoreService;
use DockerVol\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class BackupRestoreCommandsTest extends TestCase
{
    public function testBackupVolumesCommandBacksUpSelectedVolume(): void
    {
        $backupDir = $this->createTempDirectory();
        $archivePath = $backupDir . DIRECTORY_SEPARATOR . 'app-data.tar.gz';
        $dockerService = $this->createMock(DockerServiceInterface::class);

        $dockerService
            ->expects($this->once())
            ->method('listVolumes')
            ->willReturn([$this->createTestVolume('app-data')])
        ;
        $dockerService
            ->expects($this->once())
            ->method('volumeExists')
            ->with('app-data')
            ->willReturn(true)
        ;
        $dockerService
            ->expects($this->once())
            ->method('runContainer')
            ->willReturnCallback(function (array $dockerArgs) use ($backupDir) {
                $this->touchVolumeArchiveFromDockerArgs($dockerArgs, $backupDir);

                return $this->createMockProcess(0, 'Backup completed');
            })
        ;

        $tester = new CommandTester(new BackupVolumesCommand(new VolumeBackupService($dockerService)));

        $exitCode = $tester->execute([
            'volumes' => ['app-data'],
            '--output-dir' => $backupDir,
        ]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('Docker Volume Backup', $tester->getDisplay());
        $this->assertFileExists($archivePath);
    }

    public function testRestoreVolumesCommandRestoresSelectedArchive(): void
    {
        $archivePath = $this->createTempTarArchive('.tar.gz');
        $volumeName = basename($archivePath, '.tar.gz');
        $dockerService = $this->createMock(DockerServiceInterface::class);

        $dockerService
            ->expects($this->once())
            ->method('volumeExists')
            ->with($volumeName)
            ->willReturn(false)
        ;
        $dockerService
            ->expects($this->once())
            ->method('createVolume')
            ->with($volumeName)
            ->willReturn($this->createMockProcess(0, $volumeName))
        ;
        $dockerService
            ->expects($this->exactly(2))
            ->method('runContainer')
            ->willReturnOnConsecutiveCalls(
                $this->createMockProcess(0, 'Restore completed'),
                $this->createMockProcess(0, "12\t/volume\n")
            )
        ;

        $tester = new CommandTester(new RestoreVolumesCommand(new VolumeRestoreService($dockerService)));

        $exitCode = $tester->execute([
            'archives' => [basename($archivePath)],
            '--backup-dir' => dirname($archivePath),
        ]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('Docker Volume Restore', $tester->getDisplay());
    }

    public function testBackupImagesCommandBacksUpSelectedImage(): void
    {
        $backupDir = $this->createTempDirectory();
        $imageReference = 'nginx:latest';
        $archivePath = $backupDir . DIRECTORY_SEPARATOR . rawurlencode($imageReference) . '.tar';
        $dockerService = $this->createMock(DockerServiceInterface::class);

        $dockerService->expects($this->never())->method('listImages');
        $dockerService
            ->expects($this->exactly(2))
            ->method('imageExists')
            ->with($imageReference)
            ->willReturn(true)
        ;
        $dockerService
            ->expects($this->once())
            ->method('saveImage')
            ->with(
                $imageReference,
                $this->callback(fn (string $outputPath): bool => $this->isTemporaryArchivePath($outputPath, $archivePath))
            )
            ->willReturnCallback(function (string $imageReference, string $outputPath) {
                touch($outputPath);

                return $this->createMockProcess(0, 'Backup completed');
            })
        ;

        $tester = new CommandTester(new BackupImagesCommand(new ImageBackupService($dockerService)));

        $exitCode = $tester->execute([
            'images' => [$imageReference],
            '--output-dir' => $backupDir,
            '--no-compression' => true,
        ]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('Docker Image Backup', $tester->getDisplay());
        $this->assertFileExists($archivePath);
    }

    public function testBackupImagesCommandAcceptsDigestReference(): void
    {
        $backupDir = $this->createTempDirectory();
        $digestRef = 'sha256:abc123def456abc123def456abc123def456abc123def456abc123def456abc1';
        $archivePath = $backupDir . DIRECTORY_SEPARATOR . rawurlencode($digestRef) . '.tar.gz';
        $dockerService = $this->createMock(DockerServiceInterface::class);

        $dockerService->expects($this->never())->method('listImages');
        $dockerService
            ->expects($this->exactly(2))
            ->method('imageExists')
            ->with($digestRef)
            ->willReturn(true)
        ;
        $dockerService
            ->expects($this->once())
            ->method('streamSavedImage')
            ->willReturnCallback(function (string $ref, callable $onChunk) {
                $onChunk('some-tar-data');

                return $this->createMockProcess(0, '');
            })
        ;

        $tester = new CommandTester(new BackupImagesCommand(new ImageBackupService($dockerService)));

        $exitCode = $tester->execute([
            'images' => [$digestRef],
            '--output-dir' => $backupDir,
        ]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertFileExists($archivePath);
    }

    public function testBackupImagesCommandRejectsUnknownDigest(): void
    {
        $dockerService = $this->createMock(DockerServiceInterface::class);
        $dockerService->expects($this->never())->method('listImages');
        $dockerService
            ->expects($this->once())
            ->method('imageExists')
            ->with('sha256:deadbeef')
            ->willReturn(false)
        ;

        $tester = new CommandTester(new BackupImagesCommand(new ImageBackupService($dockerService)));

        $exitCode = $tester->execute([
            'images' => ['sha256:deadbeef'],
            '--output-dir' => sys_get_temp_dir(),
        ]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('do not exist', $tester->getDisplay());
    }

    public function testBackupImagesListCountsRenderedRowsForMultiTagImages(): void
    {
        $dockerService = $this->createMock(DockerServiceInterface::class);
        $dockerService
            ->expects($this->once())
            ->method('listImages')
            ->willReturn([
                $this->createTestImage(repoTags: ['app:latest', 'app:stable', 'app:1.0']),
            ])
        ;

        $tester = new CommandTester(new BackupImagesCommand(new ImageBackupService($dockerService)));

        $exitCode = $tester->execute(['--list' => true]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('Total: 3 images', $tester->getDisplay());
    }

    public function testRestoreImagesCommandRestoresSelectedArchive(): void
    {
        $backupDir = $this->createTempDirectory();
        $imageReference = 'nginx:latest';
        $archivePath = $backupDir . DIRECTORY_SEPARATOR . rawurlencode($imageReference) . '.tar';
        $this->writeImageArchiveWithManifest($archivePath, [$imageReference]);
        $dockerService = $this->createMock(DockerServiceInterface::class);

        $dockerService->expects($this->never())->method('runContainer');
        $dockerService
            ->expects($this->once())
            ->method('imageExists')
            ->with($imageReference)
            ->willReturn(false)
        ;
        $dockerService
            ->expects($this->once())
            ->method('loadImage')
            ->with($archivePath)
            ->willReturn($this->createMockProcess(0, 'Loaded image'))
        ;

        $tester = new CommandTester(new RestoreImagesCommand(new ImageRestoreService($dockerService)));

        $exitCode = $tester->execute([
            'archives' => [basename($archivePath)],
            '--backup-dir' => dirname($archivePath),
        ]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('Docker Image Restore', $tester->getDisplay());
    }

    public function testRestoreImagesCommandFailsForInvalidArchive(): void
    {
        $archivePath = $this->createTempFile('not a tar archive', '.tar');
        $dockerService = $this->createMock(DockerServiceInterface::class);

        $dockerService->expects($this->never())->method('imageExists');
        $dockerService->expects($this->never())->method('loadImage');

        $tester = new CommandTester(new RestoreImagesCommand(new ImageRestoreService($dockerService)));

        $exitCode = $tester->execute([
            'archives' => [basename($archivePath)],
            '--backup-dir' => dirname($archivePath),
        ]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('failed validation', $tester->getDisplay());
        $this->assertStringContainsString('Invalid tar header', $tester->getDisplay());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidTimeoutProvider(): iterable
    {
        yield 'text' => ['abc'];
        yield 'zero' => ['0'];
        yield 'negative' => ['-1'];
    }

    #[DataProvider('invalidTimeoutProvider')]
    public function testBackupCommandRejectsInvalidTimeout(string $timeout): void
    {
        $dockerService = $this->createMock(DockerServiceInterface::class);
        $dockerService->expects($this->never())->method('listVolumes');
        $dockerService->expects($this->never())->method('volumeExists');

        $tester = new CommandTester(new BackupVolumesCommand(new VolumeBackupService($dockerService)));

        $exitCode = $tester->execute([
            'volumes' => ['app-data'],
            '--timeout' => $timeout,
        ]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('--timeout must be a positive integer.', $tester->getDisplay());
    }

    #[DataProvider('invalidTimeoutProvider')]
    public function testRestoreCommandRejectsInvalidTimeout(string $timeout): void
    {
        $dockerService = $this->createMock(DockerServiceInterface::class);
        $dockerService->expects($this->never())->method('volumeExists');
        $dockerService->expects($this->never())->method('runContainer');

        $tester = new CommandTester(new RestoreVolumesCommand(new VolumeRestoreService($dockerService)));

        $exitCode = $tester->execute([
            'archives' => ['app-data.tar.gz'],
            '--timeout' => $timeout,
        ]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('--timeout must be a positive integer.', $tester->getDisplay());
    }

    private function touchVolumeArchiveFromDockerArgs(array $dockerArgs, string $backupDir): void
    {
        $archiveArgument = null;
        foreach ($dockerArgs as $argument) {
            if (is_string($argument) && str_starts_with($argument, '/backup/')) {
                $archiveArgument = $argument;

                break;
            }
        }

        $this->assertNotNull($archiveArgument);
        touch($backupDir . DIRECTORY_SEPARATOR . basename((string) $archiveArgument));
    }

    private function isTemporaryArchivePath(string $actualPath, string $finalPath): bool
    {
        $expectedPrefix = dirname($finalPath) . DIRECTORY_SEPARATOR . '.' . basename($finalPath) . '.tmp.';

        return str_starts_with($actualPath, $expectedPrefix);
    }
}
