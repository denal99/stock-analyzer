<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;
use Exception;

class StockScreenerService
{
    private array $watchlist = [
        'BBCA', 'BBRI', 'BMRI', 'BBNI', 'BBTN', 'BRIS', 'BNGA', 'BDMN', 'NISP', 'BJBR', 'BJTM',
        'ASII', 'UNTR', 'AUTO', 'DRMA', 'GJTL',
        'TLKM', 'ISAT', 'EXCL', 'TOWR', 'TBIG', 'MTEL',
        'ADRO', 'PTBA', 'ITMG', 'HRUM', 'INDY', 'BSSR', 'MBAP', 'ADMR', 'BYAN',
        'PGAS', 'AKRA', 'MEDC', 'ELSA', 'RAJA', 'ENRG',
        'ANTM', 'INCO', 'TINS', 'MDKA', 'MBMA', 'NCKL', 'AMMN',
        'ICBP', 'INDF', 'MYOR', 'CPIN', 'JPFA', 'MAIN', 'ULTJ', 'CMRY', 'SIDO', 'KLBF',
        'SMGR', 'INTP', 'INKP', 'TKIM', 'TPIA', 'BRPT', 'ESSA',
        'BSDE', 'PWON', 'CTRA', 'SMRA', 'PANI', 'ASRI',
        'MAPI', 'ACES', 'AMRT', 'MIDI', 'ERAA', 'LPPF',
        'JSMR', 'BIRD', 'ASSA', 'HEAL', 'MIKA', 'SILO', 'SCMA', 'MNCN', 'EMTK', 'GOTO', 'BUKA'
    ];

    public function screenValueStocks(
        float $maxPer = 10.0,
        float $maxPbv = 5.0,
        ?float $maxDer = null,
        ?float $minDividend = null,
        int $limit = 15
    ): array {
        $allStocks = $this->getWatchlistFundamentals();

        $filtered = array_filter($allStocks, function ($stock) use ($maxPer, $maxPbv, $maxDer, $minDividend) {
            $passBasic = $stock['per'] !== null
                && $stock['per'] > 0
                && $stock['per'] < $maxPer
                && $stock['pbv'] !== null
                && $stock['pbv'] > 0
                && $stock['pbv'] < $maxPbv;

            if (!$passBasic) {
                return false;
            }

            if ($maxDer !== null) {
                if ($stock['der'] === null or $stock['der'] >= $maxDer) {
                    return false;
                }
            }

            if ($minDividend !== null) {
                if ($stock['dividend_yield'] === null or $stock['dividend_yield'] < $minDividend) {
                    return false;
                }
            }

            return true;
        });

        usort($filtered, fn($a, $b) => $b['market_cap'] <=> $a['market_cap']);

        return array_slice($filtered, 0, $limit);
    }

    public function screenDividendStocks(float $minDividend = 5.0, int $limit = 15): array
    {
        $allStocks = $this->getWatchlistFundamentals();

        $filtered = array_filter($allStocks, function ($stock) use ($minDividend) {
            return $stock['dividend_yield'] !== null
                && $stock['dividend_yield'] >= $minDividend;
        });

        usort($filtered, fn($a, $b) => $b['dividend_yield'] <=> $a['dividend_yield']);
        $topStocks = array_slice($filtered, 0, $limit);

        return $this->enrichDividendDetails($topStocks);
    }

    public function screenVolumeSpike(float $minRatio = 1.5, bool $onlyGreen = true, int $limit = 15): array
    {
        $allPriceVol = $this->getWatchlistPriceVolume();

        $filtered = array_filter($allPriceVol, function ($item) use ($minRatio, $onlyGreen) {
            if ($item['vol_ratio'] < $minRatio) {
                return false;
            }
            if ($onlyGreen && $item['change_pct'] < 0) {
                return false;
            }
            return true;
        });

        usort($filtered, fn($a, $b) => $b['vol_ratio'] <=> $a['vol_ratio']);

        return array_slice($filtered, 0, $limit);
    }

