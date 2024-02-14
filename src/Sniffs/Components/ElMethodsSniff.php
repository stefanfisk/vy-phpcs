<?php

declare(strict_types=1);

namespace StefanFisk\Vy\Sniffs\Components;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;
use StefanFisk\Vy\Utils\FixerUtils;
use StefanFisk\Vy\Utils\SniffUtils;

use function implode;
use function substr;

use const T_CLASS;

class ElMethodsSniff implements Sniff
{
    private const VY_ELEMENT_ATTRIBUTE = 'StefanFisk\Vy\Attributes\VyElement';
    private const VY_COMPONENT_ATTRIBUTE = 'StefanFisk\Vy\Attributes\VyComponent';

    /** {@inheritdoc} */
    public function register()
    {
        return [T_CLASS];
    }

    /** {@inheritdoc} */
    public function process(File $phpcsFile, $stackPtr)
    {
        $tokens = SniffUtils::getTokens($phpcsFile);

        $token = $tokens[$stackPtr];

        // Skip for classes without body.
        if (isset($token['scope_opener']) === false) {
            return;
        }

        foreach (SniffUtils::findMethods($phpcsFile, $stackPtr) as $methodPtr) {
            $this->processMethod($phpcsFile, $methodPtr, $stackPtr);
        }
    }

    private function processMethod(File $phpcsFile, int $methodPtr, int $classPtr): void
    {
        $this->processRenderMethodToken($phpcsFile, $methodPtr, $classPtr);
    }

    private function processRenderMethodToken(File $phpcsFile, int $stackPtr, int $currScope): void
    {
        // Bail if not render()

        $name = $phpcsFile->getDeclarationName($stackPtr);

        if ($name !== 'render') {
            return;
        }

        $renderPtr = $stackPtr;

        // Bail if the method does not have the VyComponent attribute

        if (!SniffUtils::methodHasAttribute($phpcsFile, $renderPtr, self::VY_COMPONENT_ATTRIBUTE)) {
            return;
        }

        // Find matching el()

        $elPtr = SniffUtils::findMethod($phpcsFile, $currScope, 'el');

        // If no el() is found

        if ($elPtr === null) {
            // Add error

            $this->addElNotFoundError($phpcsFile, $renderPtr);

            return;
        }

        // If render() and el() params don't match

        $renderParamsContent = SniffUtils::getParamsContent($phpcsFile, $renderPtr);
        $elParamsContent = SniffUtils::getParamsContent($phpcsFile, $elPtr);

        if ($renderParamsContent !== $elParamsContent) {
            // Add error
            $this->addElParamsMismatchError($phpcsFile, $renderPtr, $elPtr);

            return;
        }
    }

    private function addElNotFoundError(File $phpcsFile, int $renderPtr): void
    {
        $paramsContent = SniffUtils::getParamsContent($phpcsFile, $renderPtr);
        $elPtr = SniffUtils::findInsertionPointBeforeMethod($phpcsFile, $renderPtr);

        if ($paramsContent === null || $elPtr === null) {
            // Live coding or syntax error, so bail
            return;
        }

        $fix = $phpcsFile->addFixableError(
            error: 'Method "render" does not have matching "el" method',
            stackPtr: $renderPtr,
            code: 'RenderWithoutEl',
        );

        if (!$fix) {
            return;
        }

        /** @var string $visibility */
        $visibility = $phpcsFile->getMethodProperties($renderPtr)['scope'];

        $params = SniffUtils::getMethodParameters($phpcsFile, $renderPtr);

        $fixer = $phpcsFile->fixer;

        $fixer->beginChangeset();

        $fixer->addContent($elPtr, '    #[\\' . self::VY_ELEMENT_ATTRIBUTE . ']');
        $fixer->addNewline($elPtr);
        $fixer->addContent($elPtr, "    $visibility static function el");
        $fixer->addContent($elPtr, $paramsContent);
        $fixer->addContent($elPtr, ': \\StefanFisk\\Vy\\Element ');
        $fixer->addContent($elPtr, $this->getElBodyContent($params, $phpcsFile->eolChar));
        $fixer->addNewline($elPtr);
        $fixer->addNewline($elPtr);

        $fixer->endChangeset();
    }

    private function addElParamsMismatchError(File $phpcsFile, int $renderPtr, int $elPtr): void
    {
        $params = SniffUtils::getMethodParameters($phpcsFile, $renderPtr);
        $paramsOpenClosePtrs = SniffUtils::getParamsOpenClose($phpcsFile, $elPtr);
        $paramsContent = SniffUtils::getParamsContent($phpcsFile, $renderPtr);
        $elBodyOpenClosePtrs = SniffUtils::getFunctionBodyOpenClose($phpcsFile, $elPtr);

        if ($paramsOpenClosePtrs === null || $paramsContent === null || $elBodyOpenClosePtrs === null) {
            // Live coding or syntax error, so bail
            return;
        }

        $fix = $phpcsFile->addFixableError(
            error: 'Parameters of "el()" do not match parameters of "render()"',
            stackPtr: $elPtr,
            code: 'RenderElParamsMismatch',
        );

        if (!$fix) {
            return;
        }

        $fixer = $phpcsFile->fixer;

        // Replace el() params content

        FixerUtils::replaceTokens($fixer, $paramsOpenClosePtrs[0], $paramsOpenClosePtrs[1], $paramsContent);

        // Replace el() function body

        $elBodyContent = $this->getElBodyContent($params, $phpcsFile->eolChar);

        FixerUtils::replaceTokens($fixer, $elBodyOpenClosePtrs[0], $elBodyOpenClosePtrs[1], $elBodyContent);
    }

    /**
     * @param array<array{name:string,...}> $params
     */
    public static function getElBodyContent(array $params, string $eolChar): string
    {
        $content = [];
        $content[] = '{';
        $content[] = "        return \\StefanFisk\\Vy\\el(static::class, [";
        foreach ($params as $param) {
            $propName = substr($param['name'], 1);

            $content[] = "            '$propName' => $param[name],";
        }
        $content[] = '        ]);';
        $content[] = '    }';

        return implode($eolChar, $content);
    }
}
