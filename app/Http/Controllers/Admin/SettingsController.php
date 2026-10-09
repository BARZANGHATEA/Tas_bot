<?php

namespace App\Http\Controllers\Admin;

use App\Services\AuditLogger;
use App\Services\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

class SettingsController extends AdminController
{
    public function __construct(private readonly Settings $settings) {}

    public function edit(?string $group = null): View
    {
        $schema = $this->settings->schema();
        $group ??= array_key_first($schema);
        abort_unless(isset($schema[$group]), 404);

        return view('admin.settings.edit', [
            'schema' => $schema,
            'group' => $group,
            'definition' => $schema[$group],
            'values' => $this->settings->all(),
            'canSensitive' => $this->admin()->hasPermission('settings.sensitive'),
        ]);
    }

    public function update(Request $request, string $group, AuditLogger $audit): RedirectResponse
    {
        $schema = $this->settings->schema();
        abort_unless(isset($schema[$group]), 404);
        $definition = $schema[$group];
        $sensitive = (bool) ($definition['sensitive'] ?? false);

        if ($sensitive) {
            abort_unless($this->admin()->hasPermission('settings.sensitive'), 403, 'Only super administrators can change these settings.');
            $request->validate($this->confirmRules());
        }

        $values = [];
        $data = [];
        $rules = [];
        $attributes = [];
        $input = $request->input('settings', []);

        foreach ($definition['fields'] as $key => $field) {
            // Form field names cannot contain dots; "app.name" is posted as settings[app__name].
            $name = str_replace('.', '__', $key);
            $raw = $input[$name] ?? null;
            $attributes[$name] = $field['label'];

            switch ($field['type']) {
                case 'bool':
                    $values[$key] = filter_var($raw, FILTER_VALIDATE_BOOLEAN);
                    break;
                case 'int':
                    $values[$key] = is_numeric($raw) ? (int) $raw : $raw;
                    $rules[$name] = $field['rules'] ?? 'required|integer';
                    break;
                case 'json':
                    $decoded = json_decode((string) $raw, true);
                    if (! is_array($decoded)) {
                        return back()->withInput()->withErrors([$name => "{$field['label']}: invalid JSON."]);
                    }
                    if ($error = $this->validateJsonField($key, $decoded)) {
                        return back()->withInput()->withErrors([$name => $error]);
                    }
                    $values[$key] = $decoded;
                    break;
                case 'select':
                    $values[$key] = (string) $raw;
                    $rules[$name] = 'required|in:'.implode(',', array_keys($field['options']));
                    break;
                case 'datetime':
                    $values[$key] = $raw === null || $raw === '' ? null : (string) $raw;
                    $rules[$name] = $field['rules'] ?? 'nullable|date';
                    break;
                case 'decimal':
                    $values[$key] = is_string($raw) ? trim($raw) : $raw;
                    $rules[$name] = ($field['rules'] ?? 'required|numeric').'|regex:/^\d{1,12}(\.\d{1,6})?$/';
                    break;
                default:
                    $values[$key] = $raw === null ? '' : (string) $raw;
                    if (isset($field['rules'])) {
                        $rules[$name] = $field['rules'];
                    }
            }

            $data[$name] = $values[$key];
        }

        $validator = Validator::make($data, $rules, [], $attributes);
        if ($validator->fails()) {
            return back()->withInput()->withErrors($validator);
        }

        $changes = $this->settings->update($values, $this->admin());
        if ($changes) {
            $audit->log('settings.updated', null, ['group' => $group, 'changes' => $changes]);
        }

        return back()->with('success', $changes ? count($changes).' setting(s) saved.' : 'No changes.');
    }

    private function validateJsonField(string $key, array $value): ?string
    {
        if ($key === 'withdraw.networks') {
            foreach ($value as $i => $network) {
                if (! is_array($network) || ! preg_match('/^[A-Z0-9_-]{2,16}$/', (string) ($network['code'] ?? ''))) {
                    return "Network #{$i}: \"code\" must be 2-16 upper-case letters/digits.";
                }
                foreach (['fee_fixed', 'fee_percent', 'min', 'max'] as $field) {
                    if (isset($network[$field]) && ! preg_match('/^\d{1,12}(\.\d{1,6})?$/', (string) $network[$field])) {
                        return "Network {$network['code']}: \"{$field}\" must be a decimal string such as \"1.5\".";
                    }
                }
                if (isset($network['address_pattern']) && @preg_match('/'.str_replace('/', '\/', $network['address_pattern']).'/', '') === false) {
                    return "Network {$network['code']}: address_pattern is not a valid regular expression.";
                }
                if (! empty($network['explorer_url']) && ! str_starts_with((string) $network['explorer_url'], 'https://')) {
                    return "Network {$network['code']}: explorer_url must start with https://";
                }
            }
        }

        if ($key === 'referral.levels') {
            foreach (array_values($value) as $i => $level) {
                if (! is_array($level)) {
                    return 'Each level must be an object like {"fixed": "0.10", "percent": "5"}.';
                }
                foreach (['fixed', 'percent'] as $field) {
                    if (isset($level[$field]) && ! preg_match('/^\d{1,6}(\.\d{1,6})?$/', (string) $level[$field])) {
                        return 'Level '.($i + 1).": \"{$field}\" must be a decimal string.";
                    }
                }
                if ((float) ($level['percent'] ?? 0) > 50) {
                    return 'Level '.($i + 1).': percent above 50 is not allowed.';
                }
            }
        }

        return null;
    }
}