    public function screenAccumulation(int $limit = 15): array
    {
        $allPriceVol = $this->getWatchlistPriceVolume();

        // Filter kandidat yang menunjukkan jejak akumulasi (skor >= 60)
        $candidates = array_filter($allPriceVol, fn($item) => $item['accum_score'] >= 60);

        // Urutkan dari Skor Akumulasi tertinggi, lalu rasio volume 3 hari
        usort($candidates, function ($a, $b) {
            if ($b['accum_score'] === $a['accum_score']) {
                return $b['vol_3d_ratio'] <=> $a['vol_3d_ratio'];
            }
            return $b['accum_score'] <=> $a['accum_score'];
        });

        return array_slice($candidates, 0, $limit);
    }

    private function getWatchlistPriceVolume(): array
    {
        return Cache::remember('idx_watchlist_price_vol_v2', 300, function () {
            $symbols = array_unique($this->watchlist);

            $responses = Http::pool(function (Pool $pool) use ($symbols) {
                $requests = [];
                foreach ($symbols as $sym) {
                    $url = "https://query1.finance.yahoo.com/v8/finance/chart/{$sym}.JK?range=2mo&interval=1d";
                    $requests[$sym] = $pool->as($sym)
                        ->timeout(12)
                        ->withOptions(['force_ip_resolve' => 'v4'])
                        ->withHeaders(['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)'])
                        ->get($url);
                }
                return $requests;
            });

            $results = [];

            foreach ($symbols as $sym) {
                $res = $responses[$sym] ?? null;
                if (!$res or $res->failed()) {
                    continue;
                }

                $result = $res->json('chart.result.0');
                $quotes = $result['indicators']['quote'][0] ?? null;
                if (!$quotes) {
                    continue;
                }

                $opens = [];
                $highs = [];
                $lows = [];
                $closes = [];
                $volumes = [];
                $count = count($quotes['close'] ?? []);

                for ($i = 0; $i < $count; $i++) {
                    if (
                        isset($quotes['open'][$i], $quotes['high'][$i], $quotes['low'][$i], $quotes['close'][$i], $quotes['volume'][$i])
                        && $quotes['volume'][$i] > 0
                    ) {
                        $opens[] = (float) $quotes['open'][$i];
                        $highs[] = (float) $quotes['high'][$i];
                        $lows[] = (float) $quotes['low'][$i];
                        $closes[] = (float) $quotes['close'][$i];
                        $volumes[] = (float) $quotes['volume'][$i];
                    }
                }

                $n = count($volumes);
                if ($n < 22) {
                    continue;
                }

                $todayClose = $closes[$n - 1];
                $prevClose = $closes[$n - 2];
                $todayHigh = $highs[$n - 1];
                $todayLow = $lows[$n - 1];
                $todayVol = $volumes[$n - 1];

                // Rata-rata volume 20 hari sebelum hari ini
                $past20Vol = array_slice($volumes, -21, 20);
                $avgVol20 = array_sum($past20Vol) / 20;

                // Rata-rata volume 3 hari terakhir (mendeteksi akumulasi bertahap)
                $last3Vol = array_slice($volumes, -3);
                $avgVol3 = array_sum($last3Vol) / 3;

                // MA 20 harga penutupan
                $last20Close = array_slice($closes, -20);
                $ma20 = array_sum($last20Close) / 20;

                if ($avgVol20 <= 0 or $ma20 <= 0) {
                    continue;
                }

                $volRatio = round($todayVol / $avgVol20, 2);
                $vol3dRatio = round($avgVol3 / $avgVol20, 2);
                $changePct = round((($todayClose - $prevClose) / $prevClose) * 100, 2);
                $distFromMa20 = round((($todayClose - $ma20) / $ma20) * 100, 2);
                $turnover = $todayClose * $todayVol;

                // Filter minimal likuiditas Rp 2 Miliar
                if ($turnover < 2_000_000_000) {
                    continue;
                }

                // --- KALKULASI SKOR AKUMULASI (VSA / Wyckoff) ---
                $score = 0;
                $signals = [];

                // 1. Peningkatan volume 3 hari terakhir dibanding rata-rata 20 hari
                if ($vol3dRatio >= 1.3) {
                    $score += 30;
                    $signals[] = "Vol 3D +";
                } elseif ($vol3dRatio >= 1.05) {
                    $score += 20;
                    $signals[] = "Vol stabil naik";
                }

                // 2. Harga berada di atas MA20 tapi belum lari terlalu jauh (area akumulasi 0% s/d +8%)
                if ($distFromMa20 >= 0 && $distFromMa20 <= 8.0) {
                    $score += 25;
                    $signals[] = "Di atas MA20";
                }

                // 3. Kekuatan Closing Price (Close Position Value >= 0.65 -> ditutup dekat harga tertinggi harian)
                $range = $todayHigh - $todayLow;
                $closePos = $range > 0 ? ($todayClose - $todayLow) / $range : 0.5;
                if ($closePos >= 0.70 && $changePct >= 0) {
                    $score += 25;
                    $signals[] = "Strong Close";
                } elseif ($closePos >= 0.50 && $changePct >= 0) {
                    $score += 15;
                }

                // 4. OBV (On-Balance Volume) 5 hari terakhir positif (lebih banyak volume saat hari hijau)
                $obvDelta = 0;
                for ($k = $n - 5; $k < $n; $k++) {
                    if ($closes[$k] > $closes[$k - 1]) {
                        $obvDelta += $volumes[$k];
                    } elseif ($closes[$k] < $closes[$k - 1]) {
                        $obvDelta -= $volumes[$k];
                    }
                }
                if ($obvDelta > 0) {
                    $score += 20;
                    $signals[] = "OBV Naik";
                }

                // Penalti jika harga turun tajam (> -2%)
                if ($changePct < -2.0) {
                    $score -= 30;
                }

                $results[] = [
                    'symbol' => $sym,
                    'price' => $todayClose,
                    'change_pct' => $changePct,
                    'vol_ratio' => $volRatio,
                    'vol_3d_ratio' => $vol3dRatio,
                    'today_lot' => round($todayVol / 100),
                    'avg_lot' => round($avgVol20 / 100),
                    'turnover_formatted' => $this->formatMarketCap($turnover),
                    'accum_score' => max(0, $score),
                    'accum_signals' => !empty($signals) ? implode(', ', $signals) : 'Netral',
                ];
            }

            return $results;
        });
    }

