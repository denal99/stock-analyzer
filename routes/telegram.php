<?php

use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Internal\InputFile;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use App\Services\StockAnalysisService;
use App\Services\StockScreenerService;
use App\Models\Watchlist;

$sendAnalysisChart = function (Nutgram $bot, string $ticker, StockAnalysisService $service, float $maxRisk = 1000000, string $timeframe = '1D') {
    $ticker = strtoupper(htmlspecialchars(trim($ticker)));
    $bot->sendMessage("⏳ Sedang menganalisa <b>{$ticker}</b>...", parse_mode: ParseMode::HTML);

    try {
        $tf = strtoupper(trim($timeframe));
        $data = $service->analyze($ticker, $maxRisk, $tf);

        $trend = "Sideways / Konsolidasi";
        if ($data['price'] > $data['ma50'] && $data['ma50'] > $data['ma200']) {
            $trend = "🟢 Strong Uptrend (Harga &gt; MA50 &gt; MA200)";
        } elseif ($data['price'] < $data['ma50'] && $data['ma50'] < $data['ma200']) {
            $trend = "🔴 Strong Downtrend (Harga &lt; MA50 &lt; MA200)";
        }

        $ma200Text = $data['ma200'] ? number_format($data['ma200'], str_ends_with($data['symbol'], ".JK") ? 0 : 2) : 'Data &lt; 200 hari';
        $sign = $data['change_pct'] >= 0 ? '+' : '';

        $cleanSym = str_replace('.JK', '', $data['symbol']);
        $caption = "📊 <b>ANALISIS SAHAM: {$cleanSym} [{$tf}]</b>\n" .
                   "━━━━━━━━━━━━━━━━━━━━\n" .
                   "💰 <b>Harga Terakhir:</b> " . number_format($data['price'], str_ends_with($data['symbol'], ".JK") ? 0 : 2) . " ({$sign}{$data['change_pct']}%)\n" .
                   "🔵 <b>MA 50:</b> " . number_format($data['ma50'], str_ends_with($data['symbol'], ".JK") ? 0 : 2) . "\n" .
                   "🔴 <b>MA 200:</b> {$ma200Text}\n" .
                   "📈 <b>Status Tren:</b> {$trend}\n";

        $riskInt = (int) round($maxRisk);
        $tfKeyboard = InlineKeyboardMarkup::make()->addRow(
            InlineKeyboardButton::make($tf === '1D' ? '• 1D •' : '1D', callback_data: "tf:{$cleanSym}:1D:{$riskInt}"),
            InlineKeyboardButton::make($tf === '1W' ? '• 1W •' : '1W', callback_data: "tf:{$cleanSym}:1W:{$riskInt}"),
            InlineKeyboardButton::make($tf === '1H' ? '• 1H •' : '1H', callback_data: "tf:{$cleanSym}:1H:{$riskInt}")
        );

        $bot->sendPhoto(
            photo: InputFile::make($data['chart_path']),
            caption: $caption,
            parse_mode: ParseMode::HTML,
            reply_markup: $tfKeyboard
        );

        @unlink($data['chart_path']);

    } catch (\Exception $e) {
        $bot->sendMessage("❌ Gagal: " . htmlspecialchars($e->getMessage()), parse_mode: ParseMode::HTML);
    }
};

$buildTickerKeyboard = function (array $stocks): InlineKeyboardMarkup {
    $keyboard = InlineKeyboardMarkup::make();
    $row = [];
    foreach ($stocks as $idx => $s) {
        $sym = $s['symbol'];
        $row[] = InlineKeyboardButton::make("📊 {$sym}", callback_data: "chart:{$sym}");
        if (count($row) === 3 || $idx === count($stocks) - 1) {
            $keyboard->addRow(...$row);
            $row = [];
        }
    }
    return $keyboard;
};

