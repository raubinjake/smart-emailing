@extends('layouts.app')

@section('title', 'Batches')

@section('content')
    @php
        // Totals across the batches on this page — a running read of the queue.
        $pageTotal   = $batches->sum('total_emails');
        $pageSent    = $batches->sum('sent_count');
        $pageFailed  = $batches->sum('failed_count');
        $pagePending = $batches->sum('pending_count');
    @endphp

    <div class="page-head">
        <div>
            <span class="eyebrow">Delivery</span>
            <h1 class="page-head__title">Batches</h1>
            <p class="page-head__sub">Recipient files, their send state, and per-recipient outcomes.</p>
        </div>
        <div class="page-head__actions">
            <a href="{{ route('batches.sample') }}" class="btn btn--sm">Download template</a>
        </div>
    </div>

    <div class="stats">
        <div class="stat">
            <span class="stat__label">Recipients</span>
            <span class="stat__value">{{ number_format($pageTotal) }}</span>
            <span class="stat__meta">Across {{ $batches->count() }} {{ Str::plural('batch', $batches->count()) }} shown</span>
        </div>
        <div class="stat">
            <span class="stat__label">Delivered</span>
            <span class="stat__value stat__value--ok">{{ number_format($pageSent) }}</span>
            <span class="stat__meta">Accepted by the relay</span>
        </div>
        <div class="stat">
            <span class="stat__label">Failed</span>
            <span class="stat__value stat__value--bad">{{ number_format($pageFailed) }}</span>
            <span class="stat__meta">Rejected or undeliverable</span>
        </div>
        <div class="stat">
            <span class="stat__label">Queued</span>
            <span class="stat__value {{ $pagePending > 0 ? 'stat__value--warn' : '' }}">{{ number_format($pagePending) }}</span>
            <span class="stat__meta">Awaiting a send</span>
        </div>
    </div>

    <div class="panel">
        <div class="panel__head">
            <h2 class="panel__title">Upload a recipient file</h2>
        </div>
        <div class="panel__body">
            <form method="POST" action="{{ route('batches.upload') }}" enctype="multipart/form-data">
                @csrf

                <div class="form-grid">
                    <div class="col-8 field">
                        <label for="file" class="field__label">Excel or CSV file</label>
                        <input type="file" class="input" id="file" name="file"
                               accept=".xlsx,.xls,.csv" required>
                    </div>
                    <div class="col-4 field">
                        <label class="field__label" for="upload-submit">&nbsp;</label>
                        <button type="submit" id="upload-submit" class="btn btn--primary btn--block">Upload file</button>
                    </div>
                </div>
            </form>

            <p class="panel__note">
                The file needs a <code>name</code> column and an <code>email</code> column.
                Rows that fail address validation are recorded as failed and never sent.
                <a href="{{ route('batches.sample') }}">Download the sample template</a>.
            </p>
        </div>
    </div>

    <div class="panel">
        <div class="panel__head">
            <h2 class="panel__title">Batch log</h2>
            <span class="small muted mono">{{ $batches->total() }} total</span>
        </div>

        <div class="panel__body panel__body--flush">
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>File</th>
                            <th>Uploaded</th>
                            <th class="num">Total</th>
                            <th class="num">Delivered</th>
                            <th class="num">Failed</th>
                            <th>State</th>
                            <th class="actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($batches as $batch)
                            @php
                                $isDraft    = $batch->status === \App\Models\EmailBatch::STATUS_DRAFT;
                                $isSending  = $batch->status === \App\Models\EmailBatch::STATUS_PROCESSING;
                                // A draft with nothing pending can never be sent: every row in
                                // the file failed validation, so compose is a dead end.
                                $nothingToSend = $isDraft && $batch->pending_count === 0;

                                $rowState = match (true) {
                                    $nothingToSend                  => 'row-bad',
                                    $isDraft                        => 'row-warn',
                                    $isSending                      => 'row-busy',
                                    $batch->failed_count > 0        => 'row-warn',
                                    default                         => 'row-ok',
                                };
                            @endphp
                            <tr class="row-state {{ $rowState }}">
                                <td data-head>
                                    <div class="cell-stack">
                                        <span class="mono">{{ $batch->file_name }}</span>
                                        @if ($nothingToSend)
                                            <span class="cell-stack__sub">No sendable rows</span>
                                        @elseif ($batch->pending_count > 0)
                                            <span class="cell-stack__sub">{{ number_format($batch->pending_count) }} queued</span>
                                        @endif
                                    </div>
                                </td>
                                <td class="mono" data-label="Uploaded">{{ $batch->created_at->format('d M Y H:i') }}</td>
                                <td class="num" data-label="Total">{{ number_format($batch->total_emails) }}</td>
                                <td class="num" data-label="Delivered">{{ number_format($batch->sent_count) }}</td>
                                <td class="num" data-label="Failed">{{ number_format($batch->failed_count) }}</td>
                                <td data-label="State">
                                    @if ($nothingToSend)
                                        <span class="pill pill--bad">Nothing to send</span>
                                    @else
                                        @include('partials.status-badge', ['status' => $batch->status])
                                    @endif
                                </td>
                                <td class="actions" data-actions>
                                    <div class="actions__row">
                                        @if ($nothingToSend)
                                            <a href="{{ route('batches.show', $batch) }}"
                                               class="btn btn--sm">Report</a>
                                        @elseif ($isDraft)
                                            <a href="{{ route('batches.compose', $batch) }}"
                                               class="btn btn--sm btn--primary">Compose</a>
                                        @else
                                            <a href="{{ route('batches.show', $batch) }}"
                                               class="btn btn--sm btn--primary">Report</a>
                                        @endif

                                        <a href="{{ route('batches.export', $batch) }}"
                                           class="btn btn--sm">Export</a>

                                        <form method="POST" action="{{ route('batches.destroy', $batch) }}"
                                              class="inline-form"
                                              onsubmit="return confirm('Delete this batch and its report? This cannot be undone.');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn--sm btn--danger">Delete</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr class="table__empty">
                                <td colspan="7" data-head>
                                    <strong>No batches yet</strong>
                                    Upload a recipient file above to queue your first send.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        @if ($batches->hasPages())
            <div class="panel__foot">
                <div class="pager">
                    <span class="pager__summary">
                        Showing {{ $batches->firstItem() }}&ndash;{{ $batches->lastItem() }}
                        of {{ $batches->total() }}
                    </span>
                    {{ $batches->links() }}
                </div>
            </div>
        @endif
    </div>
@endsection
