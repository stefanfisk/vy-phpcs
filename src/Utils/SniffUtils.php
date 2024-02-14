<?php

declare(strict_types=1);

namespace StefanFisk\Vy\Utils;

use Generator;
use InvalidArgumentException;
use PHP_CodeSniffer\Files\File;

use function array_key_exists;
use function assert;
use function defined;
use function in_array;
use function ltrim;
use function strrpos;
use function substr;
use function trim;

use const T_ATTRIBUTE;
use const T_CLASS;
use const T_CLOSE_CURLY_BRACKET;
use const T_FUNCTION;
use const T_INTERFACE;
use const T_NAMESPACE;
use const T_NS_SEPARATOR;
use const T_OPEN_CURLY_BRACKET;
use const T_SEMICOLON;
use const T_STRING;
use const T_TRAIT;
use const T_USE;
use const T_WHITESPACE;

class SniffUtils
{
    /**
     * Returns the token stack for this file.
     *
     * Provides type hints.
     *
     * @return list<array{type:string,code:int|string,content:string,line:int,column:int,length:int,level:int,scope_opener?:int,scope_closer?:int,parenthesis_opener?:int,parenthesis_closer?:int,attribute_opener?:int,attribute_closer?:int}>
     *
     * @psalm-suppress MoreSpecificReturnType
     */
    public static function getTokens(File $phpcsFile): array
    {
        /** @psalm-suppress LessSpecificReturnStatement */
        return $phpcsFile->getTokens();
    }

    public static function findFirstTokenOnLine(File $phpcsFile, int $stackPtr): int
    {
        if ($stackPtr === 0) {
            return $stackPtr;
        }

        $tokens = self::getTokens($phpcsFile);

        $line = $tokens[$stackPtr]['line'];

        do {
            $stackPtr--;
        } while ($tokens[$stackPtr]['line'] === $line);

        return $stackPtr + 1;
    }

    /** @return Generator<int> */
    public static function findMethods(File $phpcsFile, int $classPtr): Generator
    {
        $tokens = self::getTokens($phpcsFile);

        if ($tokens[$classPtr]['code'] !== T_CLASS) {
            throw new InvalidArgumentException('$classPtr must be of type T_CLASS');
        }

        $scopeOpener = $tokens[$classPtr]['scope_opener'] ?? null;
        $scopeCloser = $tokens[$classPtr]['scope_closer'] ?? null;

        if ($scopeOpener === null || $scopeCloser === null) {
            // The class does not have a body

            return;
        }

        $next = $scopeOpener + 1;
        $end = $scopeCloser - 1;

        for (; $next <= $end; ++$next) {
            if ($tokens[$next]['code'] === T_FUNCTION) {
                yield $next;

                if (isset($tokens[$next]['scope_closer'])) {
                    // Skip the function body

                    $next = $tokens[$next]['scope_closer'];
                }
            }
        }
    }

    public static function findMethod(File $phpcsFile, int $classPtr, string $name): ?int
    {
        foreach (self::findMethods($phpcsFile, $classPtr) as $methodPtr) {
            $methodName = $phpcsFile->getDeclarationName($methodPtr);

            if ($methodName === $name) {
                return $methodPtr;
            }
        }

        return null;
    }

    /**
     * @return array{int,int}|null
     */
    public static function getParamsOpenClose(File $phpcsFile, int $methodPtr): ?array
    {
        $tokens = self::getTokens($phpcsFile);

        if ($tokens[$methodPtr]['code'] !== T_FUNCTION) {
            throw new InvalidArgumentException('$methodPtr must be of type T_FUNCTION');
        }

        $openerPtr = $tokens[$methodPtr]['parenthesis_opener'] ?? null;
        $closerPtr = $tokens[$openerPtr]['parenthesis_closer'] ?? null;

        if ($openerPtr === null || $closerPtr === null) {
            return null;
        }

        return [$openerPtr, $closerPtr];
    }

    public static function getParamsContent(File $phpcsFile, int $methodPtr): ?string
    {
        $openClosePtrs = self::getParamsOpenClose($phpcsFile, $methodPtr);

        if (!$openClosePtrs) {
            return null;
        }

        [$openerPtr, $closerPtr] = $openClosePtrs;

        return $phpcsFile->getTokensAsString($openerPtr, $closerPtr - $openerPtr + 1);
    }

    /**
     * Returns the method parameters for the specified function token.
     *
     * Provides type hints.
     *
     * @return array<array{name:string,content:string}>
     *
     * @psalm-suppress MixedReturnTypeCoercion
     */
    public static function getMethodParameters(File $phpcsFile, int $methodPtr): array
    {
        return $phpcsFile->getMethodParameters($methodPtr);
    }

    /**
     * @return array{int,int}|null
     */
    public static function getFunctionBodyOpenClose(File $phpcsFile, int $methodPtr): ?array
    {
        $tokens = self::getTokens($phpcsFile);

        if ($tokens[$methodPtr]['code'] !== T_FUNCTION) {
            throw new InvalidArgumentException('$methodPtr must be of type T_FUNCTION');
        }

        $openerPtr = $tokens[$methodPtr]['scope_opener'] ?? null;
        $closerPtr = $tokens[$openerPtr]['scope_closer'] ?? null;

        if ($openerPtr === null || $closerPtr === null) {
            return null;
        }

        return [$openerPtr, $closerPtr];
    }