$bot->onCommand('start', function (Nutgram $bot) {
    $bot->sendMessage(
        "Halo! Saya bot analisis &amp; screener saham.\n\n" .
        "<b>Daftar Perintah:</b>\n" .
        "📈 <code>/analisa BBCA</code> — Chart &amp; Trading Plan (Risk Rp 1 Jt)\n" .
        "🧮 <code>/analisa BBCA 500000</code> — Chart &amp; Custom Max Risk\n" .
        "🔍 <code>/screener</code> — Default (PER &lt; 10, PBV &lt; 5)\n" .
        "🎯 <code>/screener 8 1.5</code> — Custom PER &amp; PBV\n" .
        "🛡️ <code>/screener 10 2 1</code> — Custom PER, PBV &amp; DER\n" .
        "💰 <code>/dividen 10</code> — Khusus Dividen &gt;= 10%\n" .
        "🔥 <code>/volume</code> — Lonjakan Volume &gt;= 1.5x\n" .
        "🏦 <code>/akumulasi</code> — Deteksi Akumulasi (VSA/OBV)",
        parse_mode: ParseMode::HTML
    );
});

// Satu route tunggal untuk /analisa KODE maupun /analisa KODE NOMINAL_RISK
$bot->onCommand('analisa {args}', function (Nutgram $bot, string $args, StockAnalysisService $service) use ($sendAnalysisChart) {
    $parts = preg_split('/\s+/', trim($args));
    $sym = $parts[0] ?? '';
    $cleanRisk = isset($parts[1]) ? (float) preg_replace('/[^0-9]/', '', $parts[1]) : 1000000;
    $sendAnalysisChart($bot, $sym, $service, $cleanRisk > 0 ? $cleanRisk : 1000000);
});

$bot->onCallbackQueryData('chart:{ticker}', function (Nutgram $bot, string $ticker, StockAnalysisService $service) use ($sendAnalysisChart) {
    $bot->answerCallbackQuery(text: "Memuat chart {$ticker}...");
    $sendAnalysisChart($bot, $ticker, $service, 1000000);
});

$runScreener = function (
    Nutgram $bot,
    StockScreenerService $screener,
    float $maxPer = 10.0,
    float $maxPbv = 5.0,
    ?float $maxDer = null
) use ($buildTickerKeyboard) {
    if ($maxPer <= 0 or $maxPbv <= 0 or ($maxDer !== null && $maxDer <= 0)) {
        $bot->sendMessage("⚠️ Nilai PER, PBV, dan DER harus lebih besar dari 0.\nContoh: <code>/screener 10 2 1</code>", parse_mode: ParseMode::HTML);
        return;
    }

    $derLabel = $maxDer !== null ? " • DER &lt; {$maxDer}x" : "";
    $bot->sendMessage("🔍 Mencari saham IDX dengan <b>PER &lt; {$maxPer}</b> • <b>PBV &lt; {$maxPbv}</b>{$derLabel}...", parse_mode: ParseMode::HTML);

    try {
        $stocks = $screener->screenValueStocks($maxPer, $maxPbv, $maxDer, null, 15);

        if (empty($stocks)) {
            $bot->sendMessage("Tidak ditemukan saham yang memenuhi kriteria <b>PER &lt; {$maxPer}</b> • <b>PBV &lt; {$maxPbv}</b>{$derLabel}.", parse_mode: ParseMode::HTML);
            return;
        }

        $message = "🎯 <b>SCREENER SAHAM IDX (Top Market Cap)</b>\n" .
                   "<i>Kriteria: PER &lt; {$maxPer} • PBV &lt; {$maxPbv}{$derLabel}</i>\n" .
                   "━━━━━━━━━━━━━━━━━━━━\n\n";

        foreach ($stocks as $idx => $s) {
            $no = $idx + 1;
            $price = number_format($s['price'], 0, ',', '.');
            $derText = $s['der'] !== null ? "{$s['der']}x" : "N/A";
            $divText = $s['dividend_yield'] !== null ? "{$s['dividend_yield']}%" : "0%";

            $message .= "{$no}. <b>{$s['symbol']}</b> — Rp {$price}\n" .
                        "    • PER: <b>{$s['per']}x</b> • PBV: <b>{$s['pbv']}x</b> • DER: <b>{$derText}</b>\n" .
                        "    • M.Cap: <b>Rp {$s['market_cap_formatted']}</b> • Div: <b>{$divText}</b>\n\n";
        }

        $message .= "👇 <i>Klik tombol di bawah untuk langsung buka chart:</i>";
        $bot->sendMessage($message, parse_mode: ParseMode::HTML, reply_markup: $buildTickerKeyboard($stocks));

    } catch (\Exception $e) {
        $bot->sendMessage("❌ Gagal menjalankan screener: " . htmlspecialchars($e->getMessage()), parse_mode: ParseMode::HTML);
    }
};

