<?php

use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Internal\InputFile;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use App\Services\StockAnalysisService;
use App\Services\StockScreenerService;
use App\Models\Watchlist;

$formatBrokerFlowBlock = function (string $cleanSym): string {
    try {
        $flow = app(StockScreenerService::class)->getStockBrokerSummary($cleanSym);
        if (!$flow) {
            return '';
        }
        $sideIcon = $flow['is_retail_selling'] ? '🟢' : '🔴';
        $buyers = !empty($flow['top_buyers']) ? implode(', ', $flow['top_buyers']) : '-';
        $sellers = !empty($flow['top_sellers']) ? implode(', ', $flow['top_sellers']) : '-';

        return "\n\n🏦 <b>Broker Flow ({$flow['date_label']}):</b>\n"
             . "• Status: <b>{$flow['accdist']}</b> | {$sideIcon} Ritel {$flow['retail_side']} <b>{$flow['retail_pct']}%</b> ({$flow['retail_val']})\n"
             . "• Top Buy: <b>{$buyers}</b> | Top Sell: <b>{$sellers}</b>";
    } catch (\Throwable $e) {
        return '';
    }
};

$buildConfluenceStrategy = function (
    string $trend,
    ?float $rsiVal,
    float $volRatio,
    float $r1,
    float $s1,
    float $sl,
    string $ma20Text,
    int $dec,
    ?array $flow
): string {
    $r1Fmt = number_format($r1, $dec);
    $s1Fmt = number_format($s1, $dec);
    $slFmt = number_format($sl, $dec);

    $isUptrend   = str_contains($trend, 'Uptrend');
    $isDowntrend = str_contains($trend, 'Downtrend');

    if ($flow) {
        $buyers  = !empty($flow['top_buyers']) ? implode(', ', array_slice($flow['top_buyers'], 0, 5)) : 'institusi';
        $sellers = !empty($flow['top_sellers']) ? implode(', ', array_slice($flow['top_sellers'], 0, 5)) : 'institusi';
        $retailSelling = (bool) ($flow['is_retail_selling'] ?? false);
        $retailBuying  = !$retailSelling && (($flow['retail_side'] ?? '') === 'Net Buy');
        $isAccTag      = stripos($flow['accdist'], 'Acc') !== false;
        $isDistTag     = stripos($flow['accdist'], 'Dist') !== false;
        $volNote       = $volRatio < 0.8 ? " &amp; vol sepi ({$volRatio}x)" : "";

        $supportZone = ($ma20Text !== '-' && $ma20Text !== $s1Fmt) ? "S1 ({$s1Fmt}) / MA20 ({$ma20Text})" : "S1 ({$s1Fmt})";

        if (!$isDowntrend && $retailBuying && $flow['retail_pct'] >= 15) {
            $posNote = $isUptrend ? "harga bertahan dalam tren naik" : "harga konsolidasi";
            return "⚠️ <b>Divergence:</b> {$posNote}, namun flow kurang sehat karena ritel net buy ({$flow['retail_pct']}%) sementara <b>{$sellers}</b> distribusi{$volNote}. Titik pantau: koreksi di <b>{$supportZone}</b> atau tunggu breakout R1 (<b>{$r1Fmt}</b>) bervolume.";
        }

        if (!$isDowntrend && $retailSelling && $isDistTag) {
            return "⚖️ <b>Mixed Flow (Rotasi Bandar):</b> Ritel net sell ({$flow['retail_pct']}%) dan ditampung <b>{$buyers}</b>, namun masih tertahan tekanan jual besar dari <b>{$sellers}</b>{$volNote}. Titik pantau: buy on weakness di <b>{$supportZone}</b> atau tunggu breakout R1 (<b>{$r1Fmt}</b>).";
        }

        if (!$isDowntrend && ($isAccTag || $retailSelling)) {
            if ($rsiVal !== null && $rsiVal >= 72) {
                return "🔥 <b>Strong Confluence:</b> Akumulasi (<b>{$buyers}</b>) saat ritel net sell ({$flow['retail_pct']}%), namun RSI jenuh beli. Titik pantau: tunggu pullback sehat di <b>{$supportZone}</b>.";
            }
            return "✅ <b>Bullish Confluence:</b> Teknikal positif didukung penyerapan (<b>{$buyers}</b>) saat ritel net sell ({$flow['retail_pct']}%). Titik pantau: buy on weakness di <b>{$supportZone}</b>, target R1 (<b>{$r1Fmt}</b>).";
        }

        if ($isDowntrend && ($isAccTag || $retailSelling)) {
            return "👀 <b>Early Accumulation:</b> Tren masih turun, namun mulai terlihat penyerapan (<b>{$buyers}</b>) di tengah jualan ritel ({$flow['retail_pct']}%). Titik pantau: uji bertahan di <b>S1 ({$s1Fmt})</b> &amp; reclaim MA20 (<b>{$ma20Text}</b>), SL {$slFmt}.";
        }

        if ($isDowntrend && ($isDistTag || $retailBuying)) {
            return "🚨 <b>Hindari tangkap pisau jatuh:</b> Tren bearish disertai distribusi (<b>{$sellers}</b>) sementara ritel masih menampung. Titik pantau: wait &amp; see hingga reclaim MA20 (<b>{$ma20Text}</b>).";
        }
    }

    $supportZone = ($ma20Text !== '-' && $ma20Text !== $s1Fmt) ? "S1 ({$s1Fmt}) / MA20 ({$ma20Text})" : "S1 ({$s1Fmt})";

    if ($isDowntrend) {
        return ($rsiVal !== null && $rsiVal <= 30)
            ? "⚠️ Tren turun kuat namun RSI sudah <b>Oversold</b>. Titik pantau: pantulan di <b>S1 ({$s1Fmt})</b> menuju MA20 (<b>{$ma20Text}</b>), disiplin SL {$slFmt}."
            : "⚠️ Tren bearish kuat di bawah MA. Titik pantau: wait &amp; see hingga harga mampu reclaim MA20 (<b>{$ma20Text}</b>).";
    } elseif (str_contains($trend, 'Strong Uptrend')) {
        return ($rsiVal !== null && $rsiVal >= 72)
            ? "🔥 Uptrend solid namun RSI jenuh beli. Titik pantau: hindari chasing pucuk, tunggu pullback di <b>{$supportZone}</b>."
            : "✅ Tren naik sehat terkonfirmasi. Titik pantau: buy on weakness di <b>{$supportZone}</b> dengan target terdekat R1 (<b>{$r1Fmt}</b>).";
    } elseif (str_contains($trend, 'Pullback in Uptrend')) {
        return "🟡 Fase koreksi wajar dalam tren naik besar. Titik pantau: pantulan di area <b>{$supportZone}</b> sebagai titik akumulasi.";
    }

    return "⚖️ Harga bergerak konsolidasi/transisi. Titik pantau: akumulasi dekat <b>{$supportZone}</b> atau tunggu breakout R1 (<b>{$r1Fmt}</b>) bervolume.";
};

