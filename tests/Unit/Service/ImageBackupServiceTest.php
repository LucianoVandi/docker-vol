<?php

declare(strict_types=1);

namespace DockerVol\Tests\Unit\Service;

use DockerVol\Contract\DockerServiceInterface;
use DockerVol\Service\ImageBackupService;
use DockerVol\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

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
            ->with(
                $imageReference,
                $this->callback(fn (string $outputPath): bool => $this->isTemporaryArchivePath($outputPath, $expectedArchivePath))
            )
            ->willReturnCallback(function (string $imageReference, string $outputPath) {
                touch($outputPath);

                return $this->createMockProcess(0, 'Backup completed');
            })
        ;

        $result = $this->backupService->backupSingleImage($imageReference, $backupDir, false);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame($expectedArchivePath, $result->filePath);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function imageReferencesProvider(): iterable
    {
        yield 'underscore' => ['my_org/my_app:release_2026'];
        yield 'slash' => ['library/nginx:latest'];
        yield 'registry prefix' => ['registry.example.com/team/nginx:1.25'];
        yield 'tag' => ['postgres:16-alpine'];
        yield 'supported special characters' => ['ghcr.io/acme/api-worker:test.build-42_rc1'];
    }

    #[DataProvider('imageReferencesProvider')]
    public function testBackupFilenamesPreserveImageReferenceCharacters(string $imageReference): void
    {
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
            ->with(
                $imageReference,
                $this->callback(fn (string $outputPath): bool => $this->isTemporaryArchivePath($outputPath, $expectedArchivePath))
            )
            ->willReturnCallback(function (string $imageReference, string $outputPath) {
                touch($outputPath);

                return $this->createMockProcess(0, 'Backup completed');
            })
        ;

        $result = $this->backupService->backupSingleImage($imageReference, $backupDir, false);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame($expectedArchivePath, $result->filePath);
    }

    public function testBackupSkipsWhenArchiveAlreadyExists(): void
    {
        $imageReference = 'nginx:latest';
        $backupDir = $this->createTempDirectory();
        touch($backupDir . DIRECTORY_SEPARATOR . rawurlencode($imageReference) . '.tar.gz');

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
            ->expects($this->never())
            ->method('streamSavedImage')
        ;

        $result = $this->backupService->backupSingleImage($imageReference, $backupDir);

        $this->assertTrue($result->isSkipped());
        $this->assertStringContainsString('File already exists', (string) $result->message);
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
            ->with($imageReference, $this->callback(static fn (mixed $value): bool => is_callable($value)))
            ->willReturnCallback(function (string $imageReference, callable $onChunk) {
                $onChunk('tar-content');

                return $this->createMockProcess(0, 'Backup completed');
            })
        ;

        $result = $this->backupService->backupSingleImage($imageReference, $backupDir);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame($expectedArchivePath, $result->filePath);
        $this->assertSame('tar-content', gzdecode((string) file_get_contents($expectedArchivePath)));
        $this->assertSame([], glob($backupDir . DIRECTORY_SEPARATOR . '.*.tmp.*') ?: []);
    }

    public function testBackupImagesPropagatesCompressionOption(): void
    {
        $imageReference = 'nginx:latest';
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
            ->with(
                $imageReference,
                $this->callback(fn (string $outputPath): bool => $this->isTemporaryArchivePath($outputPath, $expectedArchivePath))
            )
            ->willReturnCallback(function (string $imageReference, string $outputPath) {
                touch($outputPath);

                return $this->createMockProcess(0, 'Backup completed');
            })
        ;

        $this->dockerService
            ->expects($this->never())
            ->method('streamSavedImage')
        ;

        $results = $this->backupService->backupImages([$imageReference], $backupDir, false);

        $this->assertCount(1, $results);
        $this->assertTrue($results[0]->isSuccessful());
        $this->assertSame($expectedArchivePath, $results[0]->filePath);
    }

    public function testBackupSingleImageCreatesMissingBackupDirectory(): void
    {
        $imageReference = 'nginx:latest';
        $backupDir = $this->createTempDirectory() . DIRECTORY_SEPARATOR . 'nested';
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
            ->with(
                $imageReference,
                $this->callback(fn (string $outputPath): bool => $this->isTemporaryArchivePath($outputPath, $expectedArchivePath))
            )
            ->willReturnCallback(function (string $imageReference, string $outputPath) {
                touch($outputPath);

                return $this->createMockProcess(0, 'Backup completed');
            })
        ;

        $result = $this->backupService->backupSingleImage($imageReference, $backupDir, false);

        $this->assertTrue($result->isSuccessful());
        $this->assertFileExists($expectedArchivePath);
    }

    public function testBackupSingleImageCleansTemporaryArchiveWhenDockerFails(): void
    {
        $imageReference = 'nginx:latest';
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
            ->with(
                $imageReference,
                $this->callback(fn (string $outputPath): bool => $this->isTemporaryArchivePath($outputPath, $expectedArchivePath))
            )
            ->willReturnCallback(function (string $imageReference, string $outputPath) {
                touch($outputPath);

                throw new \DockerVol\Exception\DockerCommandException('Docker error');
            })
        ;

        $result = $this->backupService->backupSingleImage($imageReference, $backupDir, false);

        $this->assertTrue($result->isFailed());
        $this->assertFileDoesNotExist($expectedArchivePath);
        $this->assertSame([], glob($backupDir . DIRECTORY_SEPARATOR . '.*.tmp.*') ?: []);
    }

    private function isTemporaryArchivePath(string $actualPath, string $finalPath): bool
    {
        $expectedPrefix = dirname($finalPath) . DIRECTORY_SEPARATOR . '.' . basename($finalPath) . '.tmp.';

        return str_starts_with($actualPath, $expectedPrefix);
    }
}