$runDividendScreener = function (Nutgram $bot, StockScreenerService $screener, float $minYield = 5.0) use ($buildTickerKeyboard) {
    if ($minYield <= 0) {
        $bot->sendMessage("⚠️ Nilai minimal dividen harus lebih besar dari 0.\nContoh: <code>/dividen 10</code>", parse_mode: ParseMode::HTML);
        return;
    }

    $bot->sendMessage("💰 Mencari saham IDX dengan <b>Dividend Yield &gt;= {$minYield}%</b>...", parse_mode: ParseMode::HTML);

    try {
        $stocks = $screener->screenDividendStocks($minYield, 15);

        if (empty($stocks)) {
            $bot->sendMessage("Tidak ditemukan saham dengan <b>Dividend Yield &gt;= {$minYield}%</b>.", parse_mode: ParseMode::HTML);
            return;
        }

        $message = "💰 <b>SCREENER DIVIDEN IDX (Yield Tertinggi)</b>\n" .
                   "<i>Kriteria: Dividend Yield &gt;= {$minYield}% (Cum Date)</i>\n" .
                   "━━━━━━━━━━━━━━━━━━━━\n\n";

        foreach ($stocks as $idx => $s) {
            $no = $idx + 1;
            $price = number_format($s['price'], 0, ',', '.');
            $perText = $s['per'] !== null ? "{$s['per']}x" : "N/A";
            $pbvText = $s['pbv'] !== null ? "{$s['pbv']}x" : "N/A";
            $derText = $s['der'] !== null ? "{$s['der']}x" : "N/A";

            $message .= "{$no}. <b>{$s['symbol']}</b> — Rp {$price} (Div: <b>{$s['dividend_yield']}%</b>)\n" .
                        "    • Final: <b>{$s['final_month']}</b> • Interim: <b>{$s['interim_month']}</b>\n" .
                        "    • PER: <b>{$perText}</b> • PBV: <b>{$pbvText}</b> • DER: <b>{$derText}</b>\n" .
                        "    • M.Cap: <b>Rp {$s['market_cap_formatted']}</b>\n\n";
        }

        $message .= "👇 <i>Klik tombol di bawah untuk langsung buka chart:</i>";
        $bot->sendMessage($message, parse_mode: ParseMode::HTML, reply_markup: $buildTickerKeyboard($stocks));

    } catch (\Exception $e) {
        $bot->sendMessage("❌ Gagal menjalankan screener dividen: " . htmlspecialchars($e->getMessage()), parse_mode: ParseMode::HTML);
    }
};