    private function enrichDividendDetails(array $stocks): array
    {
        if (empty($stocks)) {
            return [];
        }

        $responses = Http::pool(function (Pool $pool) use ($stocks) {
            $requests = [];
            foreach ($stocks as $s) {
                $sym = $s['symbol'];
                $url = "https://query1.finance.yahoo.com/v8/finance/chart/{$sym}.JK?range=15mo&interval=1mo&events=div";
                $requests[$sym] = $pool->as($sym)
                    ->timeout(12)
                    ->withOptions(['force_ip_resolve' => 'v4'])
                    ->withHeaders(['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)'])
                    ->get($url);
            }
            return $requests;
        });

        $bulanIndo = [
            1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr',
            5 => 'Mei', 6 => 'Jun', 7 => 'Jul', 8 => 'Agu',
            9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des'
        ];

        foreach ($stocks as &$s) {
            $sym = $s['symbol'];
            $res = $responses[$sym] ?? null;

            $divEvents = [];
            if ($res && $res->successful()) {
                $divEvents = $res->json('chart.result.0.events.dividends') ?? [];
            }

            usort($divEvents, fn($a, $b) => ($a['date'] ?? 0) <=> ($b['date'] ?? 0));

            $finalMonth = '-';
            $interimMonth = '-';

            if (count($divEvents) === 1) {
                $cumDate = Carbon::createFromTimestamp($divEvents[0]['date'], 'Asia/Jakarta')->subWeekday();
                $bln = $bulanIndo[(int) $cumDate->format('n')] ?? $cumDate->format('M');
                $finalMonth = "{$bln} " . $cumDate->format('Y');
            } elseif (count($divEvents) >= 2) {
                foreach ($divEvents as $ev) {
                    $cumDate = Carbon::createFromTimestamp($ev['date'], 'Asia/Jakarta')->subWeekday();
                    $monthNum = (int) $cumDate->format('n');
                    $bln = $bulanIndo[$monthNum] ?? $cumDate->format('M');
                    $label = "{$bln} " . $cumDate->format('Y');

                    if ($monthNum >= 2 && $monthNum <= 7) {
                        $finalMonth = $label;
                    } else {
                        $interimMonth = $label;
                    }
                }

                if ($finalMonth === '-' or $interimMonth === '-') {
                    usort($divEvents, fn($a, $b) => ($b['amount'] ?? 0) <=> ($a['amount'] ?? 0));
                    $cumFinal = Carbon::createFromTimestamp($divEvents[0]['date'], 'Asia/Jakarta')->subWeekday();
                    $cumInterim = Carbon::createFromTimestamp($divEvents[1]['date'], 'Asia/Jakarta')->subWeekday();

                    $finalMonth = ($bulanIndo[(int) $cumFinal->format('n')] ?? $cumFinal->format('M')) . ' ' . $cumFinal->format('Y');
                    $interimMonth = ($bulanIndo[(int) $cumInterim->format('n')] ?? $cumInterim->format('M')) . ' ' . $cumInterim->format('Y');
                }
            } elseif (!empty($s['ex_div_ts'])) {
                $cumDate = Carbon::createFromTimestamp($s['ex_div_ts'], 'Asia/Jakarta')->subWeekday();
                $bln = $bulanIndo[(int) $cumDate->format('n')] ?? $cumDate->format('M');
                $finalMonth = "{$bln} " . $cumDate->format('Y');
            }

            $s['final_month'] = $finalMonth;
            $s['interim_month'] = $interimMonth;
        }

        return $stocks;
    }

