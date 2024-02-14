<?php

declare(strict_types=1);

namespace StefanFisk\Vy\Sniffs\Components;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;
use StefanFisk\Vy\Utils\FixerUtils;
use StefanFisk\Vy\Utils\SniffUtils;

use function ctype_upper;
use function implode;
use function lcfirst;
use function str_starts_with;
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
        // Bail if not render()

        $renderName = $phpcsFile->getDeclarationName($methodPtr);

        if ($renderName === null) {
            return;
        }

        $elName = $this->getElMethodName($renderName);

        if ($elName === null) {
            return;
        }

        $renderPtr = $methodPtr;

        // Bail if the method does not have the VyComponent attribute

        if (!SniffUtils::methodHasAttribute($phpcsFile, $renderPtr, self::VY_COMPONENT_ATTRIBUTE)) {
            return;
        }

        // Find matching el()

        $elPtr = SniffUtils::findMethod($phpcsFile, $classPtr, $elName);

        // If no el() is found

        if ($elPtr === null) {
            // Add error

            $this->addElNotFoundError($phpcsFile, $renderPtr, $renderName, $elName);

            return;
        }

        // If render() and el() params don't match

        $renderParamsContent = SniffUtils::getParamsContent($phpcsFile, $renderPtr);
        $elParamsContent = SniffUtils::getParamsContent($phpcsFile, $elPtr);

        if ($renderParamsContent !== $elParamsContent) {
            // Add error
            $this->addElParamsMismatchError($phpcsFile, $renderPtr, $renderName, $elPtr, $elName);

            return;
        }
    }

    private function addElNotFoundError(File $phpcsFile, int $renderPtr, string $renderName, string $elName): void
    {
        $paramsContent = SniffUtils::getParamsContent($phpcsFile, $renderPtr);
        $elPtr = SniffUtils::findInsertionPointBeforeMethod($phpcsFile, $renderPtr);

        if ($paramsContent === null || $elPtr === null) {
            // Live coding or syntax error, so bail
            return;
        }

        $fix = $phpcsFile->addFixableError(
            error: "Method \"$renderName\" does not have matching \"$elName\" method",
            stackPtr: $renderPtr,
            code: 'RenderWithoutEl',
        );

        if (!$fix) {
            return;
        }

        $params = SniffUtils::getMethodParameters($phpcsFile, $renderPtr);

        [$elScope, $elIsStatic, $component] = $this->getElAttributes($phpcsFile, $renderPtr, $renderName);

        $fixer = $phpcsFile->fixer;

        $fixer->beginChangeset();

        $fixer->addContent($elPtr, '    #[\\' . self::VY_ELEMENT_ATTRIBUTE . ']');
        $fixer->addNewline($elPtr);
        $fixer->addContent($elPtr, "    $elScope");
        if ($elIsStatic) {
            $fixer->addContent($elPtr, ' static');
        }
        $fixer->addContent($elPtr, " function $elName");
        $fixer->addContent($elPtr, $paramsContent);
        $fixer->addContent($elPtr, ': \\StefanFisk\\Vy\\Element ');
        $fixer->addContent($elPtr, $this->getElBodyContent($params, $component, $phpcsFile->eolChar));
        $fixer->addNewline($elPtr);
        $fixer->addNewline($elPtr);

        $fixer->endChangeset();
    }

    private function addElParamsMismatchError(
        File $phpcsFile,
        int $renderPtr,
        string $renderName,
        int $elPtr,
        string $elName,
    ): void {
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

        [, , $component] = $this->getElAttributes($phpcsFile, $renderPtr, $renderName);

        $elBodyContent = $this->getElBodyContent($params, $component, $phpcsFile->eolChar);

        FixerUtils::replaceTokens($fixer, $elBodyOpenClosePtrs[0], $elBodyOpenClosePtrs[1], $elBodyContent);
    }

    private static function getElMethodName(string $renderName): ?string
    {
        if ($renderName === 'render') {
            return 'el';
        }

        if (!str_starts_with($renderName, 'render')) {
            return null;
        }

        $renderSuffix = substr($renderName, 6);

        if (!ctype_upper($renderSuffix[0])) {
            return null;
        }

        $elPrefix = lcfirst($renderSuffix);

        return "{$elPrefix}El";
    }

    /**
     * @return array{string,bool,string}
     */
    private static function getElAttributes(File $phpcsFile, int $renderPtr, string $renderName): array
    {
        /** @var array{scope:string,is_static:bool} $renderProperties */
        $renderProperties = $phpcsFile->getMethodProperties($renderPtr);

        $renderScope = $renderProperties['scope'];
        $renderIsPublic = $renderScope === 'public';

        $renderIsStatic = $renderProperties['is_static'];

        if ($renderIsStatic) {
            $component = "static::$renderName(...)";
            $elIsStatic = true;
        } else {
            if ($renderIsPublic) {
                $component = 'static::class';
                $elIsStatic = true;
            } else {
                $component = "\$this->$renderName(...)";
                $elIsStatic = false;
            }
        }

        return [$renderScope, $elIsStatic, $component];
    }

    /**
     * @param array<array{name:string,...}> $params
     */
    private static function getElBodyContent(array $params, string $component, string $eolChar): string
    {
        $content = [];
        $content[] = '{';
        $content[] = "        return \\StefanFisk\\Vy\\el($component, [";
        foreach ($params as $param) {
            $propName = substr($param['name'], 1);

            $content[] = "            '$propName' => $param[name],";
        }
        $content[] = '        ]);';
        $content[] = '    }';

        return implode($eolChar, $content);
    }
}
