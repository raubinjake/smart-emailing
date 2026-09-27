@extends('layouts.app')

@section('title', $smtp->exists ? 'Edit SMTP profile' : 'Add SMTP profile')
@section('shell-class', 'shell--mid')

@section('content')
    <div class="page-head">
        <div>
            <span class="eyebrow">Configuration</span>
            <h1 class="page-head__title">
                {{ $smtp->exists ? 'Edit SMTP profile' : 'Add SMTP profile' }}
            </h1>
            <p class="page-head__sub">Credentials for the relay that carries outgoing mail.</p>
        </div>
        <div class="page-head__actions">
            <a href="{{ route('smtp.index') }}" class="btn btn--sm">Back to profiles</a>
        </div>
    </div>

    <div class="panel">
        <div class="panel__body">
            <form method="POST"
                  action="{{ $smtp->exists ? route('smtp.update', $smtp) : route('smtp.store') }}">
                @csrf
                @if ($smtp->exists)
                    @method('PUT')
                @endif

                <div class="field">
                    <label for="name" class="field__label">Profile name</label>
                    <input type="text" class="input" id="name" name="name"
                           value="{{ old('name', $smtp->name) }}" required>
                    <p class="field__hint">How this relay is labelled in the list.</p>
                </div>

                <div class="form-grid">
                    <div class="col-8 field">
                        <label for="host" class="field__label">Host</label>
                        <input type="text" class="input input--mono" id="host" name="host"
                               value="{{ old('host', $smtp->host) }}" required>
                    </div>

                    <div class="col-4 field">
                        <label for="port" class="field__label">Port</label>
                        <input type="number" class="input input--mono" id="port" name="port"
                               value="{{ old('port', $smtp->port ?? 587) }}" required>
                    </div>
                </div>

                <div class="field">
                    <label for="username" class="field__label">Username</label>
                    <input type="text" class="input input--mono" id="username" name="username"
                           value="{{ old('username', $smtp->username) }}" required>
                </div>

                <div class="field">
                    <label for="password" class="field__label">Password</label>
                    <input type="password" class="input" id="password" name="password"
                           autocomplete="new-password" @required(! $smtp->exists)>
                    @if ($smtp->exists)
                        <p class="field__hint">Stored encrypted and never shown. Leave blank to keep the current one.</p>
                    @endif
                </div>

                <div class="field">
                    <label for="encryption" class="field__label">Encryption</label>
                    <select class="select" id="encryption" name="encryption">
                        <option value="tls" @selected(old('encryption', $smtp->encryption) === 'tls')>TLS</option>
                        <option value="ssl" @selected(old('encryption', $smtp->encryption) === 'ssl')>SSL</option>
                        <option value="" @selected(blank(old('encryption', $smtp->encryption)))>None</option>
                    </select>
                </div>

                <hr class="divider">

                <div class="form-grid">
                    <div class="col-8 field">
                        <label for="from_address" class="field__label">From address</label>
                        <input type="email" class="input input--mono" id="from_address" name="from_address"
                               value="{{ old('from_address', $smtp->from_address) }}" required>
                    </div>

                    <div class="col-4 field">
                        <label for="from_name" class="field__label">From name</label>
                        <input type="text" class="input" id="from_name" name="from_name"
                               value="{{ old('from_name', $smtp->from_name) }}" required>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn--primary">
                        {{ $smtp->exists ? 'Update profile' : 'Save profile' }}
                    </button>
                    <a href="{{ route('smtp.index') }}" class="btn">Cancel</a>
                </div>
            </form>
        </div>
    </div>
@endsection
