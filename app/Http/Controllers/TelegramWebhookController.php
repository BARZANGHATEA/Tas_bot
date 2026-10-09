<?php

namespace App\Http\Controllers;

use App\Models\TelegramUpdate;
use App\Services\Telegram\BotUpdateHandler;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class TelegramWebhookController extends Controller
{
    public function __invoke(Request $request, BotUpdateHandler $handler): JsonResponse
    {
        $secret = (string) config('dicegame.telegram.webhook_secret');
        $provided = (string) $request->header('X-Telegram-Bot-Api-Secret-Token', '');

        // Constant-time comparison; a missing secret disables the webhook entirely.
        if ($secret === '' || ! hash_equals($secret, $provided)) {
            return response()->json(['ok' => false], 403);
        }

        $update = $request->json()->all();
        $updateId = $update['update_id'] ?? null;
        if (! is_int($updateId)) {
            return response()->json(['ok' => true]);
        }

        // Telegram retries deliveries: record each update_id once and skip repeats.
        try {
            $record = TelegramUpdate::query()->create([
                'update_id' => $updateId,
                'type' => collect(array_keys($update))->first(fn ($k) => $k !== 'update_id'),
            ]);
        } catch (QueryException) {
            return response()->json(['ok' => true, 'duplicate' => true]);
        }

        try {
            $handler->handle($update);
            $record->forceFill(['processed_at' => now()])->save();
        } catch (Throwable $e) {
            // Acknowledge anyway so Telegram does not retry a poison update forever.
            Log::error('Telegram update failed', ['update_id' => $updateId, 'error' => $e->getMessage()]);
            $record->forceFill(['error' => mb_substr($e->getMessage(), 0, 250)])->save();
        }

        return response()->json(['ok' => true]);
    }
}
