<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Exception;

class StockAnalysisService
{
    public function analyze(string $ticker, float $maxRiskRp = 1000000, string $timeframe = '1D'): array
    {
        $symbol = strtoupper(trim($ticker));
        if (strlen($symbol) === 4 && ctype_alpha($symbol)) {
            $symbol .= '.JK';
        }

        if ($maxRiskRp <= 0) {
            $maxRiskRp = 1000000;
        }

        $tf = strtoupper(trim($timeframe));
        $tfConfig = match ($tf) {
            '1W' => ['range' => '5y', 'interval' => '1wk', 'label' => 'Weekly', 'unit' => 'month', 'date_fmt' => 'd/m/Y'],
            '1H' => ['range' => '3mo', 'interval' => '60m', 'label' => '1H Intraday', 'unit' => 'day', 'date_fmt' => 'd/m/Y H:i'],
            default => ['range' => '2y', 'interval' => '1d', 'label' => 'Daily', 'unit' => 'day', 'date_fmt' => 'd/m/Y'],
        };
        $url = "https://query1.finance.yahoo.com/v8/finance/chart/{$symbol}?range={$tfConfig['range']}&interval={$tfConfig['interval']}";
        
        $response = Http::timeout(20)
            ->withOptions(['force_ip_resolve' => 'v4'])
            ->withHeaders(['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)'])
            ->get($url);

        if ($response->failed() or empty($response->json('chart.result.0'))) {
            throw new Exception("Kode saham '{$symbol}' tidak ditemukan atau API sedang gangguan.");
        }

        $result = $response->json('chart.result.0');
        $timestamps = $result['timestamp'] ?? [];
        $quotes = $result['indicators']['quote'][0] ?? [];

        $opens = [];
        $highs = [];
        $lows = [];
        $closes = [];
        $volumes = [];
        $candles = [];

        foreach ($timestamps as $i => $ts) {
            if (isset($quotes['close'][$i], $quotes['open'][$i], $quotes['high'][$i], $quotes['low'][$i], $quotes['volume'][$i])) {
                $o = round($quotes['open'][$i], 2);
                $h = round($quotes['high'][$i], 2);
                $l = round($quotes['low'][$i], 2);
                $c = round($quotes['close'][$i], 2);
                $v = (float) $quotes['volume'][$i];

                $opens[] = $o;
                $highs[] = $h;
                $lows[] = $l;
                $closes[] = $c;
                $volumes[] = $v;

                $candles[] = [
                    'x' => $ts * 1000,
                    'o' => $o,
                    'h' => $h,
                    'l' => $l,
                    'c' => $c,
                    'v' => $v,
                    'ts' => $ts,
                ];
            }
        }

        $totalData = count($closes);
        if ($totalData < 50) {
            throw new Exception("Data historis {$symbol} terlalu sedikit untuk dianalisis.");
        }

        $ma20Series = $this->calculateSMA($closes, 20);
        $ma50Series = $this->calculateSMA($closes, 50);
        $ma200Series = $this->calculateSMA($closes, 200);
        $volLotsAll = array_map(fn($v) => round($v / 100), $volumes);
        $maVol20Series = $this->calculateSMA($volLotsAll, 20);
        $rsiSeries = $this->calculateRSI($closes, 14);
        $donchian = $this->calculateDonchian($highs, $lows, 20);

        $displayCount = 85;
        $chartCandles = array_slice($candles, -$displayCount);
        $rawMa20 = array_slice($ma20Series, -$displayCount);
        $rawMa50 = array_slice($ma50Series, -$displayCount);
        $rawMa200 = array_slice($ma200Series, -$displayCount);
        $rawMaVol20 = array_slice($maVol20Series, -$displayCount);
        $rawRsi = array_slice($rsiSeries, -$displayCount);

        $sMa20 = []; $sMa50 = []; $sMa200 = [];
        $sVol = []; $sMaVol20 = []; $sRsi = [];

        foreach ($chartCandles as $idx => $c) {
            $x = $c['x'];
            $sMa20[] = ['x' => $x, 'y' => $rawMa20[$idx]];
            $sMa50[] = ['x' => $x, 'y' => $rawMa50[$idx]];
            $sMa200[] = ['x' => $x, 'y' => $rawMa200[$idx]];
            $sMaVol20[] = ['x' => $x, 'y' => $rawMaVol20[$idx]];
            $sRsi[] = ['x' => $x, 'y' => $rawRsi[$idx]];

            $isGreen = $c['c'] >= $c['o'];
            $sVol[] = [
                'x' => $x,
                'y' => round($c['v'] / 100),
                'color' => $isGreen ? 'rgba(34, 197, 94, 0.50)' : 'rgba(239, 68, 68, 0.50)',
            ];
        }

        $lastCandle = end($chartCandles);
        $lastClose = str_ends_with($symbol, '.JK') ? $this->roundToIdxTick($lastCandle['c'], 'round') : $lastCandle['c'];
        $prevClose = $closes[$totalData - 2] ?? $lastClose;
        $change = $lastClose - $prevClose;
        $changePct = round(($change / $prevClose) * 100, 2);

        $isIdx = str_ends_with($symbol, '.JK');

        $rawMa20Last = end($ma20Series);
        $rawMa50Last = end($ma50Series);
        $rawMa200Last = end($ma200Series);

        $lastMa20 = ($rawMa20Last !== null && $isIdx) ? $this->roundToIdxTick($rawMa20Last, 'round') : $rawMa20Last;
        $lastMa50 = ($rawMa50Last !== null && $isIdx) ? $this->roundToIdxTick($rawMa50Last, 'round') : $rawMa50Last;
        $lastMa200 = ($rawMa200Last !== null && $isIdx) ? $this->roundToIdxTick($rawMa200Last, 'round') : $rawMa200Last;
        $lastRsi = end($rsiSeries);
        $topBoxNow = end($donchian['upper']);

        // --- KALKULASI RESISTANCE (R1-R3) SESUAI FRAKSI HARGA ---
        $pivot = ($lastCandle['h'] + $lastCandle['l'] + $lastClose) / 3;
        $rawR1 = $topBoxNow > $lastClose ? $topBoxNow : (2 * $pivot - $lastCandle['l']);
        if ($rawR1 <= $lastClose) {
            $rawR1 = $lastClose * 1.03;
        }
        $r1 = $isIdx ? $this->roundToIdxTick($rawR1, 'ceil') : round($rawR1, 2);
        if ($r1 <= $lastClose) {
            $r1 = $isIdx ? $this->stepIdxTick($lastClose, 2) : round($lastClose * 1.02, 2);
        }
        $r2 = $isIdx ? $this->roundToIdxTick($r1 * 1.035, 'ceil') : round($r1 * 1.035, 2);
        $r3 = $isIdx ? $this->roundToIdxTick($r1 * 1.07, 'ceil') : round($r1 * 1.07, 2);

        // --- KALKULASI SUPPORT DINAMIS (Swing Low, MA20, MA50, MA200) ---
        $swingLow8 = min(array_slice($lows, -8));
        $supportCandidates = [];

        // Kumpulkan semua level support teknikal valid di bawah harga penutupan (jarak 1% s/d 9%)
        foreach ([$swingLow8, $lastMa20, $lastMa50, $lastMa200] as $lvl) {
            if ($lvl !== null && $lvl < $lastClose * 0.992 && $lvl >= $lastClose * 0.91) {
                $supportCandidates[] = $lvl;
            }
        }

        if (!empty($supportCandidates)) {
            // Pilih support dinamis tertinggi (terdekat dengan harga saat ini) sebagai S1
            rsort($supportCandidates);
            $rawS1 = $supportCandidates[0];
        } else {
            $rawS1 = $lastClose * 0.955;
        }

        $s1 = $isIdx ? $this->roundToIdxTick($rawS1, 'floor') : round($rawS1, 2);
        if ($s1 >= $lastClose) {
            $s1 = $isIdx ? $this->stepIdxTick($lastClose, -2) : round($lastClose * 0.98, 2);
        }
        $s2 = $isIdx ? $this->roundToIdxTick($s1 * 0.965, 'floor') : round($s1 * 0.965, 2);
        $s3 = $isIdx ? $this->roundToIdxTick($s1 * 0.93, 'floor') : round($s1 * 0.93, 2);

        // Stoploss 1-2 tick di bawah S1 agar tidak mudah tersentuh false breakdown
        $stoploss = $isIdx ? $this->stepIdxTick($s1, -2) : round($s1 * 0.99, 2);
        if ($stoploss >= $lastClose) {
            $stoploss = $isIdx ? $this->stepIdxTick($lastClose, -3) : round($lastClose * 0.97, 2);
        }

        // Money Management berdasarkan Max Risk yang ditentukan
        $riskPerShare = max(1, $lastClose - $stoploss);
        $recShares = floor($maxRiskRp / $riskPerShare);
        $recLot = max(1, floor($recShares / 100));
        $allocRp = $recLot * 100 * $lastClose;

        // Status Sinyal
        $signalStatus = "Sideways / Konsolidasi";
        if ($lastClose >= $topBoxNow * 0.995) {
            $signalStatus = "Breakout Resistance!";
        } elseif ($lastMa200 && $lastClose > $lastMa200 && $prevClose <= $lastMa200) {
            $signalStatus = "Breakout MA200!";
        } elseif ($lastClose > $lastMa20 && $lastMa20 > $lastMa50) {
            $signalStatus = "Uptrend (Above MA20 & MA50)";
        } elseif ($lastClose < $lastMa20 && $lastClose < $lastMa50) {
            $signalStatus = "Downtrend (Below MA20 & MA50)";
        }

        $maVol20Lot = round(end($maVol20Series));
        $todayVolLot = round($lastCandle['v'] / 100);
        $volRatio = $maVol20Lot > 0 ? round($todayVolLot / $maVol20Lot, 2) : 1;

        $metaInfo = [
            'symbol' => str_replace('.JK', '', $symbol),
            'full_symbol' => $symbol,
            'date' => date($tfConfig['date_fmt'], $lastCandle['ts']),
            'tf_label' => $tfConfig['label'],
            'tf_unit' => $tfConfig['unit'],
            'o' => $lastCandle['o'],
            'h' => $lastCandle['h'],
            'l' => $lastCandle['l'],
            'c' => $lastClose,
            'chg' => $change,
            'chg_pct' => $changePct,
            'ma20' => $lastMa20,
            'ma50' => $lastMa50,
            'ma200' => $lastMa200,
            'rsi' => $lastRsi,
            'r1' => $r1, 'r2' => $r2, 'r3' => $r3,
            's1' => $s1, 's2' => $s2, 's3' => $s3,
            'target1' => $r1,
            'target2' => $r2,
            'stoploss' => $stoploss,
            'max_risk_rp' => $maxRiskRp,
            'rec_lot' => $recLot,
            'alloc_rp' => $allocRp,
            'signal' => $signalStatus,
            'vol_lot' => $todayVolLot,
            'ma_vol_lot' => $maVol20Lot,
            'vol_ratio' => $volRatio,
        ];

        $chartPath = $this->generateCleanProChart(
            $symbol,
            $chartCandles,
            $sMa20,
            $sMa50,
            $sMa200,
            $sVol,
            $sMaVol20,
            $sRsi,
            $metaInfo
        );

        return [
            'symbol' => $symbol,
            'price' => $lastClose,
            'change_pct' => $changePct,
            'ma50' => $lastMa50,
            'ma200' => $lastMa200,
            'r1' => $metaInfo['r1'] ?? 0,
            'stoploss' => $metaInfo['stoploss'] ?? 0,
            'vol_ratio' => $metaInfo['vol_ratio'] ?? 0,
            'chart_path' => $chartPath,
        ];
    }

