<?php

declare(strict_types=1);

namespace DockerVol\Tests\Unit\Service;

use DockerVol\Contract\DockerServiceInterface;
use DockerVol\Helper\ArchiveMetadata;
use DockerVol\Service\ImageRestoreService;
use DockerVol\Tests\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

#[AllowMockObjectsWithoutExpectations]
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

    public function testAvailableBackupsDecodeLegacyCustomRegistryFilenameBestEffort(): void
    {
        $backupDir = $this->createTempDirectory();
        touch($backupDir . DIRECTORY_SEPARATOR . 'my.private.registry.io_team_image_release.tar.gz');

        $backups = $this->restoreService->getAvailableBackups($backupDir);

        $this->assertCount(1, $backups);
        $this->assertSame('my.private.registry.io/team/image:release', $backups[0]['name']);
    }

    public function testRestoreSkipsExistingImageWithUnderscoreInName(): void
    {
        $backupDir = $this->createTempDirectory();
        $imageReference = 'registry.example.com/my_org/my_app:release_2026';
        $archivePath = $backupDir . DIRECTORY_SEPARATOR . rawurlencode($imageReference) . '.tar';
        $this->writeImageArchiveWithManifest($archivePath, [$imageReference]);

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
        $this->writeImageArchiveWithManifest($archivePath, ['docker.io/library/nginx:latest']);

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

    public function testRestoreUsesManifestRepoTagsWhenArchiveWasRenamed(): void
    {
        $backupDir = $this->createTempDirectory();
        $archivePath = $backupDir . DIRECTORY_SEPARATOR . 'renamed-archive.tar';
        $this->writeImageArchiveWithManifest($archivePath, ['registry.example.com/team/app:stable']);

        $this->dockerService->expects($this->never())->method('runContainer');

        $this->dockerService
            ->expects($this->once())
            ->method('imageExists')
            ->with('registry.example.com/team/app:stable')
            ->willReturn(true)
        ;

        $this->dockerService
            ->expects($this->never())
            ->method('loadImage')
        ;

        $result = $this->restoreService->restoreSingleImage($archivePath);

        $this->assertTrue($result->isSkipped());
    }

    public function testRestoreReadsManifestFromCompressedArchive(): void
    {
        $backupDir = $this->createTempDirectory();
        $archivePath = $backupDir . DIRECTORY_SEPARATOR . 'renamed-archive.tar.gz';
        $this->writeImageArchiveWithManifest($archivePath, ['nginx:1.25']);

        $this->dockerService
            ->expects($this->once())
            ->method('imageExists')
            ->with('nginx:1.25')
            ->willReturn(true)
        ;

        $this->dockerService
            ->expects($this->never())
            ->method('loadImageFromStream')
        ;

        $result = $this->restoreService->restoreSingleImage($archivePath);

        $this->assertTrue($result->isSkipped());
    }

    public function testRestoreStreamsCompressedArchiveToDockerLoad(): void
    {
        $backupDir = $this->createTempDirectory();
        $archivePath = $backupDir . DIRECTORY_SEPARATOR . rawurlencode('nginx:latest') . '.tar.gz';
        $this->writeImageArchiveWithManifest($archivePath, ['nginx:latest']);

        $this->dockerService
            ->expects($this->once())
            ->method('imageExists')
            ->with('nginx:latest')
            ->willReturn(false)
        ;

        $this->dockerService
            ->expects($this->never())
            ->method('loadImage')
        ;

        $streamedContent = '';
        $this->dockerService
            ->expects($this->once())
            ->method('loadImageFromStream')
            ->willReturnCallback(function (callable $writeInput) use (&$streamedContent) {
                $writeInput(function (string $chunk) use (&$streamedContent): void {
                    $streamedContent .= $chunk;
                });

                return $this->createMockProcess(0, 'Loaded image');
            })
        ;

        $result = $this->restoreService->restoreSingleImage($archivePath);

        $this->assertTrue($result->isSuccessful());
        $this->assertStringContainsString('manifest.json', $streamedContent);
    }

    public function testRestoreReportsCompressedStreamFailure(): void
    {
        $backupDir = $this->createTempDirectory();
        $archivePath = $backupDir . DIRECTORY_SEPARATOR . rawurlencode('nginx:latest') . '.tar.gz';
        $this->writeImageArchiveWithManifest($archivePath, ['nginx:latest']);

        $this->dockerService
            ->method('imageExists')
            ->willReturn(false)
        ;

        $this->dockerService
            ->expects($this->once())
            ->method('loadImageFromStream')
            ->willThrowException(new \RuntimeException('docker load failed'))
        ;

        $result = $this->restoreService->restoreSingleImage($archivePath);

        $this->assertTrue($result->isFailed());
        $this->assertStringContainsString('docker load failed', (string) $result->message);
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

    public function testRestoreFailsWhenManifestJsonMissing(): void
    {
        $backupDir = $this->createTempDirectory();
        $archivePath = $backupDir . DIRECTORY_SEPARATOR . rawurlencode('nginx:latest') . '.tar';
        $this->writeTarArchive($archivePath, 'layer.tar', 'data');

        $this->dockerService->expects($this->never())->method('loadImage');

        $result = $this->restoreService->restoreSingleImage($archivePath);

        $this->assertTrue($result->isFailed());
        $this->assertStringContainsString('manifest.json', (string) $result->message);
    }

    public function testRestoreFailsWhenManifestJsonIsInvalidJson(): void
    {
        $backupDir = $this->createTempDirectory();
        $archivePath = $backupDir . DIRECTORY_SEPARATOR . rawurlencode('nginx:latest') . '.tar';
        $tarContent = $this->createTarContent('manifest.json', 'not-json{');
        file_put_contents($archivePath, $tarContent);

        $result = $this->restoreService->restoreSingleImage($archivePath);

        $this->assertTrue($result->isFailed());
        $this->assertStringContainsString('invalid JSON', (string) $result->message);
    }

    public function testRestoreFailsWhenManifestJsonMissingConfigField(): void
    {
        $backupDir = $this->createTempDirectory();
        $archivePath = $backupDir . DIRECTORY_SEPARATOR . rawurlencode('nginx:latest') . '.tar';
        $manifest = json_encode([
            ['RepoTags' => ['nginx:latest'], 'Layers' => ['layer.tar']],
        ], JSON_THROW_ON_ERROR);
        $tarContent = $this->createTarContent('manifest.json', $manifest);
        file_put_contents($archivePath, $tarContent);

        $result = $this->restoreService->restoreSingleImage($archivePath);

        $this->assertTrue($result->isFailed());
        $this->assertStringContainsString("'Config'", (string) $result->message);
    }

    public function testRestoreFailsWhenManifestJsonMissingLayersField(): void
    {
        $backupDir = $this->createTempDirectory();
        $archivePath = $backupDir . DIRECTORY_SEPARATOR . rawurlencode('nginx:latest') . '.tar';
        $manifest = json_encode([
            ['Config' => 'config.json', 'RepoTags' => ['nginx:latest']],
        ], JSON_THROW_ON_ERROR);
        $tarContent = $this->createTarContent('manifest.json', $manifest);
        file_put_contents($archivePath, $tarContent);

        $result = $this->restoreService->restoreSingleImage($archivePath);

        $this->assertTrue($result->isFailed());
        $this->assertStringContainsString("'Layers'", (string) $result->message);
    }

    public function testRestoreFailsOnChecksumMismatchWithSidecar(): void
    {
        $backupDir = $this->createTempDirectory();
        $archivePath = $backupDir . DIRECTORY_SEPARATOR . rawurlencode('nginx:latest') . '.tar.gz';
        $this->writeImageArchiveWithManifest($archivePath, ['nginx:latest']);

        // Write sidecar with wrong checksum
        $sidecarPath = ArchiveMetadata::sidecarPath($archivePath);
        file_put_contents($sidecarPath, json_encode([
            'checksum_sha256' => str_repeat('0', 64),
        ]));

        $result = $this->restoreService->restoreSingleImage($archivePath);

        $this->assertTrue($result->isFailed());
        $this->assertStringContainsString('Checksum mismatch', $result->message);
    }

    public function testRestoreSkipsChecksumCheckWhenNoSidecar(): void
    {
        $backupDir = $this->createTempDirectory();
        $archivePath = $backupDir . DIRECTORY_SEPARATOR . rawurlencode('nginx:latest') . '.tar';
        $this->writeImageArchiveWithManifest($archivePath, ['nginx:latest']);

        // No sidecar - should not throw on checksum
        $this->dockerService
            ->expects($this->once())
            ->method('imageExists')
            ->with('nginx:latest')
            ->willReturn(false)
        ;

        $this->dockerService
            ->expects($this->once())
            ->method('loadImage')
        ;

        $result = $this->restoreService->restoreSingleImage($archivePath);

        $this->assertTrue($result->isSuccessful());
    }
}
