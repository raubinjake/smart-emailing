@extends('layouts.app')

@section('title', 'SMTP profiles')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h5 mb-0">SMTP Profiles</h1>
        <a href="{{ route('smtp.create') }}" class="btn btn-dark">Add Profile</a>
    </div>

    <div class="card shadow-sm">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Host</th>
                            <th class="text-end">Port</th>
                            <th>From</th>
                            <th>Active</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($settings as $smtp)
                            <tr>
                                <td>{{ $smtp->name }}</td>
                                <td>{{ $smtp->host }}</td>
                                <td class="text-end">{{ $smtp->port }}</td>
                                <td>{{ $smtp->from_name }} &lt;{{ $smtp->from_address }}&gt;</td>
                                <td>
                                    @if ($smtp->is_active)
                                        <span class="badge bg-success">Active</span>
                                    @else
                                        <form method="POST" action="{{ route('smtp.activate', $smtp) }}" class="m-0">
                                            @csrf
                                            <button type="submit" class="btn btn-sm btn-outline-secondary">
                                                Activate
                                            </button>
                                        </form>
                                    @endif
                                </td>
                                <td>
                                    <div class="d-flex justify-content-end align-items-center gap-1 flex-wrap">
                                        <form method="POST" action="{{ route('smtp.test', $smtp) }}"
                                              class="d-flex gap-1 m-0">
                                            @csrf
                                            <input type="email" name="email" class="form-control form-control-sm"
                                                   placeholder="test@example.com" required style="width: 12rem;">
                                            <button type="submit" class="btn btn-sm btn-outline-primary">Test</button>
                                        </form>

                                        <a href="{{ route('smtp.edit', $smtp) }}"
                                           class="btn btn-sm btn-outline-secondary">Edit</a>

                                        <form method="POST" action="{{ route('smtp.destroy', $smtp) }}"
                                              class="m-0"
                                              onsubmit="return confirm('Delete this SMTP profile?');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center text-muted py-4">
                                    No SMTP profiles yet. Add one before sending mail.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
