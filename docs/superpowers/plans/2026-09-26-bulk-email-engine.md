# Bulk Email Engine Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a Laravel bulk email system where an admin uploads an Excel/CSV recipient list, reviews the parsed count, composes a subject and rich-text message, and sends — with every row of the file appearing in a per-batch report.

**Architecture:** Thin controllers delegate to three services (`BatchImportService` parses and validates, `DynamicMailerService` builds an SMTP transport from the DB at runtime, `BatchDispatchService` queues one job per recipient). Parsing is synchronous; sending is queued with a 3-attempt retry policy and atomic batch counters.

**Tech Stack:** Laravel 11, PHP 8.2, MySQL 5.7 (MAMP), database queue driver, `maatwebsite/excel`, Bootstrap 5 (CDN), TinyMCE (self-hosted via npm), session auth with an `is_admin` flag.

**Spec:** `docs/superpowers/specs/2026-09-26-bulk-email-engine-design.md`

---

## Environment Notes

- PHP 8.2.30 at `/opt/homebrew/bin/php`, Composer 2.8.8
- MySQL is **MAMP MySQL 5.7**, client at `/Applications/MAMP/Library/bin/mysql`, credentials `root` / `root`, socket at `/Applications/MAMP/tmp/mysql/mysql.sock`
- **MySQL 5.7, not 8.x.** No window functions. Default index key length is 767 bytes, so `Schema::defaultStringLength(191)` is required in `AppServiceProvider` or migrations with indexed string columns will fail.
- The project directory already contains `.git`, `docs/`, an empty `index.php`, and `.DS_Store`. Laravel must be scaffolded into a temp directory and moved in — the installer refuses a non-empty target.

---

## File Structure

**Services** (`app/Services/`) — one responsibility each, independently testable:
- `DynamicMailerService.php` — takes an `SmtpSetting`, configures and returns a mailer
- `BatchImportService.php` — takes an `UploadedFile`, returns an `EmailBatch` with all logs inserted
- `BatchDispatchService.php` — takes an `EmailBatch`, queues jobs

**Models** (`app/Models/`): `User`, `SmtpSetting`, `EmailBatch`, `EmailLog`

**Controllers** (`app/Http/Controllers/`): `AuthController`, `EmailBatchController`, `SmtpSettingController`

**Excel** (`app/Imports/`, `app/Exports/`): `BulkEmailImport`, `BatchReportExport`, `SampleTemplateExport`

**Jobs** (`app/Jobs/`): `SendBulkEmailJob`

**Mail** (`app/Mail/`): `BulkEmailMessage`

**Middleware** (`app/Http/Middleware/`): `IsAdminMiddleware`

**Views** (`resources/views/`): `layouts/app`, `auth/login`, `auth/register`, `batches/index`, `batches/compose`, `batches/show`, `smtp/index`, `smtp/form`

---

## Task 1: Scaffold Laravel

**Files:**
- Create: entire Laravel skeleton at project root
- Modify: `.gitignore`

- [ ] **Step 1: Scaffold into a temp directory**

```bash
cd /Applications/MAMP/htdocs/smart-emailing
composer create-project laravel/laravel:^11.0 /tmp/se-scaffold --no-interaction
```

Expected: completes with "Application ready!"

- [ ] **Step 2: Move the skeleton in, keeping docs/ and .git**

```bash
cd /Applications/MAMP/htdocs/smart-emailing
rm -f index.php .DS_Store
rsync -a --exclude='.git' /tmp/se-scaffold/ ./
rm -rf /tmp/se-scaffold
```

- [ ] **Step 3: Verify Laravel runs**

Run: `php artisan --version`
Expected: `Laravel Framework 11.x.x`

- [ ] **Step 4: Ignore .DS_Store**

Append to `.gitignore`:

```
.DS_Store
```

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "chore: scaffold Laravel 11"
```

---

## Task 2: Configure environment and database

**Files:**
- Modify: `.env`, `.env.example`
- Modify: `app/Providers/AppServiceProvider.php`

- [ ] **Step 1: Create the databases**

```bash
/Applications/MAMP/Library/bin/mysql -u root -proot -e "CREATE DATABASE IF NOT EXISTS smart_emailing; CREATE DATABASE IF NOT EXISTS smart_emailing_test;"
```

- [ ] **Step 2: Point .env at MAMP MySQL and the database queue**

Set these keys in `.env` (and mirror the non-secret ones in `.env.example`):

```
APP_NAME="Smart Emailing"
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=8889
DB_DATABASE=smart_emailing
DB_USERNAME=root
DB_PASSWORD=root
DB_SOCKET=/Applications/MAMP/tmp/mysql/mysql.sock
QUEUE_CONNECTION=database
```

- [ ] **Step 3: Set the default string length for MySQL 5.7**

In `app/Providers/AppServiceProvider.php`, add the import and the `boot()` body:

```php
use Illuminate\Support\Facades\Schema;

public function boot(): void
{
    Schema::defaultStringLength(191);
}
```

- [ ] **Step 4: Verify the DB connection**

Run: `php artisan migrate --force`
Expected: the default Laravel migrations run and report `DONE`

- [ ] **Step 5: Create the queue tables**

Run: `php artisan queue:table && php artisan migrate --force`
Expected: `create_jobs_table` migrates

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "chore: configure MySQL, queue driver, and string length"
```

---

## Task 3: Install dependencies

**Files:**
- Modify: `composer.json`, `package.json`

- [ ] **Step 1: Install Laravel Excel and TinyMCE**

```bash
composer require maatwebsite/excel:^3.1 --no-interaction
npm install tinymce
```

- [ ] **Step 2: Publish TinyMCE assets into public/**

```bash
mkdir -p public/vendor/tinymce
cp -R node_modules/tinymce/. public/vendor/tinymce/
```

- [ ] **Step 3: Verify the package registered**

Run: `php artisan vendor:publish --provider="Maatwebsite\Excel\ExcelServiceProvider" --tag=config`
Expected: `config/excel.php` created

- [ ] **Step 4: Commit**

```bash
git add -A
git commit -m "chore: add maatwebsite/excel and self-hosted tinymce"
```

---

## Task 4: Database schema

**Files:**
- Create: `database/migrations/*_add_is_admin_to_users_table.php`
- Create: `database/migrations/*_create_smtp_settings_table.php`
- Create: `database/migrations/*_create_email_batches_table.php`
- Create: `database/migrations/*_create_email_logs_table.php`

- [ ] **Step 1: Generate the migrations**

```bash
php artisan make:migration add_is_admin_to_users_table --table=users
php artisan make:migration create_smtp_settings_table
php artisan make:migration create_email_batches_table
php artisan make:migration create_email_logs_table
```

- [ ] **Step 2: Write the users migration**

In the `add_is_admin_to_users_table` file:

```php
public function up(): void
{
    Schema::table('users', function (Blueprint $table) {
        $table->boolean('is_admin')->default(false)->after('password');
    });
}

public function down(): void
{
    Schema::table('users', function (Blueprint $table) {
        $table->dropColumn('is_admin');
    });
}
```

- [ ] **Step 3: Write the smtp_settings migration**

```php
public function up(): void
{
    Schema::create('smtp_settings', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('host');
        $table->unsignedSmallInteger('port');
        $table->string('username');
        $table->text('password');
        $table->string('encryption')->nullable();
        $table->string('from_address');
        $table->string('from_name');
        $table->boolean('is_active')->default(false);
        $table->timestamps();

        $table->index('is_active');
    });
}

public function down(): void
{
    Schema::dropIfExists('smtp_settings');
}
```

Note: `password` is `text` because `Crypt::encryptString()` output exceeds 255 characters.
`encryption` is a plain string, not an enum — string columns over ENUM is the house preference.

- [ ] **Step 4: Write the email_batches migration**

```php
public function up(): void
{
    Schema::create('email_batches', function (Blueprint $table) {
        $table->id();
        $table->uuid('batch_uuid')->unique();
        $table->string('file_name');
        $table->string('stored_path')->nullable();
        $table->string('subject')->nullable();
        $table->longText('body')->nullable();
        $table->unsignedInteger('total_emails')->default(0);
        $table->unsignedInteger('sent_count')->default(0);
        $table->unsignedInteger('failed_count')->default(0);
        $table->unsignedInteger('pending_count')->default(0);
        $table->string('status')->default('draft');
        $table->timestamps();

        $table->index('status');
    });
}

public function down(): void
{
    Schema::dropIfExists('email_batches');
}
```

- [ ] **Step 5: Write the email_logs migration**

```php
public function up(): void
{
    Schema::create('email_logs', function (Blueprint $table) {
        $table->id();
        $table->foreignId('batch_id')->constrained('email_batches')->cascadeOnDelete();
        $table->string('name');
        $table->string('email');
        $table->string('status')->default('pending');
        $table->unsignedTinyInteger('attempts')->default(0);
        $table->text('remarks')->nullable();
        $table->dateTime('sent_at')->nullable();
        $table->timestamps();

        $table->index('batch_id');
        $table->index('status');
    });
}

public function down(): void
{
    Schema::dropIfExists('email_logs');
}
```

- [ ] **Step 6: Run the migrations**

Run: `php artisan migrate --force`
Expected: all four report `DONE`

- [ ] **Step 7: Verify the schema landed**

Run: `/Applications/MAMP/Library/bin/mysql -u root -proot smart_emailing -e "DESCRIBE email_logs;"`
Expected: columns `id, batch_id, name, email, status, attempts, remarks, sent_at, created_at, updated_at`

- [ ] **Step 8: Commit**

```bash
git add -A
git commit -m "feat: add smtp_settings, email_batches, email_logs schema"
```

---

## Task 5: Models and factories

**Files:**
- Modify: `app/Models/User.php`
- Create: `app/Models/SmtpSetting.php`, `app/Models/EmailBatch.php`, `app/Models/EmailLog.php`
- Create: `database/factories/SmtpSettingFactory.php`, `database/factories/EmailBatchFactory.php`, `database/factories/EmailLogFactory.php`

- [ ] **Step 1: Add is_admin to the User model**

In `app/Models/User.php`, add `'is_admin'` to `$fillable` and add to the `casts()` array:

```php
'is_admin' => 'boolean',
```

- [ ] **Step 2: Write SmtpSetting**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * An SMTP profile used to send mail. Only one row may be active at a time.
 */
class SmtpSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'host', 'port', 'username', 'password',
        'encryption', 'from_address', 'from_name', 'is_active',
    ];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'port'      => 'integer',
        ];
    }

    /**
     * The currently active SMTP profile, or null when none is configured.
     */
    public static function active(): ?self
    {
        return static::where('is_active', true)->first();
    }
}
```

- [ ] **Step 3: Write EmailBatch**

```php
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
```

- [ ] **Step 4: Write EmailLog**

```php
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
```

- [ ] **Step 5: Write the factories**

`database/factories/SmtpSettingFactory.php`:

```php
<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Crypt;

class SmtpSettingFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name'         => 'Primary',
            'host'         => 'smtp.example.com',
            'port'         => 587,
            'username'     => 'mailer@example.com',
            'password'     => Crypt::encryptString('secret'),
            'encryption'   => 'tls',
            'from_address' => 'noreply@example.com',
            'from_name'    => 'Smart Emailing',
            'is_active'    => true,
        ];
    }
}
```

`database/factories/EmailBatchFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\EmailBatch;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class EmailBatchFactory extends Factory
{
    public function definition(): array
    {
        return [
            'batch_uuid'    => (string) Str::uuid(),
            'file_name'     => 'recipients.xlsx',
            'subject'       => null,
            'body'          => null,
            'total_emails'  => 0,
            'sent_count'    => 0,
            'failed_count'  => 0,
            'pending_count' => 0,
            'status'        => EmailBatch::STATUS_DRAFT,
        ];
    }
}
```

`database/factories/EmailLogFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\EmailBatch;
use App\Models\EmailLog;
use Illuminate\Database\Eloquent\Factories\Factory;

class EmailLogFactory extends Factory
{
    public function definition(): array
    {
        return [
            'batch_id' => EmailBatch::factory(),
            'name'     => $this->faker->name(),
            'email'    => $this->faker->safeEmail(),
            'status'   => EmailLog::STATUS_PENDING,
            'attempts' => 0,
            'remarks'  => null,
            'sent_at'  => null,
        ];
    }
}
```

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "feat: add models and factories"
```

---

## Task 6: Configure the test suite

**Files:**
- Modify: `phpunit.xml`
- Create: `tests/TestCase.php` is already present — no change needed

- [ ] **Step 1: Point tests at the MySQL test database**

In `phpunit.xml`, inside `<php>`, replace the sqlite defaults with:

```xml
<env name="DB_CONNECTION" value="mysql"/>
<env name="DB_DATABASE" value="smart_emailing_test"/>
<env name="DB_USERNAME" value="root"/>
<env name="DB_PASSWORD" value="root"/>
<env name="QUEUE_CONNECTION" value="sync"/>
```

Remove or comment out any `<env name="DB_CONNECTION" value="sqlite"/>` and
`<env name="DB_DATABASE" value=":memory:"/>` lines. SQLite is not used — the
app targets MySQL and the schema must be exercised against it.

- [ ] **Step 2: Verify tests run against MySQL**

Run: `php artisan test`
Expected: the default Laravel example tests PASS

- [ ] **Step 3: Commit**

```bash
git add -A
git commit -m "chore: run tests against MySQL test database"
```

---

## Task 7: DynamicMailerService

**Files:**
- Create: `app/Services/DynamicMailerService.php`
- Test: `tests/Unit/DynamicMailerServiceTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit;

use App\Models\SmtpSetting;
use App\Services\DynamicMailerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class DynamicMailerServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_configures_a_transport_from_the_active_profile(): void
    {
        $smtp = SmtpSetting::factory()->create([
            'host'     => 'smtp.mailer.test',
            'port'     => 2525,
            'username' => 'bob@mailer.test',
            'password' => Crypt::encryptString('s3cret'),
        ]);

        (new DynamicMailerService())->configure($smtp);

        $this->assertSame('smtp', Config::get('mail.mailers.dynamic_smtp.transport'));
        $this->assertSame('smtp.mailer.test', Config::get('mail.mailers.dynamic_smtp.host'));
        $this->assertSame(2525, Config::get('mail.mailers.dynamic_smtp.port'));
        $this->assertSame('s3cret', Config::get('mail.mailers.dynamic_smtp.password'));
    }

    public function test_it_sets_the_from_identity(): void
    {
        $smtp = SmtpSetting::factory()->create([
            'from_address' => 'hello@mailer.test',
            'from_name'    => 'Mailer Test',
        ]);

        (new DynamicMailerService())->configure($smtp);

        $this->assertSame('hello@mailer.test', Config::get('mail.from.address'));
        $this->assertSame('Mailer Test', Config::get('mail.from.name'));
    }

    public function test_it_throws_when_no_profile_is_active(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No active SMTP profile is configured.');

        (new DynamicMailerService())->configureActive();
    }
}
```

- [ ] **Step 2: Run it to make sure it fails**

Run: `php artisan test --filter=DynamicMailerServiceTest`
Expected: FAIL — `Class "App\Services\DynamicMailerService" not found`

- [ ] **Step 3: Implement the service**

```php
<?php

namespace App\Services;

use App\Models\SmtpSetting;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;

/**
 * Builds a mail transport at runtime from a database-stored SMTP profile,
 * so credentials never live in .env.
 */
class DynamicMailerService
{
    public const MAILER = 'dynamic_smtp';

    /**
     * Register the given profile as the `dynamic_smtp` mailer.
     *
     * @param  SmtpSetting  $smtp  the profile to configure
     * @return string the mailer name to pass to Mail::mailer()
     */
    public function configure(SmtpSetting $smtp): string
    {
        Config::set('mail.mailers.' . self::MAILER, [
            'transport'  => 'smtp',
            'host'       => $smtp->host,
            'port'       => $smtp->port,
            'encryption' => $smtp->encryption,
            'username'   => $smtp->username,
            'password'   => Crypt::decryptString($smtp->password),
            'timeout'    => null,
        ]);

        Config::set('mail.from.address', $smtp->from_address);
        Config::set('mail.from.name', $smtp->from_name);

        return self::MAILER;
    }

    /**
     * Configure the mailer from whichever profile is currently active.
     *
     * @return string the mailer name to pass to Mail::mailer()
     * @throws RuntimeException when no profile is active
     */
    public function configureActive(): string
    {
        $smtp = SmtpSetting::active();

        if ($smtp === null) {
            throw new RuntimeException('No active SMTP profile is configured.');
        }

        return $this->configure($smtp);
    }
}
```

- [ ] **Step 4: Run the tests and make sure they pass**

Run: `php artisan test --filter=DynamicMailerServiceTest`
Expected: PASS (3 tests)

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat: add DynamicMailerService"
```

---

## Task 8: BatchImportService — validation engine

**Files:**
- Create: `app/Services/BatchImportService.php`
- Create: `app/Imports/BulkEmailImport.php`
- Test: `tests/Unit/BatchImportServiceTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit;

use App\Models\EmailBatch;
use App\Models\EmailLog;
use App\Services\BatchImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BatchImportServiceTest extends TestCase
{
    use RefreshDatabase;

    private function csv(string $body): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'import') . '.csv';
        file_put_contents($path, $body);

        return new UploadedFile($path, 'recipients.csv', 'text/csv', null, true);
    }

    public function test_it_creates_a_draft_batch_with_one_log_per_row(): void
    {
        Storage::fake('local');

        $file = $this->csv("name,email\nAda,ada@example.com\nBob,bob@example.com\n");

        $batch = (new BatchImportService())->import($file);

        $this->assertSame(EmailBatch::STATUS_DRAFT, $batch->status);
        $this->assertSame(2, $batch->total_emails);
        $this->assertSame(2, $batch->pending_count);
        $this->assertSame(0, $batch->failed_count);
        $this->assertCount(2, $batch->logs);
    }

    public function test_invalid_rows_are_logged_as_failed_and_still_appear_in_the_report(): void
    {
        Storage::fake('local');

        $file = $this->csv("name,email\nAda,ada@example.com\nBob,not-an-email\nCarol,\n");

        $batch = (new BatchImportService())->import($file);

        $this->assertSame(3, $batch->total_emails, 'every row of the file must be recorded');
        $this->assertSame(1, $batch->pending_count);
        $this->assertSame(2, $batch->failed_count);

        $failed = $batch->logs()->where('status', EmailLog::STATUS_FAILED)->get();
        $this->assertCount(2, $failed);
        $this->assertSame('Invalid email syntax', $failed->first()->remarks);
    }

    public function test_rows_missing_a_name_are_rejected(): void
    {
        Storage::fake('local');

        $file = $this->csv("name,email\n,dave@example.com\n");

        $batch = (new BatchImportService())->import($file);

        $this->assertSame(1, $batch->failed_count);
        $this->assertSame(0, $batch->pending_count);
    }

    public function test_fully_blank_rows_are_ignored(): void
    {
        Storage::fake('local');

        $file = $this->csv("name,email\nAda,ada@example.com\n,\n");

        $batch = (new BatchImportService())->import($file);

        $this->assertSame(1, $batch->total_emails);
    }
}
```

- [ ] **Step 2: Run it to make sure it fails**

Run: `php artisan test --filter=BatchImportServiceTest`
Expected: FAIL — `Class "App\Services\BatchImportService" not found`

- [ ] **Step 3: Implement the import reader**

`app/Imports/BulkEmailImport.php`:

```php
<?php

