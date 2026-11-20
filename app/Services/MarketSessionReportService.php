<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class MarketSessionReportService
{
    private array $universe = [
        'BBCA', 'BBRI', 'BMRI', 'BBNI', 'BRIS', 'BTPS', 'ARTO', 'BBTN', 'BNGA', 'NISP',
        'TLKM', 'EXCL', 'ISAT', 'MTEL', 'TOWR', 'TBIG', 'WIFI',
        'ASII', 'UNTR', 'AUTO', 'DRMA', 'SMSM',
        'AMMN', 'ADRO', 'AADI', 'PTBA', 'ITMG', 'INDY', 'ABMM', 'BUMI', 'HRUM', 'MBMA',
        'ANTM', 'INCO', 'MDKA', 'TINS', 'NCKL', 'BRMS', 'PSAB',
        'PGAS', 'AKRA', 'MEDC', 'ELSA', 'RAJA', 'ENRG',
        'BRPT', 'TPIA', 'ESSA', 'INKP', 'TKIM',
        'ICBP', 'INDF', 'MYOR', 'KLBF', 'SIDO', 'UNVR', 'CPIN', 'JPFA', 'AMRT', 'MAPI', 'ACES', 'ERAA',
        'SMGR', 'INTP', 'AVIA', 'CTRA', 'BSDE', 'PWON', 'SMRA', 'PANI',
        'GOTO', 'BUKA', 'EMTK', 'SCMA', 'MNCN', 'JSMR', 'BIRD'
    ];

    private function getStockbitToken(): ?string
    {
        $token = Cache::get('stockbit_bearer_token');
        if (empty($token) && file_exists(storage_path('app/stockbit_token.json'))) {
            $saved = json_decode((string) file_get_contents(storage_path('app/stockbit_token.json')), true);
            if (!empty($saved['token']) && ($saved['expires_at'] ?? 0) > time()) {
                $token = $saved['token'];
                Cache::put('stockbit_bearer_token', $token, Carbon::createFromTimestamp($saved['expires_at']));
            }
        }
        return $token ?: env('STOCKBIT_BEARER_TOKEN');
    }

    public function generateReport(bool $forceRefresh = false): string
    {
        $cacheKey = 'market_report_sesi1_' . now('Asia/Jakarta')->format('Ymd_H');
        if (!$forceRefresh && Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $token = $this->getStockbitToken();
        $now = now('Asia/Jakarta');
        $from = $now->format('Y-m-d');
        $to = $now->copy()->subDays(35)->format('Y-m-d');

        $ihsgChange = $this->fetchIhsgChange($token, $from, $to);

        $records = [];
        foreach (array_chunk($this->universe, 20) as $batch) {
            $responses = Http::pool(function ($pool) use ($batch, $token, $from, $to) {
                foreach ($batch as $sym) {
                    if (!empty($token)) {
                        $pool->as($sym)
                            ->withToken($token)
                            ->withOptions(['force_ip_resolve' => 'v4'])
                            ->withHeaders([
                                'Origin' => 'https://stockbit.com',
                                'Referer' => 'https://stockbit.com/',
                                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
                            ])
                            ->connectTimeout(4)
                            ->timeout(8)
                            ->get("https://exodus.stockbit.com/chartbit/{$sym}/price/daily", [
                                'from' => $from,
                                'to' => $to,
                                'limit' => 30,
                            ]);
                    } else {
                        $pool->as($sym)
                            ->connectTimeout(4)
                            ->timeout(8)
                            ->get("https://query1.finance.yahoo.com/v8/finance/chart/{$sym}.JK", [
                                'range' => '1mo',
                                'interval' => '1d',
                            ]);
                    }
                }
            });

            foreach ($batch as $sym) {
                $res = $responses[$sym] ?? null;
                if (!$res || $res instanceof \Throwable || $res->failed()) {
                    continue;
                }

                $parsed = !empty($token)
                    ? $this->parseStockbitDaily($sym, $res->json())
                    : $this->parseYahooDaily($sym, $res->json());

                if ($parsed !== null) {
                    $records[$sym] = $parsed;
                }
            }
        }

        if (empty($records)) {
            return "⚠️ Gagal menarik data pasar Sesi 1. Pastikan koneksi atau token Stockbit aktif (<code>/settoken</code>).";
        }

        $sortedByChange = $records;
        uasort($sortedByChange, fn($a, $b) => $b['change_pct'] <=> $a['change_pct']);

        $topGainers = array_slice(array_filter($sortedByChange, fn($r) => $r['change_pct'] > 0), 0, 5, true);
        $topLosers = array_slice(array_reverse(array_filter($sortedByChange, fn($r) => $r['change_pct'] < 0), true), 0, 5, true);

        $rebound3d = array_filter($records, fn($r) => $r['change_pct'] > 0 && $r['down_streak'] >= 3);
        uasort($rebound3d, fn($a, $b) => $b['change_pct'] <=> $a['change_pct']);
        $rebound3d = array_slice($rebound3d, 0, 5, true);

        $volSpikes = array_filter($records, fn($r) => $r['rvol'] >= 2.0 && $r['today_vol'] > 0);
        uasort($volSpikes, fn($a, $b) => $b['rvol'] <=> $a['rvol']);
        $volSpikes = array_slice($volSpikes, 0, 5, true);

        // Gabungkan Top Value + Top Gainer + Lonjakan Volume agar emiten penggerak pasar ikut terpindai Foreign & Local Buy
        $byValue = $records;
        uasort($byValue, fn($a, $b) => ($b['close'] * $b['today_vol']) <=> ($a['close'] * $a['today_vol']));
        $topValSyms = array_keys(array_slice($byValue, 0, 18, true));
        $topGainSyms = array_keys(array_slice(array_filter($sortedByChange, fn($r) => $r['change_pct'] > 0), 0, 12, true));
        $activeSymbols = array_values(array_unique(array_merge($topValSyms, $topGainSyms)));

        $activeTradeDate = $this->resolveLatestTradeDate($token);
        $prevTradeDate = $this->resolvePreviousTradeDate($activeTradeDate);

        $topForeignBuy = $this->fetchTopInvestorNetBuy($token, $activeSymbols, $activeTradeDate, 'INVESTOR_TYPE_FOREIGN', $records);
        $topLocalBuy   = $this->fetchTopInvestorNetBuy($token, $activeSymbols, $activeTradeDate, 'INVESTOR_TYPE_DOMESTIC', $records);
        $foreignFollow = $this->fetchForeignFollowThrough($token, $sortedByChange, $prevTradeDate);

        $report = $this->formatReport(
            $now->format('d M Y H:i') . ' WIB',
            $ihsgChange,
            $topGainers,
            $topLosers,
            $topForeignBuy,
            $topLocalBuy,
            $foreignFollow,
            $rebound3d,
            $volSpikes,
            !empty($token) ? 'Stockbit Real-Time' : 'Yahoo Finance'
        );

        Cache::put($cacheKey, $report, now('Asia/Jakarta')->addMinutes(25));
        return $report;
    }

    private function resolveLatestTradeDate(?string $token): string
    {
        $now = now('Asia/Jakarta');
        for ($i = 0; $i <= 4; $i++) {
            $d = $now->copy()->subDays($i);
            if ($d->isWeekend()) continue;
            $dStr = $d->format('Y-m-d');
            if (empty($token)) return $dStr;

            try {
                $resp = Http::withToken($token)
                    ->withOptions(['force_ip_resolve' => 'v4'])
                    ->withHeaders(['Origin' => 'https://stockbit.com', 'Referer' => 'https://stockbit.com/', 'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36', 'Accept' => 'application/json'])
                    ->connectTimeout(3)
                    ->timeout(5)
                    ->get('https://exodus.stockbit.com/marketdetectors/BBCA', [
                        'from' => $dStr,
                        'to' => $dStr,
                        'transaction_type' => 'TRANSACTION_TYPE_NET',
                        'market_board' => 'MARKET_BOARD_REGULER',
                        'investor_type' => 'INVESTOR_TYPE_ALL',
                    ]);
                $brokers = $resp->json('data.broker_summary.brokers_buy') ?? [];
                if (!empty($brokers)) {
                    return $dStr;
                }
            } catch (\Throwable $e) {
                continue;
            }
        }
        return $now->format('Y-m-d');
    }

    private function resolvePreviousTradeDate(string $latestDateStr): string
    {
        $d = Carbon::parse($latestDateStr, 'Asia/Jakarta')->subDay();
        while ($d->isWeekend()) {
            $d->subDay();
        }
        return $d->format('Y-m-d');
    }

    private function parseNetBuyFromDetector(?array $json): float
    {
        $summary = $json['data']['broker_summary'] ?? null;
        if (!is_array($summary)) {
            return 0.0;
        }

        $totBuy = 0.0;
        foreach (($summary['brokers_buy'] ?? []) as $b) {
            $totBuy += (float) ($b['bval'] ?? $b['netbs_val'] ?? $b['value'] ?? 0);
        }

        $totSell = 0.0;
        foreach (($summary['brokers_sell'] ?? []) as $s) {
            $totSell += abs((float) ($s['sval'] ?? $s['netbs_val'] ?? $s['value'] ?? 0));
        }

        return $totBuy - $totSell;
    }

    private function fetchTopInvestorNetBuy(?string $token, array $symbols, string $dateStr, string $investorType, array $records): array
    {
        if (empty($token) || empty($symbols)) {
            return [];
        }

        $responses = [];
        foreach (array_chunk($symbols, 6) as $chunk) {
            $batch = Http::pool(function ($pool) use ($chunk, $token, $dateStr, $investorType) {
                foreach ($chunk as $sym) {
                    $pool->as($sym)
                        ->withToken($token)
                        ->withOptions(['force_ip_resolve' => 'v4'])
                        ->withHeaders([
                            'Origin' => 'https://stockbit.com',
                            'Referer' => 'https://stockbit.com/',
                            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
                            'Accept'     => 'application/json',
                        ])
                        ->connectTimeout(4)
                        ->timeout(7)
                        ->get("https://exodus.stockbit.com/marketdetectors/{$sym}", [
                            'from' => $dateStr,
                            'to' => $dateStr,
                            'transaction_type' => 'TRANSACTION_TYPE_NET',
                            'market_board' => 'MARKET_BOARD_REGULER',
                            'investor_type' => $investorType,
                        ]);
                }
            });
            foreach ($batch as $k => $v) {
                $responses[$k] = $v;
            }
            usleep(180000);
        }

        $results = [];
        foreach ($symbols as $sym) {
            $res = $responses[$sym] ?? null;
            if (!$res || $res instanceof \Throwable || $res->failed()) {
                continue;
            }

            $netVal = $this->parseNetBuyFromDetector($res->json());
            if ($netVal >= 10_000_000) {
                $results[$sym] = [
                    'symbol' => $sym,
                    'net_b' => round($netVal / 1_000_000_000, 2),
                    'change_pct' => $records[$sym]['change_pct'] ?? 0.0,
                ];
            }
        }

        uasort($results, fn($a, $b) => $b['net_b'] <=> $a['net_b']);
        return array_slice($results, 0, 5, true);
    }

    private function fetchIhsgChange(?string $token, string $from, string $to): ?array
    {
        try {
            if (!empty($token)) {
                $res = Http::withToken($token)
                    ->withOptions(['force_ip_resolve' => 'v4'])
                    ->withHeaders([
                        'Origin' => 'https://stockbit.com',
                        'Referer' => 'https://stockbit.com/',
                        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
                        'Accept' => 'application/json',
                    ])
                    ->timeout(6)
                    ->get('https://exodus.stockbit.com/chartbit/COMPOSITE/price/daily', [
                        'from' => $from,
                        'to' => $to,
                        'limit' => 5,
                    ]);
                $list = $res->json('data.chartbit') ?? [];
                if (is_array($list) && count($list) >= 2) {
                    usort($list, fn($a, $b) => ($a['unixdate'] ?? 0) <=> ($b['unixdate'] ?? 0));
                    $last = (float) end($list)['close'];
                    $prev = (float) $list[count($list) - 2]['close'];
                    if ($prev > 0) {
                        return [
                            'price' => $last,
                            'change_pct' => round((($last - $prev) / $prev) * 100, 2),
                        ];
                    }
                }
            }

            $yRes = Http::timeout(6)->get('https://query1.finance.yahoo.com/v8/finance/chart/%5EJKSE', [
                'range' => '5d',
                'interval' => '1d',
            ]);
            $closes = array_values(array_filter($yRes->json('chart.result.0.indicators.quote.0.close') ?? []));
            if (count($closes) >= 2) {
                $last = (float) end($closes);
                $prev = (float) $closes[count($closes) - 2];
                if ($prev > 0) {
                    return [
                        'price' => $last,
                        'change_pct' => round((($last - $prev) / $prev) * 100, 2),
                    ];
                }
            }
        } catch (\Throwable $e) {
            return null;
        }
        return null;
    }

    private function parseStockbitDaily(string $sym, ?array $json): ?array
    {
        $list = $json['data']['chartbit'] ?? [];
        if (!is_array($list) || count($list) < 5) {
            return null;
        }

        usort($list, fn($a, $b) => ($a['unixdate'] ?? 0) <=> ($b['unixdate'] ?? 0));
        $count = count($list);
        $today = $list[$count - 1];
        $prev = $list[$count - 2];

        $close = (float) ($today['close'] ?? 0);
        $prevClose = (float) ($prev['close'] ?? 0);
        if ($close <= 0 || $prevClose <= 0) {
            return null;
        }

        $changePct = (($close - $prevClose) / $prevClose) * 100;
        $todayVol = (float) ($today['volume'] ?? 0);

        $histVols = [];
        for ($i = max(0, $count - 21); $i < $count - 1; $i++) {
            $v = (float) ($list[$i]['volume'] ?? 0);
            if ($v > 0) $histVols[] = $v;
        }
        $avgVol = !empty($histVols) ? (array_sum($histVols) / count($histVols)) : 0;
        $rvol = $avgVol > 0 ? ($todayVol / $avgVol) : 0;

        $downStreak = 0;
        for ($i = $count - 2; $i >= 1; $i--) {
            $cCurr = (float) ($list[$i]['close'] ?? 0);
            $cPrev = (float) ($list[$i - 1]['close'] ?? 0);
            if ($cCurr > 0 && $cPrev > 0 && $cCurr < $cPrev) {
                $downStreak++;
            } else {
                break;
            }
        }

        return [
            'symbol' => $sym,
            'close' => $close,
            'change_pct' => round($changePct, 2),
            'today_vol' => $todayVol,
            'rvol' => round($rvol, 1),
            'down_streak' => $downStreak,
        ];
    }

    private function parseYahooDaily(string $sym, ?array $json): ?array
    {
        $quote = $json['chart']['result'][0]['indicators']['quote'][0] ?? null;
        if (!$quote || empty($quote['close'])) {
            return null;
        }

        $closes = [];
        $vols = [];
        foreach ($quote['close'] as $idx => $c) {
            if ($c !== null && $c > 0) {
                $closes[] = (float) $c;
                $vols[] = (float) ($quote['volume'][$idx] ?? 0);
            }
        }

        $count = count($closes);
        if ($count < 5) return null;

        $close = $closes[$count - 1];
        $prevClose = $closes[$count - 2];
        $changePct = (($close - $prevClose) / $prevClose) * 100;
        $todayVol = $vols[$count - 1];

        $histVols = array_filter(array_slice($vols, max(0, $count - 21), -1), fn($v) => $v > 0);
        $avgVol = !empty($histVols) ? (array_sum($histVols) / count($histVols)) : 0;
        $rvol = $avgVol > 0 ? ($todayVol / $avgVol) : 0;

        $downStreak = 0;
        for ($i = $count - 2; $i >= 1; $i--) {
            if ($closes[$i] < $closes[$i - 1]) {
                $downStreak++;
            } else {
                break;
            }
        }

        return [
            'symbol' => $sym,
            'close' => $close,
            'change_pct' => round($changePct, 2),
            'today_vol' => $todayVol,
            'rvol' => round($rvol, 1),
            'down_streak' => $downStreak,
        ];
    }

    private function fetchForeignFollowThrough(?string $token, array $sortedByChange, string $prevDate): array
    {
        if (empty($token)) {
            return [];
        }

        $greenCandidates = array_slice(
            array_filter($sortedByChange, fn($r) => $r['change_pct'] > 0),
            0,
            16,
            true
        );

        if (empty($greenCandidates)) {
            return [];
        }

        $responses = [];
        foreach (array_chunk(array_keys($greenCandidates), 6) as $chunk) {
            $batch = Http::pool(function ($pool) use ($chunk, $token, $prevDate) {
                foreach ($chunk as $sym) {
                    $pool->as($sym)
                        ->withToken($token)
                        ->withOptions(['force_ip_resolve' => 'v4'])
                        ->withHeaders([
                            'Origin' => 'https://stockbit.com',
                            'Referer' => 'https://stockbit.com/',
                            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
                            'Accept'     => 'application/json',
                        ])
                        ->connectTimeout(4)
                        ->timeout(7)
                        ->get("https://exodus.stockbit.com/marketdetectors/{$sym}", [
                            'from' => $prevDate,
                            'to' => $prevDate,
                            'transaction_type' => 'TRANSACTION_TYPE_NET',
                            'market_board' => 'MARKET_BOARD_REGULER',
                            'investor_type' => 'INVESTOR_TYPE_FOREIGN',
                        ]);
                }
            });
            foreach ($batch as $k => $v) {
                $responses[$k] = $v;
            }
            usleep(180000);
        }

        $results = [];
        foreach ($greenCandidates as $sym => $item) {
            $res = $responses[$sym] ?? null;
            if (!$res || $res instanceof \Throwable || $res->failed()) {
                continue;
            }

            $netForeign = $this->parseNetBuyFromDetector($res->json());
            if ($netForeign >= 50_000_000) {
                $results[$sym] = [
                    'symbol' => $sym,
                    'change_pct' => $item['change_pct'],
                    'net_foreign_b' => round($netForeign / 1_000_000_000, 2),
                ];
            }
        }

        uasort($results, fn($a, $b) => $b['net_foreign_b'] <=> $a['net_foreign_b']);
        return array_slice($results, 0, 5, true);
    }

    private function formatReport(
        string $timestamp,
        ?array $ihsgData,
        array $topGainers,
        array $topLosers,
        array $topForeignBuy,
        array $topLocalBuy,
        array $foreignFollow,
        array $rebound3d,
        array $volSpikes,
        string $source
    ): string {
        $lines = [];
        $lines[] = "📊 <b>MARKET REPORT SESI 1</b>";
        $lines[] = "🕒 <i>{$timestamp}</i>";
        $lines[] = "━━━━━━━━━━━━━━━━━━━━";

        if ($ihsgData !== null) {
            $lvl = number_format($ihsgData['price'], 2, ',', '.');
            $pct = $ihsgData['change_pct'];
            $dir = $pct >= 0 ? "🟢 naik <b>+{$pct}%</b>" : "🔴 turun <b>{$pct}%</b>";
            $lines[] = "🏛 <b>IHSG: {$lvl}</b> ({$dir})";
        } else {
            $lines[] = "🏛 <b>IHSG</b>: Data indeks belum tersedia.";
        }

        $lines[] = "";
        $lines[] = "🚀 <b>Top Gainer:</b>";
        if (empty($topGainers)) {
            $lines[] = "• <i>Tidak ada data</i>";
        } else {
            foreach ($topGainers as $r) {
                $lines[] = "• <b>{$r['symbol']}</b>: +{$r['change_pct']}% (Rp " . number_format($r['close'], 0, ',', '.') . ")";
            }
        }

        $lines[] = "";
        $lines[] = "🔻 <b>Top Loser:</b>";
        if (empty($topLosers)) {
            $lines[] = "• <i>Tidak ada data</i>";
        } else {
            foreach ($topLosers as $r) {
                $lines[] = "• <b>{$r['symbol']}</b>: {$r['change_pct']}% (Rp " . number_format($r['close'], 0, ',', '.') . ")";
            }
        }

        $lines[] = "";
        $lines[] = "🌍 <b>Top Foreign Buy:</b>";
        if (empty($topForeignBuy)) {
            $lines[] = "• <i>Belum ada net foreign buy signifikan</i>";
        } else {
            foreach ($topForeignBuy as $r) {
                $sign = $r['change_pct'] >= 0 ? '+' : '';
                $lines[] = "• <b>{$r['symbol']}</b>: Net Buy <b>Rp {$r['net_b']} M</b> ({$sign}{$r['change_pct']}%)";
            }
        }

        $lines[] = "";
        $lines[] = "🏠 <b>Top Local Buy:</b>";
        if (empty($topLocalBuy)) {
            $lines[] = "• <i>Belum ada net local buy signifikan</i>";
        } else {
            foreach ($topLocalBuy as $r) {
                $sign = $r['change_pct'] >= 0 ? '+' : '';
                $lines[] = "• <b>{$r['symbol']}</b>: Net Buy <b>Rp {$r['net_b']} M</b> ({$sign}{$r['change_pct']}%)";
            }
        }

        $lines[] = "";
        $lines[] = "🔥 <b>Naik Setelah Asing Beli Kemarin:</b>";
        if (empty($foreignFollow)) {
            $lines[] = "• <i>Belum terdeteksi follow-through net buy asing</i>";
        } else {
            foreach ($foreignFollow as $r) {
                $lines[] = "• <b>{$r['symbol']}</b>: +{$r['change_pct']}% / Net Buy Kemarin <b>Rp {$r['net_foreign_b']} M</b>";
            }
        }

        $lines[] = "";
        $lines[] = "🔄 <b>Naik Setelah Turun 3 Hari:</b>";
        if (empty($rebound3d)) {
            $lines[] = "• <i>Belum ada kandidat technical rebound 3D</i>";
        } else {
            foreach ($rebound3d as $r) {
                $lines[] = "• <b>{$r['symbol']}</b>: +{$r['change_pct']}% (<i>turun {$r['down_streak']} hari beruntun</i>)";
            }
        }

        $lines[] = "";
        $lines[] = "💥 <b>Lonjakan Volume:</b>";
        if (empty($volSpikes)) {
            $lines[] = "• <i>Belum ada lonjakan volume &gt;= 2x</i>";
        } else {
            foreach ($volSpikes as $r) {
                $sign = $r['change_pct'] >= 0 ? '+' : '';
                $lines[] = "• <b>{$r['symbol']}</b>: <b>{$r['rvol']}x</b> ({$sign}{$r['change_pct']}%)";
            }
        }

        $lines[] = "";
        $lines[] = "📡 <i>Sumber: {$source} | Ketik /analisa KODE untuk bedah chart</i>";

        return implode("\n", $lines);
    }
}
