<?php

namespace App\Http\Requests\Admin;

use App\Enums\MissionType;
use App\Enums\MissionVerification;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class MissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user('admin')?->hasPermission('missions.manage');
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'icon' => ['nullable', 'string', 'max:16'],
            'image_url' => ['nullable', 'url:https', 'max:512'],
            'type' => ['required', Rule::enum(MissionType::class)],
            'verification' => ['required', Rule::enum(MissionVerification::class)],
            'target' => ['nullable', 'string', 'max:255'],
            'target_count' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'reward' => ['required', 'string', 'regex:/^\d{1,6}(\.\d{1,6})?$/'],
            'repeat' => ['required', 'in:once,daily'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'daily_limit' => ['nullable', 'integer', 'min:1'],
            'total_limit' => ['nullable', 'integer', 'min:1'],
            'budget' => ['nullable', 'string', 'regex:/^\d{1,9}(\.\d{1,6})?$/'],
            'min_games' => ['nullable', 'integer', 'min:1'],
            'min_account_age_hours' => ['nullable', 'integer', 'min:1'],
            'status' => ['required', 'in:active,paused,completed'],
            'sort_order' => ['nullable', 'integer', 'min:-1000', 'max:1000'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            $type = MissionType::tryFrom((string) $this->input('type'));
            $verification = MissionVerification::tryFrom((string) $this->input('verification'));
            if (! $type || ! $verification) {
                return;
            }

            if (! in_array($verification, $type->allowedVerifications(), true)) {
                $allowed = implode(', ', array_map(fn ($v) => $v->label(), $type->allowedVerifications()));
                $validator->errors()->add('verification', "{$type->label()} missions can only be verified by: {$allowed}.");
            }

            $target = trim((string) $this->input('target'));
            match ($type) {
                MissionType::TelegramChannel, MissionType::TelegramGroup => preg_match('/^(@[A-Za-z0-9_]{4,64}|-100\d{5,20}|https:\/\/t\.me\/[A-Za-z0-9_+\/-]+)$/', $target)
                    ?: $validator->errors()->add('target', 'Enter @channelname, a -100… chat id or a https://t.me/… link.'),
                MissionType::Website => str_starts_with($target, 'https://')
                    ?: $validator->errors()->add('target', 'Enter the https:// address of the website.'),
                MissionType::Instagram => $target !== '' ?: $validator->errors()->add('target', 'Enter the Instagram username or profile URL.'),
                MissionType::Invite, MissionType::DailyActivity => $this->filled('target_count')
                    ?: $validator->errors()->add('target_count', 'Set how many friends / games are required.'),
                default => null,
            };

            if ($type === MissionType::Custom && $target !== '' && ! str_starts_with($target, 'https://')) {
                $validator->errors()->add('target', 'Custom mission links must start with https://');
            }
        }];
    }

    public function missionData(): array
    {
        $data = $this->validated();
        foreach (['description', 'icon', 'image_url', 'target', 'target_count', 'starts_at', 'ends_at', 'daily_limit', 'total_limit', 'budget', 'min_games', 'min_account_age_hours'] as $key) {
            $data[$key] = ($data[$key] ?? null) === '' ? null : ($data[$key] ?? null);
        }
        $data['sort_order'] = (int) ($data['sort_order'] ?? 0);

        // Dates are entered in the business time zone and stored in UTC.
        $tz = app(\App\Services\Settings::class)->timezone();
        foreach (['starts_at', 'ends_at'] as $key) {
            $data[$key] = $data[$key] ? \Carbon\CarbonImmutable::parse($data[$key], $tz)->utc() : null;
        }

        return $data;
    }
}
