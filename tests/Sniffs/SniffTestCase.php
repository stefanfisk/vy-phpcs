<?php

declare(strict_types=1);

namespace StefanFisk\Vy\Tests\Sniffs;

use PHP_CodeSniffer\Config;
use PHP_CodeSniffer\Files\DummyFile;
use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Ruleset;
use PHP_CodeSniffer\Sniffs\Sniff;
use ReflectionClass;
use StefanFisk\Vy\Tests\TestCase;

use function assert;
use function end;
use function explode;

abstract class SniffTestCase extends TestCase
{
    /**
     * @param class-string<Sniff> $sniff
     * @param array<array{int,string,string,string}> $messages
     */
    protected function assertSniff(
        string $sniff,
        string $content,
        array $messages,
        string $fixedContent = '',
    ): void {
        $file = $this->processFile($sniff, $content);

        $this->assertMessages($messages, $file);

        $this->assertFixed($fixedContent, $file);
    }

    /**
     * @param class-string<Sniff> $sniff
     */
    private function processFile(string $sniff, string $content): File
    {
        $sniffRef = new ReflectionClass($sniff);

        $sniffFile = $sniffRef->getFileName();
        assert($sniffFile);

        $config = new Config();

        $ruleset = new Ruleset($config);
        $ruleset->registerSniffs([$sniffFile], [], []);
        $ruleset->populateTokenListeners();

        $phpcsFile = new DummyFile($content, $ruleset, $config);
        $phpcsFile->process();

        return $phpcsFile;
    }

    /**
     * @param array<array{int,string,string,string}> $expected
     */
    private function assertMessages(array $expected, File $file): void
    {
        $actual = [];

        foreach (
            [
                'WARNING' => $file->getWarnings(),
                'ERROR' => $file->getErrors(),
            ] as $level => $messages
        ) {
            foreach ($messages as $line => $columns) {
                foreach ($columns as $messages) {
                    foreach ($messages as $message) {
                        $source = $message['source'];
                        $sourceParts = explode('.', $source);
                        $code = end($sourceParts);

                        $actual[] = [$line, $level, $message['message'], $code];
                    }
                }
            }
        }

        $this->assertEqualsCanonicalizing(
            $expected,
            $actual,
            'The generated messages do not match the expected messages.',
        );
    }

    private function assertFixed(string $expected, File $file): void
    {
        if (!$expected) {
            return;
        }

        $file->fixer->fixFile();

        $actual = $file->fixer->getContents();

        $this->assertEquals($expected, $actual, 'The fixed file does not match the expected contents.');
    }
}
