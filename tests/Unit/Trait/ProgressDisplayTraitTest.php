<?php

declare(strict_types=1);

namespace DockerVol\Tests\Unit\Trait;

use DockerVol\Enum\OperationStatus;
use DockerVol\Tests\TestCase;
use DockerVol\Trait\ProgressDisplayTrait;
use DockerVol\ValueObject\BackupResult;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

class ProgressDisplayTraitTest extends TestCase
{
    public function testProgressOutputDoesNotWriteCarriageReturnsWhenOutputIsNotDecorated(): void
    {
        $output = new BufferedOutput();
        $output->setDecorated(false);
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $runner = new class {
            use ProgressDisplayTrait;

            public function run(SymfonyStyle $io): void
            {
                $this->performOperationsWithProgress(
                    ['item'],
                    $io,
                    fn () => new BackupResult(
                        resourceName: 'item',
                        status: OperationStatus::SUCCESS
                    )
                );
            }
        };

        $runner->run($io);

        $this->assertStringNotContainsString("\r", $output->fetch());
    }
}
