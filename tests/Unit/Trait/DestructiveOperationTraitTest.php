<?php

declare(strict_types=1);

namespace DockerVol\Tests\Unit\Trait;

use DockerVol\Tests\TestCase;
use DockerVol\Trait\DestructiveOperationTrait;
use Symfony\Component\Console\Style\SymfonyStyle;

class DestructiveOperationTraitTest extends TestCase
{
    public function testManyArchivesConfirmationCanApproveOperation(): void
    {
        $archivePaths = [
            $this->createTempFile('one', '.tar'),
            $this->createTempFile('two', '.tar'),
            $this->createTempFile('three', '.tar'),
        ];
        $io = $this->createMock(SymfonyStyle::class);
        $io->expects($this->once())->method('warning');
        $io->expects($this->atLeastOnce())->method('text');
        $io->expects($this->once())->method('newLine');
        $io->expects($this->once())
            ->method('confirm')
            ->with('Do you want to continue?', false)
            ->willReturn(true)
        ;

        $this->assertTrue($this->createDestructiveOperationProbe()->confirm(
            $archivePaths,
            false,
            $io
        ));
    }

    public function testLargeArchiveConfirmationCanRejectOperation(): void
    {
        $archivePath = $this->createTempFile(str_repeat('x', 11), '.tar');
        $io = $this->createMock(SymfonyStyle::class);
        $io->expects($this->once())->method('warning');
        $io->expects($this->atLeastOnce())->method('text');
        $io->expects($this->once())->method('newLine');
        $io->expects($this->once())
            ->method('confirm')
            ->with('Do you want to continue?', false)
            ->willReturn(false)
        ;

        $this->assertFalse($this->createDestructiveOperationProbe()->confirm(
            [$archivePath],
            false,
            $io
        ));
    }

    private function createDestructiveOperationProbe(): object
    {
        return new class {
            use DestructiveOperationTrait;

            public function confirm(array $archivePaths, bool $overwrite, SymfonyStyle $io): bool
            {
                return $this->confirmDestructiveOperation($archivePaths, $overwrite, $io);
            }

            protected function getManyArchivesThreshold(): int
            {
                return 2;
            }

            protected function getLargeArchiveThreshold(): int
            {
                return 10;
            }
        };
    }
}