$runVolumeScreener = function (Nutgram $bot, StockScreenerService $screener, float $minRatio = 1.5, bool $onlyGreen = true) use ($buildTickerKeyboard) {
    if ($minRatio <= 0) {
        $bot->sendMessage("⚠️ Nilai rasio volume harus lebih besar dari 0.\nContoh: <code>/volume 2</code>", parse_mode: ParseMode::HTML);
        return;
    }

    $modeLabel = $onlyGreen ? "Harga Naik/Hijau" : "Semua Arah";
    $bot->sendMessage("🔥 Memindai saham IDX dengan lonjakan volume <b>&gt;= {$minRatio}x</b> rata-rata 20 hari ({$modeLabel})...", parse_mode: ParseMode::HTML);

    try {
        $stocks = $screener->screenVolumeSpike($minRatio, $onlyGreen, 15);

        if (empty($stocks)) {
            $bot->sendMessage("Tidak ditemukan saham dengan lonjakan volume <b>&gt;= {$minRatio}x</b> ({$modeLabel}) hari ini.", parse_mode: ParseMode::HTML);
            return;
        }

        $message = "🔥 <b>SCREENER LONJAKAN VOLUME IDX</b>\n" .
                   "<i>Kriteria: Vol &gt;= {$minRatio}x Avg20 • {$modeLabel}</i>\n" .
                   "━━━━━━━━━━━━━━━━━━━━\n\n";

        foreach ($stocks as $idx => $s) {
            $no = $idx + 1;
            $price = number_format($s['price'], 0, ',', '.');
            $sign = $s['change_pct'] >= 0 ? '+' : '';
            $icon = $s['change_pct'] > 0 ? '🟢' : ($s['change_pct'] < 0 ? '🔴' : '⚪');
            $todayLot = number_format($s['today_lot'], 0, ',', '.');
            $avgLot = number_format($s['avg_lot'], 0, ',', '.');

            $message .= "{$no}. {$icon} <b>{$s['symbol']}</b> — Rp {$price} (<b>{$sign}{$s['change_pct']}%</b>)\n" .
                        "    • Lonjakan: <b>{$s['vol_ratio']}x</b> ({$todayLot} vs avg {$avgLot} lot)\n" .
                        "    • Nilai Transaksi: <b>Rp {$s['turnover_formatted']}</b>\n\n";
        }

        $message .= "👇 <i>Klik tombol di bawah untuk langsung buka chart:</i>";
        $bot->sendMessage($message, parse_mode: ParseMode::HTML, reply_markup: $buildTickerKeyboard($stocks));

    } catch (\Exception $e) {
        $bot->sendMessage("❌ Gagal menjalankan screener volume: " . htmlspecialchars($e->getMessage()), parse_mode: ParseMode::HTML);
    }
};

$bot->onCommand('screener', function (Nutgram $bot, StockScreenerService $screener) use ($runScreener) {
    $runScreener($bot, $screener, 10.0, 5.0, null);
});

$bot->onCommand('screener {per}', function (Nutgram $bot, string $per, StockScreenerService $screener) use ($runScreener) {
    $maxPer = (float) str_replace(',', '.', $per);
    $runScreener($bot, $screener, $maxPer, 5.0, null);
});

$bot->onCommand('screener {per} {pbv}', function (Nutgram $bot, string $per, string $pbv, StockScreenerService $screener) use ($runScreener) {
    $maxPer = (float) str_replace(',', '.', $per);
    $maxPbv = (float) str_replace(',', '.', $pbv);
    $runScreener($bot, $screener, $maxPer, $maxPbv, null);
});

$bot->onCommand('screener {per} {pbv} {der}', function (Nutgram $bot, string $per, string $pbv, string $der, StockScreenerService $screener) use ($runScreener) {
    $maxPer = (float) str_replace(',', '.', $per);
    $maxPbv = (float) str_replace(',', '.', $pbv);
    $maxDer = (float) str_replace(',', '.', $der);
    $runScreener($bot, $screener, $maxPer, $maxPbv, $maxDer);
});

$bot->onCommand('dividen', function (Nutgram $bot, StockScreenerService $screener) use ($runDividendScreener) {
    $runDividendScreener($bot, $screener, 5.0);
});

$bot->onCommand('dividen {yield}', function (Nutgram $bot, string $yield, StockScreenerService $screener) use ($runDividendScreener) {
    $minYield = (float) str_replace(',', '.', $yield);
    $runDividendScreener($bot, $screener, $minYield);
});

$bot->onCommand('volume', function (Nutgram $bot, StockScreenerService $screener) use ($runVolumeScreener) {
    $runVolumeScreener($bot, $screener, 1.5, true);
});