$sendAnalysisChart = function (Nutgram $bot, string $ticker, StockAnalysisService $service, float $maxRisk = 1000000, string $timeframe = '1D') use ($formatBrokerFlowBlock, $buildConfluenceStrategy) {
    $ticker = strtoupper(htmlspecialchars(trim($ticker)));
    $bot->sendMessage("⏳ Sedang menganalisa <b>{$ticker}</b>...", parse_mode: ParseMode::HTML);

    try {
        $tf = strtoupper(trim($timeframe));
        $data = $service->analyze($ticker, $maxRisk, $tf);

        $trend = "🟡 Sideways / Konsolidasi";
        if ($data['ma200'] && $data['price'] > $data['ma50'] && $data['ma50'] > $data['ma200']) {
            $trend = "🟢 Strong Uptrend (Harga &gt; MA50 &gt; MA200)";
        } elseif ($data['price'] > $data['ma50'] && (!$data['ma200'] || $data['price'] < $data['ma200'])) {
            $trend = "🟢 Short-Term Uptrend (Above MA50" . ($data['ma200'] ? ", Below MA200" : "") . ")";
        } elseif ($data['ma200'] && $data['price'] < $data['ma50'] && $data['ma50'] > $data['ma200']) {
            $trend = "🟡 Pullback in Uptrend (Below MA50, Above MA200)";
        } elseif ($data['ma200'] && $data['price'] < $data['ma50'] && $data['ma50'] < $data['ma200']) {
            $trend = "🔴 Strong Downtrend (Harga &lt; MA50 &lt; MA200)";
        } elseif ($data['price'] < $data['ma50']) {
            $trend = "🔴 Short-Term Downtrend (Below MA50)";
        }

        $dec = str_ends_with($data['symbol'], ".JK") ? 0 : 2;
        $price = (float) $data['price'];
        $ma20Text = !empty($data['ma20']) ? number_format($data['ma20'], $dec) : '-';
        $ma50Text = !empty($data['ma50']) ? number_format($data['ma50'], $dec) : '-';
        $ma200Text = !empty($data['ma200']) ? number_format($data['ma200'], $dec) : 'Data &lt; 200 hari';
        $sign = $data['change_pct'] >= 0 ? '+' : '';

        $rsiVal = $data['rsi'] ?? null;
        $rsiLabel = '-';
        if ($rsiVal !== null) {
            $rsiState = $rsiVal >= 70 ? 'Overbought' : ($rsiVal <= 30 ? 'Oversold' : ($rsiVal >= 55 ? 'Bullish' : ($rsiVal <= 40 ? 'Melemah' : 'Netral')));
            $rsiLabel = number_format($rsiVal, 1) . " ({$rsiState})";
        }

        $volRatio = (float) ($data['vol_ratio'] ?? 0);
        $volState = $volRatio >= 1.5 ? '🔥 Lonjakan Volume' : ($volRatio >= 1.0 ? 'Normal' : 'Sepi');

        $r1 = (float) ($data['r1'] ?? 0);
        $r2 = (float) ($data['r2'] ?? 0);
        $s1 = (float) ($data['s1'] ?? 0);
        $s2 = (float) ($data['s2'] ?? 0);
        $sl = (float) ($data['stoploss'] ?? 0);

        $r1Pct = $price > 0 ? round((($r1 - $price) / $price) * 100, 1) : 0;
        $s1Pct = $price > 0 ? round((($s1 - $price) / $price) * 100, 1) : 0;
        $slPct = $price > 0 ? round((($sl - $price) / $price) * 100, 1) : 0;

        $reward = max(0, $r1 - $price);
        $risk = max(0, $price - $sl);
        $rrRatio = $risk > 0 ? round($reward / $risk, 2) : 0;

        $recLot = number_format((int) ($data['rec_lot'] ?? 0));
        $allocRp = number_format((float) ($data['alloc_rp'] ?? 0), 0);

        // Catatan Strategi Otomatis
        if (str_contains($trend, 'Strong Downtrend')) {
            $ma20Note = !empty($data['ma20']) ? "reclaim MA20 (" . $ma20Text . ") sebagai resistance dinamis terdekat sebelum menguji R1 (" . number_format($r1, $dec) . ")" : "menembus R1 (" . number_format($r1, $dec) . ")";
            $insight = ($rsiVal !== null && $rsiVal <= 30)
                ? "⚠️ Tren turun kuat namun RSI sudah <b>Oversold</b>. Pantau potensi teknikal rebound menuju MA20 (" . $ma20Text . "), tetap disiplin SL di " . number_format($sl, $dec) . "."
                : "⚠️ Tren bearish kuat di bawah seluruh MA. Hindari tangkap pisau jatuh; <i>wait &amp; see</i> hingga harga mampu {$ma20Note}.";
        } elseif (str_contains($trend, 'Strong Uptrend')) {
            $insight = ($rsiVal !== null && $rsiVal >= 72)
                ? "🔥 Uptrend solid namun RSI mulai jenuh beli. Hindari <i>chasing</i> pucuk, tunggu <i>pullback</i> sehat dekat MA20 (" . $ma20Text . ")."
                : "✅ Tren naik sehat terkonfirmasi. Ideal <i>buy on weakness</i> di area support/MA20 dengan target terdekat R1 (" . number_format($r1, $dec) . ").";
        } elseif (str_contains($trend, 'Pullback in Uptrend')) {
            $insight = "🟡 Fase koreksi wajar dalam tren naik besar. Amati pantulan di area Support S1 (" . number_format($s1, $dec) . ") atau MA200 sebagai titik akumulasi.";
        } else {
            $insight = "⚖️ Harga bergerak konsolidasi/transisi. Tunggu konfirmasi <i>breakout</i> di atas R1 (" . number_format($r1, $dec) . ") disertai lonjakan volume.";
        }

        if ($rrRatio > 0 && $rrRatio < 1.0) {
            $insight .= "\n⚠️ <b>R:R kurang ideal (1:{$rrRatio})</b> — tunggu koreksi dekat support agar rasio TP/SL lebih sehat.";
        }

        $cleanSym = str_replace('.JK', '', $data['symbol']);
        $brokerFlow = null;
        try {
            $brokerFlow = app(StockScreenerService::class)->getStockBrokerSummary($cleanSym);
        } catch (\Throwable $e) {
            $brokerFlow = null;
        }
        $insight = $buildConfluenceStrategy($trend, $rsiVal, $volRatio, $r1, $s1, $sl, $ma20Text, $dec, $brokerFlow);
        if ($rrRatio > 0 && $rrRatio < 1.0) {
            $insight .= "\n⚠️ <b>R:R kurang ideal (1:{$rrRatio})</b> — tunggu koreksi dekat support agar rasio TP/SL lebih sehat.";
        }
        $caption = "📊 <b>ANALISIS: {$cleanSym} [{$tf}]</b>\n" .
                   "━━━━━━━━━━━━━━━━━━━━\n" .
                   "💰 <b>Harga:</b> Rp " . number_format($price, $dec) . " (<b>{$sign}{$data['change_pct']}%</b>)\n" .
                   "📈 <b>Tren:</b> {$trend}\n\n" .
                   "🧭 <b>Indikator:</b>\n" .
                   "• MA20: <b>{$ma20Text}</b> | MA50: <b>{$ma50Text}</b> | MA200: <b>{$ma200Text}</b>\n" .
                   "• RSI(14): <b>{$rsiLabel}</b> | Vol: <b>{$volRatio}x</b> ({$volState})\n\n" .
                   "🎯 <b>Area &amp; Trading Plan:</b>\n" .
                   "• Res: R1 <b>" . number_format($r1, $dec) . "</b> (+{$r1Pct}%) | R2 <b>" . number_format($r2, $dec) . "</b>\n" .
                   "• Sup: S1 <b>" . number_format($s1, $dec) . "</b> ({$s1Pct}%) | S2 <b>" . number_format($s2, $dec) . "</b>\n" .
                   "• Plan: TP <b>" . number_format($r1, $dec) . "</b> | SL <b>" . number_format($sl, $dec) . "</b> ({$slPct}%) | R:R <b>1:{$rrRatio}</b>\n" .
                   "• Lot: Maks. <b>{$recLot} Lot</b> (±Rp {$allocRp})" .
                   $formatBrokerFlowBlock($cleanSym) . "\n\n" .
                   "💡 <b>Strategi:</b>\n{$insight}\n\n📡 <b>Sumber:</b> " . ((($data['data_source'] ?? 'Yahoo Finance') === 'Stockbit RT') ? '🟢 Stockbit Real-Time' : '🟡 Yahoo Finance (15m delay)') . ((($data['data_source'] ?? '') === 'Stockbit RT') ? " (" . now('Asia/Jakarta')->format('d/m/Y H:i') . " WIB)" : (!empty($data['last_date']) ? " ({$data['last_date']})" : ''));

        $riskInt = (int) round($maxRisk);
        $tfKeyboard = InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make($tf === '1H' ? '• 1H •' : '1H', callback_data: "tf:{$cleanSym}:1H:{$riskInt}"),
                InlineKeyboardButton::make($tf === '1D' ? '• 1D •' : '1D', callback_data: "tf:{$cleanSym}:1D:{$riskInt}"),
                InlineKeyboardButton::make($tf === '1W' ? '• 1W •' : '1W', callback_data: "tf:{$cleanSym}:1W:{$riskInt}"),
                InlineKeyboardButton::make($tf === '1M' ? '• 1M •' : '1M', callback_data: "tf:{$cleanSym}:1M:{$riskInt}")
            )
            ->addRow(
                InlineKeyboardButton::make('🤖 Proyeksi Kronos AI (5D)', callback_data: "forecast:{$cleanSym}")
            )
            ->addRow(
                InlineKeyboardButton::make('🔭 MTF', callback_data: "drill:mtf:{$cleanSym}"),
                InlineKeyboardButton::make('💎 Valuasi', callback_data: "drill:valuasi:{$cleanSym}"),
                InlineKeyboardButton::make('🧾 Audit', callback_data: "drill:audit:{$cleanSym}"),
                InlineKeyboardButton::make('🔔 Pantau', callback_data: "drill:pantau:{$cleanSym}")
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

// Simpan chat_id pengguna secara otomatis untuk laporan terjadwal
$bot->middleware(function (Nutgram $bot, $next) {
    $cid = $bot->chatId();
    if ($cid) {
        \Illuminate\Support\Facades\Cache::forever('telegram_owner_chat_id', (string) $cid);
    }
    $next($bot);
});

$bot->onCommand('start', function (Nutgram $bot) {
    $msg = "Halo! Saya <b>Easy Cuan</b>, bot analisis teknikal & fundamental saham BEI.\n\n"
        . "<b>📊 Teknikal & Trading Plan:</b>\n"
        . "📈 <code>/analisa BBCA</code> — Chart & Trading Plan (Risk Rp 1 Jt)\n"
        . "🧮 <code>/analisa BBCA 500000</code> — Chart & Custom Max Risk\n\n"
        . "<b>🔍 Screener Saham:</b>\n"
        . "🔎 <code>/screener</code> — Default (PER &lt; 10, PBV &lt; 5)\n"
        . "🎯 <code>/screener 8 1.5</code> — Custom PER &amp; PBV\n"
        . "🛡️ <code>/screener 10 2 1</code> — Custom PER, PBV &amp; DER\n"
        . "💰 <code>/dividen 10</code> — Khusus Dividen &gt;= 10%\n"
        . "🔥 <code>/volume</code> — Lonjakan Volume &gt;= 1.5x\n"
        . "🏦 <code>/akumulasi</code> — Deteksi Akumulasi (VSA/OBV)\n"
        . "📡 <code>/radar</code> — Bandar Intraday Sweep &amp; Unusual Volume (15m)\n"
        . "📉 <code>/topritel</code> — Screener Top Retail Jualan (Broker Summary)\n\n"
        . "<b>💎 Valuasi, Komparasi &amp; Audit:</b>\n"
        . "🌅 <code>/brief</code> — Morning Market Brief &amp; IHSG Macro Radar\n"
        . "🧭 <code>/sektor</code> — Radar Rotasi & Momentum 8 Sektor BEI\n"
        . "⚖️ <code>/banding BBRI BMRI BBCA</code> — Komparasi Head-to-Head\n"
        . "💎 <code>/valuasi BBRI</code> — Nilai Wajar &amp; Margin of Safety\n"
        . "🕵️ <code>/audit ASII</code> — Kualitas Laba &amp; Piotroski F-Score\n\n"
        . "<b>🔔 Watchlist &amp; Alert:</b>\n"
        . "📋 <code>/watchlist</code> | ➕ <code>/pantau BBRI</code> | ➖ <code>/unpantau BBRI</code>";

    $bot->sendMessage($msg, parse_mode: ParseMode::HTML);
});

// Satu route tunggal untuk /analisa KODE maupun /analisa KODE NOMINAL_RISK

$sendKronosForecast = function (Nutgram $bot, string $ticker, StockAnalysisService $service) {
    $ticker = strtoupper(trim($ticker));
    if (empty($ticker)) {
        $bot->sendMessage("⚠️ Masukkan kode saham. Contoh: <code>/forecast BBRI</code> atau <code>/ai BBCA</code>", parse_mode: ParseMode::HTML);
        return;
    }

    $cleanSym = str_replace('.JK', '', $ticker);
    $bot->sendMessage("🤖 Memproses proyeksi AI <b>Kronos</b> (5 hari bursa) untuk <b>{$cleanSym}</b>...", parse_mode: ParseMode::HTML);

    $forecast = $service->forecastWithKronos($ticker, 5);
    if (!$forecast || empty($forecast['steps'])) {
        $bot->sendMessage("❌ Gagal menjalankan proyeksi AI untuk <b>{$cleanSym}</b>. Pastikan kode saham valid dan service Kronos aktif.", parse_mode: ParseMode::HTML);
        return;
    }

    $dec = str_contains($forecast['symbol'], '-USD') ? 2 : 0;
    $lastFmt   = number_format($forecast['last_close'], $dec);
    $targetFmt = number_format($forecast['target_close'], $dec);
    $lowFmt    = number_format($forecast['range_low'], $dec);
    $highFmt   = number_format($forecast['range_high'], $dec);
    $chg       = $forecast['change_pct'];
    $chgSign   = $chg >= 0 ? '+' : '';

    $biasIcon = match ($forecast['bias']) {
        'BULLISH' => '🟢 BULLISH',
        'BEARISH' => '🔴 BEARISH',
        default   => '🟡 SIDEWAYS',
    };

    $stepsText = "";
    foreach ($forecast['steps'] as $idx => $step) {
        $dayNum = $idx + 1;
        $dtFmt  = date('d M', strtotime($step['date']));
        $cFmt   = number_format($step['close'], $dec);
        $lStep  = number_format($step['low'], $dec);
        $hStep  = number_format($step['high'], $dec);
        $stepsText .= "• H+{$dayNum} ({$dtFmt}): <b>Rp {$cFmt}</b> <i>({$lStep} – {$hStep})</i>\n";
    }

    $flowNote = "";
    try {
        $flow = app(StockScreenerService::class)->getStockBrokerSummary($cleanSym);
        if ($flow) {
            $sideIcon = $flow['is_retail_selling'] ? '🟢' : '🔴';
            $flowNote = "\n🏦 <b>Konfirmasi Broker Flow ({$flow['date_label']}):</b>\n"
                      . "• Status: <b>{$flow['accdist']}</b> | {$sideIcon} Ritel {$flow['retail_side']} <b>{$flow['retail_pct']}%</b>\n";
        }
    } catch (\Throwable $e) {}

    $msg = "🤖 <b>KRONOS AI FORECAST: {$cleanSym} (5D)</b>\n"
         . "━━━━━━━━━━━━━━━━━━━━\n"
         . "💰 Harga Terakhir: <b>Rp {$lastFmt}</b>\n"
         . "🎯 Proyeksi H+5: <b>Rp {$targetFmt} ({$chgSign}{$chg}%)</b>\n"
         . "🧭 Arah Bias AI: <b>{$biasIcon}</b>\n"
         . "📊 Rentang 5 Hari: <b>Rp {$lowFmt} – Rp {$highFmt}</b>\n\n"
         . "📅 <b>Estimasi Lintasan Harian:</b>\n"
         . $stepsText
         . $flowNote
         . "\n<i>⚠️ Proyeksi probabilistik berbasis foundation model Kronos-small, gunakan bersama level R1/S1 &amp; manajemen risiko.</i>";

    $kb = \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup::make()
        ->addRow(
            \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make('📊 Buka Chart & Teknikal', callback_data: "analisa:{$cleanSym}"),
            \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make('💎 Cek Valuasi', callback_data: "valuasi:{$cleanSym}")
        );

    $chartPath = $service->generateKronosForecastChart($forecast);
    if ($chartPath && file_exists($chartPath)) {
        try {
            $bot->sendPhoto(
                photo: InputFile::make($chartPath),
                caption: $msg,
                parse_mode: ParseMode::HTML,
                reply_markup: $kb
            );
            @unlink($chartPath);
            return;
        } catch (\Throwable $e) {
            @unlink($chartPath);
        }
    }

    $bot->sendMessage($msg, parse_mode: ParseMode::HTML, reply_markup: $kb);
};

$bot->onCommand('forecast {ticker}', function (Nutgram $bot, string $ticker, StockAnalysisService $service) use ($sendKronosForecast) {
    $sendKronosForecast($bot, $ticker, $service);
});

$bot->onCommand('forecast', function (Nutgram $bot) {
    $bot->sendMessage("💡 Gunakan format: <code>/forecast KODE</code> atau <code>/ai KODE</code>\nContoh: <code>/forecast BBRI</code>", parse_mode: ParseMode::HTML);
})->description('Proyeksi harga 5 hari ke depan dengan Kronos AI (Contoh: /forecast BBRI)');

$bot->onCommand('ai {ticker}', function (Nutgram $bot, string $ticker, StockAnalysisService $service) use ($sendKronosForecast) {
    $sendKronosForecast($bot, $ticker, $service);
});

$bot->onCommand('ai', function (Nutgram $bot) {
    $bot->sendMessage("💡 Gunakan format: <code>/ai KODE</code> atau <code>/forecast KODE</code>\nContoh: <code>/ai BBCA</code>", parse_mode: ParseMode::HTML);
});

$bot->onCallbackQueryData('forecast:{ticker}', function (Nutgram $bot, string $ticker, StockAnalysisService $service) use ($sendKronosForecast) {
    $bot->answerCallbackQuery(text: "Menjalankan Kronos AI untuk {$ticker}...");
    $sendKronosForecast($bot, $ticker, $service);
});

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

$bot->onCallbackQueryData('analisa:{ticker}', function (Nutgram $bot, string $ticker, StockAnalysisService $service) use ($sendAnalysisChart) {
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
    $commands = [
        new \SergiX44\Nutgram\Telegram\Types\Command\BotCommand('brief', 'Morning Market Brief & IHSG Macro Radar'),
        new \SergiX44\Nutgram\Telegram\Types\Command\BotCommand('analisa', 'Analisa teknikal, chart & trading plan'),
        new \SergiX44\Nutgram\Telegram\Types\Command\BotCommand('forecast', 'Proyeksi harga 5 hari ke depan dengan Kronos AI'),
        new \SergiX44\Nutgram\Telegram\Types\Command\BotCommand('screener', 'Screener saham (Uptrend, Oversold, Breakout)'),
        new \SergiX44\Nutgram\Telegram\Types\Command\BotCommand('dividen', 'Screener saham dividen yield tinggi'),
        new \SergiX44\Nutgram\Telegram\Types\Command\BotCommand('volume', 'Deteksi lonjakan volume hari ini'),
        new \SergiX44\Nutgram\Telegram\Types\Command\BotCommand('akumulasi', 'Deteksi saham akumulasi bandar'),
        new \SergiX44\Nutgram\Telegram\Types\Command\BotCommand('topritel', 'Screener Top Retail Jualan (Broker Summary)'),
        new \SergiX44\Nutgram\Telegram\Types\Command\BotCommand('settoken', 'Update Bearer Token Stockbit'),
        new \SergiX44\Nutgram\Telegram\Types\Command\BotCommand('banding', 'Komparasi head-to-head emiten satu sektor'),
        new \SergiX44\Nutgram\Telegram\Types\Command\BotCommand('valuasi', 'Nilai wajar intrinsik & Margin of Safety'),
        new \SergiX44\Nutgram\Telegram\Types\Command\BotCommand('audit', 'Audit kualitas laba & Piotroski F-Score'),
        new \SergiX44\Nutgram\Telegram\Types\Command\BotCommand('watchlist', 'Lihat daftar saham yang dipantau'),
        new \SergiX44\Nutgram\Telegram\Types\Command\BotCommand('pantau', 'Tambah saham ke watchlist alert'),
        new \SergiX44\Nutgram\Telegram\Types\Command\BotCommand('unpantau', 'Hapus saham dari watchlist'),
    ];
    $bot->deleteMyCommands();
    $bot->setMyCommands($commands);
    $bot->setMyCommands($commands, scope: new \SergiX44\Nutgram\Telegram\Types\Command\BotCommandScopeAllPrivateChats());
    $bot->sendMessage("✅ Menu perintah (/) berhasil diperbarui!");
});


$bot->onCallbackQueryData('tf:{ticker}:{tf}:{risk}', function (Nutgram $bot, string $ticker, string $tf, string $risk) use ($sendAnalysisChart, $formatBrokerFlowBlock) {
    $service = app(StockAnalysisService::class);
    $tf = strtoupper(trim($tf));
    $cleanSym = strtoupper(trim($ticker));
    $maxRisk = (float) $risk > 0 ? (float) $risk : 1000000;

    $bot->answerCallbackQuery(text: "⏳ Memuat chart {$cleanSym} ({$tf})...");

    try {
        $data = $service->analyze($cleanSym, $maxRisk, $tf);

        $trend = "🟡 Sideways / Konsolidasi";
        if ($data['ma200'] && $data['price'] > $data['ma50'] && $data['ma50'] > $data['ma200']) {
            $trend = "🟢 Strong Uptrend (Harga &gt; MA50 &gt; MA200)";
        } elseif ($data['price'] > $data['ma50'] && (!$data['ma200'] || $data['price'] < $data['ma200'])) {
            $trend = "🟢 Short-Term Uptrend (Above MA50" . ($data['ma200'] ? ", Below MA200" : "") . ")";
        } elseif ($data['ma200'] && $data['price'] < $data['ma50'] && $data['ma50'] > $data['ma200']) {
            $trend = "🟡 Pullback in Uptrend (Below MA50, Above MA200)";
        } elseif ($data['ma200'] && $data['price'] < $data['ma50'] && $data['ma50'] < $data['ma200']) {
            $trend = "🔴 Strong Downtrend (Harga &lt; MA50 &lt; MA200)";
        } elseif ($data['price'] < $data['ma50']) {
            $trend = "🔴 Short-Term Downtrend (Below MA50)";
        }

        $ma200Text = $data['ma200'] ? number_format($data['ma200'], str_ends_with($data['symbol'], ".JK") ? 0 : 2) : 'Data &lt; 200 period';
        $sign = $data['change_pct'] >= 0 ? '+' : '';

        $caption = "📊 <b>ANALISIS SAHAM: {$cleanSym} [{$tf}]</b>\n" .
                   "━━━━━━━━━━━━━━━━━━━━\n" .
                   "💰 <b>Harga Terakhir:</b> " . number_format($data['price'], str_ends_with($data['symbol'], ".JK") ? 0 : 2) . " ({$sign}{$data['change_pct']}%)\n" .
                   "🔵 <b>MA 50:</b> " . number_format($data['ma50'], str_ends_with($data['symbol'], ".JK") ? 0 : 2) . "\n" .
                   "🔴 <b>MA 200:</b> {$ma200Text}\n" .
                   "📈 <b>Status Tren:</b> {$trend}" .
                   $formatBrokerFlowBlock($cleanSym) . "\n📡 <b>Sumber:</b> " . ((($data['data_source'] ?? 'Yahoo Finance') === 'Stockbit RT') ? '🟢 Stockbit Real-Time' : '🟡 Yahoo Finance (15m delay)') . ((($data['data_source'] ?? '') === 'Stockbit RT') ? " (" . now('Asia/Jakarta')->format('d/m/Y H:i') . " WIB)" : (!empty($data['last_date']) ? " ({$data['last_date']})" : '')) . "\n";

        $riskInt = (int) round($maxRisk);
        $tfKeyboard = InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make($tf === '1H' ? '• 1H •' : '1H', callback_data: "tf:{$cleanSym}:1H:{$riskInt}"),
                InlineKeyboardButton::make($tf === '1D' ? '• 1D •' : '1D', callback_data: "tf:{$cleanSym}:1D:{$riskInt}"),
                InlineKeyboardButton::make($tf === '1W' ? '• 1W •' : '1W', callback_data: "tf:{$cleanSym}:1W:{$riskInt}"),
                InlineKeyboardButton::make($tf === '1M' ? '• 1M •' : '1M', callback_data: "tf:{$cleanSym}:1M:{$riskInt}")
            )
            ->addRow(
                InlineKeyboardButton::make('🤖 Proyeksi Kronos AI (5D)', callback_data: "forecast:{$cleanSym}")
            )
            ->addRow(
                InlineKeyboardButton::make('🔭 MTF', callback_data: "drill:mtf:{$cleanSym}"),
                InlineKeyboardButton::make('💎 Valuasi', callback_data: "drill:valuasi:{$cleanSym}"),
                InlineKeyboardButton::make('🧾 Audit', callback_data: "drill:audit:{$cleanSym}"),
                InlineKeyboardButton::make('🔔 Pantau', callback_data: "drill:pantau:{$cleanSym}")
            );

        $bot->sendPhoto(
            photo: InputFile::make($data['chart_path']),
            caption: $caption,
            parse_mode: ParseMode::HTML,
            reply_markup: $tfKeyboard
        );
        $bot->answerCallbackQuery();

        @unlink($data['chart_path']);
    } catch (\Exception $e) {
        $bot->sendMessage("❌ Gagal memuat timeframe {$tf}: " . htmlspecialchars($e->getMessage()), parse_mode: ParseMode::HTML);
    }
});

// Komparasi Head-to-Head Satu Sektor (/banding BBRI BMRI BBCA BBNI)
$runBanding = function (Nutgram $bot, string $tickers) {
    $bot->sendMessage('⏳ Mengambil data fundamental & membandingkan emiten...');

    try {
        $screenerService = app(\App\Services\StockScreenerService::class);
        $rawList = preg_split('/[\s,]+/', trim($tickers), -1, PREG_SPLIT_NO_EMPTY);
        $items = $screenerService->compareStocks($rawList);

        if (count($items) < 2) {
            $bot->sendMessage('❌ Data valid kurang dari 2 emiten. Pastikan kode saham BEI benar (contoh: <code>/banding BBRI BMRI BBCA BBNI</code>).', parse_mode: ParseMode::HTML);
            return;
        }

        $validPers = array_filter(array_column($items, 'per'), fn($v) => $v !== null && $v > 0);
        $bestPer = !empty($validPers) ? min($validPers) : null;

        $validPbvs = array_filter(array_column($items, 'pbv'), fn($v) => $v !== null && $v > 0);
        $bestPbv = !empty($validPbvs) ? min($validPbvs) : null;

        $validRoes = array_filter(array_column($items, 'roe'), fn($v) => $v !== null);
        $bestRoe = !empty($validRoes) ? max($validRoes) : null;

        $validNpms = array_filter(array_column($items, 'npm'), fn($v) => $v !== null);
        $bestNpm = !empty($validNpms) ? max($validNpms) : null;

        $validDivs = array_filter(array_column($items, 'dividend_yield'), fn($v) => $v !== null);
        $bestDiv = !empty($validDivs) ? max($validDivs) : null;

        $msg = "⚖️ <b>KOMPARASI HEAD-TO-HEAD EMITEN</b>\n";
        $msg .= "━━━━━━━━━━━━━━━━━━━━\n\n";

        $keyboard = InlineKeyboardMarkup::make();
        $btnRow = [];

        foreach ($items as $idx => $s) {
            $rank = $idx + 1;
            $medal = $rank === 1 ? '🥇' : ($rank === 2 ? '🥈' : ($rank === 3 ? '🥉' : "#{$rank}"));

            $sym = $s['symbol'];
            $score = $s['score'];
            $capFmt = $s['market_cap_formatted'];
            $priceFmt = number_format($s['price'], 0);

            $perStr = $s['per'] !== null ? $s['per'] . 'x' . ($s['per'] === $bestPer ? ' 👑' : '') : 'N/A';
            $pbvStr = $s['pbv'] !== null ? $s['pbv'] . 'x' . ($s['pbv'] === $bestPbv ? ' 👑' : '') : 'N/A';
            $roeStr = $s['roe'] !== null ? $s['roe'] . '%' . ($s['roe'] === $bestRoe ? ' 👑' : '') : 'N/A';
            $npmStr = $s['npm'] !== null ? $s['npm'] . '%' . ($s['npm'] === $bestNpm ? ' 👑' : '') : 'N/A';
            $derStr = $s['der'] !== null ? $s['der'] . 'x' : 'N/A';
            $divStr = $s['dividend_yield'] !== null ? $s['dividend_yield'] . '%' . ($s['dividend_yield'] === $bestDiv ? ' 👑' : '') : '-';
            $earnStr = $s['earn_growth'] !== null ? (($s['earn_growth'] >= 0 ? '+' : '') . $s['earn_growth'] . '%') : 'N/A';

            $msg .= "{$medal} <b>{$sym}</b> — Rp {$priceFmt} (Skor: <b>{$score}/100</b>)\n";
            $msg .= "   • Cap: {$capFmt} | DER: {$derStr}\n";
            $msg .= "   • Valuasi: PER <b>{$perStr}</b> | PBV <b>{$pbvStr}</b>\n";
            $msg .= "   • Profit: ROE <b>{$roeStr}</b> | NPM <b>{$npmStr}</b>\n";
            $msg .= "   • Laba YoY: {$earnStr} | Dividen: <b>{$divStr}</b>\n\n";

            $btnRow[] = InlineKeyboardButton::make("📊 {$sym}", callback_data: "chart:{$sym}");
            if (count($btnRow) === 3) {
                $keyboard->addRow(...$btnRow);
                $btnRow = [];
            }
        }

        if (!empty($btnRow)) {
            $keyboard->addRow(...$btnRow);
        }

        $winner = $items[0];
        $winSym = $winner['symbol'];
        $winScore = $winner['score'];
        $msg .= "🏆 <b>Unggul Keseluruhan: {$winSym}</b> (Skor {$winScore}/100)\n";
        $msg .= "<i>👑 = Metrik terbaik di grup perbandingan ini.</i>";

        $bot->sendMessage($msg, parse_mode: ParseMode::HTML, reply_markup: $keyboard);
    } catch (\Exception $e) {
        $bot->sendMessage('❌ Gagal membandingkan saham: ' . htmlspecialchars($e->getMessage()), parse_mode: ParseMode::HTML);
    }
};

$helpBanding = function (Nutgram $bot) {
    $bot->sendMessage(
        "⚖️ <b>Format Komparasi Emiten:</b>\n" .
        "Ketik <code>/banding KODE1 KODE2 [KODE3 KODE4 KODE5]</code>\n\n" .
        "<b>Contoh Sektor Perbankan:</b>\n" .
        "<code>/banding BBRI BMRI BBCA BBNI</code>\n\n" .
        "<b>Contoh Sektor Batu Bara:</b>\n" .
        "<code>/banding ADRO PTBA ITMG</code>",
        parse_mode: ParseMode::HTML
    );
};

$bot->onCommand('banding {tickers}', $runBanding);
$bot->onCommand('Banding {tickers}', $runBanding);
$bot->onCommand('banding', $helpBanding);
$bot->onCommand('Banding', $helpBanding);

// Kalkulator Nilai Wajar & Margin of Safety (/valuasi BBRI)
$runValuasi = function (Nutgram $bot, string $ticker) {
    $cleanSym = preg_replace('/JK$/', '', strtoupper(trim(preg_replace('/[^A-Za-z0-9]/', '', $ticker))));
    $bot->sendMessage("⏳ Menghitung nilai wajar intrinsik {$cleanSym}...");

    try {
        $screener = app(\App\Services\StockScreenerService::class);
        $v = $screener->calculateValuation($cleanSym);

        $priceFmt = number_format($v['price'], 0);
        $fairFmt = number_format($v['fair_value'], 0);
        $m1Fmt = $v['m1_price'] ? 'Rp ' . number_format($v['m1_price'], 0) : 'N/A';
        $m2Fmt = $v['m2_price'] ? 'Rp ' . number_format($v['m2_price'], 0) : 'N/A';
        $m3Fmt = $v['m3_price'] ? 'Rp ' . number_format($v['m3_price'], 0) : 'N/A';
        $mosSign = $v['mos'] >= 0 ? '+' : '';
        $upSign = $v['upside'] >= 0 ? '+' : '';
        $sectorTag = !empty($v['is_financial']) ? ' [Model Perbankan]' : '';

        $msg = "💎 <b>VALUASI & MARGIN OF SAFETY: {$v['symbol']}</b>{$sectorTag}\n";
        $msg .= "━━━━━━━━━━━━━━━━━━━━\n";
        $msg .= "💰 <b>Harga Pasar:</b> Rp {$priceFmt}\n";
        $msg .= "🎯 <b>Harga Wajar (Konsensus):</b> Rp <b>{$fairFmt}</b>\n";
        $msg .= "🛡️ <b>Margin of Safety (MoS):</b> <b>{$mosSign}{$v['mos']}%</b> (Potensi: {$upSign}{$v['upside']}%)\n";
        $msg .= "📌 <b>Status:</b> {$v['verdict']}\n\n";
        $msg .= "<b>Rincian 3 Metode Valuasi:</b>\n";
        $msg .= "• {$v['m1_label']}: <b>{$m1Fmt}</b>\n";
        $msg .= "• {$v['m2_label']}: <b>{$m2Fmt}</b>\n";
        $msg .= "• {$v['m3_label']}: <b>{$m3Fmt}</b>\n\n";
        $msg .= "<i>Data Dasar: EPS Rp " . ($v['eps'] ?? '-') . " | BVPS Rp " . ($v['bvps'] ?? '-') . " | PER " . ($v['per'] ?? '-') . "x | PBV " . ($v['pbv'] ?? '-') . "x</i>";

        $keyboard = InlineKeyboardMarkup::make()->addRow(
            InlineKeyboardButton::make("🔍 Audit {$v['symbol']}", callback_data: "audit:{$v['symbol']}"),
            InlineKeyboardButton::make("📊 Chart {$v['symbol']}", callback_data: "chart:{$v['symbol']}")
        );

        $bot->sendMessage($msg, parse_mode: ParseMode::HTML, reply_markup: $keyboard);
    } catch (\Exception $e) {
        $bot->sendMessage('❌ ' . htmlspecialchars($e->getMessage()), parse_mode: ParseMode::HTML);
    }
};

$helpValuasi = function (Nutgram $bot) {
    $bot->sendMessage("💎 Gunakan format: <code>/valuasi KODE</code> (contoh: <code>/valuasi BBRI</code>)", parse_mode: ParseMode::HTML);
};

$bot->onCommand('valuasi {ticker}', $runValuasi);
$bot->onCommand('Valuasi {ticker}', $runValuasi);
$bot->onCommand('valuasi', $helpValuasi);
$bot->onCommand('Valuasi', $helpValuasi);

// Audit Kualitas Laba & Piotroski F-Score (/audit ASII)
$runAudit = function (Nutgram $bot, string $ticker) {
    $cleanSym = preg_replace('/JK$/', '', strtoupper(trim(preg_replace('/[^A-Za-z0-9]/', '', $ticker))));
    $bot->sendMessage("⏳ Mengaudit kualitas laba & Piotroski F-Score {$cleanSym}...");

    try {
        $screener = app(\App\Services\StockScreenerService::class);
        $a = $screener->auditFundamentals($cleanSym);

        $priceFmt = number_format($a['price'], 0);
        $ccrText = $a['ccr'] !== null ? "{$a['ccr']}x" : 'N/A';

        $msg = "🕵️ <b>AUDIT KUALITAS LABA & F-SCORE: {$a['symbol']}</b>\n";
        $msg .= "━━━━━━━━━━━━━━━━━━━━\n";
        $msg .= "💰 <b>Harga Terakhir:</b> Rp {$priceFmt}\n";
        $msg .= "💵 <b>Cash Conversion (OCF/Laba):</b> <b>{$ccrText}</b>\n";
        $msg .= "   ↳ {$a['ccr_status']}\n\n";
        $msg .= "🏆 <b>Piotroski F-Score: {$a['f_score']} / 9</b>\n";
        $msg .= "📌 {$a['f_verdict']}\n\n";
        $msg .= "<b>Checklist 9 Kriteria Audit:</b>\n";

        foreach ($a['checks'] as $chk) {
            $icon = $chk['pass'] ? '✅' : '❌';
            $msg .= "{$icon} {$chk['label']}\n";
        }

        $keyboard = InlineKeyboardMarkup::make()->addRow(
            InlineKeyboardButton::make("💎 Valuasi {$a['symbol']}", callback_data: "valuasi:{$a['symbol']}"),
            InlineKeyboardButton::make("📊 Chart {$a['symbol']}", callback_data: "chart:{$a['symbol']}")
        );

        $bot->sendMessage($msg, parse_mode: ParseMode::HTML, reply_markup: $keyboard);
    } catch (\Exception $e) {
        $bot->sendMessage('❌ ' . htmlspecialchars($e->getMessage()), parse_mode: ParseMode::HTML);
    }
};

$helpAudit = function (Nutgram $bot) {
    $bot->sendMessage("🕵️ Gunakan format: <code>/audit KODE</code> (contoh: <code>/audit ASII</code>)", parse_mode: ParseMode::HTML);
};

$bot->onCommand('audit {ticker}', $runAudit);
$bot->onCommand('Audit {ticker}', $runAudit);
$bot->onCommand('audit', $helpAudit);
$bot->onCommand('Audit', $helpAudit);

// Callback tombol inline untuk lompat cepat antar Valuasi & Audit
$bot->onCallbackQueryData('valuasi:{ticker}', function (Nutgram $bot, string $ticker) use ($runValuasi) {
    $bot->answerCallbackQuery();
    $runValuasi($bot, $ticker);
});

$bot->onCallbackQueryData('audit:{ticker}', function (Nutgram $bot, string $ticker) use ($runAudit) {
    $bot->answerCallbackQuery();
    $runAudit($bot, $ticker);
});

// Radar Rotasi Sektor IDX (/sektor)
$runSektor = function (Nutgram $bot) {
    $bot->sendMessage('⏳ Memindai rotasi aliran dana & momentum 8 sektor utama BEI...');

    try {
        $screener = app(\App\Services\StockScreenerService::class);
        $sectors = $screener->getSectorRotation();

        if (empty($sectors)) {
            $bot->sendMessage('❌ Gagal mengambil data pergerakan sektor saat ini.');
            return;
        }

        $fmtPct = fn(float $v): string => ($v >= 0 ? '+' : '') . number_format($v, 2) . '%';

        $msg = "🧭 <b>RADAR ROTASI SEKTOR IDX</b>\n";
        $msg .= "━━━━━━━━━━━━━━━━━━━━\n\n";

        foreach ($sectors as $idx => $sec) {
            $rank = $idx + 1;
            $medal = $rank === 1 ? '🥇' : ($rank === 2 ? '🥈' : ($rank === 3 ? '🥉' : "#{$rank}"));
            $leader = $sec['leader'];
            $leadPct = $fmtPct($leader['chg_1d']);

            $msg .= "{$medal} <b>{$sec['sector']}</b>\n";
            $msg .= "   • Fase: {$sec['phase']}\n";
            $msg .= "   • Perf: 1D <b>{$fmtPct($sec['avg_1d'])}</b> | 1W <b>{$fmtPct($sec['avg_1w'])}</b> | 1M {$fmtPct($sec['avg_1m'])}\n";
            $msg .= "   • Leader: <b>{$leader['symbol']}</b> ({$leadPct})\n\n";
        }

        $topSector = $sectors[0];
        $topMembers = array_column($topSector['members'], 'symbol');
        $topLeaderSym = $topSector['leader']['symbol'];
        $secondLeaderSym = $sectors[1]['leader']['symbol'] ?? null;

        $improvingList = array_values(array_filter($sectors, fn($s) => str_contains($s['phase'], 'IMPROVING')));
        $msg .= "💡 <b>Fokus Inflow:</b> <b>{$topSector['sector']}</b> (Leader: <b>{$topLeaderSym}</b>)";
        if (!empty($improvingList) && $improvingList[0]['sector'] !== $topSector['sector']) {
            $impSec = $improvingList[0]['sector'];
            $impLead = $improvingList[0]['leader']['symbol'];
            $msg .= "\n🌱 <b>Awal Rotasi (Rebound):</b> <b>{$impSec}</b> (Leader: <b>{$impLead}</b>)";
        }

        $keyboard = InlineKeyboardMarkup::make();
        $row1 = [
            InlineKeyboardButton::make("📊 Chart {$topLeaderSym}", callback_data: "chart:{$topLeaderSym}"),
        ];
        if ($secondLeaderSym) {
            $row1[] = InlineKeyboardButton::make("📊 Chart {$secondLeaderSym}", callback_data: "chart:{$secondLeaderSym}");
        }
        $keyboard->addRow(...$row1);

        if (count($topMembers) >= 2) {
            $tickersStr = implode(' ', array_slice($topMembers, 0, 4));
            $keyboard->addRow(
                InlineKeyboardButton::make("⚖️ Bandingkan {$topSector['sector']}", callback_data: "banding_list:{$tickersStr}")
            );
        }

        $bot->sendMessage($msg, parse_mode: ParseMode::HTML, reply_markup: $keyboard);
    } catch (\Exception $e) {
        $bot->sendMessage('❌ Gagal memuat radar sektor: ' . htmlspecialchars($e->getMessage()), parse_mode: ParseMode::HTML);
    }
};

$bot->onCommand('sektor', $runSektor);
$bot->onCommand('Sektor', $runSektor);

$bot->onCallbackQueryData('banding_list:{tickers}', function (Nutgram $bot, string $tickers) use ($runBanding) {
    $bot->answerCallbackQuery();
    $runBanding($bot, $tickers);
});

// Morning Market Brief & IHSG Macro Radar (/brief)
$runBrief = function (Nutgram $bot) {
    $bot->sendMessage('⏳ Mengambil data IHSG, kurs USD/IDR, indeks global & komoditas...');

    try {
        $briefService = app(\App\Services\MarketBriefService::class);
        $brief = $briefService->generateBrief();
        $msg = $briefService->formatTelegramMessage($brief);

        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make("🧭 Radar Sektor", callback_data: "quick:sektor"),
                InlineKeyboardButton::make("🏦 Akumulasi Bandar", callback_data: "quick:akumulasi")
            );

        $bot->sendMessage($msg, parse_mode: ParseMode::HTML, reply_markup: $keyboard);
    } catch (\Exception $e) {
        $bot->sendMessage('❌ Gagal memuat Morning Brief: ' . htmlspecialchars($e->getMessage()), parse_mode: ParseMode::HTML);
    }
};

