<?php

namespace App\Services;

use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class MarketBriefService
{
    public function generateBrief(): array
    {
        return Cache::remember('idx_morning_brief_v2', 300, function () {
            $tickers = [
                'ihsg'   => ['symbol' => '^JKSE', 'label' => 'IHSG (Jakarta)'],
                'usdidr' => ['symbol' => 'IDR=X', 'label' => 'USD / IDR'],
                'sp500'  => ['symbol' => '^GSPC', 'label' => 'S&P 500 (US)'],
                'nasdaq' => ['symbol' => '^IXIC', 'label' => 'Nasdaq (US)'],
                'nikkei' => ['symbol' => '^N225', 'label' => 'Nikkei 225'],
                'hsi'    => ['symbol' => '^HSI',  'label' => 'Hang Seng'],
                'gold'   => ['symbol' => 'GC=F',  'label' => 'Emas (Gold)'],
                'oil'    => ['symbol' => 'CL=F',  'label' => 'Minyak WTI'],
                'coal'   => ['symbol' => 'BTU',   'label' => 'Proxy Batu Bara'],
                'cpo'    => ['symbol' => 'ZL=F',  'label' => 'Proxy CPO / Oil'],
            ];

            $responses = Http::pool(function (Pool $pool) use ($tickers) {
                $reqs = [];
                foreach ($tickers as $key => $info) {
                    $encoded = urlencode($info['symbol']);
                    $url = "https://query1.finance.yahoo.com/v8/finance/chart/{$encoded}?range=1mo&interval=1d";
                    $reqs[$key] = $pool->as($key)
                        ->timeout(12)
                        ->withOptions(['force_ip_resolve' => 'v4'])
                        ->withHeaders([
                            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
                        ])
                        ->get($url);
                }
                return $reqs;
            });

            $data = [];
            foreach ($tickers as $key => $info) {
                $res = $responses[$key] ?? null;
                if (!$res || $res->failed()) {
                    $data[$key] = null;
                    continue;
                }

                $result = $res->json('chart.result.0');
                $meta = $result['meta'] ?? [];
                $closes = $result['indicators']['quote'][0]['close'] ?? [];
                $closes = array_values(array_filter($closes, fn($v) => $v !== null && $v > 0));
                $cnt = count($closes);

                if ($cnt < 2 && empty($meta['regularMarketPrice'])) {
                    $data[$key] = null;
                    continue;
                }

                $last = isset($meta['regularMarketPrice']) && $meta['regularMarketPrice'] > 0
                    ? (float) $meta['regularMarketPrice']
                    : (float) $closes[$cnt - 1];

                // Gunakan close sebelumnya dari array historis yang tervalidasi
                $prev = $cnt >= 2
                    ? (float) $closes[$cnt - 2]
                    : (float) ($meta['chartPreviousClose'] ?? $last);

                // Khusus USD/IDR: hindari bad tick / spike semu di Yahoo Finance (> 2.5% dalam 1 hari)
                if ($key === 'usdidr' && $prev > 0 && abs(($last - $prev) / $prev) > 0.025 && $cnt >= 3) {
                    $medianWindow = array_slice($closes, -5);
                    sort($medianWindow);
                    $last = (float) $medianWindow[(int) floor(count($medianWindow) / 2)];
                    $prev = (float) $closes[$cnt - 3];
                }

                $chgPct = $prev > 0 ? (($last - $prev) / $prev) * 100 : 0.0;

                $sma20 = $cnt >= 20
                    ? array_sum(array_slice($closes, -20)) / 20
                    : ($cnt > 0 ? array_sum($closes) / $cnt : $last);

                $data[$key] = [
                    'label' => $info['label'],
                    'price' => $last,
                    'prev' => $prev,
                    'chg_pct' => round($chgPct, 2),
                    'above_sma20' => $last >= $sma20,
                ];
            }

            // Hitung Skor Sentimen Pembukaan / Pasar IHSG
            $score = 0;
            if (!empty($data['sp500'])) {
                $score += $data['sp500']['chg_pct'] >= 0.4 ? 2 : ($data['sp500']['chg_pct'] <= -0.4 ? -2 : 0);
            }
            if (!empty($data['nasdaq'])) {
                $score += $data['nasdaq']['chg_pct'] >= 0.5 ? 1 : ($data['nasdaq']['chg_pct'] <= -0.5 ? -1 : 0);
            }
            if (!empty($data['usdidr'])) {
                $score += $data['usdidr']['chg_pct'] <= -0.15 ? 2 : ($data['usdidr']['chg_pct'] >= 0.25 ? -2 : 0);
            }
            if (!empty($data['ihsg'])) {
                $score += $data['ihsg']['above_sma20'] ? 1 : -1;
            }

            if ($score >= 3) {
                $outlook = '🟢 BULLISH / RISK-ON (Potensi Menguat)';
            } elseif ($score >= 0) {
                $outlook = '🟡 MIXED / SELECTIVE (Konsolidasi & Rotasi Sektor)';
            } else {
                $outlook = '🔴 DEFENSIVE / CAUTIOUS (Tekanan Global / Rupiah, Waspada Koreksi)';
            }

            // Deteksi Katalis Sektor
            $catalysts = [];

            if (!empty($data['usdidr'])) {
                if ($data['usdidr']['chg_pct'] <= -0.15) {
                    $catalysts[] = '🏦 <b>Perbankan & Properti (Positif):</b> Rupiah menguat (' . $data['usdidr']['chg_pct'] . '%), mendukung inflow asing ke <b>BBCA, BBRI, BMRI, BSDE</b>.';
                } elseif ($data['usdidr']['chg_pct'] >= 0.25) {
                    $catalysts[] = '⚠️ <b>Emiten Impor / Utang USD (Waspada):</b> Rupiah melemah (+' . $data['usdidr']['chg_pct'] . '%), potensi tekanan jangka pendek pada perbankan.';
                }
            }

            if (!empty($data['gold']) && $data['gold']['chg_pct'] >= 0.6) {
                $catalysts[] = '🥇 <b>Sektor Emas (Positif):</b> Harga emas dunia naik +' . $data['gold']['chg_pct'] . '%, katalis untuk <b>ANTM, MDKA, BRMS</b>.';
            }

            if (!empty($data['oil']) && $data['oil']['chg_pct'] >= 1.0) {
                $catalysts[] = '🛢️ <b>Sektor Migas (Positif):</b> Minyak WTI melonjak +' . $data['oil']['chg_pct'] . '%, pantau <b>MEDC, ELSA, AKRA</b>.';
            }

            if (!empty($data['coal']) && $data['coal']['chg_pct'] >= 1.0) {
                $catalysts[] = '⛏️ <b>Sektor Batu Bara (Positif):</b> Indeks batu bara naik +' . $data['coal']['chg_pct'] . '%, pantau <b>ADRO, PTBA, ITMG</b>.';
            }

            if (!empty($data['cpo']) && $data['cpo']['chg_pct'] >= 0.8) {
                $catalysts[] = '🌴 <b>Sektor Sawit / CPO (Positif):</b> Minyak nabati menguat +' . $data['cpo']['chg_pct'] . '%, pantau <b>TAPG, LSIP, DSNG</b>.';
            }

            if (empty($catalysts)) {
                $catalysts[] = '🧭 <b>Fokus Stock-Picking:</b> Tidak ada lonjakan ekstrem komoditas/kurs; fokus pada emiten yang sedang <i>inflow</i> di <code>/sektor</code> atau <code>/akumulasi</code>.';
            }

            $nowWib = now('Asia/Jakarta');
            $hour = (int) $nowWib->format('H');
            if ($hour < 12) {
                $title = '🌅 MORNING MARKET BRIEF &amp; MACRO RADAR';
            } elseif ($hour < 16) {
                $title = '☀️ INTRADAY MARKET RADAR &amp; MACRO UPDATE';
            } else {
                $title = '🌆 CLOSING MARKET &amp; MACRO WRAP';
            }

            return [
                'title' => $title,
                'timestamp' => $nowWib->format('d M Y, H:i') . ' WIB',
                'metrics' => $data,
                'outlook' => $outlook,
                'catalysts' => $catalysts,
            ];
        });
    }

    public function formatTelegramMessage(array $brief): string
    {
        $m = $brief['metrics'];
        $fmtPct = fn(?array $item): string => $item
            ? (($item['chg_pct'] >= 0 ? '+' : '') . number_format($item['chg_pct'], 2) . '%')
            : 'N/A';

        $title = $brief['title'] ?? '🌅 MORNING MARKET BRIEF &amp; MACRO RADAR';
        $ihsgPrice = !empty($m['ihsg']) ? number_format($m['ihsg']['price'], 2) : 'N/A';
        $ihsgTrend = !empty($m['ihsg']) ? ($m['ihsg']['above_sma20'] ? 'Uptrend (&gt; SMA20)' : 'Downtrend (&lt; SMA20)') : '-';
        $usdPrice = !empty($m['usdidr']) ? 'Rp ' . number_format($m['usdidr']['price'], 0) : 'N/A';

        $msg = "<b>{$title}</b>\n";
        $msg .= "🕒 <i>Update: {$brief['timestamp']}</i>\n";
        $msg .= "━━━━━━━━━━━━━━━━━━━━\n\n";

        $msg .= "🇮🇩 <b>IHSG:</b> <b>{$ihsgPrice}</b> ({$fmtPct($m['ihsg'])}) | {$ihsgTrend}\n";
        $msg .= "💵 <b>USD/IDR:</b> <b>{$usdPrice}</b> ({$fmtPct($m['usdidr'])})\n";
        $msg .= "🎯 <b>Outlook Bursa:</b>\n{$brief['outlook']}\n\n";

        $msg .= "<b>🌍 Indeks Global &amp; Regional:</b>\n";
        $msg .= "• S&amp;P 500: <b>{$fmtPct($m['sp500'])}</b> | Nasdaq: <b>{$fmtPct($m['nasdaq'])}</b>\n";
        $msg .= "• Nikkei 225: <b>{$fmtPct($m['nikkei'])}</b> | Hang Seng: <b>{$fmtPct($m['hsi'])}</b>\n\n";

        $msg .= "<b>📦 Komoditas Utama:</b>\n";
        $msg .= "• Emas (Gold): <b>{$fmtPct($m['gold'])}</b>" . (!empty($m['gold']) ? ' ($' . number_format($m['gold']['price'], 1) . ')' : '') . "\n";
        $msg .= "• Minyak WTI: <b>{$fmtPct($m['oil'])}</b>" . (!empty($m['oil']) ? ' ($' . number_format($m['oil']['price'], 2) . ')' : '') . "\n";
        $msg .= "• Batu Bara (Proxy): <b>{$fmtPct($m['coal'])}</b> | CPO (Proxy): <b>{$fmtPct($m['cpo'])}</b>\n\n";

        $msg .= "<b>⚡ Katalis &amp; Watchlist Sektor:</b>\n";
        foreach ($brief['catalysts'] as $cat) {
            $msg .= "• {$cat}\n";
        }

        return rtrim($msg);
    }
}