$bot->onCommand('volume {ratio}', function (Nutgram $bot, string $ratio, StockScreenerService $screener) use ($runVolumeScreener) {
    $minRatio = (float) str_replace(',', '.', $ratio);
    $runVolumeScreener($bot, $screener, $minRatio, true);
});

$bot->onCommand('volume {ratio} {mode}', function (Nutgram $bot, string $ratio, string $mode, StockScreenerService $screener) use ($runVolumeScreener) {
    $minRatio = (float) str_replace(',', '.', $ratio);
    $onlyGreen = strtolower(trim($mode)) !== 'all';
    $runVolumeScreener($bot, $screener, $minRatio, $onlyGreen);
});

$bot->onCommand('akumulasi', function (Nutgram $bot, StockScreenerService $screener) use ($buildTickerKeyboard) {
    $bot->sendMessage("🏦 Memindai jejak akumulasi (VSA, OBV &amp; MA20) pada saham IDX...", parse_mode: ParseMode::HTML);

    try {
        $stocks = $screener->screenAccumulation(15);

        if (empty($stocks)) {
            $bot->sendMessage("Belum ditemukan saham dengan pola akumulasi kuat saat ini.");
            return;
        }

        $message = "🏦 <b>SCREENER AKUMULASI IDX (VSA &amp; OBV)</b>\n" .
                   "<i>Indikator: Vol 3D, Strong Close, OBV &amp; Area MA20</i>\n" .
                   "━━━━━━━━━━━━━━━━━━━━\n\n";

        foreach ($stocks as $idx => $s) {
            $no = $idx + 1;
            $price = number_format($s['price'], 0, ',', '.');
            $sign = $s['change_pct'] >= 0 ? '+' : '';

            $message .= "{$no}. <b>{$s['symbol']}</b> — Rp {$price} (<b>{$sign}{$s['change_pct']}%</b>) • Skor: <b>{$s['accum_score']}/100</b>\n" .
                        "    • Sinyal: <b>{$s['accum_signals']}</b>\n" .
                        "    • Vol 3D: <b>{$s['vol_3d_ratio']}x</b> Avg20 • Tx: <b>Rp {$s['turnover_formatted']}</b>\n\n";
        }

        $message .= "👇 <i>Klik tombol di bawah untuk langsung buka chart:</i>";
        $bot->sendMessage($message, parse_mode: ParseMode::HTML, reply_markup: $buildTickerKeyboard($stocks));

    } catch (\Exception $e) {
        $bot->sendMessage("❌ Gagal menjalankan screener akumulasi: " . htmlspecialchars($e->getMessage()), parse_mode: ParseMode::HTML);
    }
});

$bot->onText('^([a-zA-Z]{4,5})$', function (Nutgram $bot, string $ticker) {
    $ticker = strtoupper(htmlspecialchars($ticker));
    $keyboard = InlineKeyboardMarkup::make()->addRow(
        InlineKeyboardButton::make("📊 Buka Chart {$ticker}", callback_data: "chart:{$ticker}")
    );
    $bot->sendMessage("Klik tombol di bawah untuk menganalisa <b>{$ticker}</b>:", parse_mode: ParseMode::HTML, reply_markup: $keyboard);
});


$bot->onCommand('pantau {ticker}', function (Nutgram $bot, string $ticker) {
    $symbol = strtoupper(preg_replace('/[^a-zA-Z0-9.]/', '', trim($ticker)));
    $userId = $bot->userId();

    $count = Watchlist::where('telegram_id', $userId)->count();
    if ($count >= 15) {
        $bot->sendMessage("⚠️ Maksimal watchlist pribadi adalah 15 saham.", parse_mode: ParseMode::HTML);
        return;
    }

    Watchlist::firstOrCreate([
        'telegram_id' => $userId,
        'symbol' => $symbol,
    ]);

    $bot->sendMessage("✅ Saham <b>{$symbol}</b> ditambahkan ke watchlist pribadi!\nBot otomatis memantau Target R1, Stoploss, dan Breakout Volume setiap 15 menit di jam bursa.", parse_mode: ParseMode::HTML);
});

