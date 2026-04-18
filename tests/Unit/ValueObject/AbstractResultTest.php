<?php

declare(strict_types=1);

namespace DockerVol\Tests\Unit\ValueObject;

use DockerVol\Enum\OperationStatus;
use DockerVol\Tests\TestCase;
use DockerVol\ValueObject\BackupResult;
use PHPUnit\Framework\Attributes\DataProvider;

class AbstractResultTest extends TestCase
{
    #[DataProvider('formattedFileSizeProvider')]
    public function testGetFormattedFileSizeUsesSharedFormatter(?int $bytes, string $expected): void
    {
        $result = new BackupResult(
            resourceName: 'test',
            status: OperationStatus::SUCCESS,
            fileSize: $bytes
        );

        $this->assertSame($expected, $result->getFormattedFileSize());
    }

    public static function formattedFileSizeProvider(): array
    {
        return [
            [null, 'N/A'],
            [0, '0 B'],
            [1023, '1023.00 B'],
            [1024, '1.00 KB'],
            [1048576, '1.00 MB'],
            [1073741824, '1.00 GB'],
        ];
    }
}