namespace App\Imports;

use Maatwebsite\Excel\Concerns\ToArray;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Reads a recipient file into raw rows keyed by heading.
 * Validation happens in BatchImportService, not here.
 */
class BulkEmailImport implements ToArray, WithHeadingRow
{
    public array $rows = [];

    /**
     * @param  array  $rows  rows keyed by heading name
     */
    public function array(array $rows): void
    {
        $this->rows = $rows;
    }
}
```

- [ ] **Step 4: Implement the service**

`app/Services/BatchImportService.php`:

```php
<?php

namespace App\Services;

use App\Imports\BulkEmailImport;
use App\Models\EmailBatch;
use App\Models\EmailLog;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Parses an uploaded recipient file into a draft batch.
 *
 * Every row of the file becomes an email_logs row — valid rows as `pending`,
 * invalid rows as `failed` — so the batch report accounts for the whole file.
 */
class BatchImportService
{
    public const INVALID_EMAIL_REMARK = 'Invalid email syntax';

    private const INSERT_CHUNK = 500;

    /**
     * @param  UploadedFile  $file  an .xlsx or .csv with `name` and `email` headings
     * @return EmailBatch the created draft batch, with logs loaded
     */
    public function import(UploadedFile $file): EmailBatch
    {
        $storedPath   = $file->store('imports');
        $originalName = $file->getClientOriginalName();

        $reader = new BulkEmailImport();
        Excel::import($reader, $file);

        $rows = $this->normalise($reader->rows);

        return DB::transaction(function () use ($rows, $originalName, $storedPath) {
            $batch = EmailBatch::create([
                'batch_uuid' => (string) Str::uuid(),
                'file_name'  => $originalName,
                'status'     => EmailBatch::STATUS_DRAFT,
            ]);

            $pending = 0;
            $failed  = 0;
            $now     = now();
            $records = [];

            foreach ($rows as $row) {
                $isValid = $this->isValid($row['name'], $row['email']);
                $isValid ? $pending++ : $failed++;

                $records[] = [
                    'batch_id'   => $batch->id,
                    'name'       => $row['name'],
                    'email'      => $row['email'],
                    'status'     => $isValid ? EmailLog::STATUS_PENDING : EmailLog::STATUS_FAILED,
                    'attempts'   => 0,
                    'remarks'    => $isValid ? null : self::INVALID_EMAIL_REMARK,
                    'sent_at'    => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            foreach (array_chunk($records, self::INSERT_CHUNK) as $chunk) {
                EmailLog::insert($chunk);
            }

            $batch->update([
                'total_emails'  => count($records),
                'pending_count' => $pending,
                'failed_count'  => $failed,
                'stored_path'   => $storedPath,
            ]);

            return $batch->fresh('logs');
        });
    }

    /**
     * Drop fully-blank rows and trim the two columns we care about.
     *
     * @param  array  $rows  raw heading-keyed rows from the reader
     * @return array<int, array{name: string, email: string}>
     */
    private function normalise(array $rows): array
    {
        $clean = [];

        foreach ($rows as $row) {
            $name  = trim((string) ($row['name'] ?? ''));
            $email = trim((string) ($row['email'] ?? ''));

            if ($name === '' && $email === '') {
                continue;
            }

            $clean[] = ['name' => $name, 'email' => $email];
        }

        return $clean;
    }

    /**
     * A row is valid when both columns are present and the email is RFC-parseable.
     */
    private function isValid(string $name, string $email): bool
    {
        return $name !== ''
            && $email !== ''
            && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
}
```

- [ ] **Step 5: Run the tests and make sure they pass**

Run: `php artisan test --filter=BatchImportServiceTest`
Expected: PASS (4 tests)

The service writes `stored_path` so Delete Batch can remove the uploaded file;
that column was created in Task 4 and added to `$fillable` in Task 5.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "feat: add BatchImportService validation engine"
```

---

## Task 9: Mailable and SendBulkEmailJob

**Files:**
- Create: `app/Mail/BulkEmailMessage.php`
- Create: `resources/views/emails/bulk.blade.php`
- Create: `app/Jobs/SendBulkEmailJob.php`
- Test: `tests/Unit/SendBulkEmailJobTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit;

use App\Jobs\SendBulkEmailJob;
use App\Mail\BulkEmailMessage;
use App\Models\EmailBatch;
use App\Models\EmailLog;
use App\Models\SmtpSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

class SendBulkEmailJobTest extends TestCase
{
    use RefreshDatabase;

    private function batchWithLog(): array
    {
        SmtpSetting::factory()->create();

        $batch = EmailBatch::factory()->create([
            'subject'       => 'Hello {{ name }}',
            'body'          => '<p>Hi {{ name }}</p>',
            'total_emails'  => 1,
            'pending_count' => 1,
            'status'        => EmailBatch::STATUS_PROCESSING,
        ]);

        $log = EmailLog::factory()->create([
            'batch_id' => $batch->id,
            'name'     => 'Ada',
            'email'    => 'ada@example.com',
        ]);

        return [$batch, $log];
    }

    public function test_a_successful_send_marks_the_log_sent_and_moves_the_counters(): void
    {
        Mail::fake();
        [$batch, $log] = $this->batchWithLog();

        (new SendBulkEmailJob($log->id))->handle(app(\App\Services\DynamicMailerService::class));

        $log->refresh();
        $batch->refresh();

        $this->assertSame(EmailLog::STATUS_SENT, $log->status);
        $this->assertNotNull($log->sent_at);
        $this->assertSame(1, $batch->sent_count);
        $this->assertSame(0, $batch->pending_count);
        $this->assertSame(EmailBatch::STATUS_COMPLETED, $batch->status);

        Mail::assertSent(BulkEmailMessage::class);
    }

    public function test_the_recipient_name_is_substituted_into_subject_and_body(): void
    {
        Mail::fake();
        [, $log] = $this->batchWithLog();

        (new SendBulkEmailJob($log->id))->handle(app(\App\Services\DynamicMailerService::class));

        Mail::assertSent(BulkEmailMessage::class, function (BulkEmailMessage $mail) {
            return $mail->subjectLine === 'Hello Ada'
                && str_contains($mail->bodyHtml, 'Hi Ada');
        });
    }

    public function test_permanent_failure_marks_the_log_failed_with_the_error(): void
    {
        [$batch, $log] = $this->batchWithLog();

        (new SendBulkEmailJob($log->id))->failed(new RuntimeException('535 auth failed'));

        $log->refresh();
        $batch->refresh();

        $this->assertSame(EmailLog::STATUS_FAILED, $log->status);
        $this->assertSame('535 auth failed', $log->remarks);
        $this->assertSame(1, $batch->failed_count);
        $this->assertSame(0, $batch->pending_count);
        $this->assertSame(EmailBatch::STATUS_COMPLETED, $batch->status);
    }

    public function test_a_transient_failure_records_the_error_and_rethrows(): void
    {
        [, $log] = $this->batchWithLog();

        Mail::shouldReceive('mailer')->andThrow(new RuntimeException('connection reset'));

        try {
            (new SendBulkEmailJob($log->id))->handle(app(\App\Services\DynamicMailerService::class));
            $this->fail('the job should rethrow so Laravel retries');
        } catch (RuntimeException $e) {
            $this->assertSame('connection reset', $e->getMessage());
        }

        $log->refresh();
        $this->assertSame(1, $log->attempts);
        $this->assertSame('connection reset', $log->remarks);
        $this->assertSame(EmailLog::STATUS_PENDING, $log->status, 'still retryable');
    }
}
```

- [ ] **Step 2: Run it to make sure it fails**

Run: `php artisan test --filter=SendBulkEmailJobTest`
Expected: FAIL — `Class "App\Jobs\SendBulkEmailJob" not found`

- [ ] **Step 3: Implement the mailable**

`app/Mail/BulkEmailMessage.php`:

```php
<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * One rendered bulk message, with placeholders already substituted.
 */
class BulkEmailMessage extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  string  $subjectLine  the rendered subject
     * @param  string  $bodyHtml     the rendered HTML body
     */
    public function __construct(
        public string $subjectLine,
        public string $bodyHtml,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.bulk',
            with: ['bodyHtml' => $this->bodyHtml],
        );
    }
}
```

`resources/views/emails/bulk.blade.php`:

```blade
{!! $bodyHtml !!}
```

- [ ] **Step 4: Implement the job**

`app/Jobs/SendBulkEmailJob.php`:

```php
<?php

namespace App\Jobs;

use App\Mail\BulkEmailMessage;
use App\Models\EmailBatch;
use App\Models\EmailLog;
use App\Services\DynamicMailerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sends one recipient's message and records the outcome on its email_logs row.
 */
class SendBulkEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    /** @var array<int, int> seconds to wait before each retry */
    public array $backoff = [10, 30, 60];

    /**
     * @param  int  $emailLogId  the recipient row to send
     */
    public function __construct(public int $emailLogId)
    {
    }

    /**
     * @return array<int, string> Horizon tags
     */
    public function tags(): array
    {
        return ['bulk-email', 'log:' . $this->emailLogId];
    }

    /**
     * Render and send the message, then advance the batch counters.
     *
     * @param  DynamicMailerService  $mailer  builds the transport from the active SMTP profile
     * @throws Throwable rethrown so Laravel applies the backoff and retries
     */
    public function handle(DynamicMailerService $mailer): void
    {
        $log = EmailLog::with('batch')->find($this->emailLogId);

        if ($log === null || $log->status === EmailLog::STATUS_SENT) {
            return;
        }

        try {
            $mailerName = $mailer->configureActive();
            $batch      = $log->batch;

            Mail::mailer($mailerName)
                ->to($log->email, $log->name)
                ->send(new BulkEmailMessage(
                    $this->substitute($batch->subject, $log->name),
                    $this->substitute($batch->body, $log->name),
                ));

            $log->update([
                'status'  => EmailLog::STATUS_SENT,
                'sent_at' => now(),
                'remarks' => null,
            ]);

            $this->settle($batch, 'sent_count');
        } catch (Throwable $e) {
            $log->increment('attempts');
            $log->update(['remarks' => $e->getMessage()]);

            throw $e;
        }
    }

    /**
     * Permanent failure after the final attempt.
     */
    public function failed(Throwable $exception): void
    {
        $log = EmailLog::with('batch')->find($this->emailLogId);

        if ($log === null || $log->status !== EmailLog::STATUS_PENDING) {
            return;
        }

        $log->update([
            'status'  => EmailLog::STATUS_FAILED,
            'remarks' => $exception->getMessage(),
        ]);

        $this->settle($log->batch, 'failed_count');
    }

    /**
     * Atomically move one recipient out of pending, and close the batch at zero.
     *
     * Uses SQL increments rather than read-modify-write because parallel
     * workers would otherwise race and lose updates.
     *
     * @param  EmailBatch  $batch   the batch to advance
     * @param  string      $column  `sent_count` or `failed_count`
     */
    private function settle(EmailBatch $batch, string $column): void
    {
        EmailBatch::whereKey($batch->id)->update([
            $column         => DB::raw($column . ' + 1'),
            'pending_count' => DB::raw('GREATEST(pending_count - 1, 0)'),
        ]);

        $fresh = $batch->fresh();

        if ($fresh !== null && $fresh->pending_count === 0) {
            $fresh->update(['status' => EmailBatch::STATUS_COMPLETED]);
        }
    }

    /**
     * Replace the `{{ name }}` placeholder with the recipient's name.
     */
    private function substitute(?string $template, string $name): string
    {
        return str_replace(
            ['{{ name }}', '{{name}}'],
            $name,
            (string) $template,
        );
    }
}
```

- [ ] **Step 5: Run the tests and make sure they pass**

Run: `php artisan test --filter=SendBulkEmailJobTest`
Expected: PASS (4 tests)

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "feat: add SendBulkEmailJob with retry and atomic counters"
```

---

## Task 10: BatchDispatchService

**Files:**
- Create: `app/Services/BatchDispatchService.php`
- Test: `tests/Unit/BatchDispatchServiceTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit;

use App\Jobs\SendBulkEmailJob;
use App\Models\EmailBatch;
use App\Models\EmailLog;
use App\Services\BatchDispatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class BatchDispatchServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_queues_one_job_per_pending_row_only(): void
    {
        Queue::fake();

        $batch = EmailBatch::factory()->create([
            'status'        => EmailBatch::STATUS_DRAFT,
            'total_emails'  => 3,
            'pending_count' => 2,
            'failed_count'  => 1,
        ]);

        EmailLog::factory()->count(2)->create([
            'batch_id' => $batch->id,
            'status'   => EmailLog::STATUS_PENDING,
        ]);
        EmailLog::factory()->create([
            'batch_id' => $batch->id,
            'status'   => EmailLog::STATUS_FAILED,
            'remarks'  => 'Invalid email syntax',
        ]);

        (new BatchDispatchService())->dispatch($batch, 'Subject', '<p>Body</p>');

        Queue::assertPushed(SendBulkEmailJob::class, 2);
    }

    public function test_it_persists_the_message_and_marks_the_batch_processing(): void
    {
        Queue::fake();

        $batch = EmailBatch::factory()->create(['status' => EmailBatch::STATUS_DRAFT]);
        EmailLog::factory()->create(['batch_id' => $batch->id]);

        (new BatchDispatchService())->dispatch($batch, 'Hi there', '<p>Body</p>');

        $batch->refresh();
        $this->assertSame('Hi there', $batch->subject);
        $this->assertSame('<p>Body</p>', $batch->body);
        $this->assertSame(EmailBatch::STATUS_PROCESSING, $batch->status);
    }

    public function test_a_batch_can_only_be_sent_once(): void
    {
        Queue::fake();

        $batch = EmailBatch::factory()->create(['status' => EmailBatch::STATUS_PROCESSING]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('This batch has already been sent.');

        (new BatchDispatchService())->dispatch($batch, 'Subject', '<p>Body</p>');
    }
}
```

- [ ] **Step 2: Run it to make sure it fails**

Run: `php artisan test --filter=BatchDispatchServiceTest`
Expected: FAIL — `Class "App\Services\BatchDispatchService" not found`

- [ ] **Step 3: Implement the service**

```php
<?php

namespace App\Services;

use App\Jobs\SendBulkEmailJob;
use App\Models\EmailBatch;
use App\Models\EmailLog;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Commits a draft batch: saves its message and queues one job per recipient.
 */
class BatchDispatchService
{
    private const SELECT_CHUNK = 500;

    /**
     * @param  EmailBatch  $batch    a batch in `draft` status
     * @param  string      $subject  the subject line, may contain `{{ name }}`
     * @param  string      $body     the HTML body, may contain `{{ name }}`
     * @return int the number of jobs queued
     * @throws RuntimeException when the batch is not a draft
     */
    public function dispatch(EmailBatch $batch, string $subject, string $body): int
    {
        if ($batch->status !== EmailBatch::STATUS_DRAFT) {
            throw new RuntimeException('This batch has already been sent.');
        }

        DB::transaction(function () use ($batch, $subject, $body) {
            $batch->update([
                'subject' => $subject,
                'body'    => $body,
                'status'  => EmailBatch::STATUS_PROCESSING,
            ]);
        });

        $queued = 0;

        $batch->logs()
            ->where('status', EmailLog::STATUS_PENDING)
            ->chunkById(self::SELECT_CHUNK, function ($logs) use (&$queued) {
                foreach ($logs as $log) {
                    SendBulkEmailJob::dispatch($log->id);
                    $queued++;
                }
            });

        return $queued;
    }
}
```

- [ ] **Step 4: Run the tests and make sure they pass**

Run: `php artisan test --filter=BatchDispatchServiceTest`
Expected: PASS (3 tests)

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat: add BatchDispatchService"
```

---

## Task 11: Auth and admin middleware

**Files:**
- Create: `app/Http/Controllers/AuthController.php`
- Create: `app/Http/Middleware/IsAdminMiddleware.php`
- Modify: `bootstrap/app.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/AuthTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_creates_a_non_admin_user(): void
    {
        $this->post('/register', [
            'name'                  => 'Ada',
            'email'                 => 'ada@example.com',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
        ])->assertRedirect();

        $user = User::where('email', 'ada@example.com')->first();
        $this->assertNotNull($user);
        $this->assertFalse($user->is_admin);
    }

    public function test_a_user_can_log_in_and_out(): void
    {
        $user = User::factory()->create(['password' => bcrypt('password123')]);

        $this->post('/login', [
            'email'    => $user->email,
            'password' => 'password123',
        ])->assertRedirect();

        $this->assertAuthenticatedAs($user);

        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_guests_are_redirected_from_batches(): void
    {
        $this->get('/batches')->assertRedirect('/login');
    }

    public function test_non_admins_get_403_on_batches(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)->get('/batches')->assertForbidden();
    }

    public function test_admins_can_reach_batches(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->get('/batches')->assertOk();
    }
}
```

- [ ] **Step 2: Run it to make sure it fails**

Run: `php artisan test --filter=AuthTest`
Expected: FAIL — routes not defined (404)

- [ ] **Step 3: Implement the middleware**

`app/Http/Middleware/IsAdminMiddleware.php`:

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts a route to users flagged is_admin.
 */
class IsAdminMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->is_admin) {
            abort(403, 'Administrator access required.');
        }

        return $next($request);
    }
}
```

- [ ] **Step 4: Register the alias**

In `bootstrap/app.php`, inside `->withMiddleware(function (Middleware $middleware) {`:

```php
$middleware->alias([
    'admin' => \App\Http\Middleware\IsAdminMiddleware::class,
]);
```

- [ ] **Step 5: Implement AuthController**

```php
<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Session authentication. Registration always creates non-admin users;
 * promotion is a manual SQL update.
 */
class AuthController extends Controller
{
    public function showLogin(): View
    {
        return view('auth.login');
    }

    public function showRegister(): View
    {
        return view('auth.register');
    }

    /**
     * @param  Request  $request  name, email, password, password_confirmation
     */
    public function register(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name'     => ['required', 'string', 'max:255'],
            'email'    => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = User::create([
            'name'     => $data['name'],
            'email'    => $data['email'],
            'password' => Hash::make($data['password']),
            'is_admin' => false,
        ]);

        Auth::login($user);

        return redirect('/');
    }

    /**
     * @param  Request  $request  email, password
     * @throws ValidationException on bad credentials
     */
    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($data, $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'email' => 'These credentials do not match our records.',
            ]);
        }

        $request->session()->regenerate();

        return redirect()->intended('/');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }
}
```

- [ ] **Step 6: Define the routes**

Replace `routes/web.php` with:

```php
<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\EmailBatchController;
use App\Http\Controllers\SmtpSettingController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login']);
    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [AuthController::class, 'register']);
});

