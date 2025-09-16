<?php

declare(strict_types=1);

namespace DockerBackup\Tests\Functional\Command;

use DockerBackup\Command\BackupImagesCommand;
use DockerBackup\Command\BackupVolumesCommand;
use DockerBackup\Command\RestoreImagesCommand;
use DockerBackup\Command\RestoreVolumesCommand;
use DockerBackup\Contract\DockerServiceInterface;
use DockerBackup\Service\ImageBackupService;
use DockerBackup\Service\ImageRestoreService;
use DockerBackup\Service\VolumeBackupService;
use DockerBackup\Service\VolumeRestoreService;
use DockerBackup\Tests\TestCase;
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
            ->willReturnCallback(function () use ($archivePath) {
                touch($archivePath);

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
        $archivePath = $this->createTempFile('backup content', '.tar.gz');
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
            ->expects($this->exactly(3))
            ->method('runContainer')
            ->willReturnOnConsecutiveCalls(
                $this->createMockProcess(0, "file.txt\n"),
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

        $dockerService
            ->expects($this->once())
            ->method('listImages')
            ->willReturn([$this->createTestImage(repoTags: [$imageReference])])
        ;
        $dockerService
            ->expects($this->once())
            ->method('imageExists')
            ->with($imageReference)
            ->willReturn(true)
        ;
        $dockerService
            ->expects($this->once())
            ->method('saveImage')
            ->with($imageReference, $archivePath)
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

    public function testRestoreImagesCommandRestoresSelectedArchive(): void
    {
        $backupDir = $this->createTempDirectory();
        $imageReference = 'nginx:latest';
        $archivePath = $backupDir . DIRECTORY_SEPARATOR . rawurlencode($imageReference) . '.tar';
        file_put_contents($archivePath, 'backup content');
        $dockerService = $this->createMock(DockerServiceInterface::class);

        $dockerService
            ->expects($this->once())
            ->method('runContainer')
            ->willReturn($this->createMockProcess(0, "manifest.json\n"))
        ;
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
}