$bot->onCommand('unpantau {ticker}', function (Nutgram $bot, string $ticker) {
    $symbol = strtoupper(preg_replace('/[^a-zA-Z0-9.]/', '', trim($ticker)));
    $userId = $bot->userId();

    $deleted = Watchlist::where('telegram_id', $userId)->where('symbol', $symbol)->delete();
    if ($deleted) {
        $bot->sendMessage("🗑️ Saham <b>{$symbol}</b> dihapus dari watchlist.", parse_mode: ParseMode::HTML);
    } else {
        $bot->sendMessage("⚠️ Saham <b>{$symbol}</b> tidak ada di watchlist Anda.", parse_mode: ParseMode::HTML);
    }
});

$bot->onCommand('watchlist', function (Nutgram $bot) use ($buildTickerKeyboard) {
    $userId = $bot->userId();
    $items = Watchlist::where('telegram_id', $userId)->pluck('symbol')->toArray();

    if (empty($items)) {
        $bot->sendMessage("📭 Watchlist Anda masih kosong.\nKetik <code>/pantau BBCA</code> untuk mulai memantau.", parse_mode: ParseMode::HTML);
        return;
    }

    $stocks = array_map(fn($s) => ['symbol' => $s], $items);
    $listStr = implode(', ', $items);
    $bot->sendMessage(
        "📋 <b>WATCHLIST PRIBADI ANDA:</b>\n\n" .
        "Emiten dipantau: <b>{$listStr}</b>\n\n" .
        "<i>Hapus dengan</i> <code>/unpantau KODE</code> <i>atau klik tombol chart di bawah:</i>",
        parse_mode: ParseMode::HTML,
        reply_markup: $buildTickerKeyboard($stocks)
    );
});


$bot->onCommand('analisa', function (\SergiX44\Nutgram\Nutgram $bot) {
    $bot->sendMessage("💡 Gunakan format: <code>/analisa KODE</code> atau <code>/analisa KODE MAX_RISK</code>\nContoh: <code>/analisa BBCA</code> atau <code>/analisa BBRI 500000</code>", parse_mode: \SergiX44\Nutgram\Telegram\Properties\ParseMode::HTML);
})->description('Analisa teknikal, chart & trading plan (Contoh: /analisa BBCA)');

$bot->onCommand('pantau', function (\SergiX44\Nutgram\Nutgram $bot) {
    $bot->sendMessage("💡 Gunakan format: <code>/pantau KODE</code>\nContoh: <code>/pantau BBRI</code>", parse_mode: \SergiX44\Nutgram\Telegram\Properties\ParseMode::HTML);
})->description('Tambah saham ke watchlist alert (Contoh: /pantau BBRI)');

$bot->onCommand('unpantau', function (\SergiX44\Nutgram\Nutgram $bot) {
    $bot->sendMessage("💡 Gunakan format: <code>/unpantau KODE</code>\nContoh: <code>/unpantau BBRI</code>", parse_mode: \SergiX44\Nutgram\Telegram\Properties\ParseMode::HTML);
})->description('Hapus saham dari watchlist (Contoh: /unpantau BBRI)');

// Daftarkan menu perintah resmi ke server Telegram
$bot->onCommand('setmenu', function (\SergiX44\Nutgram\Nutgram $bot) {
    $bot->setMyCommands([
        new \SergiX44\Nutgram\Telegram\Types\Command\BotCommand('analisa', 'Analisa teknikal, chart & trading plan (misal: /analisa BBCA)'),
        new \SergiX44\Nutgram\Telegram\Types\Command\BotCommand('screener', 'Screener saham pilihan (Uptrend, Oversold, Breakout)'),
        new \SergiX44\Nutgram\Telegram\Types\Command\BotCommand('dividen', 'Screener saham dividen yield tinggi & sehat'),
        new \SergiX44\Nutgram\Telegram\Types\Command\BotCommand('volume', 'Deteksi saham lonjakan volume hari ini'),
        new \SergiX44\Nutgram\Telegram\Types\Command\BotCommand('akumulasi', 'Deteksi saham indikasi akumulasi bandar'),
        new \SergiX44\Nutgram\Telegram\Types\Command\BotCommand('watchlist', 'Lihat daftar saham yang sedang dipantau'),
        new \SergiX44\Nutgram\Telegram\Types\Command\BotCommand('pantau', 'Tambah saham ke watchlist alert (misal: /pantau BBRI)'),
        new \SergiX44\Nutgram\Telegram\Types\Command\BotCommand('unpantau', 'Hapus saham dari watchlist (misal: /unpantau BBRI)'),
    ]);
    $bot->sendMessage("✅ Menu perintah (/) berhasil didaftarkan ke Telegram!");
});