$bot->onCommand('brief', $runBrief);
$bot->onCommand('Brief', $runBrief);

$bot->onCallbackQueryData('quick:sektor', function (Nutgram $bot) use ($runSektor) {
    $bot->answerCallbackQuery();
    $runSektor($bot);
});

// Callback tombol quick:akumulasi dari Morning Brief
$bot->onCallbackQueryData('quick:akumulasi', function (\SergiX44\Nutgram\Nutgram $bot) {
    $bot->answerCallbackQuery(text: 'Memindai akumulasi bandar...');
    $bot->sendMessage('⏳ Memindai saham dengan indikasi akumulasi bandar (VSA/OBV)...');

    try {
        $screener = app(\App\Services\StockScreenerService::class);
        // Cek nama method akumulasi yang tersedia di StockScreenerService
        $method = method_exists($screener, 'scanAccumulation') ? 'scanAccumulation'
            : (method_exists($screener, 'getAccumulationStocks') ? 'getAccumulationStocks'
            : (method_exists($screener, 'screenAccumulation') ? 'screenAccumulation' : null));

        if (!$method) {
            throw new \Exception('Method screener akumulasi tidak ditemukan di StockScreenerService.');
        }

        $results = $screener->$method();

        if (empty($results)) {
            $bot->sendMessage('ℹ️ Belum ditemukan saham dengan pola akumulasi kuat hari ini.');
            return;
        }

        $msg = "🏦 <b>DETEKSI AKUMULASI BANDAR (VSA &amp; OBV)</b>\n";
        $msg .= "━━━━━━━━━━━━━━━━━━━━\n\n";

        foreach (array_slice($results, 0, 10) as $idx => $item) {
            $no = $idx + 1;
            $sym = $item['symbol'] ?? '-';
            $price = isset($item['price']) ? 'Rp ' . number_format($item['price'], 0) : '-';
            $chg = isset($item['change_pct']) ? (($item['change_pct'] >= 0 ? '+' : '') . number_format($item['change_pct'], 2) . '%')
                : (isset($item['chg_pct']) ? (($item['chg_pct'] >= 0 ? '+' : '') . number_format($item['chg_pct'], 2) . '%') : '');
            $volRatio = $item['vol_ratio'] ?? ($item['volume_ratio'] ?? '-');
            $signal = $item['signal'] ?? ($item['pattern'] ?? 'Akumulasi Terdeteksi');

            $msg .= "{$no}. <b>{$sym}</b> — {$price} ({$chg})\n";
            $msg .= "   • Vol Ratio: <b>{$volRatio}x</b> | {$signal}\n\n";
        }

        $bot->sendMessage($msg, parse_mode: \SergiX44\Nutgram\Telegram\Properties\ParseMode::HTML);
    } catch (\Exception $e) {
        $bot->sendMessage('❌ Gagal memindai akumulasi: ' . htmlspecialchars($e->getMessage()), parse_mode: \SergiX44\Nutgram\Telegram\Properties\ParseMode::HTML);
    }
});

