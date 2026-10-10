@props(['label' => 'Confirm with your password', 'id' => null])
@php $fieldId = $id ?? 'cp-'.\Illuminate\Support\Str::random(6); @endphp
<label class="field" for="{{ $fieldId }}">
    <span class="field-label">{{ $label }} <span class="req" aria-hidden="true">*</span></span>
    <input type="password" id="{{ $fieldId }}" name="confirm_password" class="input" required autocomplete="current-password" @error('confirm_password') aria-invalid="true" @enderror>
    @error('confirm_password')<span class="field-error"><x-admin.icon name="alert" size="xs" />{{ $message }}</span>@else<span class="field-help">Required for financial and security-sensitive actions.</span>@enderror
</label>
