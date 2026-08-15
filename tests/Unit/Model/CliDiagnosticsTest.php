<?php

declare(strict_types=1);

namespace CleatSquad\HttpReplay\Tests\Unit\Model;

use CleatSquad\HttpReplay\Model\Difference;
use CleatSquad\HttpReplay\Model\MatchResult;
use PHPUnit\Framework\TestCase;

final class CliDiagnosticsTest extends TestCase
{
    public function testDifferenceCliStringUncolorized(): void
    {
        $diff = Difference::bodyAt('messages.0.content', 'ExpectedContent', 'ActualContent');
        $cliOutput = $diff->toCliString(colorize: false);

        $this->assertStringContainsString('Body mismatch at messages.0.content', $cliOutput);
        $this->assertStringContainsString('expected: ExpectedContent', $cliOutput);
        $this->assertStringContainsString('actual:   ActualContent', $cliOutput);
        $this->assertStringNotContainsString("\033[", $cliOutput);
    }

    public function testDifferenceCliStringColorized(): void
    {
        $diff = Difference::bodyAt('messages.0.content', 'ExpectedContent', 'ActualContent');
        $cliOutput = $diff->toCliString(colorize: true);

        $this->assertStringContainsString('Body mismatch at messages.0.content', $cliOutput);
        $this->assertStringContainsString("\033[", $cliOutput);
    }

    public function testMatchResultCliString(): void
    {
        $matchResult = MatchResult::mismatch([
            'body.messages.0.content' => 'Expected "Hello", got "Hi"',
        ]);

        $cliOutput = $matchResult->toCliString(colorize: false);

        $this->assertStringContainsString('Request mismatch:', $cliOutput);
        $this->assertStringContainsString('body.messages.0.content: Expected "Hello", got "Hi"', $cliOutput);
    }
}
