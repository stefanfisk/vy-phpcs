<?php

declare(strict_types=1);

namespace StefanFisk\Vy\Utils;

use PHP_CodeSniffer\Fixer;

class FixerUtils
{
    public static function replaceTokens(Fixer $fixer, int $startPtr, int $endPtr, string $content): void
    {
        for ($ptr = $startPtr; $ptr < $endPtr; $ptr++) {
            $fixer->replaceToken($ptr, '');
        }

        $fixer->replaceToken($endPtr, $content);
    }
}
