<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use App\Models\Watchlist;
use App\Services\StockAnalysisService;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;

class CheckStockAlerts extends Command
{
    protected $signature = 'stocks:check-alerts {--test : Jalankan simulasi alert sekarang di luar jam bursa}';
    protected $description = 'Memeriksa watchlist saham dan mengirim alert otomatis ke Telegram';

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

            try {
                $data = $service->analyze($symbol);
                @unlink($data['chart_path']);

                $price = $data['price'];
                $r1 = $data['r1'] ?? 0;
                $sl = $data['stoploss'] ?? 0;
                $volRatio = $data['vol_ratio'] ?? 0;
                $chg = $data['change_pct'];
                $priceFmt = number_format($price, 0, ',', '.');
                $r1Fmt = number_format($r1, 0, ',', '.');
                $slFmt = number_format($sl, 0, ',', '.');

                $alerts = [];

                if ($r1 > 0 && $price >= $r1) {
                    if ($isTest || Cache::add("alert:{$todayKey}:{$chatId}:{$symbol}:R1", true, $now->copy()->endOfDay())) {
                        $alerts[] = "🎯 <b>Menyentuh/Tembus Target R1</b> (Rp {$r1Fmt})";
                    }
                }

                if ($sl > 0 && $price <= $sl) {
                    if ($isTest || Cache::add("alert:{$todayKey}:{$chatId}:{$symbol}:SL", true, $now->copy()->endOfDay())) {
                        $alerts[] = "🛑 <b>Menyentuh Batas Stoploss</b> (Rp {$slFmt})";
                    }
                }

                if ($volRatio >= 2.0 && $chg > 0) {
                    if ($isTest || Cache::add("alert:{$todayKey}:{$chatId}:{$symbol}:VOL", true, $now->copy()->endOfDay())) {
                        $alerts[] = "🔥 <b>Breakout Volume {$volRatio}x</b> (+{$chg}%)";
                    }
                }

                if ($isTest && empty($alerts)) {
                    $alerts[] = "🧪 <b>[MODE SIMULASI]</b> Belum ada sinyal aktif (R1: Rp {$r1Fmt} • SL: Rp {$slFmt} • Vol: {$volRatio}x)";
                }

                if (!empty($alerts)) {
                    $alertList = implode("\n• ", $alerts);
                    $header = $isTest ? "🧪 <b>SIMULASI ALERT WATCHLIST: {$symbol}</b>" : "🚨 <b>ALERT WATCHLIST: {$symbol}</b>";

                    $bot->sendMessage(
                        chat_id: $chatId,
                        text: "{$header}\n" .
                              "━━━━━━━━━━━━━━━━━━━━\n" .
                              "💰 Harga Saat Ini: <b>Rp {$priceFmt}</b>\n" .
                              "• {$alertList}\n\n" .
                              "💡 Ketik <code>/analisa {$symbol}</code> untuk melihat chart.",
                        parse_mode: ParseMode::HTML
                    );
                    $this->info("Alert terkirim untuk {$symbol} ke chat ID {$chatId}");
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
