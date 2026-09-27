@extends('layouts.app')

@section('title', 'Batch report')

@section('content')
    <div class="page-head">
        <div>
            <span class="eyebrow">Batch report</span>
            <h1 class="page-head__title filename">{{ $batch->file_name }}</h1>
            <p class="page-head__sub">
                @include('partials.status-badge', ['status' => $batch->status])
                <span class="mono">&nbsp;Uploaded {{ $batch->created_at->format('d M Y H:i') }}</span>
            </p>
        </div>
        <div class="page-head__actions">
            <a href="{{ route('batches.index') }}" class="btn btn--sm">Back to batches</a>
            <a href="{{ route('batches.export', ['batch' => $batch, 'status' => $status]) }}"
               class="btn btn--sm btn--primary">Download Excel report</a>
        </div>
    </div>

    <div class="stats">
        <div class="stat">
            <span class="stat__label">Recipients</span>
            <span class="stat__value">{{ number_format($batch->total_emails) }}</span>
            <span class="stat__meta">Rows read from the file</span>
        </div>
        <div class="stat">
            <span class="stat__label">Delivered</span>
            <span class="stat__value stat__value--ok">{{ number_format($batch->sent_count) }}</span>
            <span class="stat__meta">Accepted by the relay</span>
        </div>
        <div class="stat">
            <span class="stat__label">Failed</span>
            <span class="stat__value stat__value--bad">{{ number_format($batch->failed_count) }}</span>
            <span class="stat__meta">Rejected or undeliverable</span>
        </div>
        <div class="stat">
            <span class="stat__label">Success rate</span>
            <span class="stat__value">{{ $batch->successRate() }}%</span>
            <span class="stat__meta">Delivered against the whole file</span>
        </div>
    </div>

    <div class="panel">
        <div class="panel__head">
            <form method="GET" action="{{ route('batches.show', $batch) }}" class="filter-bar">
                <div>
                    <label for="status" class="label">Filter by state</label>
                    <select name="status" id="status" class="select select--auto"
                            onchange="this.form.submit();">
                        <option value="" @selected($status === null || $status === '')>All recipients</option>
                        <option value="sent" @selected($status === 'sent')>Sent</option>
                        <option value="failed" @selected($status === 'failed')>Failed</option>
                        <option value="pending" @selected($status === 'pending')>Pending</option>
                    </select>
                </div>
                <noscript><button type="submit" class="btn btn--sm">Apply</button></noscript>
                <span class="filter-bar__count">{{ number_format($logs->total()) }} {{ \Illuminate\Support\Str::plural('row', $logs->total()) }}</span>
            </form>
        </div>

        <div class="panel__body panel__body--flush">
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Recipient</th>
                            <th>Address</th>
                            <th>State</th>
                            <th>Date</th>
                            <th>Time</th>
                            <th class="num">Attempts</th>
                            <th>Response</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($logs as $log)
                            @php
                                $stamp = $log->sent_at ?? $log->created_at;
                                $rowState = match ($log->status) {
                                    \App\Models\EmailLog::STATUS_SENT   => 'row-ok',
                                    \App\Models\EmailLog::STATUS_FAILED => 'row-bad',
                                    default                             => 'row-warn',
                                };
                            @endphp
                            <tr class="row-state {{ $rowState }}">
                                <td data-head>{{ $log->displayName() }}</td>
                                <td class="mono" data-label="Address">{{ $log->email }}</td>
                                <td data-label="State">@include('partials.status-badge', ['status' => $log->status])</td>
                                <td class="mono" data-label="Date">{{ $stamp?->format('d M Y') }}</td>
                                <td class="mono" data-label="Time">{{ $stamp?->format('H:i:s') }}</td>
                                <td class="num" data-label="Attempts">{{ $log->attempts }}</td>
                                <td data-label="Response">
                                    @if (filled($log->remarks))
                                        <span class="remark">{{ $log->remarks }}</span>
                                    @else
                                        <span class="remark remark--empty">&mdash;</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr class="table__empty">
                                <td colspan="7" data-head>
                                    <strong>No rows match this filter</strong>
                                    Choose a different state to see the rest of the batch.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        @if ($logs->hasPages())
            <div class="panel__foot">
                <div class="pager">
                    <span class="pager__summary">
                        Showing {{ $logs->firstItem() }}&ndash;{{ $logs->lastItem() }}
                        of {{ $logs->total() }}
                    </span>
                    {{ $logs->links() }}
                </div>
            </div>
        @endif
    </div>
@endsection
