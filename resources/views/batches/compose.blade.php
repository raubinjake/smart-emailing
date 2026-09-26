@extends('layouts.app')

@section('title', 'Compose')

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-9">
            <div class="card shadow-sm">
                <div class="card-body p-4">

                    <h1 class="h5 mb-3">Upload Excel File:</h1>
                    <p class="text-muted mb-3">{{ $batch->file_name }}</p>

                    <p class="h4 mb-4">Matching Records: {{ $batch->pending_count }}</p>

                    @if ($batch->failed_count > 0)
                        <div class="alert alert-warning">
                            {{ $batch->failed_count }} row(s) were rejected for invalid email syntax and
                            will not be sent. They appear in the report as failed.
                        </div>
                    @endif

                    @if ($batch->pending_count === 0)
                        <div class="alert alert-warning">
                            <strong>Nothing will be sent.</strong>
                            No rows in this file could be read, so there are no recipients to email.
                            The most likely cause is that the column headings were not
                            <code>name</code> and <code>email</code> &mdash; those exact headings are required.
                            <a href="{{ route('batches.index') }}">Go back and upload the file again</a>
                            once the headings are fixed.
                        </div>
                    @endif

                    <form method="POST" action="{{ route('batches.send', $batch) }}">
                        @csrf

                        <div class="mb-3">
                            <label for="subject" class="form-label">Subject:</label>
                            <input type="text" class="form-control" id="subject" name="subject"
                                   value="{{ old('subject', $batch->subject) }}" required>
                        </div>

                        <div class="mb-2">
                            <label for="body" class="form-label">Message:</label>
                            <textarea class="form-control" id="body" name="body" rows="12">{{ old('body', $batch->body) }}</textarea>
                        </div>

                        <p class="text-muted small">
                            Use @{{ name }} anywhere in the subject or message and it is replaced with
                            each recipient's name.
                        </p>

                        <button type="submit" class="btn btn-dark btn-lg"
                                @disabled($batch->pending_count === 0)>
                            SEND BULK EMAIL
                        </button>
                    </form>

                </div>
            </div>
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
        });
    </script>
@endpush