    private function getWatchlistFundamentals(): array
    {
        return Cache::remember('idx_watchlist_fundamentals_v6', 900, function () {
            $auth = $this->getYahooCrumbAndCookie();
            $symbols = array_unique($this->watchlist);

            $responses = Http::pool(function (Pool $pool) use ($symbols, $auth) {
                $requests = [];
                foreach ($symbols as $sym) {
                    $ticker = "{$sym}.JK";
                    $url = "https://query1.finance.yahoo.com/v10/finance/quoteSummary/{$ticker}?modules=price,defaultKeyStatistics,summaryDetail,financialData,balanceSheetHistoryQuarterly&crumb=" . urlencode($auth['crumb']);
                    
                    $requests[$sym] = $pool->as($sym)
                        ->timeout(15)
                        ->withOptions(['force_ip_resolve' => 'v4'])
                        ->withHeaders([
                            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
                            'Cookie' => $auth['cookie'],
                        ])
                        ->get($url);
                }
                return $requests;
            });

            $results = [];

            foreach ($symbols as $sym) {
                $res = $responses[$sym] ?? null;
                if (!$res or $res->failed()) {
                    continue;
                }

                $summary = $res->json('quoteSummary.result.0');
                if (!$summary) {
                    continue;
                }

                $price = $summary['price']['regularMarketPrice']['raw'] ?? null;
                $marketCap = $summary['price']['marketCap']['raw'] ?? ($summary['summaryDetail']['marketCap']['raw'] ?? 0);
                $per = $summary['summaryDetail']['trailingPE']['raw'] ?? ($summary['defaultKeyStatistics']['trailingPE']['raw'] ?? null);
                $pbv = $summary['defaultKeyStatistics']['priceToBook']['raw'] ?? null;

                $rawDer = $summary['financialData']['debtToEquity']['raw'] ?? null;
                $der = $rawDer !== null ? round($rawDer / 100, 2) : null;

                if ($der === null) {
                    $bs = $summary['balanceSheetHistoryQuarterly']['balanceSheetStatements'][0] ?? null;
                    $totalLiab = $bs['totalLiab']['raw'] ?? null;
                    $totalEquity = $bs['totalStockholderEquity']['raw'] ?? null;

                    if ($totalLiab !== null && $totalEquity !== null && $totalEquity > 0) {
                        $der = round($totalLiab / $totalEquity, 2);
                    }
                }

                $rawDiv = $summary['summaryDetail']['dividendYield']['raw']
                    ?? ($summary['summaryDetail']['trailingAnnualDividendYield']['raw'] ?? null);
                $dividendYield = ($rawDiv !== null && $rawDiv > 0) ? round($rawDiv * 100, 2) : null;

                $exDivTs = $summary['summaryDetail']['exDividendDate']['raw']
                    ?? ($summary['defaultKeyStatistics']['lastDividendDate']['raw'] ?? null);

                if ($per === null && $price && !empty($summary['defaultKeyStatistics']['trailingEps']['raw'])) {
                    $eps = $summary['defaultKeyStatistics']['trailingEps']['raw'];
                    if ($eps > 0) {
                        $per = $price / $eps;
                    }
                }

                if ($price) {
                    $results[] = [
                        'symbol' => $sym,
                        'price' => $price,
                        'per' => $per !== null ? round($per, 2) : null,
                        'pbv' => $pbv !== null ? round($pbv, 2) : null,
                        'der' => $der,
                        'dividend_yield' => $dividendYield,
                        'ex_div_ts' => $exDivTs,
                        'market_cap' => $marketCap,
                        'market_cap_formatted' => $this->formatMarketCap($marketCap),
                    ];
                }
            }

            return $results;
        });
    }

