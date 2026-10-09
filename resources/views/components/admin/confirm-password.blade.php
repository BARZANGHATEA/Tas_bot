@props(['label' => 'Your password (required for this action)'])
<label class="field">
    <span>{{ $label }}</span>
    <input type="password" name="confirm_password" class="input" required autocomplete="current-password">
</label>
