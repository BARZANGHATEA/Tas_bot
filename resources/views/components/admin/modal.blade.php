@props(['id', 'title', 'description' => null, 'icon' => null, 'tone' => null, 'wide' => false])
<dialog id="{{ $id }}" class="modal" aria-labelledby="{{ $id }}-title" @if ($description) aria-describedby="{{ $id }}-desc" @endif @if ($wide) style="width:min(680px, calc(100vw - 32px))" @endif>
    <div class="modal-header">
        @if ($icon)<div class="modal-icon {{ $tone ? 'is-'.$tone : '' }}"><x-admin.icon :name="$icon" /></div>@endif
        <div class="grow">
            <h2 id="{{ $id }}-title">{{ $title }}</h2>
            @if ($description)<p id="{{ $id }}-desc">{{ $description }}</p>@endif
        </div>
        <button type="button" class="btn btn-ghost btn-icon btn-sm modal-close" data-dialog-close aria-label="Close"><x-admin.icon name="x" /></button>
    </div>
    {{ $slot }}
</dialog>
