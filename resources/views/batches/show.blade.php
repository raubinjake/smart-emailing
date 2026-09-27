@extends('layouts.app')

@section('title', 'Batch report')

@section('content')
    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                <div>
                    <h1 class="h5 mb-1">{{ $batch->file_name }}</h1>
                    <div>@include('partials.status-badge', ['status' => $batch->status])</div>
                </div>
                <a href="{{ route('batches.export', ['batch' => $batch, 'status' => $status]) }}"
                   class="btn btn-dark">Download Excel Report</a>
            </div>

            <div class="row text-center mt-4">
                <div class="col-6 col-md-3">
                    <div class="text-muted small">Total</div>
                    <div class="h4 mb-0">{{ $batch->total_emails }}</div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="text-muted small">Sent</div>
                    <div class="h4 mb-0 text-success">{{ $batch->sent_count }}</div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="text-muted small">Failed</div>
                    <div class="h4 mb-0 text-danger">{{ $batch->failed_count }}</div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="text-muted small">Success Rate</div>
                    <div class="h4 mb-0">{{ $batch->successRate() }}%</div>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-body">
            <form method="GET" action="{{ route('batches.show', $batch) }}" class="mb-3">
                <label for="status" class="form-label">Filter by status</label>
                <select name="status" id="status" class="form-select w-auto d-inline-block"
                        onchange="this.form.submit();">
                    <option value="" @selected($status === null || $status === '')>All</option>
                    <option value="sent" @selected($status === 'sent')>Sent</option>
                    <option value="failed" @selected($status === 'failed')>Failed</option>
                    <option value="pending" @selected($status === 'pending')>Pending</option>
                </select>
                <noscript><button type="submit" class="btn btn-sm btn-secondary">Apply</button></noscript>
            </form>

            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Status</th>
                            <th>Date</th>
                            <th>Time</th>
                            <th class="text-end">Attempts</th>
                            <th>Remarks</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($logs as $log)
                            @php $stamp = $log->sent_at ?? $log->created_at; @endphp
                            <tr>
                                <td>{{ $log->displayName() }}</td>
                                <td>{{ $log->email }}</td>
                                <td>@include('partials.status-badge', ['status' => $log->status])</td>
                                <td>{{ $stamp?->format('d M Y') }}</td>
                                <td>{{ $stamp?->format('H:i:s') }}</td>
                                <td class="text-end">{{ $log->attempts }}</td>
                                <td>{{ $log->remarks }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-center text-muted py-4">
                                    No rows match this filter.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{ $logs->links() }}
        </div>
    </div>
@endsection
