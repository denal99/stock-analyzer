<?php

use App\Services\MarketBriefService;
use App\Services\StockScreenerService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/**
 * Helper mengambil target chat_id penerima laporan otomatis
 */
$resolveOwnerChatId = function (): ?string {
    $cid = env('TELEGRAM_CHAT_ID') ?: Cache::get('telegram_owner_chat_id');
    if (!$cid) {
        $row = DB::table('watchlists')
            ->whereNotNull('telegram_id')
            ->first();
        $cid = $row->telegram_id ?? null;
    }
    return $cid ? (string) $cid : null;
};

/**
 * Command: Kirim Morning Market Brief (08:30 WIB)
 */
Artisan::command('report:morning-brief', function (Nutgram $bot, MarketBriefService $briefService) use ($resolveOwnerChatId) {
    $chatId = $resolveOwnerChatId();
    if (!$chatId) {
        $this->error('CHAT_ID belum tersimpan. Kirim pesan apa saja ke bot Telegram sekali untuk mendaftarkan chat_id.');
        return;
    }

    try {
        $brief = $briefService->generateBrief(true);
        $msg   = "⏰ <b>AUTO MORNING BRIEF (08:30 WIB)</b>\n" . $briefService->formatTelegramMessage($brief);

        $keyboard = $briefService->buildTelegramKeyboard();

        $bot->sendMessage($msg, chat_id: $chatId, parse_mode: ParseMode::HTML, reply_markup: $keyboard);
        $this->info("Morning Market Brief berhasil dikirim ke {$chatId}");
    } catch (\Throwable $e) {
        $this->error('Gagal mengirim Morning Brief: ' . $e->getMessage());
    }
})->purpose('Kirim otomatis Morning Market Brief sebelum pembukaan bursa');

/**
 * Command: Kirim Evening Brief + Rekap Sore Non-Retail Flow (17:33 WIB)
 */
