<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One uploaded recipient file and the message sent to it.
 */
class EmailBatch extends Model
{
    use HasFactory;

    public const STATUS_DRAFT      = 'draft';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED  = 'completed';

    protected $fillable = [
        'batch_uuid', 'file_name', 'stored_path', 'subject', 'body',
        'total_emails', 'sent_count', 'failed_count', 'pending_count', 'status',
    ];

    protected function casts(): array
    {
        return [
            'total_emails'  => 'integer',
            'sent_count'    => 'integer',
            'failed_count'  => 'integer',
            'pending_count' => 'integer',
        ];
    }

    public function logs(): HasMany
    {
        return $this->hasMany(EmailLog::class, 'batch_id');
    }

    /**
     * Percentage of the whole file that was delivered, to one decimal place.
     */
    public function successRate(): float
    {
        if ($this->total_emails === 0) {
            return 0.0;
        }

        return round(($this->sent_count / $this->total_emails) * 100, 1);
    }
}
