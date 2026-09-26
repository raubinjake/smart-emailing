<?php

namespace App\Http\Controllers;

use App\Exports\BatchReportExport;
use App\Exports\SampleTemplateExport;
use App\Http\Requests\SendBatchRequest;
use App\Http\Requests\UploadBatchRequest;
use App\Models\EmailBatch;
use App\Models\EmailLog;
use App\Services\BatchDispatchService;
use App\Services\BatchImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The two-step bulk send: upload and parse, then compose and queue.
 */
class EmailBatchController extends Controller
{
    public function __construct(
        private BatchImportService $importer,
        private BatchDispatchService $dispatcher,
    ) {
    }

    /**
     * List every batch, newest first.
     */
    public function index(): View
    {
        $batches = EmailBatch::latest()->paginate(15);

        return view('batches.index', compact('batches'));
    }

    /**
     * Step 1 — parse the file synchronously and create a draft batch.
     */
    public function upload(UploadBatchRequest $request): RedirectResponse
    {
        $batch = $this->importer->import($request->file('file'));

        return redirect()
            ->route('batches.compose', $batch)
            ->with('status', "Parsed {$batch->total_emails} rows.");
    }

    /**
     * Step 2 — show the parsed count and the message form.
     */
    public function compose(EmailBatch $batch): View|RedirectResponse
    {
        if ($batch->status !== EmailBatch::STATUS_DRAFT) {
            return redirect()
                ->route('batches.show', $batch)
                ->with('error', 'This batch has already been sent.');
        }

        return view('batches.compose', compact('batch'));
    }

    /**
     * Step 3 — persist the message and queue one job per recipient.
     */
    public function send(SendBatchRequest $request, EmailBatch $batch): RedirectResponse
    {
        $data = $request->validated();

        try {
            $queued = $this->dispatcher->dispatch($batch, $data['subject'], $data['body']);
        } catch (RuntimeException $e) {
            return redirect()
                ->route('batches.show', $batch)
                ->with('error', $e->getMessage());
        }

        return redirect()
            ->route('batches.show', $batch)
            ->with('status', "Queued {$queued} emails.");
    }

    /**
     * The per-batch report: every row of the file, optionally filtered.
     *
     * @param  Request  $request  optional `status` query filter
     */
    public function show(Request $request, EmailBatch $batch): View
    {
        $status = $this->statusFilter($request);

        $logs = $batch->logs()
            ->when($status, fn ($q) => $q->where('status', $status))
            ->orderBy('id')
            ->paginate(50)
            ->withQueryString();

        return view('batches.show', compact('batch', 'logs', 'status'));
    }

    /**
     * Download the report as an .xlsx.
     */
    public function exportReport(Request $request, EmailBatch $batch): BinaryFileResponse
    {
        return Excel::download(
            new BatchReportExport($batch, $this->statusFilter($request)),
            'batch-' . $batch->id . '-report.xlsx',
        );
    }

    /**
     * The `status` query filter, or null when absent or not a real status.
     *
     * Query input can be an array (`?status[]=sent`), so it is narrowed to a
     * known status rather than passed through.
     *
     * @param  Request  $request  the incoming request
     */
    private function statusFilter(Request $request): ?string
    {
        $status = $request->query('status');

        $allowed = [
            EmailLog::STATUS_PENDING,
            EmailLog::STATUS_SENT,
            EmailLog::STATUS_FAILED,
        ];

        return is_string($status) && in_array($status, $allowed, true) ? $status : null;
    }

    /**
     * Download the blank recipient template.
     */
    public function downloadSample(): BinaryFileResponse
    {
        return Excel::download(new SampleTemplateExport(), 'recipients-sample.xlsx');
    }

    /**
     * Remove the batch, its logs (FK cascade), and the stored upload.
     */
    public function destroy(EmailBatch $batch): RedirectResponse
    {
        if ($batch->stored_path) {
            Storage::delete($batch->stored_path);
        }

        $batch->delete();

        return redirect()
            ->route('batches.index')
            ->with('status', 'Batch deleted.');
    }
}