Artisan::command('report:non-retail', function (Nutgram $bot, StockScreenerService $screener, MarketBriefService $briefService) use ($resolveOwnerChatId) {
    $chatId = $resolveOwnerChatId();
    if (!$chatId) {
        $this->error('CHAT_ID belum tersimpan. Kirim pesan apa saja ke bot Telegram sekali untuk mendaftarkan chat_id.');
        return;
    }

    // 1. Kirim Evening Market Brief terlebih dahulu
    try {
        $brief = $briefService->generateBrief(true);
        $briefMsg = "🌇 <b>EVENING MARKET BRIEF (17:30 WIB)</b>\n" . $briefService->formatTelegramMessage($brief);
        $keyboard = $briefService->buildTelegramKeyboard();
        $bot->sendMessage($briefMsg, chat_id: $chatId, parse_mode: ParseMode::HTML, reply_markup: $keyboard);
    } catch (\Throwable $be) {
        $this->warn("Gagal mengirim Evening Market Brief: " . $be->getMessage());
    }

    // 2. Lanjut kirim Rekap Sore: Non-Retail Flow (1D, Min Rp 0.5 M)
    try {
        $token = $screener->getStockbitToken();
        $usedDate = now('Asia/Jakarta');

        // Deteksi tanggal bursa terakhir yang broker summary-nya sudah terbit
        if (!empty($token)) {
            for ($i = 0; $i <= 4; $i++) {
                $cand = now('Asia/Jakarta')->subDays($i);
                if ($cand->isWeekend()) continue;
                $dStr = $cand->format('Y-m-d');
                try {
                    $chk = \Illuminate\Support\Facades\Http::withToken($token)
                        ->withOptions(['force_ip_resolve' => 'v4'])
                        ->withHeaders([
                            'Origin'     => 'https://stockbit.com',
                            'Referer'    => 'https://stockbit.com/',
                            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
                            'Accept'     => 'application/json',
                        ])
                        ->connectTimeout(3)
                        ->timeout(5)
                        ->get('https://exodus.stockbit.com/marketdetectors/BBCA', [
                            'from'             => $dStr,
                            'to'               => $dStr,
                            'transaction_type' => 'TRANSACTION_TYPE_NET',
                            'market_board'     => 'MARKET_BOARD_REGULER',
                            'investor_type'    => 'INVESTOR_TYPE_ALL',
                        ]);
                    if (!empty($chk->json('data.broker_summary.brokers_buy'))) {
                        $usedDate = $cand;
                        break;
                    }
                } catch (\Throwable $e) {
                    continue;
                }
            }
        }

        $dStr = $usedDate->format('Y-m-d');
        $res  = $screener->scanNonRetailFlow($dStr, $dStr, 0.5, 20, true);

        if (isset($res['error']) && $res['error'] === 'TOKEN_EXPIRED') {
            $bot->sendMessage(
                "⚠️ <b>AUTO REPORT NON-RETAIL FLOW</b>\nToken Stockbit kedaluwarsa. Perbarui dengan <code>/settoken &lt;token_baru&gt;</code> agar rekap sore berjalan otomatis.",
                chat_id: $chatId,
                parse_mode: ParseMode::HTML
            );
            return;
        }

        if (isset($res['error'])) {
            $this->warn('Gagal memindai Non-Retail Flow: ' . $res['error']);
            return;
        }

        $fmtValCompact = static function (float $val): string {
            $abs = abs($val);
            if ($abs >= 1e12) return number_format($abs / 1e12, 1) . 'T';
            if ($abs >= 1e10) return number_format($abs / 1e9, 0) . 'M';
            if ($abs >= 1e9)  return number_format($abs / 1e9, 1) . 'M';
            if ($abs >= 1e6)  return number_format($abs / 1e6, 0) . 'Jt';
            return number_format($abs, 0);
        };

        $dateLabel = $usedDate->format('d M Y');
        $nowWib    = now('Asia/Jakarta')->format('d/m H:i');
        $scanned   = (int) ($res['scanned'] ?? 0);
        $matched   = (int) ($res['matched'] ?? 0);
        $items     = $res['items'] ?? [];

        $msg  = "⏰ <b>AUTO REKAP SORE: NON-RETAIL FLOW • 1D</b>\n";
        $msg .= "📅 {$dateLabel} • Min Rp 0.5 M\n";
        $msg .= "📡 Stockbit • {$scanned} emiten ({$nowWib} WIB)\n";
        $msg .= "━━━━━━━━━━━━━━━━━━\n\n";

        if (empty($items)) {
            $msg .= "<i>Tidak ada emiten lolos filter (Min Rp 0.5 M) pada {$dateLabel}.</i>\n";
        } else {
            $msg .= "<b>TOP " . count($items) . " RITEL NET SELL</b> (dari {$matched} lolos)\n";
            foreach ($items as $i => $it) {
                $no   = $i + 1;
                $sym  = (string) ($it['symbol'] ?? '-');
                $pct  = (int) round((float) ($it['r_pct'] ?? 0));
                $val  = $fmtValCompact((float) ($it['val'] ?? 0));
                $sb   = ($it['r_sell'] ?? 0) . 'S/' . ($it['r_buy'] ?? 0) . 'B';
                $tier = (string) ($it['tier'] ?? '🟡');
                $msg .= sprintf("<code>%2d.%-4s %+4d%%</code> %s • %s %s\n", $no, $sym, $pct, $sb, $val, $tier);
            }
        }

        if (!empty($res['rate_limited'])) {
            $msg .= "\n⚠️ <i>Sebagian scan berhenti lebih awal (rate limit API).</i>\n";
        }

        $msg .= "\n<i>🔥 Strong • 🟢 Solid • 🟡 Early</i>\n";
        $msg .= "💡 <i>Ketik /nonritel untuk filter periode/nilai lainnya.</i>";

        $keyboard = InlineKeyboardMarkup::make();
        foreach (array_chunk(array_slice($items, 0, 10), 5) as $chunk) {
            $rowButtons = [];
            foreach ($chunk as $item) {
                $s = strtoupper(trim((string) ($item['symbol'] ?? '')));
                if ($s !== '' && $s !== '-') {
                    $rowButtons[] = InlineKeyboardButton::make("📊 {$s}", callback_data: "chart:{$s}");
                }
            }
            if (!empty($rowButtons)) {
                $keyboard->addRow(...$rowButtons);
            }
        }

        $bot->sendMessage($msg, chat_id: $chatId, parse_mode: ParseMode::HTML, reply_markup: $keyboard);
        $this->info("Rekap Non-Retail Flow ({$dateLabel}) berhasil dikirim ke {$chatId}");
    } catch (\Throwable $e) {
        $this->error('Gagal mengirim Rekap Non-Retail Flow: ' . $e->getMessage());
    }
})->purpose('Kirim otomatis Evening Brief + rekap sore Non-Retail Flow setelah penutupan sesi 2');

// Jadwal Otomatis Hari Bursa (Senin - Jumat, WIB)
Schedule::command('report:morning-brief')
    ->weekdays()
    ->at('08:30')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping();

Schedule::command('report:non-retail')
    ->weekdays()
    ->at('17:33')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping();

/**
 * Command: Cek masa berlaku Token Stockbit & kirim pengingat 2 jam sebelum expired
 */
