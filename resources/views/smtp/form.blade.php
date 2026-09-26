@extends('layouts.app')

@section('title', $smtp->exists ? 'Edit SMTP profile' : 'Add SMTP profile')

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-7">
            <div class="card shadow-sm">
                <div class="card-body p-4">
                    <h1 class="h5 mb-4">
                        {{ $smtp->exists ? 'Edit SMTP Profile' : 'Add SMTP Profile' }}
                    </h1>

                    <form method="POST"
                          action="{{ $smtp->exists ? route('smtp.update', $smtp) : route('smtp.store') }}">
                        @csrf
                        @if ($smtp->exists)
                            @method('PUT')
                        @endif

                        <div class="mb-3">
                            <label for="name" class="form-label">Name</label>
                            <input type="text" class="form-control" id="name" name="name"
                                   value="{{ old('name', $smtp->name) }}" required>
                        </div>

                        <div class="row">
                            <div class="col-md-8 mb-3">
                                <label for="host" class="form-label">Host</label>
                                <input type="text" class="form-control" id="host" name="host"
                                       value="{{ old('host', $smtp->host) }}" required>
                            </div>

                            <div class="col-md-4 mb-3">
                                <label for="port" class="form-label">Port</label>
                                <input type="number" class="form-control" id="port" name="port"
                                       value="{{ old('port', $smtp->port ?? 587) }}" required>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="username" class="form-label">Username</label>
                            <input type="text" class="form-control" id="username" name="username"
                                   value="{{ old('username', $smtp->username) }}" required>
                        </div>

                        <div class="mb-3">
                            <label for="password" class="form-label">Password</label>
                            <input type="password" class="form-control" id="password" name="password"
                                   autocomplete="new-password" @required(! $smtp->exists)>
                            @if ($smtp->exists)
                                <div class="form-text">Leave blank to keep the current password.</div>
                            @endif
                        </div>

                        <div class="mb-3">
                            <label for="encryption" class="form-label">Encryption</label>
                            <select class="form-select" id="encryption" name="encryption">
                                <option value="tls" @selected(old('encryption', $smtp->encryption) === 'tls')>TLS</option>
                                <option value="ssl" @selected(old('encryption', $smtp->encryption) === 'ssl')>SSL</option>
                                <option value="" @selected(blank(old('encryption', $smtp->encryption)))>None</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label for="from_address" class="form-label">From address</label>
                            <input type="email" class="form-control" id="from_address" name="from_address"
                                   value="{{ old('from_address', $smtp->from_address) }}" required>
                        </div>

                        <div class="mb-4">
                            <label for="from_name" class="form-label">From name</label>
                            <input type="text" class="form-control" id="from_name" name="from_name"
                                   value="{{ old('from_name', $smtp->from_name) }}" required>
                        </div>

                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-dark">
                                {{ $smtp->exists ? 'Update Profile' : 'Save Profile' }}
                            </button>
                            <a href="{{ route('smtp.index') }}" class="btn btn-outline-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
