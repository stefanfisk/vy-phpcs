<?php

declare(strict_types=1);

namespace StefanFisk\Vy\Tests\Sniffs\Components;

use PHPUnit\Framework\Attributes\CoversClass;
use StefanFisk\Vy\Sniffs\Components\ElMethodsSniff;
use StefanFisk\Vy\Tests\Sniffs\SniffTestCase;

#[CoversClass(ElMethodsSniff::class)]
class ElMethodsSniffTest extends SniffTestCase
{
    public function testIgnoresPublicRenderWithoutVyComponent(): void
    {
        $this->assertSniff(
            sniff: ElMethodsSniff::class,
            content: '<?php

class Foo
{
    public function render(
        ?string $foo = null,
        mixed $children = null,
    ): mixed {
        return $children;
    }
}
',
            messages: [],
        );
    }

    public function testGeneratesElForPublicVyComponentRenderWithoutEl(): void
    {
        $this->assertSniff(
            sniff: ElMethodsSniff::class,
            content: '<?php

use StefanFisk\Vy\Attributes\VyComponent;

class Foo
{
    #[VyComponent]
    public function render(
        ?string $foo = null,
        mixed $children = null,
    ): mixed {
        return $children;
    }
}
',
            messages: [
                [8, 'ERROR', 'Method "render" does not have matching "el" method', 'RenderWithoutEl'],
            ],
            fixedContent: '<?php

use StefanFisk\Vy\Attributes\VyComponent;

class Foo
{
    #[\StefanFisk\Vy\Attributes\VyElement]
    public static function el(
        ?string $foo = null,
        mixed $children = null,
    ): \\StefanFisk\\Vy\\Element {
        return \\StefanFisk\\Vy\\el(static::class, [
            \'foo\' => $foo,
            \'children\' => $children,
        ]);
    }

    #[VyComponent]
    public function render(
        ?string $foo = null,
        mixed $children = null,
    ): mixed {
        return $children;
    }
}
',
        );
    }

    public function testGeneratesElForPrivateVyComponentRenderWithoutEl(): void
    {
        $this->assertSniff(
            sniff: ElMethodsSniff::class,
            content: '<?php

use StefanFisk\Vy\Attributes\VyComponent;

class Foo
{
    #[VyComponent]
    private function render(
        ?string $foo = null,
        mixed $children = null,
    ): mixed {
        return $children;
    }
}
',
            messages: [
                [8, 'ERROR', 'Method "render" does not have matching "el" method', 'RenderWithoutEl'],
            ],
            fixedContent: '<?php

use StefanFisk\Vy\Attributes\VyComponent;

class Foo
{
    #[\StefanFisk\Vy\Attributes\VyElement]
    private static function el(
        ?string $foo = null,
        mixed $children = null,
    ): \\StefanFisk\\Vy\\Element {
        return \\StefanFisk\\Vy\\el(static::class, [
            \'foo\' => $foo,
            \'children\' => $children,
        ]);
    }

    #[VyComponent]
    private function render(
        ?string $foo = null,
        mixed $children = null,
    ): mixed {
        return $children;
    }
}
',
        );
    }

    public function testUpdatesElForPublicVyComponentRenderAndPublicVyElementElWithNonMatchingParams(): void
    {
        $this->assertSniff(
            sniff: ElMethodsSniff::class,
            content: '<?php

use StefanFisk\Vy\Attributes\VyComponent;
use StefanFisk\Vy\Attributes\VyElement;

class Foo
{
    #[VyElement]
    public static function el(
        ?string $foo = null,
    ): \\StefanFisk\\Vy\\Element {
        return \\StefanFisk\\Vy\\el(static::class, [
            \'foo\' => $foo,
        ]);
    }

    #[VyComponent]
    public function render(
        ?string $bar = null,
    ): mixed {
        return $bar;
    }
}
',
            messages: [
                [9, 'ERROR', 'Parameters of "el()" do not match parameters of "render()"', 'RenderElParamsMismatch'],
            ],
            fixedContent: '<?php

use StefanFisk\Vy\Attributes\VyComponent;
use StefanFisk\Vy\Attributes\VyElement;

class Foo
{
    #[VyElement]
    public static function el(
        ?string $bar = null,
    ): \\StefanFisk\\Vy\\Element {
        return \\StefanFisk\\Vy\\el(static::class, [
            \'bar\' => $bar,
        ]);
    }

    #[VyComponent]
    public function render(
        ?string $bar = null,
    ): mixed {
        return $bar;
    }
}
',
        );
    }
}
