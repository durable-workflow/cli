<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OnboardingVersionPinsTest extends TestCase
{
    #[DataProvider('publicOnboardingDocuments')]
    public function testPublicOnboardingDoesNotClaimAnExactPrereleaseAsCurrent(string $path): void
    {
        $document = file_get_contents(__DIR__.'/../'.$path);

        self::assertIsString($document);

        // Version-led Markdown bullets are retained historical release records,
        // not installation guidance or a claim about the current release.
        $onboarding = preg_replace(
            '/^- v?\d+\.\d+\.\d+-(?:alpha|beta|rc)\.\d+\h+(?:—|-)\h+.*$/mu',
            '',
            $document,
        );

        self::assertIsString($onboarding);
        self::assertDoesNotMatchRegularExpression(
            '/\bv?\d+\.\d+\.\d+-(?:alpha|beta|rc)\.\d+\b|\b\d+\.\d+\.\d+(?:a|b|rc)\d+\b/i',
            $onboarding,
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function publicOnboardingDocuments(): iterable
    {
        yield 'README' => ['README.md'];
        yield 'distribution guide' => ['docs/distribution.md'];
    }
}
