<?php

namespace App\Http\Requests\MiniApp;

use Illuminate\Foundation\Http\FormRequest;

class StoreWithdrawalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('miniapp') !== null;
    }

    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'min:3', 'max:120', 'regex:/^[\pL\pM\s.\'-]+$/u'],
            'address' => ['required', 'string', 'min:20', 'max:128', 'regex:/^[A-Za-z0-9]+$/'],
            'amount' => ['required', 'string', 'regex:/^\d{1,9}(\.\d{1,6})?$/'],
            'network' => ['required', 'string', 'max:16', 'regex:/^[A-Za-z0-9_-]+$/'],
            'note' => ['nullable', 'string', 'max:500'],
            'confirm' => ['accepted'],
        ];
    }

    public function messages(): array
    {
        return [
            'full_name.regex' => 'Please enter your name using letters only.',
            'address.regex' => 'The wallet address may contain letters and digits only.',
            'amount.regex' => 'Enter an amount such as 10 or 12.50.',
            'confirm.accepted' => 'Please confirm that the network and address are correct.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_numeric($this->input('amount'))) {
            $this->merge(['amount' => (string) $this->input('amount')]);
        }
        $this->merge(['address' => trim((string) $this->input('address'))]);
    }
}