    /**
     * @return Generator<array{attribute_opener:int,attribute_closer:int,attribute_class:string}>
     */
    public static function getMethodAttributes(File $phpcsFile, int $methodPtr): Generator
    {
        assert(defined('T_OPEN_CURLY_BRACKET'));
        assert(defined('T_CLOSE_CURLY_BRACKET'));

        $tokens = self::getTokens($phpcsFile);

        // Define boundary tokens to detect the start of a new context

        $boundaryTokens = [T_FUNCTION, T_CLASS, T_TRAIT, T_INTERFACE, T_OPEN_CURLY_BRACKET, T_CLOSE_CURLY_BRACKET];

        for ($i = $methodPtr - 1; $i > 0; $i--) {
            if ($tokens[$i]['code'] !== T_ATTRIBUTE) {
                // Stop searching if a boundary token is encountered.
                if (in_array($tokens[$i]['code'], $boundaryTokens, true)) {
                    break;
                }

                continue;
            }

            $attributeStartPtr = $i;
            $attributeEndPtr = $tokens[$i]['attribute_closer'] ?? null;

            if ($attributeEndPtr === null) {
                // Bail if attribute does not have a closer

                return;
            }

            // Extract the attribute class name

            $attributeClassName = '';

            for ($j = $attributeStartPtr + 1; $j < $attributeEndPtr; $j++) {
                if ($tokens[$j]['code'] === T_STRING || $tokens[$j]['code'] === T_NS_SEPARATOR) {
                    $attributeClassName .= $tokens[$j]['content'];
                } elseif ($tokens[$j]['code'] === T_WHITESPACE) {
                    // Stop at the first whitespace

                    break;
                }
            }

            $attributeClassName = self::resolveFullyQualifiedClassName($phpcsFile, $attributeClassName);

            if ($attributeClassName === null) {
                return null;
            }

            yield [
                'attribute_opener' => $attributeStartPtr,
                'attribute_closer' => $attributeEndPtr,
                'attribute_class' => $attributeClassName,
            ];
        }
    }

    public static function methodHasAttribute(File $phpcsFile, int $methodPtr, string $attributeClass): bool
    {
        foreach (self::getMethodAttributes($phpcsFile, $methodPtr) as ['attribute_class' => $class]) {
            if ($class === $attributeClass) {
                return true;
            }
        }

        return false;
    }

    public static function resolveFullyQualifiedClassName(File $phpcsFile, string $unqualifiedClassName): ?string
    {
        // psalm seems to require these
        assert(defined('T_SEMICOLON'));
        assert(defined('T_OPEN_CURLY_BRACKET'));

        $tokens = self::getTokens($phpcsFile);

        $useStatements = [];
        $namespace = null;

        // First, find the namespace declaration, if any.
        foreach ($tokens as $index => $token) {
            if ($token['code'] === T_NAMESPACE) {
                $namespaceStart = $phpcsFile->findNext(T_STRING, $index);
                if ($namespaceStart === false) {
                    return null;
                }

                $namespaceEnd = $phpcsFile->findNext([T_SEMICOLON, T_OPEN_CURLY_BRACKET], $namespaceStart);
                if ($namespaceEnd === false) {
                    return null;
                }

                $namespace = trim($phpcsFile->getTokensAsString($namespaceStart, $namespaceEnd - $namespaceStart));

                break; // Assuming only one namespace declaration per file.
            }
        }

        // Next, collect all use statements.
        foreach ($tokens as $index => $token) {
            if ($token['code'] === T_USE) {
                $useStartPtr = $phpcsFile->findNext([T_STRING, T_NS_SEPARATOR], $index + 1, null, true);
                if ($useStartPtr === false) {
                    return null;
                }

                $useEndPtr = $phpcsFile->findNext([T_SEMICOLON], $useStartPtr);
                if ($useEndPtr === false) {
                    return null;
                }

                $useStatement = trim($phpcsFile->getTokensAsString($useStartPtr, $useEndPtr - $useStartPtr));

                $lastNsSeparator = strrpos($useStatement, '\\');
                $alias = $lastNsSeparator !== false ? substr($useStatement, $lastNsSeparator + 1) : $useStatement;

                $useStatements[$alias] = ltrim($useStatement, '\\');
            }
        }

        // Attempt to resolve the unqualified class name.
        if (array_key_exists($unqualifiedClassName, $useStatements)) {
            return $useStatements[$unqualifiedClassName];
        } elseif ($namespace !== null) {
            return '\\' . $namespace . '\\' . $unqualifiedClassName;
        }

        // If no namespace or alias match, assume global namespace.
        return $unqualifiedClassName;
    }

    public static function findInsertionPointBeforeMethod(File $phpcsFile, int $methodPtr): ?int
    {
        $tokens = self::getTokens($phpcsFile);

        // Find the scope opener of the class containing the method.

        $classPtr = $phpcsFile->findPrevious([T_CLASS, T_INTERFACE, T_TRAIT], $methodPtr - 1);
        if ($classPtr === false) {
            // Not within a class/trait/interface.

            return null;
        }

        $classOpener = $tokens[$classPtr]['scope_opener'] ?? null;
        $classCloser = $tokens[$classPtr]['scope_closer'] ?? null;

        if ($classOpener === null || $classCloser === null) {
            // Class does not have a body
            return null;
        }

        // Ensure the methodPtr is within class bounds
        if ($methodPtr <= $classOpener || $methodPtr >= $classCloser) {
            return null; // Method pointer is outside the class scope
        }

        // Now, find the previous function or class member to determine the safe insertion point
        $prevFunctionPtr = $phpcsFile->findPrevious(T_FUNCTION, $methodPtr - 1, $classOpener);
        if ($prevFunctionPtr === false) {
            // No previous function, insert at class opener
            return $classOpener + 1; // After the opening brace of the class
        } else {
            // Found a previous function, insert after its closing brace
            $prevFunctionCloser = $tokens[$prevFunctionPtr]['scope_closer'] ?? null;

            if ($prevFunctionCloser === null) {
                return null;
            }

            return $prevFunctionCloser + 1; // After the closing brace of the previous function
        }
    }
}