// Bandar Tape-Reading & Intraday Unusual Activity (/radar)
$runRadar = function (Nutgram $bot) {
    $bot->sendMessage('⏳ Memindai aktivitas intraday (15m) & unusual volume sweep di BEI...');

    try {
        $screener = app(\App\Services\StockScreenerService::class);
        $items = $screener->scanIntradayRadar();
        $nowWib = now('Asia/Jakarta')->format('H:i') . ' WIB';

        if (empty($items)) {
            $bot->sendMessage(
                "📡 <b>INTRADAY UNUSUAL RADAR ({$nowWib})</b>\n" .
                "━━━━━━━━━━━━━━━━━━━━\n\n" .
                "ℹ️ Belum terdeteksi lonjakan volume intraday abnormal (Vol &gt;= 1.6x + Strong Close) saat ini.",
                parse_mode: ParseMode::HTML
            );
            return;
        }

        $msg = "📡 <b>INTRADAY UNUSUAL RADAR (15m)</b>\n";
        $msg .= "🕒 <i>Scan: {$nowWib}</i>\n";
        $msg .= "━━━━━━━━━━━━━━━━━━━━\n\n";

        $keyboard = InlineKeyboardMarkup::make();
        $chartBtns = [];

        foreach ($items as $idx => $it) {
            $no = $idx + 1;
            $priceFmt = number_format($it['price'], 0);
            $daySign = $it['day_chg'] >= 0 ? '+' : '';
            $cSign = $it['candle_chg'] >= 0 ? '+' : '';

            $msg .= "{$no}. <b>{$it['symbol']}</b> — Rp <b>{$priceFmt}</b> (Day: {$daySign}{$it['day_chg']}%)\n";
            $msg .= "   • {$it['tag']}\n";
            $msg .= "   • Intraday Vol: <b>{$it['vol_ratio']}x</b> | CLV: <b>{$it['clv']}%</b> | 15m: {$cSign}{$it['candle_chg']}%\n\n";

            if ($idx < 3) {
                $chartBtns[] = InlineKeyboardButton::make("📊 Chart {$it['symbol']}", callback_data: "chart:{$it['symbol']}");
            }
        }

        if (!empty($chartBtns)) {
            $keyboard->addRow(...$chartBtns);
        }
        $keyboard->addRow(InlineKeyboardButton::make("🔄 Refresh Radar", callback_data: "quick:radar"));

        $bot->sendMessage($msg, parse_mode: ParseMode::HTML, reply_markup: $keyboard);
    } catch (\Throwable $e) {
        $bot->sendMessage('❌ Gagal memindai radar intraday: ' . htmlspecialchars($e->getMessage()), parse_mode: ParseMode::HTML);
    }
};

