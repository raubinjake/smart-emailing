<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

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

    /**
     * The recipient's name as shown in reports, derived from their address.
     *
     * Uploaded name columns are inconsistent (abbreviations, blanks, casing),
     * so the report presents a name built from the address instead. The stored
     * `name` is left untouched and is what outgoing mail still greets.
     *
     * `ada.lovelace+news@example.com` becomes `Ada Lovelace`.
     *
     * @return string the display name, or an empty string for a blank address
     */
    public function displayName(): string
    {
        $local = Str::before((string) $this->email, '@');
        $local = Str::before($local, '+');

        // Separators become spaces; anything else non-word is dropped so a
        // malformed address like `raubin@gmailll.com..,` still reads cleanly.
        $local = preg_replace('/[._\-]+/', ' ', $local) ?? '';
        $local = preg_replace('/[^\p{L}\p{N} ]+/u', '', $local) ?? '';
        $local = trim(preg_replace('/\s+/', ' ', $local) ?? '');

        if ($local === '') {
            return '';
        }

        return implode(' ', array_map(
            // Only capitalise when the word is not already mixed-case, so
            // `RobinKumar` survives intact.
            fn (string $word) => $word === mb_strtolower($word) ? Str::ucfirst($word) : $word,
            explode(' ', $local),
        ));
    }
}
