@if (session('status'))
    <div class="notice notice--ok" role="status">
        <div class="notice__body">{{ session('status') }}</div>
    </div>
@endif

@if (session('error'))
    <div class="notice notice--bad" role="alert">
        <div class="notice__body">{{ session('error') }}</div>
    </div>
@endif

@if ($errors->any())
    <div class="notice notice--bad" role="alert">
        <div class="notice__body">
            <strong>{{ $errors->count() === 1 ? 'One field needs attention' : $errors->count() . ' fields need attention' }}</strong>
            <ul class="notice__list">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    </div>
@endif
