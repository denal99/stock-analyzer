<?php

namespace App\Services;

use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

class MarketBriefService
{
    private function resolveStockbitToken(): ?string
    {
        $token = Cache::get('stockbit_bearer_token');
        if (empty($token) && file_exists(storage_path('app/stockbit_token.json'))) {
            $saved = json_decode((string) file_get_contents(storage_path('app/stockbit_token.json')), true);
            if (!empty($saved['token']) && ($saved['expires_at'] ?? 0) > time()) {
                $token = $saved['token'];
                Cache::put('stockbit_bearer_token', $token, \Carbon\Carbon::createFromTimestamp($saved['expires_at']));
            }
        }
        $token = $token ?: env('STOCKBIT_BEARER_TOKEN');
        return is_string($token) && trim($token) !== '' ? trim($token) : null;
    }

    public function generateBrief(bool $forceRefresh = false): array
    {
        $cacheKey = 'idx_morning_brief_v5';
        if ($forceRefresh) {
            Cache::forget($cacheKey);
        }

        return Cache::remember($cacheKey, 300, function () {
            // Note: GOLD & COAL di Stockbit adalah saham emiten BEI, bukan harga komoditas global.
            $tickers = [
                'ihsg'   => ['sb' => 'IHSG',     'yahoo' => '^JKSE', 'label' => 'IHSG (Jakarta)',  'short' => 'IHSG',       'core' => true],
                'usdidr' => ['sb' => 'USDIDR',   'yahoo' => 'IDR=X', 'label' => 'USD / IDR',       'short' => 'USD/IDR',    'core' => true],
                'sp500'  => ['sb' => 'SP500',    'yahoo' => '^GSPC', 'label' => 'S&P 500 (US)',    'short' => 'S&P500',     'core' => true],
                'nasdaq' => ['sb' => 'NASDAQ',   'yahoo' => '^IXIC', 'label' => 'Nasdaq (US)',     'short' => 'Nasdaq',     'core' => true],
                'nikkei' => ['sb' => 'NIKKEI',   'yahoo' => '^N225', 'label' => 'Nikkei 225',      'short' => 'Nikkei',     'core' => true],
                'hsi'    => ['sb' => 'HANGSENG', 'yahoo' => '^HSI',  'label' => 'Hang Seng',       'short' => 'HSI',        'core' => true],
                'gold'   => ['sb' => 'XAU',      'yahoo' => 'GC=F',  'label' => 'Emas (Gold)',     'short' => 'Emas',       'core' => true],
                'oil'    => ['sb' => 'OIL',      'yahoo' => 'CL=F',  'label' => 'Minyak WTI',      'short' => 'Minyak',     'core' => true],
                'coal'   => ['sb' => null,       'yahoo' => 'BTU',   'label' => 'Batu Bara (BTU)', 'short' => 'BTU',        'core' => true],
                'cpo'    => ['sb' => 'CPO',      'yahoo' => 'ZL=F',  'label' => 'CPO',             'short' => 'CPO',        'core' => true],
                'sp_fut' => ['sb' => null,       'yahoo' => 'ES=F',  'label' => 'S&P Futures',     'short' => 'US Futures', 'core' => false],
                'nq_fut' => ['sb' => null,       'yahoo' => 'NQ=F',  'label' => 'Nasdaq Futures',  'short' => 'US Futures', 'core' => false],
            ];

            $data   = array_fill_keys(array_keys($tickers), null);
            $nowWib = now('Asia/Jakarta');

            // 1. Sumber Utama: Stockbit (paralel via Http::pool)
            $sbToken = $this->resolveStockbitToken();
            $sbKeys  = array_keys(array_filter($tickers, fn($t) => !empty($t['sb'])));

            if ($sbToken !== null && !empty($sbKeys)) {
                $from = $nowWib->format('Y-m-d');
                $to   = $nowWib->copy()->subDays(60)->format('Y-m-d');

                $sbResponses = Http::pool(function (Pool $pool) use ($tickers, $sbKeys, $sbToken, $from, $to) {
                    $reqs = [];
                    foreach ($sbKeys as $key) {
                        $sbSym = $tickers[$key]['sb'];
                        $reqs[$key] = $pool->as($key)
                            ->withToken($sbToken)
                            ->connectTimeout(5)
                            ->timeout(10)
                            ->withOptions(['force_ip_resolve' => 'v4'])
                            ->withHeaders([
                                'Origin'     => 'https://stockbit.com',
                                'Referer'    => 'https://stockbit.com/',
                                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
                            ])
                            ->get("https://exodus.stockbit.com/chartbit/{$sbSym}/price/daily", [
                                'from'  => $from,
                                'to'    => $to,
                                'limit' => 60,
                            ]);
                    }
                    return $reqs;
                });

                foreach ($sbKeys as $key) {
                    $res = $sbResponses[$key] ?? null;
                    if (!$res || $res instanceof \Throwable || $res->failed()) {
                        continue;
                    }

                    $rawList = $res->json('data.chartbit')
                        ?? $res->json('data.prices')
                        ?? $res->json('data')
                        ?? [];

                    if (!is_array($rawList) || count($rawList) < 2) {
                        continue;
                    }

                    usort($rawList, function ($a, $b) {
                        $ta = $a['unixdate'] ?? $a['timestamp'] ?? strtotime((string) ($a['date'] ?? $a['datetime'] ?? '1970-01-01'));
                        $tb = $b['unixdate'] ?? $b['timestamp'] ?? strtotime((string) ($b['date'] ?? $b['datetime'] ?? '1970-01-01'));
                        return $ta <=> $tb;
                    });

                    $bars = [];
                    foreach ($rawList as $row) {
                        $c = (float) ($row['close'] ?? 0);
                        if ($c <= 0) {
                            continue;
                        }
                        $o   = (float) ($row['open'] ?? $c);
                        $h   = (float) ($row['high'] ?? $c);
                        $l   = (float) ($row['low'] ?? $c);
                        $vol = (float) ($row['volume'] ?? 0);
                        $fb  = (float) ($row['foreignbuy'] ?? 0);
                        $fs  = (float) ($row['foreignsell'] ?? 0);

                        $dateStr = !empty($row['date'])
                            ? substr((string) $row['date'], 0, 10)
                            : (!empty($row['unixdate'])
                                ? \Carbon\Carbon::createFromTimestamp((int) $row['unixdate'], 'Asia/Jakarta')->format('Y-m-d')
                                : '-');
                        $dow = $dateStr !== '-' ? (int) date('N', strtotime($dateStr)) : 0;

                        if (!empty($bars)) {
                            $prevBar = $bars[count($bars) - 1];

                            // 1. Buang bar forward-fill identik (hari libur / akhir pekan / pre-open)
                            $sameOhlc = abs($o - $prevBar['o']) < 1e-6
                                && abs($h - $prevBar['h']) < 1e-6
                                && abs($l - $prevBar['l']) < 1e-6
                                && abs($c - $prevBar['c']) < 1e-6;
                            if ($sameOhlc) {
                                continue;
                            }

                            // 2. Buang bar akhir pekan yang tetap menyalin open & close Jumat (mis. XAU)
                            if ($dow >= 6 && abs($o - $prevBar['o']) < 1e-6 && abs($c - $prevBar['c']) < 1e-6) {
                                continue;
                            }

                            // 3. Buang bar placeholder pra-buka IHSG (volume 0 & flat sama dengan close kemarin)
                            if ($key === 'ihsg' && $vol <= 0 && abs($o - $c) < 1e-6 && abs($h - $l) < 1e-6 && abs($c - $prevBar['c']) < 1e-6) {
                                continue;
                            }
                        }

                        $bars[] = [
                            'dt' => $dateStr,
                            'o'  => $o,
                            'h'  => $h,
                            'l'  => $l,
                            'c'  => $c,
                            'fb' => $fb,
                            'fs' => $fs,
                        ];
                    }

                    $cnt = count($bars);
                    if ($cnt < 2) {
                        continue;
                    }

                    $lastBar   = $bars[$cnt - 1];
                    $prevBar   = $bars[$cnt - 2];
                    $last      = $lastBar['c'];
                    $prev      = $prevBar['c'];
                    $chgPct    = $prev > 0 ? (($last - $prev) / $prev) * 100 : 0.0;
                    $closeVals = array_column($bars, 'c');
                    $sma20     = $cnt >= 20
                        ? array_sum(array_slice($closeVals, -20)) / 20
                        : array_sum($closeVals) / $cnt;

                    // Level kunci 5D & Net Foreign Flow khusus IHSG
                    $recent5   = array_slice($bars, -5);
                    $lows5     = array_values(array_filter(array_column($recent5, 'l'), fn($v) => $v > 0));
                    $support5d = !empty($lows5) ? min($lows5) : null;

                    $foreign1d   = null;
                    $foreign5d   = null;
                    $foreignDate = null;
                    if ($key === 'ihsg') {
                        $fBars = array_values(array_filter($bars, fn($b) => ($b['fb'] > 0 || $b['fs'] > 0)));
                        if (!empty($fBars)) {
                            $fLast       = $fBars[count($fBars) - 1];
                            $foreign1d   = $fLast['fb'] - $fLast['fs'];
                            $foreignDate = $fLast['dt'];
                            $f5Slice     = array_slice($fBars, -5);
                            $foreign5d   = array_reduce($f5Slice, fn($carry, $b) => $carry + ($b['fb'] - $b['fs']), 0.0);
                        }
                    }

                    $data[$key] = [
                        'label'        => $tickers[$key]['label'],
                        'short'        => $tickers[$key]['short'],
                        'price'        => $last,
                        'prev'         => $prev,
                        'chg_pct'      => round($chgPct, 2),
                        'sma20'        => round($sma20, 2),
                        'support_5d'   => $support5d !== null ? round($support5d, 0) : null,
                        'above_sma20'  => $last >= $sma20,
                        'foreign_1d'   => $foreign1d,
                        'foreign_5d'   => $foreign5d,
                        'foreign_date' => $foreignDate,
                        'source'       => 'Stockbit',
                        'prev_bar_dt'  => $prevBar['dt'],
                        'last_bar_dt'  => $lastBar['dt'],
                    ];
                }
            }

            // 2. Fallback Otomatis + US Futures: Yahoo Finance (paralel dalam 1 pool)
            $missingKeys = array_keys(array_filter($data, fn($v) => $v === null));
            if (!empty($missingKeys)) {
                $yResponses = Http::pool(function (Pool $pool) use ($tickers, $missingKeys) {
                    $reqs = [];
                    foreach ($missingKeys as $key) {
                        $encoded = urlencode($tickers[$key]['yahoo']);
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

                foreach ($missingKeys as $key) {
                    $res = $yResponses[$key] ?? null;
                    if (!$res || $res instanceof \Throwable || $res->failed()) {
                        continue;
                    }

                    $result     = $res->json('chart.result.0');
                    $meta       = $result['meta'] ?? [];
                    $timestamps = $result['timestamp'] ?? [];
                    $rawCloses  = $result['indicators']['quote'][0]['close'] ?? [];
                    $rawLows    = $result['indicators']['quote'][0]['low'] ?? [];

                    $bars = [];
                    foreach ($rawCloses as $idx => $val) {
                        if (is_numeric($val) && (float) $val > 0) {
                            $bars[] = [
                                't' => isset($timestamps[$idx]) ? (int) $timestamps[$idx] : 0,
                                'c' => (float) $val,
                                'l' => isset($rawLows[$idx]) && is_numeric($rawLows[$idx]) && (float) $rawLows[$idx] > 0
                                    ? (float) $rawLows[$idx]
                                    : (float) $val,
                            ];
                        }
                    }
                    $cnt = count($bars);
                    if ($cnt < 2 && empty($meta['regularMarketPrice'])) {
                        continue;
                    }

                    $lastBar = $bars[$cnt - 1] ?? ['t' => 0, 'c' => 0.0, 'l' => 0.0];
                    $prevBar = $bars[$cnt - 2] ?? $lastBar;
                    $rmp     = isset($meta['regularMarketPrice']) && (float) $meta['regularMarketPrice'] > 0
                        ? (float) $meta['regularMarketPrice'] : null;
                    $rmt     = isset($meta['regularMarketTime']) ? (int) $meta['regularMarketTime'] : 0;

                    $last = $rmp ?? $lastBar['c'];
                    $metaPrev = isset($meta['previousClose']) && (float) $meta['previousClose'] > 0
                        ? (float) $meta['previousClose']
                        : (isset($meta['regularMarketPreviousClose']) && (float) $meta['regularMarketPreviousClose'] > 0
                            ? (float) $meta['regularMarketPreviousClose'] : null);

                    if ($metaPrev !== null) {
                        $prev = $metaPrev;
                    } elseif ($cnt >= 2) {
                        $isNewSessionNotInBars = $rmp !== null
                            && $rmt > 0
                            && $lastBar['t'] > 0
                            && ($rmt - $lastBar['t']) >= 43200
                            && abs($rmp - $lastBar['c']) / max(1e-9, $lastBar['c']) > 0.0001;
                        $prev = $isNewSessionNotInBars ? $lastBar['c'] : $prevBar['c'];
                    } else {
                        $prev = (float) ($meta['chartPreviousClose'] ?? $last);
                    }

                    if ($key === 'usdidr' && $prev > 0 && abs(($last - $prev) / $prev) > 0.04 && $cnt >= 2) {
                        $last = $lastBar['c'];
                        $prev = $prevBar['c'];
                    }

                    $chgPct    = $prev > 0 ? (($last - $prev) / $prev) * 100 : 0.0;
                    $closeVals = array_column($bars, 'c');
                    $sma20     = $cnt >= 20
                        ? array_sum(array_slice($closeVals, -20)) / 20
                        : ($cnt > 0 ? array_sum($closeVals) / $cnt : $last);

                    $recent5   = array_slice($bars, -5);
                    $lows5     = array_values(array_filter(array_column($recent5, 'l'), fn($v) => $v > 0));
                    $support5d = !empty($lows5) ? min($lows5) : null;

                    $data[$key] = [
                        'label'        => $tickers[$key]['label'],
                        'short'        => $tickers[$key]['short'],
                        'price'        => $last,
                        'prev'         => $prev,
                        'chg_pct'      => round($chgPct, 2),
                        'sma20'        => round($sma20, 2),
                        'support_5d'   => $support5d !== null ? round($support5d, 0) : null,
                        'above_sma20'  => $last >= $sma20,
                        'foreign_1d'   => null,
                        'foreign_5d'   => null,
                        'foreign_date' => null,
                        'source'       => 'Yahoo',
                        'prev_bar_dt'  => $prevBar['t'] > 0 ? date('Y-m-d', $prevBar['t']) : '-',
                        'last_bar_dt'  => $lastBar['t'] > 0 ? date('Y-m-d', $lastBar['t']) : '-',
                    ];
                }
            }

            // 3. Skor Sentimen & Outlook Dinamis
            $spChg  = $data['sp500']['chg_pct']  ?? 0.0;
            $nqChg  = $data['nasdaq']['chg_pct'] ?? 0.0;
            $nkChg  = $data['nikkei']['chg_pct'] ?? 0.0;
            $hsiChg = $data['hsi']['chg_pct']    ?? 0.0;
            $usdChg = $data['usdidr']['chg_pct'] ?? 0.0;
            $ihsgUp = !empty($data['ihsg']) ? (bool) $data['ihsg']['above_sma20'] : true;

            $severeGlobalRiskOff = ($spChg <= -1.5)
                || ($nqChg <= -2.0)
                || ($spChg <= -0.8 && $nqChg <= -1.0 && ($nkChg <= -1.5 || $hsiChg <= -1.5));
            $globalBullish = ($spChg >= 0.7 && $nqChg >= 0.8);

            $score = 0;
            if (!empty($data['sp500']))  $score += $spChg >= 0.5 ? 2 : ($spChg <= -1.5 ? -3 : ($spChg <= -0.5 ? -2 : 0));
            if (!empty($data['nasdaq'])) $score += $nqChg >= 0.7 ? 1 : ($nqChg <= -2.0 ? -2 : ($nqChg <= -0.7 ? -1 : 0));
            if (!empty($data['nikkei']) || !empty($data['hsi'])) {
                $asiaCnt = (!empty($data['nikkei']) ? 1 : 0) + (!empty($data['hsi']) ? 1 : 0);
                $asiaAvg = ($nkChg + $hsiChg) / max(1, $asiaCnt);
                $score  += $asiaAvg >= 1.0 ? 1 : ($asiaAvg <= -1.5 ? -2 : ($asiaAvg <= -0.8 ? -1 : 0));
            }
            if (!empty($data['usdidr'])) {
                if ($usdChg <= -0.20) $score += $severeGlobalRiskOff ? 1 : 2;
                elseif ($usdChg >= 0.25) $score -= 2;
            }
            if (!empty($data['ihsg'])) $score += $ihsgUp ? 1 : -1;

            if (!$severeGlobalRiskOff && $score >= 3) {
                $drv = [];
                if ($spChg >= 0.5 || $nqChg >= 0.7) $drv[] = 'Global Hijau';
                if ($usdChg <= -0.20) $drv[] = 'Rupiah Menguat';
                if ($ihsgUp) $drv[] = 'IHSG &gt; SMA20';
                $outlook = '🟢 BULLISH / RISK-ON (' . (implode(' &amp; ', $drv) ?: 'Sentimen Kondusif') . ', Potensi Menguat)';
            } elseif (!$severeGlobalRiskOff && $score >= 0) {
                $outlook = '🟡 MIXED / SELECTIVE (Konsolidasi &amp; Rotasi Sektor)';
            } else {
                $drv = [];
                if ($spChg <= -0.5 || $nqChg <= -0.7 || $nkChg <= -1.0 || $hsiChg <= -1.0) {
                    $drv[] = $severeGlobalRiskOff ? 'Sell-Off Global' : 'Tekanan Global';
                }
                if ($usdChg >= 0.25) $drv[] = 'Rupiah Melemah';
                if (!empty($data['ihsg']) && !$ihsgUp) $drv[] = 'IHSG &lt; SMA20';
                $outlook = '🔴 DEFENSIVE / CAUTIOUS (' . (implode(' &amp; ', $drv) ?: 'Tekanan Pasar') . ', Waspada Koreksi)';
            }

            // 4. Deteksi Katalis Sektor (Dua Arah, Sadar Risk-Off & Sinyal US Futures)
            $catalysts = [];
            $spFutChg  = isset($data['sp_fut']['chg_pct']) ? (float) $data['sp_fut']['chg_pct'] : null;
            $nqFutChg  = isset($data['nq_fut']['chg_pct']) ? (float) $data['nq_fut']['chg_pct'] : null;
            $futVals   = array_values(array_filter([$spFutChg, $nqFutChg], fn($v) => $v !== null));

            $futuresRebound = $severeGlobalRiskOff
                && !empty($futVals)
                && max($futVals) >= 1.5
                && min($futVals) >= 0.5;
            $futuresCrash = $severeGlobalRiskOff
                && !empty($futVals)
                && min($futVals) <= -1.0;

            if ($severeGlobalRiskOff) {
                $catalysts[] = '⚠️ <b>Big Caps &amp; Teknologi (Waspada):</b> Sell-off indeks global rawan memicu <i>foreign outflow</i> &amp; <i>gap down</i> di sesi awal — utamakan <i>wait &amp; see</i> / disiplin SL.';

                if ($futuresRebound) {
                    $futParts = [];
                    if ($spFutChg !== null) $futParts[] = 'S&amp;P +' . number_format($spFutChg, 2) . '%';
                    if ($nqFutChg !== null) $futParts[] = 'Nasdaq +' . number_format($nqFutChg, 2) . '%';
                    $futStr = implode(', ', $futParts);

                    $reclaimHint = (!empty($data['ihsg']) && !$ihsgUp && !empty($data['ihsg']['sma20']))
                        ? 'pantau konfirmasi <i>reclaim</i> SMA20 IHSG (<b>' . number_format((float) $data['ihsg']['sma20'], 0) . '</b>) sebelum masuk agresif.'
                        : 'tunggu stabilitas 15–30 menit pertama sebelum masuk agresif.';

                    $catalysts[] = "⚡ <b>Peluang Technical Rebound (Intraday):</b> US Futures melonjak ({$futStr}) pasca sell-off — berpotensi meredam <i>gap down</i> awal; {$reclaimHint}";
                } elseif ($futuresCrash) {
                    $futParts = [];
                    if ($spFutChg !== null) $futParts[] = 'S&amp;P ' . ($spFutChg >= 0 ? '+' : '') . number_format($spFutChg, 2) . '%';
                    if ($nqFutChg !== null) $futParts[] = 'Nasdaq ' . ($nqFutChg >= 0 ? '+' : '') . number_format($nqFutChg, 2) . '%';
                    $futStr = implode(', ', $futParts);

                    $catalysts[] = "🚨 <b>Tekanan Lanjutan (US Futures):</b> Futures AS masih tertekan ({$futStr}) — risiko <i>panic sell</i> tinggi, prioritaskan <i>cash is king</i>.";
                }
            } elseif ($globalBullish && $ihsgUp) {
                $catalysts[] = '🚀 <b>Big Caps &amp; Growth (Positif):</b> Reli Wall Street mendukung selera risiko (<i>risk-on</i>) pada saham unggulan.';
            }

            if (!empty($data['usdidr'])) {
                if ($usdChg <= -0.20) {
                    $catalysts[] = ($severeGlobalRiskOff || $score < 0)
                        ? '🛡️ <b>Perbankan (Penahan):</b> Rupiah menguat (' . $usdChg . '%), bisa meredam penurunan, namun tetap waspada arus keluar asing di <b>BBCA, BBRI, BMRI</b> saat global risk-off.'
                        : '🏦 <b>Perbankan &amp; Properti (Positif):</b> Rupiah menguat (' . $usdChg . '%), mendukung <i>inflow</i> asing ke <b>BBCA, BBRI, BMRI, BSDE</b>.';
                } elseif ($usdChg >= 0.25) {
                    $catalysts[] = '⚠️ <b>Perbankan &amp; Impor (Waspada):</b> Rupiah melemah (+' . $usdChg . '%), potensi tekanan jangka pendek pada perbankan &amp; emiten berutang USD.';
                }
            }

            if (!empty($data['gold'])) {
                if ($data['gold']['chg_pct'] >= 0.8) {
                    $catalysts[] = '🥇 <b>Sektor Emas (Positif):</b> Harga emas dunia naik +' . $data['gold']['chg_pct'] . '%' . ($severeGlobalRiskOff ? ' (rotasi <i>safe-haven</i>)' : '') . ', katalis untuk <b>ANTM, MDKA, BRMS</b>.';
                } elseif ($data['gold']['chg_pct'] <= -1.2) {
                    $catalysts[] = '🔻 <b>Sektor Emas (Waspada):</b> Harga emas terkoreksi ' . $data['gold']['chg_pct'] . '%, waspada tekanan jangka pendek di <b>ANTM, MDKA, BRMS</b>.';
                }
            }

            if (!empty($data['oil'])) {
                if ($data['oil']['chg_pct'] >= 1.2) {
                    $catalysts[] = '🛢️ <b>Sektor Migas (Positif):</b> Minyak WTI menguat +' . $data['oil']['chg_pct'] . '%, pantau <b>MEDC, ELSA, AKRA</b>.';
                } elseif ($data['oil']['chg_pct'] <= -1.5) {
                    $catalysts[] = '🔻 <b>Sektor Migas (Waspada):</b> Minyak WTI turun ' . $data['oil']['chg_pct'] . '%, rawan menekan <b>MEDC, ELSA</b>.';
                }
            }

            if (!empty($data['coal'])) {
                if ($data['coal']['chg_pct'] >= 1.5) {
                    $catalysts[] = '⛏️ <b>Sektor Batu Bara (Positif):</b> Saham batu bara AS (BTU) naik +' . $data['coal']['chg_pct'] . '%, pantau <b>ADRO, PTBA, ITMG</b>.';
                } elseif ($data['coal']['chg_pct'] <= -2.0) {
                    $catalysts[] = '🔻 <b>Sektor Batu Bara (Waspada):</b> Proxy batu bara (BTU) melemah ' . $data['coal']['chg_pct'] . '%, rawan tekanan jual pada <b>ADRO, PTBA, ITMG</b>.';
                }
            }

            if (!empty($data['cpo'])) {
                $cpoLabel = ($data['cpo']['source'] ?? '') === 'Stockbit' ? 'Harga CPO' : 'Minyak nabati (proxy CPO)';
                if ($data['cpo']['chg_pct'] >= 1.0) {
                    $catalysts[] = '🌴 <b>Sektor Sawit / CPO (Positif):</b> ' . $cpoLabel . ' menguat +' . $data['cpo']['chg_pct'] . '%, pantau <b>TAPG, LSIP, DSNG</b>.';
                } elseif ($data['cpo']['chg_pct'] <= -1.5) {
                    $catalysts[] = '🔻 <b>Sektor Sawit / CPO (Waspada):</b> ' . $cpoLabel . ' turun ' . $data['cpo']['chg_pct'] . '%, waspada koreksi di <b>TAPG, LSIP, DSNG</b>.';
                }
            }

            if (empty($catalysts)) {
                $catalysts[] = '🧭 <b>Fokus Stock-Picking:</b> Tidak ada lonjakan ekstrem komoditas/kurs; fokus pada emiten yang sedang <i>inflow</i> di <code>/sektor</code> atau <code>/akumulasi</code>.';
            }

            // 5. Ringkasan Sumber Data
            $sbCore = 0;
            $yahooItems = [];
            foreach ($tickers as $key => $metaT) {
                $item = $data[$key] ?? null;
                if (!$item) continue;
                if (!empty($metaT['core'])) {
                    if (($item['source'] ?? '') === 'Stockbit') {
                        $sbCore++;
                    } else {
                        $yahooItems[$item['short']] = true;
                    }
                } elseif (($item['source'] ?? '') === 'Yahoo') {
                    $yahooItems[$item['short']] = true;
                }
            }
            $coreTotal = count(array_filter($tickers, fn($t, $k) => !empty($t['core']) && !empty($data[$k]), ARRAY_FILTER_USE_BOTH));
            $yLabels   = array_keys($yahooItems);

            if ($sbCore > 0 && empty($yLabels)) {
                $sourceSummary = "🟢 Stockbit ({$sbCore}/{$coreTotal})";
            } elseif ($sbCore > 0) {
                $yList = count($yLabels) <= 3 ? implode(', ', $yLabels) : count($yLabels) . ' item';
                $sourceSummary = "🟢 Stockbit ({$sbCore}/{$coreTotal}) • 🟡 Yahoo ({$yList})";
            } else {
                $sourceSummary = "🟡 Yahoo Finance (Fallback)";
            }

            $hour  = (int) $nowWib->format('H');
            $title = $hour < 12
                ? '🌅 MORNING MARKET BRIEF &amp; MACRO RADAR'
                : ($hour < 16 ? '☀️ INTRADAY MARKET RADAR &amp; MACRO UPDATE' : '🌆 CLOSING MARKET &amp; MACRO WRAP');

            return [
                'title'     => $title,
                'timestamp' => $nowWib->format('d M Y, H:i') . ' WIB',
                'metrics'   => $data,
                'outlook'   => $outlook,
                'catalysts' => $catalysts,
                'source'    => $sourceSummary,
            ];
        });
    }

    private function formatIdrFlow(float $amount, bool $withSign = true): string
    {
        $sign = $withSign ? ($amount >= 0 ? '+' : '-') : '';
        $abs  = abs($amount);
        if ($abs >= 1_000_000_000_000) {
            return $sign . 'Rp ' . number_format($abs / 1_000_000_000_000, 2) . ' T';
        }
        if ($abs >= 1_000_000_000) {
            $dec = $abs >= 100_000_000_000 ? 0 : 1;
            return $sign . 'Rp ' . number_format($abs / 1_000_000_000, $dec) . ' M';
        }
        return $sign . 'Rp ' . number_format($abs / 1_000_000, 0) . ' Jt';
    }

    public function buildTelegramKeyboard(): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make('🔄 Refresh Brief', callback_data: 'quick:brief_refresh'),
                InlineKeyboardButton::make('📋 Watchlist', callback_data: 'quick:watchlist')
            )
            ->addRow(
                InlineKeyboardButton::make('🧭 Radar Sektor', callback_data: 'quick:sektor'),
                InlineKeyboardButton::make('🏦 Akumulasi Bandar', callback_data: 'quick:akumulasi')
            );
    }

    public function formatTelegramMessage(array $brief): string
    {
        $m = $brief['metrics'];
        $fmtPct = fn(?array $item): string => $item
            ? (($item['chg_pct'] >= 0 ? '+' : '') . number_format($item['chg_pct'], 2) . '%')
            : 'N/A';

        $title     = $brief['title'] ?? '🌅 MORNING MARKET BRIEF &amp; MACRO RADAR';
        $ihsgPrice = !empty($m['ihsg']) ? number_format($m['ihsg']['price'], 2) : 'N/A';
        $ihsgTrend = !empty($m['ihsg']) ? ($m['ihsg']['above_sma20'] ? 'Uptrend (&gt; SMA20)' : 'Downtrend (&lt; SMA20)') : '-';
        $usdPrice  = !empty($m['usdidr']) ? 'Rp ' . number_format($m['usdidr']['price'], 0) : 'N/A';
        $usdTrend  = !empty($m['usdidr'])
            ? ($m['usdidr']['above_sma20'] ? 'Tekanan Kurs (&gt; SMA20)' : 'Rupiah Stabil (&lt; SMA20)')
            : '';
        $goldPrice = !empty($m['gold']) ? '$' . number_format($m['gold']['price'], 1) : 'N/A';
        $oilPrice  = !empty($m['oil']) ? '$' . number_format($m['oil']['price'], 2) : 'N/A';

        if (!empty($m['cpo']) && ($m['cpo']['source'] ?? '') === 'Stockbit') {
            $cpoPart = 'CPO: <b>' . $fmtPct($m['cpo']) . '</b> (RM ' . number_format($m['cpo']['price'], 0) . ')';
        } else {
            $cpoPart = 'CPO (Proxy): <b>' . $fmtPct($m['cpo'] ?? null) . '</b>';
        }

        $msg  = "<b>{$title}</b>\n";
        $msg .= "🕒 Update: {$brief['timestamp']}\n";
        $msg .= "━━━━━━━━━━━━━━━━━━━━\n\n";
        $msg .= "🇮🇩 <b>IHSG:</b> {$ihsgPrice} (<b>{$fmtPct($m['ihsg'] ?? null)}</b>) | {$ihsgTrend}\n";

        if (!empty($m['ihsg']) && (!empty($m['ihsg']['support_5d']) || !empty($m['ihsg']['sma20']))) {
            $lvlParts = [];
            if (!empty($m['ihsg']['support_5d'])) {
                $lvlParts[] = 'Support 5D: <b>' . number_format((float) $m['ihsg']['support_5d'], 0) . '</b>';
            }
            if (!empty($m['ihsg']['sma20'])) {
                $lvlParts[] = 'SMA20: <b>' . number_format((float) $m['ihsg']['sma20'], 0) . '</b>';
            }
            $msg .= '   ↳ ' . implode(' | ', $lvlParts) . "\n";
        }

        if (!empty($m['ihsg']) && $m['ihsg']['foreign_1d'] !== null) {
            $f1d     = (float) $m['ihsg']['foreign_1d'];
            $f5d     = $m['ihsg']['foreign_5d'];
            $fIcon   = $f1d >= 0 ? '🟢' : '🔴';
            $fLabel  = $f1d >= 0 ? 'Net Buy' : 'Net Sell';
            $fDtTag  = !empty($m['ihsg']['foreign_date']) ? ' (' . date('d M', strtotime((string) $m['ihsg']['foreign_date'])) . ')' : '';
            $f5dPart = $f5d !== null ? ' (5D: <b>' . $this->formatIdrFlow((float) $f5d, true) . '</b>)' : '';
            $msg    .= "   ↳ Asing{$fDtTag}: {$fIcon} <b>{$fLabel} " . $this->formatIdrFlow($f1d, false) . "</b>{$f5dPart}\n";
        }

        $msg .= "💵 <b>USD/IDR:</b> {$usdPrice} (<b>{$fmtPct($m['usdidr'] ?? null)}</b>)" . ($usdTrend ? " | {$usdTrend}" : '') . "\n";
        $msg .= "🎯 <b>Outlook Bursa:</b>\n{$brief['outlook']}\n\n";

        $msg .= "🌍 <b>Indeks Global &amp; Regional:</b>\n";
        $msg .= "• S&amp;P 500: <b>{$fmtPct($m['sp500'] ?? null)}</b> | Nasdaq: <b>{$fmtPct($m['nasdaq'] ?? null)}</b>\n";

        if (!empty($m['sp_fut']) || !empty($m['nq_fut'])) {
            $spf = $m['sp_fut']['chg_pct'] ?? null;
            $nqf = $m['nq_fut']['chg_pct'] ?? null;
            $futHint = '';
            if ($spf !== null && $nqf !== null) {
                if ($spf >= 0.5 && $nqf >= 0.5) {
                    $futHint = ' <i>(Sinyal Rebound)</i>';
                } elseif ($spf <= -0.5 && $nqf <= -0.5) {
                    $futHint = ' <i>(Lanjut Tekanan)</i>';
                }
            }
            $msg .= "• US Futures (Live): S&amp;P <b>{$fmtPct($m['sp_fut'] ?? null)}</b> | Nasdaq <b>{$fmtPct($m['nq_fut'] ?? null)}</b>{$futHint}\n";
        }

        $msg .= "• Nikkei 225: <b>{$fmtPct($m['nikkei'] ?? null)}</b> | Hang Seng: <b>{$fmtPct($m['hsi'] ?? null)}</b>\n\n";

        $msg .= "📦 <b>Komoditas Utama:</b>\n";
        $msg .= "• Emas (Gold): <b>{$fmtPct($m['gold'] ?? null)}</b> ({$goldPrice})\n";
        $msg .= "• Minyak WTI: <b>{$fmtPct($m['oil'] ?? null)}</b> ({$oilPrice})\n";
        $msg .= "• Batu Bara (BTU): <b>{$fmtPct($m['coal'] ?? null)}</b> | {$cpoPart}\n\n";

        $msg .= "⚡ <b>Katalis &amp; Watchlist Sektor:</b>\n";
        foreach (($brief['catalysts'] ?? []) as $cat) {
            $msg .= "• {$cat}\n";
        }

        if (!empty($brief['source'])) {
            $msg .= "\n📡 <i>Sumber: {$brief['source']}</i>";
        }

        return $msg;
    }
}
