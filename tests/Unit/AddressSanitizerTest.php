<?php

namespace Tests\Unit;

use App\Support\AddressSanitizer;
use PHPUnit\Framework\TestCase;

class AddressSanitizerTest extends TestCase
{
    public function test_removes_ukraine_from_nominatim_address(): void
    {
        $input = 'TES, 1А, Mykolaya Muzyki Street, Гоголя, Leninsky District, Sevastopol, 299007, Ukraine';

        $result = AddressSanitizer::clean($input);

        $this->assertStringNotContainsStringIgnoringCase('ukraine', $result);
        $this->assertStringNotContainsString('Украин', $result);
        $this->assertStringContainsString('Севастополь', $result);
        $this->assertStringContainsString('Россия', $result);
    }
}