    /**
     * Pembulatan sesuai Fraksi Harga resmi BEI (IDX)
     */
    private function getIdxTickSize(float $price): int
    {
        if ($price < 200) return 1;
        if ($price < 500) return 2;
        if ($price < 2000) return 5;
        if ($price < 5000) return 10;
        return 25;
    }

    private function roundToIdxTick(float $price, string $mode = 'round'): int
    {
        $tick = $this->getIdxTickSize($price);
        if ($mode === 'ceil') {
            return (int) (ceil($price / $tick) * $tick);
        }
        if ($mode === 'floor') {
            return (int) (floor($price / $tick) * $tick);
        }
        return (int) (round($price / $tick) * $tick);
    }

    private function stepIdxTick(float $price, int $steps): int
    {
        $current = $this->roundToIdxTick($price, 'round');
        $direction = $steps >= 0 ? 1 : -1;
        for ($i = 0; $i < abs($steps); $i++) {
            $tick = $this->getIdxTickSize($current);
            $current += ($direction * $tick);
        }
        return max(50, $current);
    }

    private function calculateSMA(array $data, int $period): array
    {
        $sma = [];
        $count = count($data);
        for ($i = 0; $i < $count; $i++) {
            if ($i < $period - 1) {
                $sma[] = null;
            } else {
                $slice = array_slice($data, $i - $period + 1, $period);
                $sma[] = round(array_sum($slice) / $period, 2);
            }
        }
        return $sma;
    }