$bot->onCommand('radar', $runRadar);
$bot->onCommand('Radar', $runRadar);

$bot->onCallbackQueryData('quick:radar', function (Nutgram $bot) use ($runRadar) {
    $bot->answerCallbackQuery();
    \Illuminate\Support\Facades\Cache::forget('idx_intraday_radar_v4');
    $runRadar($bot);
});

// Multi-Timeframe Confluence & Trend Alignment (/mtf)
$runMtf = function (\SergiX44\Nutgram\Nutgram $bot, string $ticker) {
    $sym = strtoupper(trim($ticker));
    $bot->sendMessage("⏳ Mengaudit konfluensi tren 4 timeframe (1H, 1D, 1W, 1M) untuk <b>{$sym}</b>...", parse_mode: \SergiX44\Nutgram\Telegram\Properties\ParseMode::HTML);

    try {
        $mtfService = app(\App\Services\MultiTimeframeService::class);
        $data = $mtfService->analyze($sym);
        $priceFmt = number_format($data['price'], 0);

        $msg = "🔭 <b>MULTI-TIMEFRAME CONFLUENCE: {$data['symbol']}</b>\n";
        $msg .= "💵 <b>Harga Terakhir:</b> Rp <b>{$priceFmt}</b>\n";
        $msg .= "━━━━━━━━━━━━━━━━━━━━\n\n";

        foreach (['1H', '1D', '1W', '1M'] as $tfKey) {
            $tf = $data['timeframes'][$tfKey] ?? null;
            if (!$tf) {
                $msg .= "• <b>[{$tfKey}]</b>: Data tidak tersedia\n\n";
                continue;
            }

            $ema20Fmt = number_format($tf['ema20'], 0);
            $ema50Fmt = number_format($tf['ema50'], 0);
            $macdSign = $tf['macd_hist'] >= 0 ? '+' : '';

            $msg .= "<b>[{$tf['tf']}] {$tf['label']}</b>\n";
            $msg .= "   • Status: {$tf['status']}\n";
            $msg .= "   • EMA20: {$ema20Fmt} | EMA50: {$ema50Fmt} | RSI: <b>{$tf['rsi']}</b> | MACD: {$macdSign}{$tf['macd_hist']}\n\n";
        }

        $msg .= "━━━━━━━━━━━━━━━━━━━━\n";
        $msg .= "🎯 <b>Skor Konfluensi:</b> <b>{$data['verdict']}</b>\n";
        $msg .= "💡 <b>Strategi:</b> {$data['advice']}";

        $keyboard = \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup::make()
            ->addRow(
                \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make("📊 Chart {$data['symbol']}", callback_data: "chart:{$data['symbol']}"),
                \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make("💎 Valuasi {$data['symbol']}", callback_data: "valuasi:{$data['symbol']}")
            );

        $bot->sendMessage($msg, parse_mode: \SergiX44\Nutgram\Telegram\Properties\ParseMode::HTML, reply_markup: $keyboard);
    } catch (\Throwable $e) {
        $bot->sendMessage('❌ Gagal analisa MTF: ' . htmlspecialchars($e->getMessage()), parse_mode: \SergiX44\Nutgram\Telegram\Properties\ParseMode::HTML);
    }
};

