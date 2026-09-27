@php
    // Delivery vocabulary on the surface; the stored enum is untouched.
    [$pillClass, $label] = match ($status) {
        'sent'       => ['pill--ok', 'Delivered'],
        'completed'  => ['pill--ok', 'Completed'],
        'failed'     => ['pill--bad', 'Failed'],
        'pending'    => ['pill--warn', 'Queued'],
        'processing' => ['pill--busy', 'Sending'],
        'draft'      => ['pill--idle', 'Draft'],
        default      => ['pill--idle', ucfirst((string) $status)],
    };
@endphp
<span class="pill {{ $pillClass }}">{{ $label }}</span>
