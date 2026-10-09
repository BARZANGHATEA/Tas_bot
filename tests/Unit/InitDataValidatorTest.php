<?php

namespace Tests\Unit;

use App\Exceptions\BusinessRuleException;
use App\Services\Telegram\InitDataValidator;
use PHPUnit\Framework\TestCase;

class InitDataValidatorTest extends TestCase
{
    private const TOKEN = '123456:TEST-TOKEN';

    private function fields(array $overrides = []): array
    {
        return array_merge([
            'query_id' => 'AAHdF6IQAAAAAN0XohDhrOrc',
            'user' => json_encode(['id' => 279058397, 'first_name' => 'Vlad', 'last_name' => 'Larin', 'username' => 'vdkfrost', 'language_code' => 'en']),
            'auth_date' => (string) time(),
            'start_param' => 'ref_ABCDEFGH',
        ], $overrides);
    }

    public function test_valid_init_data_is_accepted(): void
    {
        $data = (new InitDataValidator)->validate(InitDataValidator::sign($this->fields(), self::TOKEN), self::TOKEN, 3600);

        $this->assertSame(279058397, $data['user']['id']);
        $this->assertSame('ref_ABCDEFGH', $data['start_param']);
    }

    public function test_tampered_user_is_rejected(): void
    {
        $initData = InitDataValidator::sign($this->fields(), self::TOKEN);
        $tampered = str_replace('279058397', '111111111', $initData);

        $this->expectException(BusinessRuleException::class);
        (new InitDataValidator)->validate($tampered, self::TOKEN, 3600);
    }

    public function test_signature_from_another_bot_is_rejected(): void
    {
        $this->expectException(BusinessRuleException::class);
        (new InitDataValidator)->validate(InitDataValidator::sign($this->fields(), '999:OTHER'), self::TOKEN, 3600);
    }

    public function test_expired_auth_date_is_rejected(): void
    {
        $initData = InitDataValidator::sign($this->fields(['auth_date' => (string) (time() - 7200)]), self::TOKEN);

        try {
            (new InitDataValidator)->validate($initData, self::TOKEN, 3600);
            $this->fail('Expected expiry');
        } catch (BusinessRuleException $e) {
            $this->assertSame('auth_expired', $e->errorCode);
        }
    }

    public function test_missing_hash_or_user_is_rejected(): void
    {
        $this->expectException(BusinessRuleException::class);
        $fields = $this->fields();
        unset($fields['user']);
        (new InitDataValidator)->validate(InitDataValidator::sign($fields, self::TOKEN), self::TOKEN, 3600);
    }

    public function test_unsigned_data_is_rejected(): void
    {
        $this->expectException(BusinessRuleException::class);
        (new InitDataValidator)->validate(http_build_query($this->fields()), self::TOKEN, 3600);
    }
}