$helpMtf = function (\SergiX44\Nutgram\Nutgram $bot) {
    $bot->sendMessage(
        "🔭 <b>Multi-Timeframe Confluence (1H, 1D, 1W, 1M)</b>\n" .
        "Format: <code>/mtf KODE</code>\n" .
        "Contoh: <code>/mtf BBCA</code>",
        parse_mode: \SergiX44\Nutgram\Telegram\Properties\ParseMode::HTML
    );
};

$bot->onCommand('mtf {ticker}', $runMtf);
$bot->onCommand('Mtf {ticker}', $runMtf);
$bot->onCommand('mtf', $helpMtf);
$bot->onCommand('Mtf', $helpMtf);

// Callback tombol drill-down dari hasil /analisa
$bot->onCallbackQueryData('drill:mtf:{ticker}', function (Nutgram $bot, string $ticker) use ($runMtf) {
    $bot->answerCallbackQuery();
    $runMtf($bot, $ticker);
});

$bot->onCallbackQueryData('drill:valuasi:{ticker}', function (Nutgram $bot, string $ticker) use ($runValuasi) {
    $bot->answerCallbackQuery();
    $runValuasi($bot, $ticker);
});

$bot->onCallbackQueryData('drill:audit:{ticker}', function (Nutgram $bot, string $ticker) use ($runAudit) {
    $bot->answerCallbackQuery();
    $runAudit($bot, $ticker);
});

