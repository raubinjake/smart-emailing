@extends('layouts.app')

@section('title', 'Batches')

@section('content')
    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <h1 class="h5 mb-3">Upload Excel File</h1>

            <form method="POST" action="{{ route('batches.upload') }}" enctype="multipart/form-data">
                @csrf

                <div class="row g-2 align-items-end">
                    <div class="col-md-8">
                        <label for="file" class="form-label">Upload Excel File:</label>
                        <input type="file" class="form-control" id="file" name="file"
                               accept=".xlsx,.xls,.csv" required>
                    </div>
                    <div class="col-md-4">
                        <button type="submit" class="btn btn-dark w-100">Upload</button>
                    </div>
                </div>
            </form>

            <p class="text-muted small mt-3 mb-0">
                The file needs a <code>name</code> column and an <code>email</code> column.
                <a href="{{ route('batches.sample') }}">Download the sample template</a>.
            </p>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-body">
            <h2 class="h5 mb-3">Batches</h2>

            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th>File Name</th>
                            <th>Uploaded</th>
                            <th class="text-end">Total</th>
                            <th class="text-end">Sent</th>
                            <th class="text-end">Failed</th>
                            <th>Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($batches as $batch)
                            <tr>
                                <td>{{ $batch->file_name }}</td>
                                <td>{{ $batch->created_at->format('d M Y H:i') }}</td>
                                <td class="text-end">{{ $batch->total_emails }}</td>
                                <td class="text-end">{{ $batch->sent_count }}</td>
                                <td class="text-end">{{ $batch->failed_count }}</td>
                                <td>@include('partials.status-badge', ['status' => $batch->status])</td>
                                <td class="text-end">
                                    <div class="d-inline-flex gap-1">
                                        @if ($batch->status === \App\Models\EmailBatch::STATUS_DRAFT)
                                            <a href="{{ route('batches.compose', $batch) }}"
                                               class="btn btn-sm btn-dark">Compose</a>
                                        @else
                                            <a href="{{ route('batches.show', $batch) }}"
                                               class="btn btn-sm btn-dark">Report</a>
                                        @endif

                                        <a href="{{ route('batches.export', $batch) }}"
                                           class="btn btn-sm btn-outline-secondary">Export</a>

                                        <form method="POST" action="{{ route('batches.destroy', $batch) }}"
                                              class="m-0"
                                              onsubmit="return confirm('Delete this batch and its report? This cannot be undone.');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-center text-muted py-4">
                                    No batches yet. Upload a file to get started.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{ $batches->links() }}
        </div>
    </div>
@endsection
