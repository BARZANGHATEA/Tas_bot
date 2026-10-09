<?php

namespace Tests\Unit;

use App\Support\Money;
use App\Support\TronAddress;
use App\Support\WalletMask;
use PHPUnit\Framework\TestCase;

class MoneyAndMaskTest extends TestCase
{
    public function test_money_is_exact_and_never_uses_float_rounding(): void
    {
        $sum = Money::of('0.1')->plus(Money::of('0.2'));
        $this->assertSame('0.300000', (string) $sum);
        $this->assertSame('0.30', Money::format($sum));
        $this->assertSame('1.234567', Money::str('1.2345679'), 'truncates beyond 6 decimals');
        $this->assertSame('0.000500', (string) Money::percentOf('0.01', '5'));
        $this->assertSame('0.0005', Money::format('0.0005'), 'tiny amounts are not shown as 0.00');
    }

    public function test_money_validation(): void
    {
        $this->assertTrue(Money::isValid('10'));
        $this->assertTrue(Money::isValid('10.123456'));
        $this->assertFalse(Money::isValid('10.1234567'));
        $this->assertFalse(Money::isValid('-1'));
        $this->assertFalse(Money::isValid('1e3'));
    }

    public function test_wallet_mask_keeps_only_configured_characters(): void
    {
        $address = 'TQ8xAbCdEfGhJkLmNpQrStUvWxYz7mK2';
        $masked = WalletMask::mask($address, 4, 4);

        $this->assertStringStartsWith('TQ8x', $masked);
        $this->assertStringEndsWith('7mK2', $masked);
        $this->assertSame(strlen($address), strlen($masked));
        $this->assertSame(strlen($address) - 8, substr_count($masked, '*'));
        $this->assertStringNotContainsString('AbCd', $masked);
    }

    public function test_wallet_mask_always_hides_at_least_half(): void
    {
        $masked = WalletMask::mask('ABCDEFGHIJ', 10, 10);
        $this->assertGreaterThanOrEqual(5, substr_count($masked, '*'));
    }

    public function test_public_alias(): void
    {
        $this->assertSame('Alex M.', WalletMask::alias('Alex  Morgan'));
        $this->assertSame('Alex M.', WalletMask::alias('Alex James Morgan'));
        $this->assertSame('Alex', WalletMask::alias('Alex'));
    }

    public function test_tron_address_checksum(): void
    {
        $this->assertTrue(TronAddress::isValid('TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t'));
        $this->assertFalse(TronAddress::isValid('TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6u'), 'checksum mismatch');
        $this->assertFalse(TronAddress::isValid('0x52908400098527886E0F7030069857D2E4169EE7'));
    }
}
