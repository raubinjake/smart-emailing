@php
    $badgeClass = match ($status) {
        'sent', 'completed' => 'bg-success',
        'failed'            => 'bg-danger',
        'pending'           => 'bg-warning text-dark',
        'draft'             => 'bg-secondary',
        'processing'        => 'bg-info text-dark',
        default             => 'bg-secondary',
    };
@endphp
<span class="badge {{ $badgeClass }}">{{ ucfirst($status) }}</span>