Route::post('/logout', [AuthController::class, 'logout'])
    ->middleware('auth')
    ->name('logout');

Route::get('/', fn () => redirect('/batches'))->middleware('auth');

Route::middleware(['auth', 'admin'])->group(function () {
    Route::get('/batches', [EmailBatchController::class, 'index'])->name('batches.index');
    Route::get('/batches/sample-download', [EmailBatchController::class, 'downloadSample'])->name('batches.sample');
    Route::post('/batches/upload', [EmailBatchController::class, 'upload'])->name('batches.upload');
    Route::get('/batches/{batch}/compose', [EmailBatchController::class, 'compose'])->name('batches.compose');
    Route::post('/batches/{batch}/send', [EmailBatchController::class, 'send'])->name('batches.send');
    Route::get('/batches/{batch}', [EmailBatchController::class, 'show'])->name('batches.show');
    Route::get('/batches/{batch}/export', [EmailBatchController::class, 'exportReport'])->name('batches.export');
    Route::delete('/batches/{batch}', [EmailBatchController::class, 'destroy'])->name('batches.destroy');

    Route::get('/smtp', [SmtpSettingController::class, 'index'])->name('smtp.index');
    Route::get('/smtp/create', [SmtpSettingController::class, 'create'])->name('smtp.create');
    Route::post('/smtp', [SmtpSettingController::class, 'store'])->name('smtp.store');
    Route::get('/smtp/{smtp}/edit', [SmtpSettingController::class, 'edit'])->name('smtp.edit');
    Route::put('/smtp/{smtp}', [SmtpSettingController::class, 'update'])->name('smtp.update');
    Route::post('/smtp/{smtp}/activate', [SmtpSettingController::class, 'activate'])->name('smtp.activate');
    Route::post('/smtp/{smtp}/test', [SmtpSettingController::class, 'sendTest'])->name('smtp.test');
    Route::delete('/smtp/{smtp}', [SmtpSettingController::class, 'destroy'])->name('smtp.destroy');
});
```

Note: `/batches/sample-download` is declared before `/batches/{batch}` so the
literal path is not swallowed by the wildcard.

- [ ] **Step 7: Run the tests**

Run: `php artisan test --filter=AuthTest`
Expected: the three auth tests PASS; the two `/batches` tests still fail until
Task 13 adds the controller. That is expected at this point.

- [ ] **Step 8: Commit**

```bash
git add -A
git commit -m "feat: add session auth and admin middleware"
```

---

## Task 12: Excel exports and sample template

**Files:**
- Create: `app/Exports/SampleTemplateExport.php`
- Create: `app/Exports/BatchReportExport.php`
- Test: `tests/Unit/BatchReportExportTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit;

