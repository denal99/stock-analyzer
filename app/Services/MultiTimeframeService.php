<?php

namespace App\Services;

use Exception;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class MultiTimeframeService
{
    public function analyze(string $rawSymbol): array
    {
        $sym = preg_replace('/JK$/', '', strtoupper(trim(preg_replace('/[^A-Za-z0-9]/', '', $rawSymbol))));
        if (strlen($sym) < 4) {
            throw new Exception('Kode saham tidak valid. Contoh: /mtf BBCA');
        }

        return Cache::remember("idx_mtf_v1_{$sym}", 180, function () use ($sym) {
            $timeframes = [
                '1H' => ['range' => '1mo', 'interval' => '1h',  'label' => '1 Jam (Intraday)'],
                '1D' => ['range' => '1y',  'interval' => '1d',  'label' => '1 Hari (Daily)'],
                '1W' => ['range' => '3y',  'interval' => '1wk', 'label' => '1 Minggu (Weekly)'],
                '1M' => ['range' => '10y', 'interval' => '1mo', 'label' => '1 Bulan (Monthly)'],
            ];

            $responses = Http::pool(function (Pool $pool) use ($sym, $timeframes) {
                $reqs = [];
                foreach ($timeframes as $tf => $cfg) {
                    $url = "https://query1.finance.yahoo.com/v8/finance/chart/{$sym}.JK?range={$cfg['range']}&interval={$cfg['interval']}";
                    $reqs[$tf] = $pool->as($tf)
                        ->connectTimeout(4)
                        ->timeout(8)
                        ->withOptions(['force_ip_resolve' => 'v4'])
                        ->withHeaders([
                            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
                        ])
                        ->get($url);
                }
                return $reqs;
            });

            $tfResults = [];
            $bullishCount = 0;
            $validCount = 0;
            $latestPrice = 0.0;

            foreach ($timeframes as $tf => $cfg) {
                $res = $responses[$tf] ?? null;
                if (!$res || $res instanceof \Throwable || $res->failed()) {
                    $tfResults[$tf] = null;
                    continue;
                }

                $result = $res->json('chart.result.0');
                $meta = $result['meta'] ?? [];
                $closes = $result['indicators']['quote'][0]['close'] ?? [];
                $closes = array_values(array_filter($closes, fn($v) => $v !== null && $v > 0));
                $cnt = count($closes);

                if ($cnt < 15) {
                    $tfResults[$tf] = null;
                    continue;
                }

                $validCount++;
                $close = (float) $closes[$cnt - 1];
                if ($tf === '1D' || $latestPrice <= 0) {
                    $latestPrice = isset($meta['regularMarketPrice']) && $meta['regularMarketPrice'] > 0
                        ? (float) $meta['regularMarketPrice']
                        : $close;
                }

                $ema20 = $this->calculateEma($closes, min(20, $cnt));
                $ema50 = $this->calculateEma($closes, min(50, $cnt));
                $rsi = $this->calculateRsi($closes, 14);
                $macdHist = $this->calculateMacdHist($closes);

                // Evaluasi arah tren per timeframe
                $aboveEma20 = $close >= $ema20;
                $aboveEma50 = $close >= $ema50;
                $emaAligned = $ema20 >= $ema50;

                if ($aboveEma20 && $aboveEma50 && $emaAligned) {
                    $status = '🟢 STRONG BULLISH';
                    $isBullish = true;
                } elseif ($aboveEma20 && $aboveEma50) {
                    $status = '🟢 BULLISH';
                    $isBullish = true;
                } elseif (!$aboveEma20 && $aboveEma50) {
                    $status = '🟡 PULLBACK / KONSOLIDASI';
                    $isBullish = false;
                } elseif ($aboveEma20 && !$aboveEma50) {
                    $status = '🟡 EARLY REBOUND';
                    $isBullish = false;
                } else {
                    $status = '🔴 BEARISH';
                    $isBullish = false;
                }

                if ($isBullish) {
                    $bullishCount++;
                }

                $tfResults[$tf] = [
                    'tf' => $tf,
                    'label' => $cfg['label'],
                    'close' => $close,
                    'ema20' => round($ema20, 0),
                    'ema50' => round($ema50, 0),
                    'rsi' => round($rsi, 1),
                    'macd_hist' => round($macdHist, 2),
                    'status' => $status,
                    'is_bullish' => $isBullish,
                ];
            }

            if ($validCount === 0) {
                throw new Exception("Data emiten {$sym} tidak ditemukan atau tidak mencukupi.");
            }

            // Kesimpulan Konfluensi Lintas Timeframe
            $htfBullish = (!empty($tfResults['1W']['is_bullish']) ? 1 : 0) + (!empty($tfResults['1M']['is_bullish']) ? 1 : 0);
            $ltfBullish = (!empty($tfResults['1H']['is_bullish']) ? 1 : 0) + (!empty($tfResults['1D']['is_bullish']) ? 1 : 0);

            if ($bullishCount === 4) {
                $verdict = '🚀 4/4 FULL BULLISH CONFLUENCE';
                $advice = 'Seluruh timeframe (1H–1M) searah naik. Tren institusi & momentum jangka pendek sangat selaras; valid untuk trend-following / breakout entry.';
            } elseif ($bullishCount === 3) {
                $verdict = '🟢 3/4 STRONG ALIGNMENT';
                $advice = 'Mayoritas timeframe mendukung tren naik. Jika 1H sedang pullback, manfaatkan area support harian sebagai titik entry terukur.';
            } elseif ($htfBullish === 2 && $ltfBullish === 0) {
                $verdict = '🟡 2/4 HEALTHY PULLBACK IN UPTREND';
                $advice = 'Tren besar (1W & 1M) masih kuat naik, namun 1H & 1D sedang koreksi jangka pendek. Pantau konfirmasi pantulan sebelum masuk.';
            } elseif ($htfBullish === 0 && $ltfBullish >= 1) {
                $verdict = '⚠️ COUNTER-TREND BOUNCE (Waspada Bull Trap)';
                $advice = 'Kenaikan hanya terjadi di timeframe pendek (1H/1D) sementara tren besar (1W & 1M) masih bearish. Cocok hanya untuk scalping cepat dengan SL ketat.';
            } elseif ($bullishCount === 2) {
                $verdict = '🟡 2/4 MIXED / TRANSITION';
                $isDailyBullish = !empty($tfResults['1D']['is_bullish']);
                $advice = $isDailyBullish
                    ? 'Tren harian (1D) sudah bullish, namun timeframe besar masih dalam fase transisi. Manfaatkan pullback dekat support 1D EMA20/50 untuk entry bertahap.'
                    : 'Arah tren belum kompak antar-timeframe. Tunggu konfirmasi penutupan harian (1D) mampu bertahan di atas EMA 20/50.';
            } else {
                $verdict = "🔴 {$bullishCount}/4 BEARISH ALIGNMENT";
                $advice = 'Tekanan turun mendominasi lintas timeframe. Hindari averaging down sebelum struktur tren berbalik.';
            }

            return [
                'symbol' => $sym,
                'price' => $latestPrice,
                'score' => $bullishCount,
                'total' => $validCount,
                'verdict' => $verdict,
                'advice' => $advice,
                'timeframes' => $tfResults,
            ];
        });
    }

    private function calculateEma(array $values, int $period): float
    {
        $n = count($values);
        if ($n === 0) {
            return 0.0;
        }
        if ($n < $period) {
            return array_sum($values) / $n;
        }

        $k = 2 / ($period + 1);
        $ema = array_sum(array_slice($values, 0, $period)) / $period;

        for ($i = $period; $i < $n; $i++) {
            $ema = ($values[$i] * $k) + ($ema * (1 - $k));
        }

        return $ema;
    }

    private function calculateRsi(array $closes, int $period = 14): float
    {
        $n = count($closes);
        if ($n <= $period) {
            return 50.0;
        }

        $gains = 0.0;
        $losses = 0.0;

        for ($i = 1; $i <= $period; $i++) {
            $diff = $closes[$i] - $closes[$i - 1];
            if ($diff >= 0) {
                $gains += $diff;
            } else {
                $losses += abs($diff);
            }
        }

        $avgGain = $gains / $period;
        $avgLoss = $losses / $period;

        for ($i = $period + 1; $i < $n; $i++) {
            $diff = $closes[$i] - $closes[$i - 1];
            $gain = $diff > 0 ? $diff : 0.0;
            $loss = $diff < 0 ? abs($diff) : 0.0;

            $avgGain = (($avgGain * ($period - 1)) + $gain) / $period;
            $avgLoss = (($avgLoss * ($period - 1)) + $loss) / $period;
        }

        if ($avgLoss == 0.0) {
            return 100.0;
        }

        $rs = $avgGain / $avgLoss;
        return 100 - (100 / (1 + $rs));
    }

    private function calculateMacdHist(array $closes): float
    {
        $n = count($closes);
        if ($n < 26) {
            return 0.0;
        }

        $macdSeries = [];
        for ($i = 25; $i < $n; $i++) {
            $slice = array_slice($closes, 0, $i + 1);
            $ema12 = $this->calculateEma($slice, 12);
            $ema26 = $this->calculateEma($slice, 26);
            $macdSeries[] = $ema12 - $ema26;
        }

        if (empty($macdSeries)) {
            return 0.0;
        }

        $latestMacd = end($macdSeries);
        $signal = $this->calculateEma($macdSeries, min(9, count($macdSeries)));
        return $latestMacd - $signal;
    }
}