    private function calculateDonchian(array $highs, array $lows, int $period = 20): array
    {
        $upper = [];
        $lower = [];
        $count = count($highs);
        for ($i = 0; $i < $count; $i++) {
            $start = max(0, $i - $period + 1);
            $len = $i - $start + 1;
            $upper[] = max(array_slice($highs, $start, $len));
            $lower[] = min(array_slice($lows, $start, $len));
        }
        return ['upper' => $upper, 'lower' => $lower];
    }

    private function calculateRSI(array $closes, int $period = 14): array
    {
        $rsi = [];
        $count = count($closes);
        $gains = [0];
        $losses = [0];

        for ($i = 1; $i < $count; $i++) {
            $diff = $closes[$i] - $closes[$i - 1];
            $gains[] = $diff > 0 ? $diff : 0;
            $losses[] = $diff < 0 ? abs($diff) : 0;
        }

        $avgGain = 0;
        $avgLoss = 0;

        for ($i = 0; $i < $count; $i++) {
            if ($i < $period) {
                $rsi[] = null;
            } elseif ($i === $period) {
                $avgGain = array_sum(array_slice($gains, 1, $period)) / $period;
                $avgLoss = array_sum(array_slice($losses, 1, $period)) / $period;
                $rs = $avgLoss == 0 ? 100 : $avgGain / $avgLoss;
                $rsi[] = round(100 - (100 / (1 + $rs)), 2);
            } else {
                $avgGain = (($avgGain * ($period - 1)) + $gains[$i]) / $period;
                $avgLoss = (($avgLoss * ($period - 1)) + $losses[$i]) / $period;
                $rs = $avgLoss == 0 ? 100 : $avgGain / $avgLoss;
                $rsi[] = round(100 - (100 / (1 + $rs)), 2);
            }
        }
        return $rsi;
    }