use App\Exports\BatchReportExport;
use App\Models\EmailBatch;
use App\Models\EmailLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BatchReportExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_exports_every_row_of_the_batch(): void
    {
        $batch = EmailBatch::factory()->create();

        EmailLog::factory()->create([
            'batch_id' => $batch->id,
            'name'     => 'Ada',
            'email'    => 'ada@example.com',
            'status'   => EmailLog::STATUS_SENT,
            'sent_at'  => now(),
        ]);
        EmailLog::factory()->create([
            'batch_id' => $batch->id,
            'name'     => 'Bob',
            'email'    => 'bad-address',
            'status'   => EmailLog::STATUS_FAILED,
            'remarks'  => 'Invalid email syntax',
        ]);

        $rows = (new BatchReportExport($batch))->collection();

        $this->assertCount(2, $rows);
    }

    public function test_the_heading_row_names_every_report_column(): void
    {
        $batch = EmailBatch::factory()->create();

        $headings = (new BatchReportExport($batch))->headings();

        $this->assertSame(
            ['Name', 'Email', 'Status', 'Date', 'Time', 'Attempts', 'Remarks'],
            $headings,
        );
    }

    public function test_a_status_filter_narrows_the_export(): void
    {
        $batch = EmailBatch::factory()->create();

        EmailLog::factory()->create(['batch_id' => $batch->id, 'status' => EmailLog::STATUS_SENT]);
        EmailLog::factory()->create(['batch_id' => $batch->id, 'status' => EmailLog::STATUS_FAILED]);

        $rows = (new BatchReportExport($batch, EmailLog::STATUS_FAILED))->collection();

        $this->assertCount(1, $rows);
    }
}
```

- [ ] **Step 2: Run it to make sure it fails**

Run: `php artisan test --filter=BatchReportExportTest`
Expected: FAIL — `Class "App\Exports\BatchReportExport" not found`

- [ ] **Step 3: Implement the sample template**

`app/Exports/SampleTemplateExport.php`:

```php
<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

/**
 * The blank recipient template offered on the upload screen.
 */
class SampleTemplateExport implements FromCollection, WithHeadings
{
    public function collection(): Collection
    {
        return collect([
            ['Ada Lovelace', 'ada@example.com'],
            ['Alan Turing', 'alan@example.com'],
        ]);
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return ['name', 'email'];
    }
}
```

- [ ] **Step 4: Implement the report export**

`app/Exports/BatchReportExport.php`:

```php
<?php

namespace App\Exports;

use App\Models\EmailBatch;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

/**
 * Every recipient row of one batch, including rows rejected at import.
 */
class BatchReportExport implements FromCollection, WithHeadings
{
    /**
     * @param  EmailBatch   $batch   the batch to export
     * @param  string|null  $status  optional `pending`/`sent`/`failed` filter
     */
    public function __construct(
        private EmailBatch $batch,
        private ?string $status = null,
    ) {
    }

    public function collection(): Collection
    {
        return $this->batch->logs()
            ->when($this->status, fn ($q) => $q->where('status', $this->status))
            ->orderBy('id')
            ->get()
            ->map(fn ($log) => [
                $log->name,
                $log->email,
                ucfirst($log->status),
                $log->sent_at?->format('Y-m-d') ?? $log->created_at->format('Y-m-d'),
                $log->sent_at?->format('H:i:s') ?? $log->created_at->format('H:i:s'),
                $log->attempts,
                $log->remarks ?? '',
            ]);
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return ['Name', 'Email', 'Status', 'Date', 'Time', 'Attempts', 'Remarks'];
    }
}
```

- [ ] **Step 5: Run the tests and make sure they pass**

Run: `php artisan test --filter=BatchReportExportTest`
Expected: PASS (3 tests)

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "feat: add sample template and batch report exports"
```

---

## Task 13: EmailBatchController

**Files:**
- Create: `app/Http/Controllers/EmailBatchController.php`
- Create: `app/Http/Requests/UploadBatchRequest.php`, `app/Http/Requests/SendBatchRequest.php`
- Test: `tests/Feature/EmailBatchFlowTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Jobs\SendBulkEmailJob;
use App\Models\EmailBatch;
use App\Models\EmailLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EmailBatchFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['is_admin' => true]);
    }

    private function csv(string $body): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'flow') . '.csv';
        file_put_contents($path, $body);

        return new UploadedFile($path, 'recipients.csv', 'text/csv', null, true);
    }

    public function test_upload_parses_the_file_and_redirects_to_compose(): void
    {
        Storage::fake('local');

        $response = $this->actingAs($this->admin)->post('/batches/upload', [
            'file' => $this->csv("name,email\nAda,ada@example.com\nBob,bad\n"),
        ]);

        $batch = EmailBatch::first();
        $this->assertNotNull($batch);
        $response->assertRedirect(route('batches.compose', $batch));

        $this->assertSame(2, $batch->total_emails);
        $this->assertSame(1, $batch->pending_count);
        $this->assertSame(1, $batch->failed_count);
    }

    public function test_the_compose_page_shows_the_matching_record_count(): void
    {
        $batch = EmailBatch::factory()->create([
            'total_emails'  => 3,
            'pending_count' => 3,
        ]);

        $this->actingAs($this->admin)
            ->get(route('batches.compose', $batch))
            ->assertOk()
            ->assertSee('Matching Records: 3');
    }

    public function test_send_queues_jobs_and_marks_the_batch_processing(): void
    {
        Queue::fake();

        $batch = EmailBatch::factory()->create(['pending_count' => 1, 'total_emails' => 1]);
        EmailLog::factory()->create(['batch_id' => $batch->id]);

        $this->actingAs($this->admin)
            ->post(route('batches.send', $batch), [
                'subject' => 'Hello',
                'body'    => '<p>Hi {{ name }}</p>',
            ])
            ->assertRedirect(route('batches.show', $batch));

        Queue::assertPushed(SendBulkEmailJob::class, 1);
        $this->assertSame(EmailBatch::STATUS_PROCESSING, $batch->fresh()->status);
    }

    public function test_a_batch_cannot_be_sent_twice(): void
    {
        Queue::fake();

        $batch = EmailBatch::factory()->create(['status' => EmailBatch::STATUS_PROCESSING]);

        $this->actingAs($this->admin)
            ->post(route('batches.send', $batch), ['subject' => 'Hello', 'body' => '<p>Hi</p>'])
            ->assertRedirect();

        Queue::assertNothingPushed();
    }

    public function test_the_report_lists_every_row_including_invalid_ones(): void
    {
        $batch = EmailBatch::factory()->create(['total_emails' => 2]);
        EmailLog::factory()->create([
            'batch_id' => $batch->id,
            'email'    => 'ada@example.com',
            'status'   => EmailLog::STATUS_SENT,
        ]);
        EmailLog::factory()->create([
            'batch_id' => $batch->id,
            'email'    => 'bad-address',
            'status'   => EmailLog::STATUS_FAILED,
            'remarks'  => 'Invalid email syntax',
        ]);

        $this->actingAs($this->admin)
            ->get(route('batches.show', $batch))
            ->assertOk()
            ->assertSee('ada@example.com')
            ->assertSee('bad-address')
            ->assertSee('Invalid email syntax');
    }

    public function test_deleting_a_batch_removes_its_logs(): void
    {
        $batch = EmailBatch::factory()->create();
        EmailLog::factory()->count(2)->create(['batch_id' => $batch->id]);

        $this->actingAs($this->admin)
            ->delete(route('batches.destroy', $batch))
            ->assertRedirect(route('batches.index'));

        $this->assertDatabaseCount('email_batches', 0);
        $this->assertDatabaseCount('email_logs', 0);
    }

    public function test_the_sample_template_downloads(): void
    {
        $this->actingAs($this->admin)
            ->get(route('batches.sample'))
            ->assertOk();
    }
}
```

