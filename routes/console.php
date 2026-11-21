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
        $brief = $briefService->generateBrief();
        $msg   = "⏰ <b>AUTO MORNING BRIEF (08:30 WIB)</b>\n" . $briefService->formatTelegramMessage($brief);

        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make("🧭 Radar Sektor", callback_data: "quick:sektor"),
                InlineKeyboardButton::make("🏦 Akumulasi Bandar", callback_data: "quick:akumulasi")
            );

        $bot->sendMessage($msg, chat_id: $chatId, parse_mode: ParseMode::HTML, reply_markup: $keyboard);
        $this->info("Morning Market Brief berhasil dikirim ke {$chatId}");
    } catch (\Throwable $e) {
        $this->error('Gagal mengirim Morning Brief: ' . $e->getMessage());
    }
})->purpose('Kirim otomatis Morning Market Brief sebelum pembukaan bursa');

/**
 * Command: Kirim Rekap Sore Top Retail Jualan (17:30 WIB)
 */
Artisan::command('report:top-retail', function (Nutgram $bot, StockScreenerService $screener, MarketBriefService $briefService) use ($resolveOwnerChatId) {
    $chatId = $resolveOwnerChatId();
    if (!$chatId) {
        $this->error('CHAT_ID belum tersimpan. Kirim pesan apa saja ke bot Telegram sekali untuk mendaftarkan chat_id.');
        return;
    }

        // 1. Kirim Evening Market Brief terlebih dahulu (17:30 WIB)
    try {
        $brief = $briefService->generateBrief();
        $briefMsg = "🌇 <b>EVENING MARKET BRIEF (17:30 WIB)</b>\n" . $briefService->formatTelegramMessage($brief);
        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make("🧭 Radar Sektor", callback_data: "quick:sektor"),
                InlineKeyboardButton::make("🏦 Akumulasi Bandar", callback_data: "quick:akumulasi")
            );
        $bot->sendMessage($briefMsg, chat_id: $chatId, parse_mode: ParseMode::HTML, reply_markup: $keyboard);
    } catch (\Throwable $be) {
        $this->warn("Gagal mengirim Evening Market Brief: " . $be->getMessage());
    }

    // 2. Lanjut kirim Rekap Sore: Top Retail Jualan
    $universe = [
        'BBCA', 'BBRI', 'BMRI', 'BBNI', 'BRIS', 'BBTN', 'BDMN', 'BNGA',
        'TLKM', 'ISAT', 'EXCL', 'MTEL', 'TOWR',
        'ASII', 'AUTO', 'UNTR', 'INDF', 'ICBP', 'MYOR', 'KLBF', 'SIDO', 'MIKA', 'HEAL', 'ACES', 'MAPI', 'ERAA',
        'AMMN', 'ADRO', 'PTBA', 'ITMG', 'ABMM', 'BUMI', 'HRUM', 'INDY', 'PGAS', 'MEDC', 'AKRA', 'ELSA', 'RAJA',
        'ANTM', 'INCO', 'MDKA', 'MBMA', 'NCKL', 'TINS', 'BRMS',
        'BRPT', 'TPIA', 'PGEO', 'BREN', 'CUAN', 'PTRO', 'SMGR', 'INTP', 'INKP', 'TKIM',
        'BSDE', 'CTRA', 'PWON', 'SMRA', 'PANI', 'JSMR', 'NRCA', 'GOTO', 'BUKA', 'EMTK', 'SCMA', 'SRTG', 'VKTR'
    ];

    try {
        $result = [];
        $usedDate = now('Asia/Jakarta');

        for ($i = 0; $i <= 3; $i++) {
            $candidate = now('Asia/Jakarta')->subDays($i);
            if ($candidate->isWeekend()) continue;
            $dStr = $candidate->format('Y-m-d');
            $res = $screener->scanTopRetailSell($universe, 18, $dStr, $dStr);
            if (isset($res['error'])) {
                $result = $res;
                break;
            }
            if (!empty($res)) {
                $result = $res;
                $usedDate = $candidate;
                break;
            }
        }

        $dateLabel = $usedDate->format('d M Y');

        if (isset($result['error']) && $result['error'] === 'TOKEN_EXPIRED') {
            $bot->sendMessage(
                "⚠️ <b>AUTO REPORT TOP RETAIL (17:30 WIB)</b>\nToken Stockbit kedaluwarsa. Perbarui dengan <code>/settoken &lt;token_baru&gt;</code> agar rekap sore berjalan otomatis.",
                chat_id: $chatId,
                parse_mode: ParseMode::HTML
            );
            return;
        }

        if (empty($result) || isset($result['error'])) {
            $this->warn('Data Top Retail hari ini kosong atau token belum diatur.');
            return;
        }

        $lines = [
            "⏰ <b>AUTO REKAP SORE: TOP RETAIL JUALAN</b>",
            "Periode: <b>{$dateLabel}</b> • <i>Market Reguler</i>",
            "",
            "<pre>Symbol | Sell% |  Close |   Chg1",
            "-------|-------|--------|-------",
        ];

        foreach ($result as $row) {
            $sym      = str_pad((string) ($row['symbol'] ?? '-'), 6, ' ');
            $rawPct   = $row['sell_pct'] ?? $row['nrd0_pct'] ?? $row['retail_sell_pct'] ?? 0;
            $pct      = str_pad(number_format((float) $rawPct, 1) . '%', 5, ' ', STR_PAD_LEFT);
            $rawClose = (float) ($row['close'] ?? $row['price'] ?? 0);
            $close    = str_pad(number_format($rawClose, 0, ',', '.'), 6, ' ', STR_PAD_LEFT);
            $rawChg   = (float) ($row['chg1'] ?? $row['change_pct'] ?? $row['chg_pct'] ?? 0);
            $sign     = $rawChg > 0 ? '+' : '';
            $chg      = str_pad($sign . number_format($rawChg, 1) . '%', 6, ' ', STR_PAD_LEFT);
            $lines[]  = "{$sym} | {$pct} | {$close} | {$chg}";
        }

        $lines[] = "</pre>";
        $lines[] = "💡 <i>Ketik /topritel untuk melihat tanggal bursa lainnya.</i>";

        $top10 = array_slice($result, 0, 10);
$keyboard = InlineKeyboardMarkup::make();
foreach (array_chunk($top10, 5) as $chunk) {
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

$bot->sendMessage(implode("\n", $lines), chat_id: $chatId, parse_mode: ParseMode::HTML, reply_markup: $keyboard);
        $this->info("Rekap Top Retail berhasil dikirim ke {$chatId}");
    } catch (\Throwable $e) {
        $this->error('Gagal mengirim Rekap Top Retail: ' . $e->getMessage());
    }
})->purpose('Kirim otomatis rekap sore Top Retail Jualan setelah penutupan sesi 2');

// Jadwal Otomatis Hari Bursa (Senin - Jumat, WIB)
Schedule::command('report:morning-brief')
    ->weekdays()
    ->at('08:30')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping();

Schedule::command('report:top-retail')
    ->weekdays()
    ->at('17:30')
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
             . "Segera perbarui token agar fitur <code>/topritel</code> dan auto-rekap sore (17:30 WIB) tetap berjalan lancar:\n"
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


\Illuminate\Support\Facades\Schedule::call(function () use ($resolveOwnerChatId) {
    $chatId = $resolveOwnerChatId();
    if (!$chatId) return;
    $report = app(\App\Services\MarketSessionReportService::class)->generateReport(true);
    app(\SergiX44\Nutgram\Nutgram::class)->sendMessage($report, chat_id: $chatId, parse_mode: \SergiX44\Nutgram\Telegram\Properties\ParseMode::HTML);
})->name('report:sesi1')->weekdays()->at('12:05')->timezone('Asia/Jakarta')->withoutOverlapping();