$bot->onCallbackQueryData('tf:{ticker}:{tf}:{risk}', function (Nutgram $bot, string $ticker, string $tf, string $risk) use ($sendAnalysisChart) {
    $service = app(StockAnalysisService::class);
    $tf = strtoupper(trim($tf));
    $cleanSym = strtoupper(trim($ticker));
    $maxRisk = (float) $risk > 0 ? (float) $risk : 1000000;

    $bot->answerCallbackQuery(text: "⏳ Memuat chart {$cleanSym} ({$tf})...");

    try {
        $data = $service->analyze($cleanSym, $maxRisk, $tf);

        $trend = "Sideways / Konsolidasi";
        if ($data['price'] > $data['ma50'] && $data['ma50'] > $data['ma200']) {
            $trend = "🟢 Strong Uptrend (Harga &gt; MA50 &gt; MA200)";
        } elseif ($data['price'] < $data['ma50'] && $data['ma50'] < $data['ma200']) {
            $trend = "🔴 Strong Downtrend (Harga &lt; MA50 &lt; MA200)";
        }

        $ma200Text = $data['ma200'] ? number_format($data['ma200'], str_ends_with($data['symbol'], ".JK") ? 0 : 2) : 'Data &lt; 200 period';
        $sign = $data['change_pct'] >= 0 ? '+' : '';

        $caption = "📊 <b>ANALISIS SAHAM: {$cleanSym} [{$tf}]</b>\n" .
                   "━━━━━━━━━━━━━━━━━━━━\n" .
                   "💰 <b>Harga Terakhir:</b> " . number_format($data['price'], str_ends_with($data['symbol'], ".JK") ? 0 : 2) . " ({$sign}{$data['change_pct']}%)\n" .
                   "🔵 <b>MA 50:</b> " . number_format($data['ma50'], str_ends_with($data['symbol'], ".JK") ? 0 : 2) . "\n" .
                   "🔴 <b>MA 200:</b> {$ma200Text}\n" .
                   "📈 <b>Status Tren:</b> {$trend}\n";

        $riskInt = (int) round($maxRisk);
        $tfKeyboard = InlineKeyboardMarkup::make()->addRow(
            InlineKeyboardButton::make($tf === '1D' ? '• 1D •' : '1D', callback_data: "tf:{$cleanSym}:1D:{$riskInt}"),
            InlineKeyboardButton::make($tf === '1W' ? '• 1W •' : '1W', callback_data: "tf:{$cleanSym}:1W:{$riskInt}"),
            InlineKeyboardButton::make($tf === '1H' ? '• 1H •' : '1H', callback_data: "tf:{$cleanSym}:1H:{$riskInt}")
        );

        $bot->editMessageMedia(
            media: new \SergiX44\Nutgram\Telegram\Types\Input\InputMediaPhoto(
                media: InputFile::make($data['chart_path']),
                caption: $caption,
                parse_mode: ParseMode::HTML
            ),
            reply_markup: $tfKeyboard
        );

        @unlink($data['chart_path']);
    } catch (\Exception $e) {
        $bot->sendMessage("❌ Gagal memuat timeframe {$tf}: " . htmlspecialchars($e->getMessage()), parse_mode: ParseMode::HTML);
    }
});