- [ ] **Step 2: Run it to make sure it fails**

Run: `php artisan test --filter=EmailBatchFlowTest`
Expected: FAIL — `Class "App\Http\Controllers\EmailBatchController" not found`

- [ ] **Step 3: Implement the form requests**

`app/Http/Requests/UploadBatchRequest.php`:

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UploadBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv,txt', 'max:10240'],
        ];
    }
}
```

`app/Http/Requests/SendBatchRequest.php`:

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SendBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'subject' => ['required', 'string', 'max:255'],
            'body'    => ['required', 'string'],
        ];
    }
}
```

- [ ] **Step 4: Implement the controller**

```php
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
        try {
            $queued = $this->dispatcher->dispatch(
                $batch,
                $request->validated()['subject'],
                $request->validated()['body'],
            );
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
     * @param  Request  $request  optional `status` filter
     */
    public function show(Request $request, EmailBatch $batch): View
    {
        $status = $request->query('status');

        $logs = $batch->logs()
            ->when($status, fn ($q) => $q->where('status', $status))
            ->orderBy('id')
            ->paginate(50)
            ->withQueryString();

        return view('batches.show', compact('batch', 'logs', 'status'));
    }

    public function exportReport(Request $request, EmailBatch $batch): BinaryFileResponse
    {
        $name = 'batch-' . $batch->id . '-report.xlsx';

        return Excel::download(
            new BatchReportExport($batch, $request->query('status')),
            $name,
        );
    }

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
```

- [ ] **Step 5: Run the tests**

Run: `php artisan test --filter=EmailBatchFlowTest`
Expected: PASS (7 tests) — except any assertion depending on views, which land
in Task 15. If `assertSee` assertions fail with "View not found", that is
expected; re-run after Task 15.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "feat: add EmailBatchController with two-step upload flow"
```

---

## Task 14: SmtpSettingController

**Files:**
- Create: `app/Http/Controllers/SmtpSettingController.php`
- Create: `app/Http/Requests/SmtpSettingRequest.php`
- Test: `tests/Feature/SmtpSettingTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\SmtpSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class SmtpSettingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['is_admin' => true]);
    }

    public function test_storing_a_profile_encrypts_the_password(): void
    {
        $this->actingAs($this->admin)->post('/smtp', [
            'name'         => 'Primary',
            'host'         => 'smtp.example.com',
            'port'         => 587,
            'username'     => 'mailer@example.com',
            'password'     => 'plaintext',
            'encryption'   => 'tls',
            'from_address' => 'noreply@example.com',
            'from_name'    => 'Smart Emailing',
        ])->assertRedirect(route('smtp.index'));

        $smtp = SmtpSetting::first();
        $this->assertNotSame('plaintext', $smtp->password);
        $this->assertSame('plaintext', Crypt::decryptString($smtp->password));
    }

    public function test_activating_a_profile_deactivates_the_others(): void
    {
        $first  = SmtpSetting::factory()->create(['is_active' => true]);
        $second = SmtpSetting::factory()->create(['is_active' => false]);

        $this->actingAs($this->admin)
            ->post(route('smtp.activate', $second))
            ->assertRedirect();

        $this->assertFalse($first->fresh()->is_active);
        $this->assertTrue($second->fresh()->is_active);
        $this->assertSame(1, SmtpSetting::where('is_active', true)->count());
    }

    public function test_updating_without_a_password_keeps_the_existing_one(): void
    {
        $smtp = SmtpSetting::factory()->create(['password' => Crypt::encryptString('original')]);

        $this->actingAs($this->admin)->put(route('smtp.update', $smtp), [
            'name'         => 'Renamed',
            'host'         => 'smtp.example.com',
            'port'         => 587,
            'username'     => 'mailer@example.com',
            'password'     => '',
            'encryption'   => 'tls',
            'from_address' => 'noreply@example.com',
            'from_name'    => 'Smart Emailing',
        ])->assertRedirect(route('smtp.index'));

        $smtp->refresh();
        $this->assertSame('Renamed', $smtp->name);
        $this->assertSame('original', Crypt::decryptString($smtp->password));
    }

    public function test_non_admins_cannot_reach_smtp_settings(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)->get('/smtp')->assertForbidden();
    }
}
```

- [ ] **Step 2: Run it to make sure it fails**

Run: `php artisan test --filter=SmtpSettingTest`
Expected: FAIL — `Class "App\Http\Controllers\SmtpSettingController" not found`

- [ ] **Step 3: Implement the form request**

`app/Http/Requests/SmtpSettingRequest.php`:

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SmtpSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name'         => ['required', 'string', 'max:255'],
            'host'         => ['required', 'string', 'max:255'],
            'port'         => ['required', 'integer', 'min:1', 'max:65535'],
            'username'     => ['required', 'string', 'max:255'],
            'password'     => [$this->isMethod('post') ? 'required' : 'nullable', 'string'],
            'encryption'   => ['nullable', Rule::in(['tls', 'ssl'])],
            'from_address' => ['required', 'email', 'max:255'],
            'from_name'    => ['required', 'string', 'max:255'],
        ];
    }
}
```

- [ ] **Step 4: Implement the controller**

```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\SmtpSettingRequest;
use App\Mail\BulkEmailMessage;
use App\Models\SmtpSetting;
use App\Services\DynamicMailerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;
use Throwable;

/**
 * Admin CRUD for SMTP profiles. Exactly one profile may be active.
 */
class SmtpSettingController extends Controller
{
    public function __construct(private DynamicMailerService $mailer)
    {
    }

    public function index(): View
    {
        $settings = SmtpSetting::orderByDesc('is_active')->orderBy('name')->get();

        return view('smtp.index', compact('settings'));
    }

    public function create(): View
    {
        return view('smtp.form', ['smtp' => new SmtpSetting()]);
    }

    public function store(SmtpSettingRequest $request): RedirectResponse
    {
        $data             = $request->validated();
        $data['password'] = Crypt::encryptString($data['password']);
        $data['is_active'] = SmtpSetting::count() === 0;

        SmtpSetting::create($data);

        return redirect()->route('smtp.index')->with('status', 'SMTP profile saved.');
    }

    public function edit(SmtpSetting $smtp): View
    {
        return view('smtp.form', compact('smtp'));
    }

    /**
     * A blank password field leaves the stored credential untouched.
     */
    public function update(SmtpSettingRequest $request, SmtpSetting $smtp): RedirectResponse
    {
        $data = $request->validated();

        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        } else {
            $data['password'] = Crypt::encryptString($data['password']);
        }

        $smtp->update($data);

        return redirect()->route('smtp.index')->with('status', 'SMTP profile updated.');
    }

    /**
     * Make this the only active profile.
     */
    public function activate(SmtpSetting $smtp): RedirectResponse
    {
        DB::transaction(function () use ($smtp) {
            SmtpSetting::where('id', '!=', $smtp->id)->update(['is_active' => false]);
            $smtp->update(['is_active' => true]);
        });

        return redirect()->route('smtp.index')->with('status', "{$smtp->name} is now active.");
    }

    /**
     * Send a probe message through this profile to surface bad credentials
     * before a bulk run rather than after it.
     *
     * @param  Request  $request  `email` — where to send the probe
     */
    public function sendTest(Request $request, SmtpSetting $smtp): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);

        try {
            $mailerName = $this->mailer->configure($smtp);

            Mail::mailer($mailerName)
                ->to($data['email'])
                ->send(new BulkEmailMessage(
                    'Smart Emailing test message',
                    '<p>This is a test message from the ' . e($smtp->name) . ' profile.</p>',
                ));
        } catch (Throwable $e) {
            return redirect()->route('smtp.index')
                ->with('error', 'Test failed: ' . $e->getMessage());
        }

        return redirect()->route('smtp.index')
            ->with('status', 'Test message sent to ' . $data['email'] . '.');
    }

    public function destroy(SmtpSetting $smtp): RedirectResponse
    {
        $smtp->delete();

        return redirect()->route('smtp.index')->with('status', 'SMTP profile deleted.');
    }
}
```