Artisan::command('report:check-stockbit-token', function (Nutgram $bot) use ($resolveOwnerChatId) {
    $chatId = $resolveOwnerChatId();
    if (!$chatId) {
        return;
    }

    $token = Cache::get('stockbit_bearer_token');
    $expTs = Cache::get('stockbit_token_expires_at');

    // Auto-fallback ke file JSON jika cache sempat terhapus
    if (!$token || !$expTs) {
        $path = storage_path('app/stockbit_token.json');
        if (file_exists($path)) {
            $saved = json_decode(file_get_contents($path), true);
            if (!empty($saved['expires_at'])) {
                $expTs = (int) $saved['expires_at'];
                Cache::forever('stockbit_token_expires_at', $expTs);
            }
            if (!empty($saved['token']) && $expTs && time() < $expTs) {
                $token = $saved['token'];
                Cache::put('stockbit_bearer_token', $token, \Carbon\Carbon::createFromTimestamp($expTs));
            }
        }
    }

    // Jika expTs belum tercatat (token lama sebelum update), ekstrak dari JWT yang tersimpan
    if ($token && !$expTs) {
        $parts = explode('.', $token);
        if (count($parts) === 3) {
            $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')) ?: '', true);
            if (!empty($payload['exp'])) {
                $expTs = (int) $payload['exp'];
                Cache::forever('stockbit_token_expires_at', $expTs);
            }
        }
    }

    if (!$expTs) {
        return;
    }

    $expiresAt = \Carbon\Carbon::createFromTimestamp((int) $expTs, 'Asia/Jakarta');
    $now = now('Asia/Jakarta');
    $minutesLeft = (int) round($now->diffInMinutes($expiresAt, false));

    // Kirim peringatan 1x jika sisa masa berlaku <= 120 menit (2 jam) atau sudah expired
    if ($minutesLeft <= 120 && !Cache::get('stockbit_token_warned')) {
        $expFmt = $expiresAt->format('d M Y H:i') . ' WIB';
        if ($minutesLeft > 0) {
            $statusText = "akan kedaluwarsa dalam <b>{$minutesLeft} menit</b> (pukul <b>{$expFmt}</b>).";
        } else {
            $statusText = "telah <b>kedaluwarsa</b> pada <b>{$expFmt}</b>.";
        }

        $msg = "⚠️ <b>PENGINGAT TOKEN STOCKBIT</b>\n"
             . "━━━━━━━━━━━━━━━━━━━━\n"
             . "Bearer Token Stockbit Anda {$statusText}\n\n"
             . "Segera perbarui token agar fitur <code>/nonritel</code> dan auto-rekap sore (17:30 WIB) tetap berjalan lancar:\n"
             . "👉 <code>/settoken &lt;token_baru&gt;</code>";

        $bot->sendMessage($msg, chat_id: $chatId, parse_mode: ParseMode::HTML);
        Cache::forever('stockbit_token_warned', true);
        $this->info("Pengingat token dikirim ke {$chatId} (sisa {$minutesLeft} menit).");
    } else {
        $this->info("Status token aman. Kedaluwarsa pada: " . $expiresAt->format('d M Y H:i') . " WIB (sisa {$minutesLeft} menit).");
    }
})->purpose('Cek sisa masa aktif token Stockbit dan ingatkan 2 jam sebelum habis');

Schedule::command('report:check-stockbit-token')
    ->everyFifteenMinutes()
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping();


/**
 * Command: Kirim Market Report Sesi 1 (12:05 WIB)
 */
Artisan::command('report:sesi1', function (Nutgram $bot, \App\Services\MarketSessionReportService $sessionReport) use ($resolveOwnerChatId) {
    $chatId = $resolveOwnerChatId();
    if (!$chatId) {
        $this->error('CHAT_ID belum tersimpan.');
        return;
    }

    try {
        $report   = $sessionReport->generateReport(true);
        $keyboard = $sessionReport->buildTelegramKeyboard($report);
        $bot->sendMessage($report, chat_id: $chatId, parse_mode: ParseMode::HTML, reply_markup: $keyboard);
        $this->info("Market Report Sesi 1 berhasil dikirim ke {$chatId}");
    } catch (\Throwable $e) {
        $this->error('Gagal mengirim Market Report Sesi 1: ' . $e->getMessage());
    }
})->purpose('Kirim otomatis Market Report Sesi 1 (12:05 WIB)');

/**
 * Command: Kirim Market Report Closing + Foreign/Local Flow Hari Ini (17:30 WIB)
 */
Artisan::command('report:closing', function (Nutgram $bot, \App\Services\MarketSessionReportService $sessionReport) use ($resolveOwnerChatId) {
    $chatId = $resolveOwnerChatId();
    if (!$chatId) {
        $this->error('CHAT_ID belum tersimpan.');
        return;
    }

    try {
        $report   = $sessionReport->generateReport(true);
        $keyboard = $sessionReport->buildTelegramKeyboard($report);
        $bot->sendMessage($report, chat_id: $chatId, parse_mode: ParseMode::HTML, reply_markup: $keyboard);
        $this->info("Market Report Closing berhasil dikirim ke {$chatId}");
    } catch (\Throwable $e) {
        $this->error('Gagal mengirim Market Report Closing: ' . $e->getMessage());
    }
})->purpose('Kirim otomatis Market Report Closing dengan data Foreign/Local Buy hari ini (17:30 WIB)');

Schedule::command('report:sesi1')
    ->weekdays()
    ->at('12:05')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping();

Schedule::command('report:closing')
    ->weekdays()
    ->at('17:30')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping();
