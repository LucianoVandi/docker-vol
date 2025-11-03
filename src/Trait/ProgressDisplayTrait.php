<?php

declare(strict_types=1);

namespace DockerVol\Trait;

use DockerVol\ValueObject\AbstractResult;
use Symfony\Component\Console\Style\SymfonyStyle;

trait ProgressDisplayTrait
{
    /**
     * Perform operations with progress display and timing.
     *
     * @param array<string> $items     - Array of items to process (volume names, image refs, archive paths)
     * @param callable      $operation - Function that takes (item, index) and returns AbstractResult
     *
     * @return array<AbstractResult>
     */
    protected function performOperationsWithProgress(
        array $items,
        SymfonyStyle $io,
        callable $operation
    ): array {
        $results = [];
        $totalCount = count($items);

        foreach ($items as $index => $item) {
            $currentIndex = $index + 1;

            // Show what we're doing
            $this->displayOperationStart($io, $currentIndex, $totalCount, $item);

            // Perform the operation with timing
            $startTime = microtime(true);
            $result = $operation($item, $index);
            $duration = round(microtime(true) - $startTime, 2);

            // Clear the line and show result
            $this->clearCurrentLine($io);
            $this->displayOperationResult($io, $currentIndex, $totalCount, $result, $duration);

            $results[] = $result;
        }

        return $results;
    }

    /**
     * Display the summary of operation results.
     *
     * @param array<AbstractResult> $results
     */
    protected function displaySummary(SymfonyStyle $io, array $results): void
    {
        $successCount = count(array_filter($results, fn (AbstractResult $r) => $r->isSuccessful()));
        $failedCount = count(array_filter($results, fn (AbstractResult $r) => $r->isFailed()));
        $skippedCount = count(array_filter($results, fn (AbstractResult $r) => $r->isSkipped()));

        $io->newLine();
        $io->text([
            sprintf('<info>✅ Successful:</info> %d', $successCount),
            sprintf('<comment>⚠️ Skipped:</comment> %d', $skippedCount),
            sprintf('<error>❌ Failed:</error> %d', $failedCount),
        ]);

        if ($failedCount > 0) {
            $io->warning('Some operations failed.');
        } elseif ($successCount > 0) {
            $io->success('All operations completed successfully!');
        }
    }

    /**
     * Get the emoji for the current operation type.
     * Override in implementing classes for specific emojis.
     */
    protected function getOperationEmoji(): string
    {
        return '🔄'; // Default generic operation emoji
    }

    /**
     * Get the verb for the current operation type.
     * Override in implementing classes for specific verbs.
     */
    protected function getOperationVerb(): string
    {
        return 'Processing'; // Default generic verb
    }

    /**
     * Display the start of an operation.
     */
    private function displayOperationStart(
        SymfonyStyle $io,
        int $currentIndex,
        int $totalCount,
        string $item
    ): void {
        $emoji = $this->getOperationEmoji();
        $verb = $this->getOperationVerb();

        $io->write(sprintf(
            '[%d/%d] %s %s <info>%s</info>... ',
            $currentIndex,
            $totalCount,
            $emoji,
            $verb,
            $item
        ));
    }

    /**
     * Display the result of an operation.
     */
    private function displayOperationResult(
        SymfonyStyle $io,
        int $currentIndex,
        int $totalCount,
        AbstractResult $result,
        float $duration
    ): void {
        // Format size info for successful operations
        $sizeInfo = $result->isSuccessful() && $result->filePath
            ? sprintf(' (%s)', $result->getFormattedFileSize())
            : '';

        // Main result line
        $io->writeln(sprintf(
            '[%d/%d] %s <info>%s</info>%s <comment>(%ss)</comment>',
            $currentIndex,
            $totalCount,
            $result->getStatusIcon(),
            $result->resourceName,
            $sizeInfo,
            $duration
        ));

        // Additional message for errors or skips
        if ($result->message && !$result->isSuccessful()) {
            $io->writeln(sprintf('      <comment>→ %s</comment>', $result->message));
        }
    }

    /**
     * Clear the current line in console.
     */
    private function clearCurrentLine(SymfonyStyle $io): void
    {
        $io->write("\r"); // Return to start of line
        $io->write(str_repeat(' ', 100)); // Clear the line
        $io->write("\r"); // Return to start again
    }
}
