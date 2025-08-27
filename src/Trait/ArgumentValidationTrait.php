<?php

declare(strict_types=1);

namespace DockerBackup\Trait;

use Symfony\Component\Console\Style\SymfonyStyle;

trait ArgumentValidationTrait
{
    /**
     * Validate that required arguments are provided.
     * Shows error message and usage instructions if validation fails.
     */
    protected function validateRequiredArguments(array $arguments, SymfonyStyle $io): bool
    {
        if (empty($arguments)) {
            $io->error($this->getEmptyArgumentsErrorMessage());

            $usageExamples = $this->getUsageExamples();
            foreach ($usageExamples as $example) {
                $io->text($example);
            }

            return false;
        }

        return true;
    }

    /**
     * Get the error message when no arguments are provided.
     * Must be implemented by the using class.
     */
    abstract protected function getEmptyArgumentsErrorMessage(): string;

    /**
     * Get usage examples to show when validation fails.
     * Must be implemented by the using class.
     *
     * @return array<string>
     */
    abstract protected function getUsageExamples(): array;
}
