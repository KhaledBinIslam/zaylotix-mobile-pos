<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin wrapper over Meta's WhatsApp Cloud API (graph.facebook.com) — each
 * shop brings its own Phone Number ID + permanent access token from their
 * own Meta Business account (see WhatsappCredential), same
 * bring-your-own-key shape as the payment gateway drivers.
 *
 * IMPORTANT, not optional: WhatsApp only allows free-form text messages to
 * a customer within a 24-hour window after that customer last messaged the
 * business — true bulk/marketing sends to a customer list outside that
 * window MUST use a pre-approved message template (created and approved in
 * Meta Business Manager first). Sending free text to someone outside the
 * window will be rejected by Meta with error code 131047. This class
 * supports both call shapes; the caller (WhatsappBulkController) is
 * responsible for picking the right one and explaining the constraint to
 * the shop owner in the UI, not silently failing.
 *
 * NOTE: written without a live Meta Business account to test end-to-end
 * against — the field names/response shape match Meta's current (v20.0)
 * documented Cloud API, but should be re-verified against a real sandbox
 * before a shop's first live send.
 */
class WhatsappCloudApi
{
    private const API_VERSION = 'v20.0';

    /** @return array{ok: bool, message_id: ?string, error: ?string} */
    public function sendTemplate(array $credentials, string $toPhone, string $templateName, string $languageCode = 'bn', array $bodyParams = []): array
    {
        $components = [];
        if (! empty($bodyParams)) {
            $components[] = [
                'type' => 'body',
                'parameters' => array_map(fn ($p) => ['type' => 'text', 'text' => (string) $p], $bodyParams),
            ];
        }

        return $this->send($credentials, [
            'messaging_product' => 'whatsapp',
            'to' => $this->normalizePhone($toPhone),
            'type' => 'template',
            'template' => [
                'name' => $templateName,
                'language' => ['code' => $languageCode],
                ...($components ? ['components' => $components] : []),
            ],
        ]);
    }

    /** Only actually delivers within Meta's 24-hour customer-service window — see class docblock. */
    public function sendText(array $credentials, string $toPhone, string $body): array
    {
        return $this->send($credentials, [
            'messaging_product' => 'whatsapp',
            'to' => $this->normalizePhone($toPhone),
            'type' => 'text',
            'text' => ['body' => $body],
        ]);
    }

    private function send(array $credentials, array $payload): array
    {
        $phoneNumberId = $credentials['phone_number_id'] ?? null;
        $accessToken = $credentials['access_token'] ?? null;
        if (! $phoneNumberId || ! $accessToken) {
            return ['ok' => false, 'message_id' => null, 'error' => 'phone_number_id/access_token missing'];
        }

        try {
            $response = Http::withToken($accessToken)
                ->timeout(15)
                ->post("https://graph.facebook.com/".self::API_VERSION."/{$phoneNumberId}/messages", $payload);
        } catch (\Throwable $e) {
            Log::warning('WhatsApp Cloud API request failed', ['error' => $e->getMessage()]);

            return ['ok' => false, 'message_id' => null, 'error' => $e->getMessage()];
        }

        if ($response->failed()) {
            $error = $response->json('error.message') ?? 'unknown error';
            Log::warning('WhatsApp Cloud API send failed', ['status' => $response->status(), 'body' => $response->json()]);

            return ['ok' => false, 'message_id' => null, 'error' => $error];
        }

        return ['ok' => true, 'message_id' => $response->json('messages.0.id'), 'error' => null];
    }

    /** Meta wants a plain international-format number, no leading +, no spaces/dashes — best-effort BD-number normalization (assumes a bare 01XXXXXXXXX means Bangladesh). */
    private function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone);
        if (str_starts_with($digits, '01') && strlen($digits) === 11) {
            return '88'.$digits;
        }

        return $digits;
    }
}