- [ ] **Step 5: Run the tests**

Run: `php artisan test --filter=SmtpSettingTest`
Expected: PASS (4 tests) — view-dependent assertions land in Task 15.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "feat: add SMTP profile management with test send"
```

---

## Task 15: Blade views

**Files:**
- Create: `resources/views/layouts/app.blade.php`
- Create: `resources/views/auth/login.blade.php`, `resources/views/auth/register.blade.php`
- Create: `resources/views/batches/index.blade.php`, `compose.blade.php`, `show.blade.php`
- Create: `resources/views/smtp/index.blade.php`, `form.blade.php`
- Create: `resources/views/partials/status-badge.blade.php`

- [ ] **Step 1: Write the layout**

`resources/views/layouts/app.blade.php`:

```blade
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Smart Emailing')</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container">
        <a class="navbar-brand" href="{{ url('/') }}">Smart Emailing</a>
        @auth
            <div class="d-flex align-items-center gap-3">
                <a class="nav-link text-white" href="{{ route('batches.index') }}">Batches</a>
                <a class="nav-link text-white" href="{{ route('smtp.index') }}">SMTP</a>
                <span class="text-white">{{ auth()->user()->name }}</span>
                <span class="badge {{ auth()->user()->is_admin ? 'bg-success' : 'bg-secondary' }}">
                    {{ auth()->user()->is_admin ? 'Admin' : 'User' }}
                </span>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button class="btn btn-sm btn-outline-light" type="submit">Logout</button>
                </form>
            </div>
        @endauth
    </div>
</nav>

<main class="container py-4">
    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    @if (session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @yield('content')
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
@stack('scripts')
</body>
</html>
```

- [ ] **Step 2: Write the status badge partial**

`resources/views/partials/status-badge.blade.php`:

```blade
@php
    $colours = [
        'sent'       => 'bg-success',
        'failed'     => 'bg-danger',
        'pending'    => 'bg-warning text-dark',
        'draft'      => 'bg-secondary',
        'processing' => 'bg-info text-dark',
        'completed'  => 'bg-success',
    ];
@endphp
<span class="badge {{ $colours[$status] ?? 'bg-secondary' }}">{{ ucfirst($status) }}</span>
```

- [ ] **Step 3: Write the auth views**

`resources/views/auth/login.blade.php`:

```blade
@extends('layouts.app')
@section('title', 'Log in')
@section('content')
<div class="row justify-content-center">
    <div class="col-md-5">
        <div class="card">
            <div class="card-body">
                <h1 class="h4 mb-3">Log in</h1>
                <form method="POST" action="{{ url('/login') }}">
                    @csrf
                    <div class="mb-3">
                        <label class="form-label" for="email">Email</label>
                        <input class="form-control" id="email" type="email" name="email" value="{{ old('email') }}" required autofocus>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="password">Password</label>
                        <input class="form-control" id="password" type="password" name="password" required>
                    </div>
                    <button class="btn btn-dark w-100" type="submit">Log in</button>
                </form>
                <p class="mt-3 mb-0 text-center">
                    <a href="{{ route('register') }}">Create an account</a>
                </p>
            </div>
        </div>
    </div>
</div>
@endsection
```

`resources/views/auth/register.blade.php`:

```blade
@extends('layouts.app')
@section('title', 'Register')
@section('content')
<div class="row justify-content-center">
    <div class="col-md-5">
        <div class="card">
            <div class="card-body">
                <h1 class="h4 mb-3">Register</h1>
                <form method="POST" action="{{ url('/register') }}">
                    @csrf
                    <div class="mb-3">
                        <label class="form-label" for="name">Name</label>
                        <input class="form-control" id="name" type="text" name="name" value="{{ old('name') }}" required autofocus>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="email">Email</label>
                        <input class="form-control" id="email" type="email" name="email" value="{{ old('email') }}" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="password">Password</label>
                        <input class="form-control" id="password" type="password" name="password" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="password_confirmation">Confirm password</label>
                        <input class="form-control" id="password_confirmation" type="password" name="password_confirmation" required>
                    </div>
                    <button class="btn btn-dark w-100" type="submit">Register</button>
                </form>
                <p class="mt-3 mb-0 text-center">
                    <a href="{{ route('login') }}">Already registered?</a>
                </p>
            </div>
        </div>
    </div>
</div>
@endsection
```

- [ ] **Step 4: Write the batches index**

`resources/views/batches/index.blade.php`:

```blade
@extends('layouts.app')
@section('title', 'Batches')
@section('content')
<div class="card mb-4">
    <div class="card-body">
        <h1 class="h5 mb-3">Upload Excel File</h1>
        <form method="POST" action="{{ route('batches.upload') }}" enctype="multipart/form-data">
            @csrf
            <div class="row g-2 align-items-center">
                <div class="col-md-8">
                    <input class="form-control" type="file" name="file" accept=".xlsx,.xls,.csv" required>
                </div>
                <div class="col-md-4 d-flex gap-2">
                    <button class="btn btn-dark" type="submit">Upload &amp; Continue</button>
                    <a class="btn btn-outline-secondary" href="{{ route('batches.sample') }}">Sample</a>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <h2 class="h5 mb-3">Batches</h2>
        <div class="table-responsive">
            <table class="table align-middle">
                <thead>
                <tr>
                    <th>File</th><th>Uploaded</th><th>Total</th><th>Sent</th>
                    <th>Failed</th><th>Status</th><th class="text-end">Actions</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($batches as $batch)
                    <tr>
                        <td>{{ $batch->file_name }}</td>
                        <td>{{ $batch->created_at->format('Y-m-d H:i') }}</td>
                        <td>{{ $batch->total_emails }}</td>
                        <td>{{ $batch->sent_count }}</td>
                        <td>{{ $batch->failed_count }}</td>
                        <td>@include('partials.status-badge', ['status' => $batch->status])</td>
                        <td class="text-end">
                            @if ($batch->status === \App\Models\EmailBatch::STATUS_DRAFT)
                                <a class="btn btn-sm btn-primary" href="{{ route('batches.compose', $batch) }}">Compose</a>
                            @else
                                <a class="btn btn-sm btn-outline-primary" href="{{ route('batches.show', $batch) }}">View Report</a>
                            @endif
                            <a class="btn btn-sm btn-outline-secondary" href="{{ route('batches.export', $batch) }}">Export</a>
                            <form class="d-inline" method="POST" action="{{ route('batches.destroy', $batch) }}"
                                  onsubmit="return confirm('Delete this batch and all its logs?');">
                                @csrf @method('DELETE')
                                <button class="btn btn-sm btn-outline-danger" type="submit">Delete</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-center text-muted">No batches yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        {{ $batches->links() }}
    </div>
</div>
@endsection
```

- [ ] **Step 5: Write the compose view**

`resources/views/batches/compose.blade.php`:

```blade
@extends('layouts.app')
@section('title', 'Compose')
@section('content')
<div class="card mb-3">
    <div class="card-body">
        <h1 class="h5 mb-1">{{ $batch->file_name }}</h1>
        <p class="h4 mb-1">Matching Records: {{ $batch->pending_count }}</p>
        @if ($batch->failed_count > 0)
            <p class="text-danger mb-0">
                {{ $batch->failed_count }} row(s) were rejected for invalid email syntax.
                They will appear in the report as failed.
            </p>
        @endif
    </div>
</div>

<div class="card">
    <div class="card-body">
        <form method="POST" action="{{ route('batches.send', $batch) }}">
            @csrf
            <div class="mb-3">
                <label class="form-label" for="subject">Subject:</label>
                <input class="form-control" id="subject" type="text" name="subject" value="{{ old('subject') }}" required>
            </div>
            <div class="mb-3">
                <label class="form-label" for="body">Message:</label>
                <textarea id="body" name="body">{{ old('body') }}</textarea>
                <div class="form-text">Use <code>{{ '{{ name }}' }}</code> to insert each recipient's name.</div>
            </div>
            <button class="btn btn-dark" type="submit">SEND BULK EMAIL</button>
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
        plugins: 'lists link image code table',
        toolbar: 'undo redo | blocks | bold italic forecolor | alignleft aligncenter alignright alignjustify | bullist numlist outdent indent | link image | code',
        menubar: false,
    });
