<?php

namespace App\Http\Controllers\Admin;

use App\Models\TelegramMessage;
use App\Models\TelegramUpdate;
use App\Services\AuditLogger;
use App\Services\Telegram\NotificationService;
use App\Services\Telegram\TelegramApiException;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramSetupService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TelegramController extends AdminController
{
    public function index(TelegramClient $client, TelegramSetupService $setup): View
    {
        $info = null;
        $bot = null;
        $error = null;

        if ($client->isConfigured()) {
            try {
                $bot = $setup->botInfo();
                $info = $setup->webhookInfo();
            } catch (TelegramApiException $e) {
                $error = $e->getMessage();
            }
        }

        $token = (string) config('dicegame.telegram.bot_token');

        return view('admin.telegram.index', [
            'configured' => $client->isConfigured(),
            'tokenHint' => $token ? substr($token, 0, 4).'…'.substr($token, -4) : null,
            'secretSet' => strlen((string) config('dicegame.telegram.webhook_secret')) >= 16,
            'bot' => $bot,
            'info' => $info,
            'error' => $error,
            'webhookUrl' => $setup->webhookUrl(),
            'miniAppUrl' => url('/app'),
            'outbox' => TelegramMessage::query()->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status'),
            'failed' => TelegramMessage::query()->where('status', 'failed')->latest('id')->limit(10)->get(),
            'lastUpdate' => TelegramUpdate::query()->latest('id')->first(),
        ]);
    }

    public function setup(Request $request, TelegramSetupService $setup, AuditLogger $audit): RedirectResponse
    {
        $request->validate($this->confirmRules());

        try {
            $setup->configure();
        } catch (TelegramApiException $e) {
            return back()->with('error', $e->getMessage());
        }

        $audit->log('telegram.configured', null, ['webhook' => $setup->webhookUrl()]);

        return back()->with('success', 'Webhook, commands and menu button configured.');
    }

    public function test(Request $request, NotificationService $notifications): RedirectResponse
    {
        $data = $request->validate(['chat_id' => ['required', 'string', 'max:64', 'regex:/^(@[A-Za-z0-9_]{4,64}|-?\d{3,20})$/']]);
        $notifications->raw($data['chat_id'], '✅ Test message from the '.e(config('app.name')).' dashboard.');
        $sent = $notifications->flush(5);

        return back()->with($sent ? 'success' : 'error', $sent ? 'Test message sent.' : 'Message queued but not delivered yet – check the outbox errors below.');
    }
}
