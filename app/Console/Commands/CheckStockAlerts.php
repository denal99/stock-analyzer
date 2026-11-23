<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use App\Models\Watchlist;
use App\Services\StockAnalysisService;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;

class CheckStockAlerts extends Command
{
    protected $signature = 'stocks:check-alerts {--test : Jalankan simulasi alert sekarang di luar jam bursa}';
    protected $description = 'Memeriksa smart watchlist saham (BoW, BoB, SL, Vol) dan mengirim alert otomatis ke Telegram';

    public function handle(StockAnalysisService $service)
    {
        $isTest = (bool) $this->option('test');
        $now = now('Asia/Jakarta');

        if (!$isTest && ($now->isWeekend() || $now->hour < 9 || $now->hour >= 16)) {
            return;
        }

        $watchlists = Watchlist::all();
        if ($watchlists->isEmpty()) {
            $this->warn("Watchlist kosong. Tambahkan emiten dulu dengan /pantau KODE di Telegram.");
            return;
        }

        $bot = new Nutgram(config('nutgram.token'));
        $todayKey = $now->format('Y-m-d');

        foreach ($watchlists as $item) {
            $symbol = $item->symbol;
            $chatId = $item->telegram_id;
            $mode   = strtolower((string) ($item->alert_mode ?: 'all'));
            $minVol = (float) ($item->min_vol_ratio ?: 1.2);

            try {
                $data = $service->analyze($symbol);
                if (!empty($data['chart_path']) && file_exists($data['chart_path'])) {
                    @unlink($data['chart_path']);
                }

                $price    = (float) ($data['price'] ?? 0);
                $bowLevel = (float) ($item->target_bow ?: ($data['s1'] ?? 0));
                $bobLevel = (float) ($item->target_bob ?: ($data['r1'] ?? 0));
                $slLevel  = (float) ($item->target_sl  ?: ($data['stoploss'] ?? 0));
                $volRatio = (float) ($data['vol_ratio'] ?? 0);
                $volTag   = !empty($data['vol_is_projected']) ? ' Est.' : '';
                $chg      = (float) ($data['change_pct'] ?? 0);

                $priceFmt = number_format($price, 0, ',', '.');
                $bowFmt   = number_format($bowLevel, 0, ',', '.');
                $bobFmt   = number_format($bobLevel, 0, ',', '.');
                $slFmt    = number_format($slLevel, 0, ',', '.');

                $alerts = [];

                if (in_array($mode, ['bow', 'both'], true) && $bowLevel > 0 && $price <= $bowLevel && ($slLevel <= 0 || $price > $slLevel)) {
                    if ($isTest || Cache::add("alert:{$todayKey}:{$chatId}:{$symbol}:BOW", true, $now->copy()->endOfDay())) {
                        $alerts[] = "🟢 <b>Masuk Area BoW</b> (≤ Rp {$bowFmt} • Vol {$volRatio}x{$volTag})";
                    }
                }

                if (in_array($mode, ['bob', 'both'], true) && $bobLevel > 0 && $price >= $bobLevel && $volRatio >= $minVol) {
                    if ($isTest || Cache::add("alert:{$todayKey}:{$chatId}:{$symbol}:BOB", true, $now->copy()->endOfDay())) {
                        $alerts[] = "🚀 <b>Breakout BoB Terkonfirmasi</b> (≥ Rp {$bobFmt} • Vol {$volRatio}x{$volTag})";
                    }
                }

                if ($mode === 'all') {
                    if ($bobLevel > 0 && $price >= $bobLevel) {
                        if ($isTest || Cache::add("alert:{$todayKey}:{$chatId}:{$symbol}:R1", true, $now->copy()->endOfDay())) {
                            $alerts[] = "🎯 <b>Menyentuh/Tembus Target R1</b> (Rp {$bobFmt})";
                        }
                    }
                    if ($volRatio >= 2.0 && $chg > 0) {
                        if ($isTest || Cache::add("alert:{$todayKey}:{$chatId}:{$symbol}:VOL", true, $now->copy()->endOfDay())) {
                            $alerts[] = "🔥 <b>Breakout Volume {$volRatio}x{$volTag}</b> (+{$chg}%)";
                        }
                    }
                }

                if ($slLevel > 0 && $price <= $slLevel) {
                    if ($isTest || Cache::add("alert:{$todayKey}:{$chatId}:{$symbol}:SL", true, $now->copy()->endOfDay())) {
                        $alerts[] = "🛑 <b>Menyentuh Batas Stoploss</b> (Rp {$slFmt})";
                    }
                }

                if ($isTest && empty($alerts)) {
                    $alerts[] = "🧪 <b>[SIMULASI - " . strtoupper($mode) . "]</b> Belum terpicu (BoW: Rp {$bowFmt} • BoB: Rp {$bobFmt} • SL: Rp {$slFmt} • Vol: {$volRatio}x{$volTag})";
                }

                if (!empty($alerts)) {
                    $alertList = implode("\n• ", $alerts);
                    $header = $isTest
                        ? "🧪 <b>SIMULASI SMART ALERT: {$symbol}</b>"
                        : "🚨 <b>SMART ALERT WATCHLIST: {$symbol}</b>";
                    $kb = InlineKeyboardMarkup::make()->addRow(
                        InlineKeyboardButton::make("📊 Buka Chart {$symbol}", callback_data: "chart:{$symbol}")
                    );
                    $bot->sendMessage(
                        chat_id: $chatId,
                        text: "{$header}\n━━━━━━━━━━━━━━━━━━━━\n💰 Harga Saat Ini: <b>Rp {$priceFmt}</b> (" . ($chg >= 0 ? '+' : '') . "{$chg}%)\n• {$alertList}\n\n💡 Klik tombol di bawah untuk cek chart &amp; broker flow terbaru.",
                        parse_mode: ParseMode::HTML,
                        reply_markup: $kb
                    );
                    $this->info("Alert terkirim untuk {$symbol} ({$mode}) ke chat ID {$chatId}");
                }
            } catch (\Exception $e) {
                $this->error("Gagal memeriksa {$symbol}: " . $e->getMessage());
                continue;
            }
            usleep(1000000);
        }

        $this->info("Pemeriksaan alert watchlist selesai.");
    }
}