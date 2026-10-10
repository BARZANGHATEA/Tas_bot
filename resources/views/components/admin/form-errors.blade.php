@props(['dialog'])
@if ($errors->any() && old('_dialog') === $dialog)
    <div class="alert alert-danger" role="alert"><x-admin.icon name="alert" /><div class="alert-body"><ul style="margin:0;padding-left:16px">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div></div>
@endif
<input type="hidden" name="_dialog" value="{{ $dialog }}">