$bot->onCallbackQueryData('drill:pantau:{ticker}', function (Nutgram $bot, string $ticker) {
    $symbol = strtoupper(preg_replace('/[^a-zA-Z0-9.]/', '', trim($ticker)));
    $userId = $bot->userId();

    try {
        $exists = Watchlist::where('telegram_id', $userId)->where('symbol', $symbol)->exists();
        if ($exists) {
            $bot->answerCallbackQuery(text: "ℹ️ {$symbol} sudah ada di watchlist Anda.", show_alert: true);
            return;
        }

        $count = Watchlist::where('telegram_id', $userId)->count();
        if ($count >= 15) {
            $bot->answerCallbackQuery(text: "⚠️ Maksimal watchlist pribadi adalah 15 saham.", show_alert: true);
            return;
        }

        Watchlist::create([
            'telegram_id' => $userId,
            'symbol' => $symbol,
        ]);

        $bot->answerCallbackQuery(text: "✅ {$symbol} ditambahkan ke watchlist!", show_alert: true);
        $bot->sendMessage("✅ Saham <b>{$symbol}</b> ditambahkan ke watchlist pribadi!\nBot otomatis memantau Target R1, Stoploss, dan Breakout Volume setiap 15 menit di jam bursa.", parse_mode: ParseMode::HTML);
    } catch (\Throwable $e) {
        $bot->answerCallbackQuery(text: "❌ Gagal menambah watchlist: " . $e->getMessage(), show_alert: true);
    }
});


$bot->onCommand('settoken {token}', function (Nutgram $bot, string $token) {
    $cleanToken = trim(str_replace('Bearer ', '', $token));
    $expiresAt = now('Asia/Jakarta')->addHours(24);

    // Dekode payload JWT untuk membaca waktu kedaluwarsa (exp) asli dari Stockbit
    $parts = explode('.', $cleanToken);
    if (count($parts) === 3) {
        $payloadJson = base64_decode(strtr($parts[1], '-_', '+/'));
        $payload = json_decode($payloadJson ?: '', true);
        if (is_array($payload) && !empty($payload['exp'])) {
            $expiresAt = \Carbon\Carbon::createFromTimestamp((int) $payload['exp'], 'Asia/Jakarta');
        }
    }

    $ttl = $expiresAt->isFuture() ? $expiresAt : now('Asia/Jakarta')->addHours(30);
    \Illuminate\Support\Facades\Cache::put('stockbit_bearer_token', $cleanToken, $ttl);
    @file_put_contents(storage_path('app/stockbit_token.json'), json_encode(['token' => $cleanToken, 'expires_at' => $expiresAt->timestamp]));
    \Illuminate\Support\Facades\Cache::forever('stockbit_token_expires_at', $expiresAt->timestamp);
    \Illuminate\Support\Facades\Cache::forget('stockbit_token_warned');

    $expFmt = $expiresAt->format('d M Y H:i') . ' WIB';
    $bot->sendMessage(
        "✅ <b>Token Stockbit berhasil disimpan!</b>\n" .
        "⏳ Berlaku hingga: <b>{$expFmt}</b>\n" .
        "🔔 Bot akan otomatis mengingatkan 2 jam sebelum token kedaluwarsa.",
        parse_mode: ParseMode::HTML
    );
});

