<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SanityTest extends TestCase
{
    public function testBasicAssertion(): void
    {
        $this->assertSame(2, 1 + 1);
    }
}
