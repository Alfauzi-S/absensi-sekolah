<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\Setting;

/**
 * Pengirim pesan WhatsApp lewat penyedia yang dipilih sekolah (PRD Fase 6).
 *  - sandbox: tidak mengirim apa pun, hanya menulis ke storage/logs/wa-sandbox.log (untuk uji coba);
 *  - fonnte : POST https://api.fonnte.com/send (form target, message; header Authorization: token);
 *  - webhook: POST JSON {"phone","message"} ke URL sekolah (header Authorization: Bearer token), untuk gateway lain.
 * Jawaban: ok, ref (id dari penyedia), error, retry (false = kesalahan permanen, tidak dicoba ulang).
 */
final class WaGateway
{
    public const FONNTE_URL = 'https://api.fonnte.com/send';
    public const TIMEOUT = 10;

    /** @var null|callable(string $phone, string $message): array pengganti pengiriman (dipakai pengujian) */
    public static $fake = null;

    /** @return array{ok:bool, ref:?string, error:?string, retry:bool} */
    public static function send(string $phone, string $message): array
    {
        if (self::$fake !== null) {
            return (self::$fake)($phone, $message) + ['ok' => false, 'ref' => null, 'error' => null, 'retry' => true];
        }
        $provider = Setting::get('wa_provider', 'sandbox');
        $token = Setting::get('wa_gateway_token');
        $url = Setting::get('wa_gateway_url');
        try {
            return match ($provider) {
                'fonnte' => self::fonnte($url !== '' ? $url : self::FONNTE_URL, $token, $phone, $message),
                'webhook' => self::webhook($url, $token, $phone, $message),
                default => self::sandbox($phone, $message),
            };
        } catch (\Throwable $e) {
            return ['ok' => false, 'ref' => null, 'error' => mb_substr($e->getMessage(), 0, 250), 'retry' => true];
        }
    }

    public static function sandboxLog(): string
    {
        return dirname(__DIR__, 2) . '/storage/logs/wa-sandbox.log';
    }

    private static function sandbox(string $phone, string $message): array
    {
        $file = self::sandboxLog();
        if (!is_dir(dirname($file))) {
            @mkdir(dirname($file), 0775, true);
        }
        $ref = 'sbx-' . bin2hex(random_bytes(6));
        @file_put_contents($file, json_encode(['at' => now(), 'ref' => $ref, 'phone' => $phone, 'message' => $message], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
        return ['ok' => true, 'ref' => $ref, 'error' => null, 'retry' => false];
    }

    private static function fonnte(string $url, string $token, string $phone, string $message): array
    {
        if ($token === '') {
            return ['ok' => false, 'ref' => null, 'error' => 'Token Fonnte belum diisi', 'retry' => false];
        }
        [$code, $body, $err] = self::post($url, ['Authorization: ' . $token], http_build_query(['target' => $phone, 'message' => $message, 'countryCode' => '62']));
        $json = json_decode((string) $body, true);
        if ($code >= 200 && $code < 300 && is_array($json) && !empty($json['status'])) {
            $ref = $json['id'][0] ?? $json['id'] ?? null;
            return ['ok' => true, 'ref' => is_scalar($ref) ? (string) $ref : null, 'error' => null, 'retry' => false];
        }
        $reason = is_array($json) ? (string) ($json['reason'] ?? $json['detail'] ?? '') : '';
        return ['ok' => false, 'ref' => null, 'error' => self::describe($code, $err, $reason ?: (string) $body), 'retry' => self::retryable($code, $err)];
    }

    private static function webhook(string $url, string $token, string $phone, string $message): array
    {
        if (!str_starts_with($url, 'https://')) {
            return ['ok' => false, 'ref' => null, 'error' => 'URL webhook belum diisi (harus https://)', 'retry' => false];
        }
        $headers = ['Content-Type: application/json'];
        if ($token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
        }
        [$code, $body, $err] = self::post($url, $headers, (string) json_encode(['phone' => $phone, 'message' => $message], JSON_UNESCAPED_UNICODE));
        if ($code >= 200 && $code < 300) {
            $json = json_decode((string) $body, true);
            $ref = is_array($json) ? ($json['id'] ?? $json['ref'] ?? null) : null;
            return ['ok' => true, 'ref' => is_scalar($ref) ? (string) $ref : null, 'error' => null, 'retry' => false];
        }
        return ['ok' => false, 'ref' => null, 'error' => self::describe($code, $err, (string) $body), 'retry' => self::retryable($code, $err)];
    }

    /** @return array{0:int,1:string|false,2:string} */
    private static function post(string $url, array $headers, string $payload): array
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('Ekstensi PHP curl tidak tersedia');
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload, CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        return [$code, $body, $err];
    }

    /** Gangguan jaringan, 408, 429, dan 5xx dicoba ulang; 4xx lain (token salah, nomor ditolak) tidak. */
    private static function retryable(int $code, string $err): bool
    {
        return $err !== '' || $code === 0 || $code === 408 || $code === 429 || $code >= 500;
    }

    private static function describe(int $code, string $err, string $body): string
    {
        $msg = $err !== '' ? $err : 'HTTP ' . $code . ($body !== '' ? ': ' . preg_replace('/\s+/', ' ', strip_tags($body)) : '');
        return mb_substr($msg, 0, 250);
    }
}
