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

    /**
     * Save a new profile. The first profile ever created becomes active.
     */
    public function store(SmtpSettingRequest $request): RedirectResponse
    {
        $data              = $request->validated();
        $data['password']  = Crypt::encryptString($data['password']);
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
