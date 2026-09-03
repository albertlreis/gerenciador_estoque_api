<?php

namespace Tests\Unit;

use App\Support\ProductIdentifierSearch;
use PHPUnit\Framework\TestCase;

class ProductIdentifierSearchTest extends TestCase
{
    /** @dataProvider equivalentReferences */
    public function test_normalizes_case_and_common_separators(string $input): void
    {
        $this->assertSame('k1167vd', ProductIdentifierSearch::normalize($input));
    }

    public static function equivalentReferences(): array
    {
        return [
            ['K1167 VD'],
            ['k1167vd'],
            ['K1167-VD'],
            ['K1167/VD'],
            ['K.1167_VD'],
        ];
    }

    public function test_returns_null_for_empty_or_separator_only_values(): void
    {
        $this->assertNull(ProductIdentifierSearch::normalize(null));
        $this->assertNull(ProductIdentifierSearch::normalize('  - / . _  '));
    }

    public function test_escapes_like_wildcards_that_are_not_separators(): void
    {
        $this->assertSame('%ab\%cd%', ProductIdentifierSearch::normalizedLike('AB%CD'));
    }
}