    private function getYahooCrumbAndCookie(): array
    {
        return Cache::remember('yahoo_finance_crumb_session', 3600, function () {
            $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

            $cookieRes = Http::timeout(15)
                ->withOptions(['force_ip_resolve' => 'v4', 'allow_redirects' => true])
                ->withHeaders(['User-Agent' => $userAgent])
                ->get('https://fc.yahoo.com');

            $setCookies = $cookieRes->header('Set-Cookie');
            if (is_array($setCookies)) {
                $cookieParts = array_map(fn($c) => explode(';', $c)[0], $setCookies);
                $cookieString = implode('; ', $cookieParts);
            } else {
                $cookieString = explode(';', (string) $setCookies)[0];
            }

            $crumbRes = Http::timeout(15)
                ->withOptions(['force_ip_resolve' => 'v4'])
                ->withHeaders([
                    'User-Agent' => $userAgent,
                    'Cookie' => $cookieString,
                ])
                ->get('https://query1.finance.yahoo.com/v1/test/getcrumb');

            $crumb = trim($crumbRes->body());

            if (empty($crumb) or str_contains($crumb, '{')) {
                throw new Exception("Gagal mendapatkan sesi crumb dari Yahoo Finance.");
            }

            return [
                'cookie' => $cookieString,
                'crumb' => $crumb,
            ];
        });
    }

    private function formatMarketCap(float|int $cap): string
    {
        if ($cap >= 1_000_000_000_000) {
            return number_format($cap / 1_000_000_000_000, 2) . ' T';
        }
        if ($cap >= 1_000_000_000) {
            return number_format($cap / 1_000_000_000, 2) . ' M';
        }
        return number_format($cap);
    }
}
