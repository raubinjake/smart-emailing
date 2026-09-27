@extends('layouts.app')

@section('title', 'SMTP profiles')

@section('content')
    <div class="page-head">
        <div>
            <span class="eyebrow">Configuration</span>
            <h1 class="page-head__title">SMTP profiles</h1>
            <p class="page-head__sub">The relays this app hands mail to. One profile is active at a time.</p>
        </div>
        <div class="page-head__actions">
            <a href="{{ route('smtp.create') }}" class="btn btn--primary btn--sm">Add profile</a>
        </div>
    </div>

    <div class="panel">
        <div class="panel__head">
            <h2 class="panel__title">Relays</h2>
            <span class="small muted mono">{{ $settings->count() }} configured</span>
        </div>

        <div class="panel__body panel__body--flush">
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Profile</th>
                            <th>Host</th>
                            <th class="num">Port</th>
                            <th>Envelope from</th>
                            <th>State</th>
                            <th class="actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($settings as $smtp)
                            <tr class="row-state {{ $smtp->is_active ? 'row-ok' : '' }}">
                                <td>
                                    <div class="cell-stack">
                                        <span>{{ $smtp->name }}</span>
                                        <span class="cell-stack__sub mono">{{ $smtp->username }}</span>
                                    </div>
                                </td>
                                <td>
                                    <div class="cell-stack">
                                        <span class="mono">{{ $smtp->host }}</span>
                                        <span class="cell-stack__sub">
                                            {{ filled($smtp->encryption) ? strtoupper($smtp->encryption) : 'No encryption' }}
                                        </span>
                                    </div>
                                </td>
                                <td class="num">{{ $smtp->port }}</td>
                                <td>
                                    <div class="cell-stack">
                                        <span>{{ $smtp->from_name }}</span>
                                        <span class="cell-stack__sub mono">{{ $smtp->from_address }}</span>
                                    </div>
                                </td>
                                <td>
                                    @if ($smtp->is_active)
                                        <span class="pill pill--ok">Active</span>
                                    @else
                                        <span class="pill pill--idle">Standby</span>
                                    @endif
                                </td>
                                <td class="actions">
                                    <div class="actions__row">
                                        <form method="POST" action="{{ route('smtp.test', $smtp) }}"
                                              class="inline-form">
                                            @csrf
                                            <input type="email" name="email" class="input input--mono"
                                                   placeholder="test@example.com" required
                                                   aria-label="Send a test message to"
                                                   style="width: 12rem;">
                                            <button type="submit" class="btn btn--sm">Send test</button>
                                        </form>

                                        @unless ($smtp->is_active)
                                            <form method="POST" action="{{ route('smtp.activate', $smtp) }}"
                                                  class="inline-form">
                                                @csrf
                                                <button type="submit" class="btn btn--sm">Make active</button>
                                            </form>
                                        @endunless

                                        <a href="{{ route('smtp.edit', $smtp) }}"
                                           class="btn btn--sm">Edit</a>

                                        <form method="POST" action="{{ route('smtp.destroy', $smtp) }}"
                                              class="inline-form"
                                              onsubmit="return confirm('Delete this SMTP profile?');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn--sm btn--danger">Delete</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr class="table__empty">
                                <td colspan="6">
                                    <strong>No relay configured</strong>
                                    Add an SMTP profile before running a send.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