// Helper render hasil Top Retail Jualan + Inline Keyboard Kalender
$renderTopRetail = function (Nutgram $bot, ?string $fromDate = null, ?string $toDate = null, bool $editMessage = false) {
    $tz = 'Asia/Jakarta';
    $today = now()->timezone($tz)->format('Y-m-d');
    $from = $fromDate ?: $today;
    $to   = $toDate ?: $from;

    if ($from > $to) {
        [$from, $to] = [$to, $from];
    }

    $screener = app(\App\Services\StockScreenerService::class);

    $universe = [
        'BBCA', 'BBRI', 'BMRI', 'BBNI', 'BRIS', 'BNGA', 'NISP', 'BDMN', 'BTPS',
        'TLKM', 'EXCL', 'ISAT', 'TOWR', 'MTEL',
        'ASII', 'AUTO', 'UNTR', 'ADRO', 'AADI', 'PTBA', 'ITMG', 'ABMM', 'BUMI', 'HRUM', 'INDY',
        'AMMN', 'ANTM', 'INCO', 'MDKA', 'MBMA', 'TINS', 'NICL', 'NCKL',
        'ICBP', 'INDF', 'MYOR', 'KLBF', 'MIKA', 'HEAL', 'SIDO',
        'SMGR', 'INTP', 'CTRA', 'PWON', 'BSDE', 'SMRA', 'PANI', 'NRCA',
        'ACES', 'AMRT', 'MAPI', 'ERAA', 'JPFA', 'CPIN', 'AKRA', 'PGAS', 'BRPT', 'TPIA', 'CUAN', 'PTRO', 'RAJA', 'VKTR', 'ISSP'
    ];

    $result = $screener->scanTopRetailSell($universe, 18, $from, $to);

    $prevDay = \Carbon\Carbon::parse($from, $tz)->subWeekday()->format('Y-m-d');
    $nextDay = \Carbon\Carbon::parse($to, $tz)->addWeekday()->format('Y-m-d');
    $yesterday = now()->timezone($tz)->subWeekday()->format('Y-m-d');
    $d3From = now()->timezone($tz)->subWeekdays(2)->format('Y-m-d');
    $w1From = now()->timezone($tz)->subWeekdays(4)->format('Y-m-d');

    $keyboard = \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup::make()
        ->addRow(
            \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make('Hari Ini', callback_data: "ritel:range:{$today}:{$today}"),
            \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make('Kemarin', callback_data: "ritel:range:{$yesterday}:{$yesterday}"),
            \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make('3 Hari', callback_data: "ritel:range:{$d3From}:{$today}"),
            \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make('1 Minggu', callback_data: "ritel:range:{$w1From}:{$today}")
        )
        ->addRow(
            \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make("⬅️ {$prevDay}", callback_data: "ritel:range:{$prevDay}:{$prevDay}"),
            \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make("📅 Kalender", callback_data: "ritel:cal"),
            \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make("{$nextDay} ➡️", callback_data: "ritel:range:{$nextDay}:{$nextDay}")
        );

    $periodLabel = ($from === $to)
        ? \Carbon\Carbon::parse($from, $tz)->format('d M Y')
        : \Carbon\Carbon::parse($from, $tz)->format('d M') . ' - ' . \Carbon\Carbon::parse($to, $tz)->format('d M Y');

    if (isset($result['error'])) {
        $errMsg = $result['error'] === 'NO_TOKEN'
            ? "⚠️ <b>Token Stockbit belum diatur.</b>\nKirim perintah:\n<code>/settoken PASTE_BEARER_TOKEN</code>"
            : "⚠️ <b>Token Stockbit sudah expired (401/403).</b>\nAmbil Bearer Token baru lalu kirim ulang lewat:\n<code>/settoken PASTE_BEARER_TOKEN</code>";

        if ($editMessage) {
            $bot->editMessageText($errMsg, parse_mode: ParseMode::HTML, reply_markup: $keyboard);
        } else {
            $bot->sendMessage($errMsg, parse_mode: ParseMode::HTML, reply_markup: $keyboard);
        }
        return;
    }

    if (empty($result)) {
        $emptyMsg = "ℹ️ <b>Belum ada data distribusi ritel</b> untuk periode <b>{$periodLabel}</b>.\n<i>(Jika memilih hari ini, data baru tersedia setelah pukul 16:15 WIB. Silakan pilih Kemarin atau tanggal bursa lainnya di bawah).</i>";
        if ($editMessage) {
            $bot->editMessageText($emptyMsg, parse_mode: ParseMode::HTML, reply_markup: $keyboard);
        } else {
            $bot->sendMessage($emptyMsg, parse_mode: ParseMode::HTML, reply_markup: $keyboard);
        }
        return;
    }

    $lines = [
        "📉 <b>TOP RETAIL JUALAN</b>",
        "Periode: <b>{$periodLabel}</b> • <i>Market Reguler</i>\n",
        "<pre>Symbol | Sell% |  Close |   Chg1",
        "-------|-------|--------|-------"
    ];

    foreach ($result as $row) {
        $sym   = str_pad($row['symbol'], 6);
        $nrd   = str_pad(number_format($row['nrd0'], 1) . '%', 5, ' ', STR_PAD_LEFT);
        $close = str_pad(number_format($row['close'], 0, ',', '.'), 6, ' ', STR_PAD_LEFT);
        $sign  = $row['chg'] > 0 ? '+' : '';
        $chg   = str_pad($sign . number_format($row['chg'], 1) . '%', 6, ' ', STR_PAD_LEFT);

        $lines[] = "{$sym} | {$nrd} | {$close} | {$chg}";
    }

    $lines[] = "</pre>";
    $lines[] = "💡 <i>Ketik /topritel YYYY-MM-DD atau /topritel YYYY-MM-DD YYYY-MM-DD untuk periode kustom.</i>";

    $text = implode("\n", $lines);

    $topPickChunks = array_chunk(array_slice($result, 0, 6), 3);
    foreach ($topPickChunks as $chunk) {
        $rowBtns = [];
        foreach ($chunk as $item) {
            $symClean = trim($item['symbol']);
            $rowBtns[] = InlineKeyboardButton::make("📊 {$symClean}", callback_data: "analisa:{$symClean}");
        }
        if (!empty($rowBtns)) {
            $keyboard->addRow(...$rowBtns);
        }
    }

    if ($editMessage) {
        $bot->editMessageText($text, parse_mode: ParseMode::HTML, reply_markup: $keyboard);
    } else {
        $bot->sendMessage($text, parse_mode: ParseMode::HTML, reply_markup: $keyboard);
    }
};

$handleTopRetailCmd = function (Nutgram $bot, ?string $args = null) use ($renderTopRetail) {
    try {
        $rawArgs = trim($args ?? '');
        $parts = $rawArgs !== '' ? preg_split('/\s+/', $rawArgs) : [];

        $normalizeDate = function (?string $raw): ?string {
            if (empty($raw)) return null;
            $raw = str_replace('/', '-', trim($raw));
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
                return $raw;
            }
            if (preg_match('/^(\d{2})-(\d{2})-(\d{2})$/', $raw, $m)) {
                return "20{$m[1]}-{$m[2]}-{$m[3]}";
            }
            if (preg_match('/^(\d{2})-(\d{2})-(\d{4})$/', $raw, $m)) {
                return "{$m[3]}-{$m[2]}-{$m[1]}";
            }
            return null;
        };

        $from = $normalizeDate($parts[0] ?? null);
        $to   = $normalizeDate($parts[1] ?? null) ?? $from;

        $bot->sendMessage('⏳ Memindai broker summary...');
        $renderTopRetail($bot, $from, $to, false);
    } catch (\Throwable $e) {
        $bot->sendMessage("❌ <b>Error /topritel:</b> " . htmlspecialchars($e->getMessage()), parse_mode: ParseMode::HTML);
    }
};

$bot->onText('^/[Tt]opritel(?:@[a-zA-Z0-9_]+)?(?:\s+(.+))?$', function (Nutgram $bot, ?string $args = null) use ($handleTopRetailCmd) {
    $handleTopRetailCmd($bot, $args);
});


$bot->onCallbackQueryData('ritel:range:{from}:{to}', function (Nutgram $bot, string $from, string $to) use ($renderTopRetail) {
    $bot->answerCallbackQuery(text: "⏳ Memuat data {$from}...");
    $bot->sendMessage("⏳ Memindai broker summary periode <b>{$from}</b>...", parse_mode: ParseMode::HTML);
    $renderTopRetail($bot, $from, $to, false);
});

$bot->onCallbackQueryData('ritel:cal', function (Nutgram $bot) {
    $bot->answerCallbackQuery();
    $tz = 'Asia/Jakarta';
    $cursor = now()->timezone($tz);

    $calKeyboard = \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup::make();
    $rowButtons = [];

    for ($i = 0; $i < 12; $i++) {
        $dStr = $cursor->format('Y-m-d');
        $lbl  = $cursor->format('d M (D)');
        $rowButtons[] = \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make($lbl, callback_data: "ritel:range:{$dStr}:{$dStr}");

        if (count($rowButtons) === 3) {
            $calKeyboard->addRow(...$rowButtons);
            $rowButtons = [];
        }
        $cursor = $cursor->subWeekday();
    }

    if (!empty($rowButtons)) {
        $calKeyboard->addRow(...$rowButtons);
    }

    $bot->editMessageText(
        "📅 <b>Pilih Tanggal Bursa (12 Hari Kerja Terakhir):</b>\n<i>Atau ketik manual:</i> <code>/topritel 2026-11-10 2026-11-18</code>",
        parse_mode: ParseMode::HTML,
        reply_markup: $calKeyboard
    );
});
