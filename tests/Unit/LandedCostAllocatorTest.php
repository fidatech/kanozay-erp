<?php

namespace Tests\Unit;

use App\Services\LandedCostAllocator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class LandedCostAllocatorTest extends TestCase
{
    public function test_allocates_proportionally_and_preserves_total_with_rounding(): void
    {
        $result = (new LandedCostAllocator())->allocate('10.00', [11 => '1', 12 => '1', 13 => '1']);
        $this->assertSame([11 => '3.33', 12 => '3.33', 13 => '3.34'], $result);
    }

    public function test_zero_value_lines_receive_no_allocation(): void
    {
        $result = (new LandedCostAllocator())->allocate('7.25', [1 => '0', 2 => '5', 3 => '0']);
        $this->assertSame([1 => '0.00', 2 => '7.25', 3 => '0.00'], $result);
    }

    public function test_rejects_zero_total_weights(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new LandedCostAllocator())->allocate('1.00', [1 => '0']);
    }

    public function test_rejects_negative_cost(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new LandedCostAllocator())->allocate('-1', [1 => '1']);
    }
}
