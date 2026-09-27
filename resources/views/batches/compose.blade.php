@extends('layouts.app')

@section('title', 'Compose')
@section('shell-class', 'shell--wide-form')

@section('content')
    @php $nothingToSend = $batch->pending_count === 0; @endphp

    <div class="page-head">
        <div>
            <span class="eyebrow">Batch</span>
            <h1 class="page-head__title">Compose the message</h1>
            <p class="page-head__sub">Written once, sent to every queued recipient in this file.</p>
        </div>
        <div class="page-head__actions">
            <a href="{{ route('batches.index') }}" class="btn btn--sm">Back to batches</a>
        </div>
    </div>

    <div class="panel">
        <div class="compose-head">
            <div>
                <span class="eyebrow">Source file</span>
                <p class="filename">{{ $batch->file_name }}</p>
            </div>
            <div class="compose-count {{ $nothingToSend ? 'compose-count--zero' : '' }}">
                <span class="sr-only">Matching Records: {{ $batch->pending_count }}</span>
                <strong aria-hidden="true">{{ $batch->pending_count }}</strong>
                <span aria-hidden="true">Matching Records</span>
            </div>
        </div>

        <div class="panel__body">
            @if ($batch->failed_count > 0)
                <div class="notice notice--warn">
                    <div class="notice__body">
                        <strong>{{ $batch->failed_count }} {{ \Illuminate\Support\Str::plural('row', $batch->failed_count) }} rejected before the send.</strong>
                        Their addresses failed syntax validation, so they stay out of the queue
                        and appear in the report as failed.
                    </div>
                </div>
            @endif

            @if ($nothingToSend)
                <div class="notice notice--bad">
                    <div class="notice__body">
                        <strong>Nothing to send.</strong>
                        No rows in this file could be read, so there are no recipients to email.
                        The most likely cause is that the column headings were not
                        <code>name</code> and <code>email</code> &mdash; those exact headings are required.
                        <a href="{{ route('batches.index') }}">Go back and upload the file again</a>
                        once the headings are fixed.
                    </div>
                </div>
            @endif

            <form method="POST" action="{{ route('batches.send', $batch) }}">
                @csrf

                <div class="field">
                    <label for="subject" class="field__label">Subject</label>
                    <input type="text" class="input" id="subject" name="subject"
                           value="{{ old('subject', $batch->subject) }}" required>
                </div>

                <div class="field">
                    <label for="body" class="field__label">Message</label>
                    <textarea class="textarea" id="body" name="body" rows="12">{{ old('body', $batch->body) }}</textarea>
                    <p class="token-hint">
                        Use @{{ name }} anywhere in the subject or message and it is replaced with
                        each recipient's name.
                    </p>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn--primary btn--lg"
                            @disabled($nothingToSend)>
                        SEND BULK EMAIL
                    </button>
                    <span class="small muted">
                        @if ($nothingToSend)
                            No queued recipients &mdash; sending is unavailable for this file.
                        @else
                            Queues {{ number_format($batch->pending_count) }}
                            {{ \Illuminate\Support\Str::plural('message', $batch->pending_count) }} on the active relay.
                        @endif
                    </span>
                </div>
            </form>
        </div>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('vendor/tinymce/tinymce.min.js') }}"></script>
    <script>
        tinymce.init({
            selector: '#body',
            license_key: 'gpl',
            height: 400,
            menubar: false,
            plugins: 'lists link image code table',
            toolbar: 'undo redo | blocks | bold italic forecolor | alignleft aligncenter alignright alignjustify | bullist numlist outdent indent | link image | code',
            content_style: "body { font-family: 'Instrument Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; font-size: 14px; line-height: 1.5; color: #0F172A; }",
        });
    </script>
@endpush
