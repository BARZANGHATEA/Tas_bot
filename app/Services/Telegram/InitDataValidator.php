<?php

namespace App\Services\Telegram;

use App\Exceptions\BusinessRuleException;

/**
 * Validates Telegram Mini App init data exactly as documented at
 * https://core.telegram.org/bots/webapps#validating-data-received-via-the-mini-app
 *
 *   secret_key = HMAC_SHA256(key = "WebAppData", message = bot_token)
 *   hash       = hex(HMAC_SHA256(key = secret_key, message = data_check_string))
 *
 * where data_check_string is every received field except `hash`, sorted by key,
 * formatted as "key=value" and joined with "\n".
 */
class InitDataValidator
{
    /**
     * @return array{user: array, auth_date: int, start_param: ?string, query_id: ?string}
     *
     * @throws BusinessRuleException
     */
    public function validate(string $initData, string $botToken, int $maxAgeSeconds, ?int $now = null): array
    {
        $now ??= time();

        if ($botToken === '') {
            throw new BusinessRuleException('Telegram authentication is not configured.', 'auth_unavailable', 503);
        }
        if ($initData === '' || strlen($initData) > 8192) {
            throw $this->invalid();
        }

        $fields = [];
        foreach (explode('&', $initData) as $pair) {
            if ($pair === '') {
                continue;
            }
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $key = urldecode($key);
            if (array_key_exists($key, $fields)) {
                // Duplicate keys are never produced by Telegram: reject ambiguity.
                throw $this->invalid();
            }
            $fields[$key] = urldecode($value);
        }

        $hash = $fields['hash'] ?? '';
        unset($fields['hash']);

        if (! preg_match('/^[a-f0-9]{64}$/', $hash)) {
            throw $this->invalid();
        }

        ksort($fields, SORT_STRING);
        $dataCheckString = implode("\n", array_map(fn ($k, $v) => $k.'='.$v, array_keys($fields), $fields));

        $secretKey = hash_hmac('sha256', $botToken, 'WebAppData', true);
        $expected = hash_hmac('sha256', $dataCheckString, $secretKey);

        if (! hash_equals($expected, $hash)) {
            throw $this->invalid();
        }

        $authDate = (int) ($fields['auth_date'] ?? 0);
        if ($authDate <= 0 || $authDate > $now + 60 || $now - $authDate > $maxAgeSeconds) {
            throw new BusinessRuleException('Your Telegram session expired. Please reopen the app.', 'auth_expired', 401);
        }

        $user = json_decode($fields['user'] ?? '', true);
        if (! is_array($user) || ! is_int($user['id'] ?? null) || $user['id'] <= 0 || trim((string) ($user['first_name'] ?? '')) === '') {
            throw $this->invalid();
        }
        if (($user['is_bot'] ?? false) === true) {
            throw $this->invalid();
        }

        $startParam = $fields['start_param'] ?? null;
        if ($startParam !== null && ! preg_match('/^[A-Za-z0-9_-]{1,64}$/', $startParam)) {
            $startParam = null;
        }

        return [
            'user' => $user,
            'auth_date' => $authDate,
            'start_param' => $startParam,
            'query_id' => $fields['query_id'] ?? null,
        ];
    }

    /** Builds signed init data. Used by tests and the local development login. */
    public static function sign(array $fields, string $botToken): string
    {
        ksort($fields, SORT_STRING);
        $dataCheckString = implode("\n", array_map(fn ($k, $v) => $k.'='.$v, array_keys($fields), $fields));
        $secretKey = hash_hmac('sha256', $botToken, 'WebAppData', true);
        $fields['hash'] = hash_hmac('sha256', $dataCheckString, $secretKey);

        return http_build_query($fields, '', '&', PHP_QUERY_RFC3986);
    }

    private function invalid(): BusinessRuleException
    {
        return new BusinessRuleException('Telegram authentication failed. Please open the app from Telegram.', 'auth_invalid', 401);
    }
}