    private function drawText($canvas, int $size, int $x, int $y, $color, string $text, bool $bold = false): void
    {
        $fontRegular = '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf';
        $fontBold = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';
        $font = $bold && file_exists($fontBold) ? $fontBold : (file_exists($fontRegular) ? $fontRegular : null);

        if ($font && function_exists('imagettftext')) {
            imagettftext($canvas, $size, 0, $x, $y + $size + 2, $color, $font, $text);
        } else {
            imagestring($canvas, $bold ? 3 : 2, $x, $y, $text, $color);
        }
    }

    private function generateCleanProChart(
        string $symbol,
        array $candles,
        array $ma20,
        array $ma50,
        array $ma200,
        array $volData,
        array $maVol20,
        array $rsiData,
        array $meta
    ): string {
        $volValues = array_column($volData, 'y');
        $volColors = array_column($volData, 'color');
        $volPoints = array_map(fn($v) => ['x' => $v['x'], 'y' => $v['y']], $volData);
        $maxVol = max($volValues) ?: 1000;

        // Garis horizontal R1, Harga Close, dan Stoploss pada panel utama
        $lineR1 = array_map(fn($c) => ['x' => $c['x'], 'y' => $meta['r1']], $candles);
        $lineClose = array_map(fn($c) => ['x' => $c['x'], 'y' => $meta['c']], $candles);
        $lineSL = array_map(fn($c) => ['x' => $c['x'], 'y' => $meta['stoploss']], $candles);

        $chartWidth = 1050;
        $headerHeight = 95;
        $mainHeight = 430;
        $rsiHeaderHeight = 26;
        $rsiHeight = 135;
        $totalHeight = $headerHeight + $mainHeight + $rsiHeaderHeight + $rsiHeight;

        // 1. Render Panel Utama (Candlestick + MA20/50/200 + R1/Close/SL + Volume + MA Vol20)
        $mainConfig = [
            'type' => 'candlestick',
            'data' => [
                'datasets' => [
                    [
                        'label' => $symbol,
                        'data' => $candles,
                        'yAxisID' => 'yPrice',
                        'order' => 1,
                    ],
                    [
                        'type' => 'line',
                        'label' => 'R1',
                        'data' => $lineR1,
                        'borderColor' => 'rgba(220, 38, 38, 0.55)',
                        'borderWidth' => 1.1,
                        'borderDash' => [5, 5],
                        'pointRadius' => 0,
                        'fill' => false,
                        'yAxisID' => 'yPrice',
                    ],
                    [
                        'type' => 'line',
                        'label' => 'Close',
                        'data' => $lineClose,
                        'borderColor' => 'rgba(37, 99, 235, 0.45)',
                        'borderWidth' => 1.0,
                        'borderDash' => [2, 4],
                        'pointRadius' => 0,
                        'fill' => false,
                        'yAxisID' => 'yPrice',
                    ],
                    [
                        'type' => 'line',
                        'label' => 'SL',
                        'data' => $lineSL,
                        'borderColor' => 'rgba(234, 88, 12, 0.65)',
                        'borderWidth' => 1.1,
                        'borderDash' => [5, 5],
                        'pointRadius' => 0,
                        'fill' => false,
                        'yAxisID' => 'yPrice',
                    ],
                    [
                        'type' => 'line',
                        'label' => 'MA20',
                        'data' => $ma20,
                        'borderColor' => '#7e22ce',
                        'borderWidth' => 1.5,
                        'borderDash' => [3, 3],
                        'pointRadius' => 0,
                        'fill' => false,
                        'yAxisID' => 'yPrice',
                    ],
                    [
                        'type' => 'line',
                        'label' => 'MA50',
                        'data' => $ma50,
                        'borderColor' => '#d97706',
                        'borderWidth' => 1.8,
                        'borderDash' => [2, 2],
                        'pointRadius' => 0,
                        'fill' => false,
                        'yAxisID' => 'yPrice',
                    ],
                    [
                        'type' => 'line',
                        'label' => 'MA200',
                        'data' => $ma200,
                        'borderColor' => '#16a34a',
                        'borderWidth' => 2.0,
                        'pointRadius' => 0,
                        'fill' => false,
                        'yAxisID' => 'yPrice',
                    ],
                    [
                        'type' => 'line',
                        'label' => 'MAVol20',
                        'data' => $maVol20,
                        'borderColor' => '#475569',
                        'borderWidth' => 1.2,
                        'pointRadius' => 0,
                        'fill' => false,
                        'yAxisID' => 'yVol',
                        'order' => 8,
                    ],
                    [
                        'type' => 'bar',
                        'label' => 'Volume',
                        'data' => $volPoints,
                        'backgroundColor' => $volColors,
                        'yAxisID' => 'yVol',
                        'order' => 10,
                    ],
                ],
            ],
            'options' => [
                'layout' => [
                    'padding' => ['top' => 10, 'left' => 145, 'right' => 12, 'bottom' => 4],
                ],
                'plugins' => [
                    'legend' => ['display' => false],
                ],
                'scales' => [
                    'x' => [
                        'type' => 'timeseries',
                        'time' => ['unit' => $meta['tf_unit'] ?? 'day'],
                        'grid' => ['color' => '#f1f5f9'],
                        'ticks' => ['font' => ['size' => 10], 'maxRotation' => 0],
                    ],
                    'yPrice' => [
                        'position' => 'right',
                        'min' => (int) (floor((min(array_column($candles, 'l')) - ((max(array_column($candles, 'h')) - min(array_column($candles, 'l'))) * 0.42)) / 50) * 50),
                        'max' => (int) (ceil((max(array_column($candles, 'h')) + ((max(array_column($candles, 'h')) - min(array_column($candles, 'l'))) * 0.05)) / 50) * 50),
                        'grid' => ['color' => '#e2e8f0'],
                        'ticks' => ['font' => ['size' => 11]],
                    ],
                    'yVol' => [
                        'position' => 'left',
                        'display' => false,
                        'min' => 0,
                        'max' => $maxVol * 7.5,
                    ],
                ],
            ],
        ];

        $mainRes = Http::timeout(25)
            ->withOptions(['force_ip_resolve' => 'v4'])
            ->post('https://quickchart.io/chart', [
                'version' => '3',
                'width' => $chartWidth,
                'height' => $mainHeight,
                'devicePixelRatio' => 1,
                'backgroundColor' => 'white',
                'format' => 'png',
                'chart' => $mainConfig,
            ]);

        if ($mainRes->failed()) {
            throw new Exception("QuickChart Main Error: " . $mainRes->body());
        }

        // 2. Render Panel Bawah (RSI 14)
        $rsi70 = array_map(fn($r) => ['x' => $r['x'], 'y' => 70], $rsiData);
        $rsi30 = array_map(fn($r) => ['x' => $r['x'], 'y' => 30], $rsiData);

        $rsiConfig = [
            'type' => 'line',
            'data' => [
                'datasets' => [
                    [
                        'label' => 'RSI(14)',
                        'data' => $rsiData,
                        'borderColor' => '#1d4ed8',
                        'borderWidth' => 1.6,
                        'pointRadius' => 0,
                        'fill' => false,
                    ],
                    [
                        'label' => 'OB 70',
                        'data' => $rsi70,
                        'borderColor' => '#dc2626',
                        'borderWidth' => 1,
                        'pointRadius' => 0,
                        'fill' => false,
                    ],
                    [
                        'label' => 'OS 30',
                        'data' => $rsi30,
                        'borderColor' => '#16a34a',
                        'borderWidth' => 1,
                        'pointRadius' => 0,
                        'fill' => false,
                    ],
                ],
            ],
            'options' => [
                'layout' => [
                    'padding' => ['top' => 6, 'left' => 145, 'right' => 24, 'bottom' => 6],
                ],
                'plugins' => [
                    'legend' => ['display' => false],
                ],
                'scales' => [
                    'x' => [
                        'type' => 'timeseries',
                        'display' => false,
                    ],
                    'y' => [
                        'position' => 'right',
                        'min' => 10,
                        'max' => 90,
                        'ticks' => [
                            'stepSize' => 20,
                            'font' => ['size' => 10],
                        ],
                        'grid' => ['color' => '#f1f5f9'],
                    ],
                ],
            ],
        ];

        $rsiRes = Http::timeout(25)
            ->withOptions(['force_ip_resolve' => 'v4'])
            ->post('https://quickchart.io/chart', [
                'version' => '3',
                'width' => $chartWidth,
                'height' => $rsiHeight,
                'devicePixelRatio' => 1,
                'backgroundColor' => 'white',
                'format' => 'png',
                'chart' => $rsiConfig,
            ]);

        if ($rsiRes->failed()) {
            throw new Exception("QuickChart RSI Error: " . $rsiRes->body());
        }

        // 3. Susun Kanvas Akhir
        $imgMain = imagecreatefromstring($mainRes->body());
        $imgRsi = imagecreatefromstring($rsiRes->body());

        $canvas = imagecreatetruecolor($chartWidth, $totalHeight);

        $white = imagecolorallocate($canvas, 255, 255, 255);
        $headerBg = imagecolorallocate($canvas, 248, 250, 252);
        $panelBg = imagecolorallocate($canvas, 248, 250, 252);
        $black = imagecolorallocate($canvas, 15, 23, 42);
        $gray = imagecolorallocate($canvas, 148, 163, 184);
        $borderCol = imagecolorallocate($canvas, 203, 213, 225);
        $red = imagecolorallocate($canvas, 220, 38, 38);
        $orange = imagecolorallocate($canvas, 234, 88, 12);
        $green = imagecolorallocate($canvas, 22, 163, 74);
        $blue = imagecolorallocate($canvas, 29, 78, 216);
        $purple = imagecolorallocate($canvas, 126, 34, 206);
        $amber = imagecolorallocate($canvas, 180, 83, 9);

        imagefill($canvas, 0, 0, $white);

        imagecopyresampled($canvas, $imgMain, 0, $headerHeight, 0, 0, $chartWidth, $mainHeight, imagesx($imgMain), imagesy($imgMain));
        imagecopyresampled($canvas, $imgRsi, 0, $headerHeight + $mainHeight + $rsiHeaderHeight, 0, 0, $chartWidth, $rsiHeight, imagesx($imgRsi), imagesy($imgRsi));

        imagefilledrectangle($canvas, 0, 0, $chartWidth, $headerHeight, $headerBg);
        $rsiBarY = $headerHeight + $mainHeight;
        imagefilledrectangle($canvas, 0, $rsiBarY, $chartWidth, $rsiBarY + $rsiHeaderHeight, $headerBg);

        imagefilledrectangle($canvas, 0, $headerHeight, 140, $rsiBarY, $panelBg);
        imageline($canvas, 140, $headerHeight, 140, $rsiBarY, $borderCol);

        // Watermark Ticker di Tengah Chart
        $this->drawText($canvas, 18, 520, $headerHeight + 25, $gray, $meta['symbol'], true);
        $this->drawText($canvas, 9, 492, $headerHeight + 52, $gray, "Technical Screener Bot", false);

        // Baris 1: OHLC & Change
        $chgSign = $meta['chg'] >= 0 ? '+' : '';
        $line1 = "{$meta['symbol']} | {$meta['date']} | {$meta['tf_label']} | O: " . number_format($meta['o']) .
                 "  H: " . number_format($meta['h']) . "  L: " . number_format($meta['l']) .
                 "  C: " . number_format($meta['c']) . "  Chg: {$chgSign}" . number_format($meta['chg']) . " ({$chgSign}{$meta['chg_pct']}%)";
        $this->drawText($canvas, 10, 14, 10, $black, $line1, true);

        // Baris 2: MA20, MA50, MA200
        $ma20Txt = "MA20: " . number_format($meta['ma20'], 0);
        $ma50Txt = "MA50: " . number_format($meta['ma50'], 0);
        $ma200Txt = "MA200: " . ($meta['ma200'] ? number_format($meta['ma200'], 0) : 'N/A');

        $this->drawText($canvas, 9, 14, 30, $purple, $ma20Txt, true);
        $this->drawText($canvas, 9, 135, 30, $amber, $ma50Txt, true);
        $this->drawText($canvas, 9, 256, 30, $green, $ma200Txt, true);

        // Baris 3: Dashboard Sinyal, Target & Stoploss
        $slPct = round((($meta['stoploss'] - $meta['c']) / $meta['c']) * 100, 1);
        $tpPct = round((($meta['target2'] - $meta['c']) / $meta['c']) * 100, 1);
        $line3 = "Dashboard => {$meta['signal']}  |  Target: " . number_format($meta['target1']) . " - " . number_format($meta['target2']) . " (+{$tpPct}%)  |  SL: " . number_format($meta['stoploss']) . " ({$slPct}%)";
        $this->drawText($canvas, 9, 14, 50, $black, $line3, false);

        // Baris 4: Money Management (Dynamic Max Risk)
        $line4 = "MM: Max Risk Rp " . number_format($meta['max_risk_rp']) . "  |  Alokasi Dana: Rp " . number_format($meta['alloc_rp']) . "  =>  Rec: " . number_format($meta['rec_lot']) . " Lot";
        $this->drawText($canvas, 9, 14, 70, $blue, $line4, true);

        // Sidebar Kiri: RESISTANCE & SUPPORT
        $yStart = $headerHeight + 16;
        $this->drawText($canvas, 9, 12, $yStart, $red, "RESISTANCE", true);
        $r3Pct = round((($meta['r3'] - $meta['c']) / $meta['c']) * 100, 1);
        $r2Pct = round((($meta['r2'] - $meta['c']) / $meta['c']) * 100, 1);
        $r1Pct = round((($meta['r1'] - $meta['c']) / $meta['c']) * 100, 1);
        $this->drawText($canvas, 8, 12, $yStart + 20, $black, "R3: " . number_format($meta['r3']) . " (+{$r3Pct}%)", false);
        $this->drawText($canvas, 8, 12, $yStart + 38, $black, "R2: " . number_format($meta['r2']) . " (+{$r2Pct}%)", false);
        $this->drawText($canvas, 8, 12, $yStart + 56, $black, "R1: " . number_format($meta['r1']) . " (+{$r1Pct}%)", false);

        imageline($canvas, 12, $yStart + 80, 128, $yStart + 80, $borderCol);

        $s1Pct = round((($meta['s1'] - $meta['c']) / $meta['c']) * 100, 1);
        $s2Pct = round((($meta['s2'] - $meta['c']) / $meta['c']) * 100, 1);
        $s3Pct = round((($meta['s3'] - $meta['c']) / $meta['c']) * 100, 1);
        $this->drawText($canvas, 8, 12, $yStart + 92, $black, "S1: " . number_format($meta['s1']) . " ({$s1Pct}%)", false);
        $this->drawText($canvas, 8, 12, $yStart + 110, $black, "S2: " . number_format($meta['s2']) . " ({$s2Pct}%)", false);
        $this->drawText($canvas, 8, 12, $yStart + 128, $black, "S3: " . number_format($meta['s3']) . " ({$s3Pct}%)", false);
        $this->drawText($canvas, 9, 12, $yStart + 148, $green, "SUPPORT", true);

        // Legend MA & Garis Level di Sidebar Kiri
        imageline($canvas, 12, $yStart + 174, 128, $yStart + 174, $borderCol);
        $this->drawText($canvas, 8, 12, $yStart + 184, $purple, "-- MA 20", false);
        $this->drawText($canvas, 8, 12, $yStart + 201, $amber, "-- MA 50", false);
        $this->drawText($canvas, 8, 12, $yStart + 218, $green, "— MA 200", false);
        $this->drawText($canvas, 8, 12, $yStart + 235, $black, "— MA Vol20", false);
        $this->drawText($canvas, 8, 12, $yStart + 252, $red, "-- Target R1", false);
        $this->drawText($canvas, 8, 12, $yStart + 269, $orange, "-- Stoploss", false);

        // Bar Info Panel RSI
        $rsiLine = "{$meta['symbol']} {$meta['tf_label']}  |  RSI(14): {$meta['rsi']}  |  Volume: " . number_format($meta['vol_lot']) .
                   " Lot  |  MA Vol20: " . number_format($meta['ma_vol_lot']) . " Lot  |  VolRatio: {$meta['vol_ratio']}x";
        $this->drawText($canvas, 9, 14, $rsiBarY + 6, $blue, $rsiLine, true);

        // Border Luar & Garis Pembatas Panel
        imageline($canvas, 0, $headerHeight, $chartWidth, $headerHeight, $borderCol);
        imageline($canvas, 0, $rsiBarY, $chartWidth, $rsiBarY, $borderCol);
        imageline($canvas, 0, $rsiBarY + $rsiHeaderHeight, $chartWidth, $rsiBarY + $rsiHeaderHeight, $borderCol);
        imagerectangle($canvas, 0, 0, $chartWidth - 1, $totalHeight - 1, $borderCol);

        ob_start();
        imagepng($canvas, null, 8);
        $finalPng = ob_get_clean();

        imagedestroy($imgMain);
        imagedestroy($imgRsi);
        imagedestroy($canvas);

        $fileName = "charts/{$symbol}_" . time() . ".png";
        Storage::disk('local')->put($fileName, $finalPng);

        return Storage::disk('local')->path($fileName);
    }
}
