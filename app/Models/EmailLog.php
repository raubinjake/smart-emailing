<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One recipient row from an uploaded file, with its delivery outcome.
 */
class EmailLog extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT    = 'sent';
    public const STATUS_FAILED  = 'failed';

    protected $fillable = [
        'batch_id', 'name', 'email', 'status', 'attempts', 'remarks', 'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'sent_at'  => 'datetime',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(EmailBatch::class, 'batch_id');
    }
}