</script>
@endpush
```

- [ ] **Step 6: Write the report view**

`resources/views/batches/show.blade.php`:

```blade
@extends('layouts.app')
@section('title', 'Batch Report')
@section('content')
<div class="card mb-3">
    <div class="card-body">
        <h1 class="h5">{{ $batch->file_name }}</h1>
        <div class="row text-center mt-3">
            <div class="col"><div class="h3 mb-0">{{ $batch->total_emails }}</div><small class="text-muted">Total</small></div>
            <div class="col"><div class="h3 mb-0 text-success">{{ $batch->sent_count }}</div><small class="text-muted">Sent</small></div>
            <div class="col"><div class="h3 mb-0 text-danger">{{ $batch->failed_count }}</div><small class="text-muted">Failed</small></div>
            <div class="col"><div class="h3 mb-0">{{ $batch->successRate() }}%</div><small class="text-muted">Success Rate</small></div>
            <div class="col"><div class="mt-2">@include('partials.status-badge', ['status' => $batch->status])</div></div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <form method="GET" class="d-flex gap-2">
                <select class="form-select form-select-sm" name="status" onchange="this.form.submit()">
                    <option value="">All</option>
                    <option value="sent" @selected($status === 'sent')>Sent</option>
                    <option value="failed" @selected($status === 'failed')>Failed</option>
                    <option value="pending" @selected($status === 'pending')>Pending</option>
                </select>
            </form>
            <a class="btn btn-sm btn-outline-secondary"
               href="{{ route('batches.export', ['batch' => $batch, 'status' => $status]) }}">Download Excel Report</a>
        </div>

        <div class="table-responsive">
            <table class="table table-sm align-middle">
                <thead>
                <tr><th>Name</th><th>Email</th><th>Status</th><th>Date</th><th>Time</th><th>Attempts</th><th>Remarks</th></tr>
                </thead>
                <tbody>
                @forelse ($logs as $log)
                    @php $at = $log->sent_at ?? $log->created_at; @endphp
                    <tr>
                        <td>{{ $log->name }}</td>
                        <td>{{ $log->email }}</td>
                        <td>@include('partials.status-badge', ['status' => $log->status])</td>
                        <td>{{ $at->format('Y-m-d') }}</td>
                        <td>{{ $at->format('H:i:s') }}</td>
                        <td>{{ $log->attempts }}</td>
                        <td class="small text-muted">{{ $log->remarks }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-center text-muted">No rows match this filter.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        {{ $logs->links() }}
    </div>
</div>
@endsection
```

- [ ] **Step 7: Write the SMTP views**

`resources/views/smtp/index.blade.php`:

```blade
@extends('layouts.app')
@section('title', 'SMTP Settings')
@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h5 mb-0">SMTP Profiles</h1>
    <a class="btn btn-dark btn-sm" href="{{ route('smtp.create') }}">Add Profile</a>
</div>

<div class="card">
    <div class="card-body table-responsive">
        <table class="table align-middle">
            <thead>
            <tr><th>Name</th><th>Host</th><th>Port</th><th>From</th><th>Active</th><th class="text-end">Actions</th></tr>
            </thead>
            <tbody>
            @forelse ($settings as $smtp)
                <tr>
                    <td>{{ $smtp->name }}</td>
                    <td>{{ $smtp->host }}</td>
                    <td>{{ $smtp->port }}</td>
                    <td>{{ $smtp->from_name }} &lt;{{ $smtp->from_address }}&gt;</td>
                    <td>
                        @if ($smtp->is_active)
                            <span class="badge bg-success">Active</span>
                        @else
                            <form method="POST" action="{{ route('smtp.activate', $smtp) }}">
                                @csrf
                                <button class="btn btn-sm btn-outline-success" type="submit">Activate</button>
                            </form>
                        @endif
                    </td>
                    <td class="text-end">
                        <form class="d-inline" method="POST" action="{{ route('smtp.test', $smtp) }}">
                            @csrf
                            <div class="input-group input-group-sm d-inline-flex" style="width: 260px;">
                                <input class="form-control" type="email" name="email" placeholder="test@example.com" required>
                                <button class="btn btn-outline-primary" type="submit">Test</button>
                            </div>
                        </form>
                        <a class="btn btn-sm btn-outline-secondary" href="{{ route('smtp.edit', $smtp) }}">Edit</a>
                        <form class="d-inline" method="POST" action="{{ route('smtp.destroy', $smtp) }}"
                              onsubmit="return confirm('Delete this profile?');">
                            @csrf @method('DELETE')
                            <button class="btn btn-sm btn-outline-danger" type="submit">Delete</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-center text-muted">No SMTP profiles configured.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
```

`resources/views/smtp/form.blade.php`:

```blade
@extends('layouts.app')
@section('title', 'SMTP Profile')
@section('content')
@php $isEdit = (bool) $smtp->exists; @endphp
<div class="row justify-content-center">
    <div class="col-md-8">
        <div class="card">
            <div class="card-body">
                <h1 class="h5 mb-3">{{ $isEdit ? 'Edit' : 'Add' }} SMTP Profile</h1>
                <form method="POST" action="{{ $isEdit ? route('smtp.update', $smtp) : route('smtp.store') }}">
                    @csrf
                    @if ($isEdit) @method('PUT') @endif

                    <div class="mb-3">
                        <label class="form-label" for="name">Profile name</label>
                        <input class="form-control" id="name" name="name" value="{{ old('name', $smtp->name) }}" required>
                    </div>
                    <div class="row">
                        <div class="col-md-8 mb-3">
                            <label class="form-label" for="host">Host</label>
                            <input class="form-control" id="host" name="host" value="{{ old('host', $smtp->host) }}" required>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label" for="port">Port</label>
                            <input class="form-control" id="port" type="number" name="port" value="{{ old('port', $smtp->port ?? 587) }}" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="username">Username</label>
                        <input class="form-control" id="username" name="username" value="{{ old('username', $smtp->username) }}" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="password">Password</label>
                        <input class="form-control" id="password" type="password" name="password" {{ $isEdit ? '' : 'required' }}>
                        @if ($isEdit)
                            <div class="form-text">Leave blank to keep the current password.</div>
                        @endif
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="encryption">Encryption</label>
                        <select class="form-select" id="encryption" name="encryption">
                            <option value="tls" @selected(old('encryption', $smtp->encryption) === 'tls')>TLS</option>
                            <option value="ssl" @selected(old('encryption', $smtp->encryption) === 'ssl')>SSL</option>
                            <option value="" @selected(old('encryption', $smtp->encryption) === null)>None</option>
                        </select>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label" for="from_address">From address</label>
                            <input class="form-control" id="from_address" type="email" name="from_address" value="{{ old('from_address', $smtp->from_address) }}" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label" for="from_name">From name</label>
                            <input class="form-control" id="from_name" name="from_name" value="{{ old('from_name', $smtp->from_name) }}" required>
                        </div>
                    </div>

                    <button class="btn btn-dark" type="submit">Save</button>
                    <a class="btn btn-outline-secondary" href="{{ route('smtp.index') }}">Cancel</a>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
```

- [ ] **Step 8: Run the whole suite**

Run: `php artisan test`
Expected: all tests PASS, including the view-dependent assertions in
`EmailBatchFlowTest` and `AuthTest` that were deferred.

- [ ] **Step 9: Commit**

```bash
git add -A
git commit -m "feat: add Blade views for auth, batches, and SMTP"
```

---

## Task 16: Seed an admin and verify end-to-end

**Files:**
- Create: `database/seeders/AdminUserSeeder.php`
- Modify: `database/seeders/DatabaseSeeder.php`
- Create: `README.md`

- [ ] **Step 1: Write the seeder**

`database/seeders/AdminUserSeeder.php`:

```php
<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'admin@smart-emailing.test'],
            [
                'name'     => 'Admin',
                'password' => Hash::make('password'),
                'is_admin' => true,
            ],
        );
    }
}
```

In `database/seeders/DatabaseSeeder.php`, replace the `run()` body with:

```php
$this->call(AdminUserSeeder::class);
```

- [ ] **Step 2: Seed**

Run: `php artisan db:seed --force`
Expected: `Database seeding completed successfully.`

- [ ] **Step 3: Write the README**

`README.md`:

````markdown
# Smart Emailing

Bulk email sender. Upload an Excel/CSV recipient list, review the parsed count,
compose a message, and send — with a full per-recipient delivery report.

## Requirements

- PHP 8.2+
- MySQL 5.7+ (MAMP)
- Composer, Node

## Setup

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
```

Default admin: `admin@smart-emailing.test` / `password`.

To promote another user:

```sql
UPDATE users SET is_admin = 1 WHERE email = '...';
```

## Running

```bash
php artisan serve
php artisan queue:work --tries=3
```

**The queue worker must be running for mail to send.** Without it, batches stay
at `processing` and nothing is delivered.

## Usage

1. Add an SMTP profile under **SMTP** and activate it. Use **Test** to verify
   the credentials before a real run.
2. On **Batches**, download the sample template, fill in `name` and `email`
   columns, and upload it.
3. Review the parsed count, write the subject and message — `{{ name }}` is
   replaced with each recipient's name — and send.
4. Watch the report. Every row of the uploaded file appears, including rows
   rejected for invalid email syntax.

## Testing

```bash
php artisan test
```

Tests run against the `smart_emailing_test` MySQL database.
````

- [ ] **Step 4: Verify the full suite one more time**

Run: `php artisan test`
Expected: all tests PASS. Record the actual pass/fail counts — do not claim
success without reading the output.

- [ ] **Step 5: Manual smoke test**

```bash
php artisan serve
```

In a second terminal: `php artisan queue:work`

Log in as the seeded admin, add an SMTP profile, send a test message, then
upload a small CSV and run a real batch. Confirm the report fills in.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "feat: add admin seeder and README"
```

---

## Self-Review Notes

**Spec coverage:** dynamic SMTP (Task 7, 14) · Excel import with validation
(Task 8) · sample template (Task 12) · batch isolation and counters (Tasks 4,
9) · 3-attempt retry (Task 9) · two-step flow (Task 13) · full-file reporting
(Tasks 8, 12, 13) · auth and admin gating (Task 11) · all views (Task 15) ·
delete cascade (Tasks 4, 13).

**Deviation from the spec:** `stored_path` was added to `email_batches` (Task
4) — the spec's column list omits it, but Delete Batch is specified to remove
the physical file, which requires knowing where it went.

**Deferred test runs:** Tasks 11, 13, and 14 have view-dependent assertions
that only pass after Task 15. Each task notes this; Task 15 Step 8 runs the
full suite.
