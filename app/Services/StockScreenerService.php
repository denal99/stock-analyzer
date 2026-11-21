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

    public function compareStocks(array $rawSymbols): array
    {
        $symbols = array_values(array_unique(array_filter(array_map(function ($s) {
            $clean = strtoupper(trim(preg_replace('/[^A-Za-z0-9]/', '', $s)));
            return preg_replace('/JK$/', '', $clean);
        }, $rawSymbols))));

        $symbols = array_slice($symbols, 0, 5);
        if (count($symbols) < 2) {
            throw new Exception('Masukkan minimal 2 kode saham untuk dibandingkan (contoh: /banding BBRI BMRI BBCA BBNI).');
        }

        $cacheKey = 'idx_compare_v3_' . implode('_', $symbols);

        return Cache::remember($cacheKey, 600, function () use ($symbols) {
            $auth = $this->getYahooCrumbAndCookie();

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
                if (!$res || $res->failed()) {
                    continue;
                }

                $summary = $res->json('quoteSummary.result.0');
                if (!$summary) {
                    continue;
                }

                $price = $summary['price']['regularMarketPrice']['raw'] ?? null;
                if (!$price) {
                    continue;
                }

                $name = $summary['price']['shortName']['raw']
                    ?? ($summary['price']['shortName'] ?? $sym);
                $marketCap = $summary['price']['marketCap']['raw'] ?? ($summary['summaryDetail']['marketCap']['raw'] ?? 0);
                $per = $summary['summaryDetail']['trailingPE']['raw'] ?? ($summary['defaultKeyStatistics']['trailingPE']['raw'] ?? null);
                $pbv = $summary['defaultKeyStatistics']['priceToBook']['raw'] ?? null;

                if ($per === null && !empty($summary['defaultKeyStatistics']['trailingEps']['raw'])) {
                    $eps = $summary['defaultKeyStatistics']['trailingEps']['raw'];
                    if ($eps > 0) {
                        $per = $price / $eps;
                    }
                }

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

                $rawRoe = $summary['financialData']['returnOnEquity']['raw'] ?? null;
                $roe = $rawRoe !== null ? round($rawRoe * 100, 2) : null;

                $rawNpm = $summary['financialData']['profitMargins']['raw'] ?? null;
                $npm = $rawNpm !== null ? round($rawNpm * 100, 2) : null;

                $rawRevGrowth = $summary['financialData']['revenueGrowth']['raw'] ?? null;
                $revGrowth = $rawRevGrowth !== null ? round($rawRevGrowth * 100, 2) : null;

                $rawEarnGrowth = $summary['financialData']['earningsGrowth']['raw'] ?? null;
                $earnGrowth = $rawEarnGrowth !== null ? round($rawEarnGrowth * 100, 2) : null;

                $rawDiv = $summary['summaryDetail']['dividendYield']['raw']
                    ?? ($summary['summaryDetail']['trailingAnnualDividendYield']['raw'] ?? null);
                $dividendYield = ($rawDiv !== null && $rawDiv > 0) ? round($rawDiv * 100, 2) : null;

                $results[] = [
                    'symbol' => $sym,
                    'name' => $name,
                    'price' => $price,
                    'market_cap' => $marketCap,
                    'market_cap_formatted' => $this->formatMarketCap($marketCap),
                    'per' => $per !== null ? round($per, 2) : null,
                    'pbv' => $pbv !== null ? round($pbv, 2) : null,
                    'roe' => $roe,
                    'npm' => $npm,
                    'rev_growth' => $revGrowth,
                    'earn_growth' => $earnGrowth,
                    'der' => $der,
                    'dividend_yield' => $dividendYield,
                    'score' => 0,
                ];
            }

            // Pillar Scoring + Anti-Value Trap & Dividend Trap Guard (Max 100 poin)
            $scoreROE = function (?float $val, float $max): float {
                if ($val === null || $val <= 0) return 0;
                if ($val >= 20) return $max;
                if ($val >= 15) return $max * 0.85;
                if ($val >= 10) return $max * 0.65;
                return $max * 0.35;
            };

            $scoreNPM = function (?float $val, float $max): float {
                if ($val === null || $val <= 0) return 0;
                if ($val >= 15) return $max;
                if ($val >= 10) return $max * 0.80;
                if ($val >= 5)  return $max * 0.50;
                return $max * 0.25;
            };

            $scoreYoY = function (?float $val, float $max): float {
                if ($val === null || $val <= -30) return 0;
                if ($val >= 25) return $max;
                if ($val >= 10) return $max * 0.80;
                if ($val >= 0)  return $max * 0.55;
                if ($val > -15) return $max * 0.30;
                return $max * 0.10;
            };

            $scoreDER = function (?float $val, float $max): float {
                if ($val === null || $val < 0) return $max * 0.50;
                if ($val <= 0.5) return $max;
                if ($val <= 1.0) return $max * 0.80;
                if ($val <= 1.5) return $max * 0.50;
                if ($val <= 2.0) return $max * 0.25;
                return 0;
            };

            $scorePER = function (?float $val, float $max): float {
                if ($val === null || $val <= 0) return 0;
                if ($val <= 8)  return $max;
                if ($val <= 12) return $max * 0.80;
                if ($val <= 16) return $max * 0.60;
                if ($val <= 22) return $max * 0.35;
                return $max * 0.15;
            };

            $scorePBV = function (?float $val, float $max): float {
                if ($val === null || $val <= 0) return 0;
                if ($val <= 1.0) return $max;
                if ($val <= 1.5) return $max * 0.80;
                if ($val <= 2.5) return $max * 0.50;
                return $max * 0.20;
            };

            $scoreDividend = function (?float $val, float $max): float {
                if ($val === null || $val <= 0) return 0;
                if ($val >= 8) return $max;
                if ($val >= 5) return $max * 0.80;
                if ($val >= 3) return $max * 0.55;
                return $max * 0.30;
            };

            $bankTickers = ['BBCA', 'BBRI', 'BMRI', 'BBNI', 'BRIS', 'BNGA', 'BDMN', 'BNII', 'NISP', 'BBTN', 'BJBR', 'BJTM', 'BTPS', 'ARTO', 'BBYB', 'AGRO', 'MEGA', 'PNBN', 'BTPN'];

            foreach ($results as &$item) {
                $roe      = $item['roe'];
                $npm      = $item['npm'];
                $labaYoY  = $item['earn_growth'];
                $der      = $item['der'];
                $per      = $item['per'];
                $pbv      = $item['pbv'];
                $divYield = $item['dividend_yield'];
                $isBank   = in_array($item['symbol'], $bankTickers, true);

                // 1. Hitung skor dasar per pilar
                $scoreProfit = $scoreROE($roe, 15) + $scoreNPM($npm, 15);
                $scoreGrowth = $scoreYoY($labaYoY, 25);
                $scoreSolv   = $isBank ? 15 : $scoreDER($der, 20);
                $scoreVal    = $scorePER($per, 10) + $scorePBV($pbv, 5);
                $scoreDiv    = $scoreDividend($divYield, 10);

                // 2. Anti-Value Trap & Dividend Trap Guard
                $isLoss = (($roe !== null && $roe < 0) || ($npm !== null && $npm < 0) || $per === null || $per <= 0);

                if ($isLoss || ($labaYoY !== null && $labaYoY < -40)) {
                    $scoreVal = 0;
                    $scoreDiv = $isLoss ? 0 : ($scoreDiv * 0.5);
                } elseif ($labaYoY !== null && $labaYoY < -25) {
                    $scoreVal *= 0.5;
                }

                // 3. Penalti Utang (Non-Bank)
                $penalty = 0;
                if (!$isBank && $der !== null) {
                    if ($der > 2.0) {
                        $penalty = 10;
                    } elseif ($der > 1.5) {
                        $penalty = 5;
                    }
                }

                // 4. Total & Hard Cap
                $finalScore = (int) round(max(0, ($scoreProfit + $scoreGrowth + $scoreSolv + $scoreVal + $scoreDiv) - $penalty));

                if ($isLoss) {
                    $finalScore = min($finalScore, 35);
                }

                $item['score'] = $finalScore;
            }
            unset($item);

            usort($results, fn($a, $b) => $b['score'] <=> $a['score']);
            return $results;
        });
    }
    private function roundToIdxTick(float $price): int
    {
        $p = (int) round($price);
        if ($p < 200) {
            return (int) (round($p / 1) * 1);
        } elseif ($p < 500) {
            return (int) (round($p / 2) * 2);
        } elseif ($p < 2000) {
            return (int) (round($p / 5) * 5);
        } elseif ($p < 5000) {
            return (int) (round($p / 10) * 10);
        }
        return (int) (round($p / 25) * 25);
    }

    public function calculateValuation(string $rawSymbol): array
    {
        $sym = preg_replace('/JK$/', '', strtoupper(trim(preg_replace('/[^A-Za-z0-9]/', '', $rawSymbol))));
        if (strlen($sym) < 4) {
            throw new Exception('Kode saham tidak valid (contoh: /valuasi BBRI).');
        }

        return Cache::remember("idx_valuasi_v6_{$sym}", 600, function () use ($sym) {
            $auth = $this->getYahooCrumbAndCookie();
            $ticker = "{$sym}.JK";
            $url = "https://query1.finance.yahoo.com/v10/finance/quoteSummary/{$ticker}?modules=price,assetProfile,defaultKeyStatistics,summaryDetail,financialData,cashflowStatementHistory,incomeStatementHistory&crumb=" . urlencode($auth['crumb']);

            $res = Http::timeout(15)
                ->withOptions(['force_ip_resolve' => 'v4'])
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
                    'Cookie' => $auth['cookie'],
                ])
                ->get($url);

            if ($res->failed()) {
                throw new Exception("Gagal mengambil data {$sym} dari Yahoo Finance.");
            }

            $summary = $res->json('quoteSummary.result.0');
            $price = $summary['price']['regularMarketPrice']['raw'] ?? null;
            if (!$summary || !$price) {
                throw new Exception("Data emiten {$sym} tidak ditemukan.");
            }

            $name = $summary['price']['shortName']['raw'] ?? ($summary['price']['shortName'] ?? $sym);
            $sector = $summary['assetProfile']['sector'] ?? '';
            $isFinancial = stripos($sector, 'Financial') !== false || in_array($sym, ['BBCA', 'BBRI', 'BMRI', 'BBNI', 'BBTN', 'BRIS', 'BNGA', 'BDMN', 'NISP', 'BJBR', 'BJTM', 'ARTO', 'BBYB', 'BTPS']);

            $shares = $summary['defaultKeyStatistics']['sharesOutstanding']['raw'] ?? null;
            $marketCap = $summary['price']['marketCap']['raw'] ?? ($shares ? $price * $shares : null);
            if (!$shares && $marketCap && $price > 0) {
                $shares = $marketCap / $price;
            }

            $eps = $summary['defaultKeyStatistics']['trailingEps']['raw'] ?? null;
            $tradeCurrency = strtoupper($summary['price']['currency'] ?? 'IDR');
            $finCurrency = strtoupper($summary['financialData']['financialCurrency'] ?? $tradeCurrency);
            $fxRate = ($tradeCurrency === 'IDR' && $finCurrency === 'USD') ? $this->getUsdToIdrRate() : 1.0;

            $bvps = $summary['defaultKeyStatistics']['bookValue']['raw'] ?? null;
            if ($bvps !== null) {
                $bvps *= $fxRate;
            }
            $per = $summary['summaryDetail']['trailingPE']['raw'] ?? ($eps && $eps > 0 ? $price / $eps : null);
            $pbv = ($fxRate > 1.0 && $bvps && $bvps > 0) ? ($price / $bvps) : ($summary['defaultKeyStatistics']['priceToBook']['raw'] ?? ($bvps && $bvps > 0 ? $price / $bvps : null));
            $roe = $summary['financialData']['returnOnEquity']['raw'] ?? (($eps && $bvps && $bvps > 0) ? ($eps / $bvps) : null);
            $divRate = $summary['summaryDetail']['dividendRate']['raw'] ?? ($summary['summaryDetail']['trailingAnnualDividendRate']['raw'] ?? null);

            if ($isFinancial) {
                // Model Khusus Sektor Perbankan & Keuangan (Kalibrasi Konservatif)
                $costOfEquity = 0.12; // 12% cost of equity perbankan IDX
                $stableGrowth = 0.05; // 5% pertumbuhan jangka panjang

                // 1. Justified PBV = (ROE - g) / (r - g) * BVPS
                $m1Label = 'Justified PBV (Excess Return)';
                $m1Price = null;
                if ($roe !== null && $roe > $stableGrowth && $bvps !== null && $bvps > 0) {
                    $justifiedPbv = min(4.0, max(0.8, ($roe - $stableGrowth) / ($costOfEquity - $stableGrowth)));
                    $m1Price = $this->roundToIdxTick($justifiedPbv * $bvps);
                }

                // 2. Gordon Growth / Dividend Discount Model (DDM) = D1 / (r - g)
                $m2Label = 'Dividend Discount (DDM)';
                $m2Price = null;
                $sustainableDiv = null;
                if ($divRate !== null && $divRate > 0) {
                    // Batasi dividen berkelanjutan maksimal 70% EPS agar dividen spesial tidak mendistorsi DDM
                    $sustainableDiv = ($eps !== null && $eps > 0) ? min($divRate, $eps * 0.70) : $divRate;
                } elseif ($eps !== null && $eps > 0) {
                    $sustainableDiv = $eps * 0.50;
                }
                if ($sustainableDiv !== null && $sustainableDiv > 0) {
                    $rawDdm = ($sustainableDiv * (1 + $stableGrowth)) / ($costOfEquity - $stableGrowth);
                    $m2Price = $this->roundToIdxTick(min($rawDdm, $price * 2.0));
                }

                // 3. Fair Forward Earnings Multiple (15x EPS untuk bank kualitas tinggi ROE >= 15%, 12x untuk lainnya)
                $m3Label = 'Bank Fair Multiple (PER)';
                $m3Price = null;
                if ($eps !== null && $eps > 0) {
                    $fairPe = ($roe !== null && $roe >= 0.18) ? 16.0 : (($roe !== null && $roe >= 0.12) ? 13.0 : 10.5);
                    $m3Price = $this->roundToIdxTick($eps * $fairPe);
                }
            } else {
                // Deteksi Sektor Siklikal / Komoditas (Batu Bara, Migas, Tambang Logam, Perkebunan)
                $sectorRaw = strtolower((string) $sector);
                $industryRaw = strtolower((string) ($summary['assetProfile']['industry'] ?? ''));
                $cyclicalTickers = [
                    'ABMM', 'ADRO', 'AADI', 'PTBA', 'ITMG', 'INDY', 'BUMI',
                    'HRUM', 'BYAN', 'GEMS', 'DOID', 'MBAP', 'TOBA', 'UNTR',
                    'MEDC', 'ELSA', 'ENRG', 'PGAS', 'AKRA', 'ANTM', 'INCO',
                    'MDKA', 'MBMA', 'NCKL', 'TINS', 'AMMN', 'BRMS', 'PSAB',
                    'AALI', 'LSIP', 'TAPG', 'DSNG', 'SSMS'
                ];
                $isCyclical = in_array($sym, $cyclicalTickers, true)
                    || str_contains($sectorRaw, 'energy')
                    || str_contains($sectorRaw, 'basic materials')
                    || str_contains($industryRaw, 'coal')
                    || str_contains($industryRaw, 'mining')
                    || str_contains($industryRaw, 'oil');

                // Model Non-Bank: Parameter lebih konservatif untuk sektor siklikal
                $grahamMult = $isCyclical ? 9.0 : 22.5;
                $epvDiscount = $isCyclical ? 0.15 : 0.10;

                // 1. Graham Number = sqrt(grahamMult * EPS * BVPS)
                $m1Label = $isCyclical ? 'Graham Number (Siklikal)' : 'Graham Number';
                $m1Price = null;
                if ($eps !== null && $eps > 0 && $bvps !== null && $bvps > 0) {
                    $m1Price = $this->roundToIdxTick(sqrt($grahamMult * $eps * $bvps));
                }

                // 2. Earnings Power Value (EPV) = EPS / Cost of Equity
                $m2Label = $isCyclical ? 'Earnings Power (EPV Siklikal)' : 'Earnings Power (EPV)';
                $m2Price = null;
                if ($eps !== null && $eps > 0) {
                    $m2Price = $this->roundToIdxTick($eps / $epvDiscount);
                }

                // Fallback Book Value jika EPS negatif sehingga Graham & EPV N/A
                if ($m1Price === null && $m2Price === null && $bvps !== null && $bvps > 0) {
                    $m1Label = 'Nilai Buku (1.0x BVPS)';
                    $m1Price = $this->roundToIdxTick($bvps);
                }

                // 3. DCF 5-Year Model (Discount Rate 10%, Terminal Growth 3%)
                $m3Label = 'DCF 5-Tahun';
                $m3Price = null;
                $cfStmt = $summary['cashflowStatementHistory']['cashflowStatements'][0] ?? null;
                $ocf = $cfStmt['totalCashFromOperatingActivities']['raw'] ?? null;
                $capex = abs($cfStmt['capitalExpenditures']['raw'] ?? 0);

                $fcf = null;
                if ($ocf !== null && ($ocf - $capex) > 0) {
                    $fcf = $ocf - $capex;
                } else {
                    $rawFcf = $summary['financialData']['freeCashflow']['raw'] ?? null;
                    if ($rawFcf !== null && $rawFcf > 0) {
                        $fcf = $rawFcf;
                    } elseif ($ocf !== null && $ocf > 0) {
                        $fcf = $ocf * 0.50;
                    }
                }

                if ($fcf !== null && $fcf > 0 && $shares && $shares > 0) {
                    $fcf *= $fxRate;
                    // Batasi FCF yield maksimal 15% dari Market Cap agar tidak terdistorsi anomali kas 1 tahun
                    if ($marketCap && $marketCap > 0) {
                        $fcf = min($fcf, $marketCap * 0.15);
                    }

                    $rawGrowth = $summary['financialData']['earningsGrowth']['raw']
                        ?? ($summary['financialData']['revenueGrowth']['raw'] ?? 0.06);
                    $g = max(0.03, min(0.12, (float) $rawGrowth));
                    $r = 0.105;
                    $tg = 0.03;

                    $pvSum = 0.0;
                    $projectedFcf = $fcf;
                    for ($yr = 1; $yr <= 5; $yr++) {
                        $projectedFcf *= (1 + $g);
                        $pvSum += $projectedFcf / pow(1 + $r, $yr);
                    }

                    $terminalVal = ($projectedFcf * (1 + $tg)) / ($r - $tg);
                    $pvTerminal = $terminalVal / pow(1 + $r, 5);

                    $totalCash = ($summary['financialData']['totalCash']['raw'] ?? 0) * $fxRate;
                    $totalDebt = ($summary['financialData']['totalDebt']['raw'] ?? 0) * $fxRate;
                    $equityVal = $pvSum + $pvTerminal + $totalCash - $totalDebt;

                    if ($equityVal > 0) {
                        // Sanity cap: DCF per saham maksimal 2.5x harga pasar saat ini
                        $rawDcfPrice = min($equityVal / $shares, $price * 2.5);
                        $m3Price = $this->roundToIdxTick($rawDcfPrice);
                    }
                }
            }

            // Konsensus Nilai Wajar
            $estimates = array_filter([$m1Price, $m2Price, $m3Price], fn($v) => $v !== null && $v > 0);
            if (empty($estimates)) {
                throw new Exception("Laba/Arus kas {$sym} masih negatif sehingga nilai wajar intrinsik belum dapat dihitung.");
            }

            $fairValue = $this->roundToIdxTick(array_sum($estimates) / count($estimates));
            $mos = round((($fairValue - $price) / $fairValue) * 100, 2);
            $upside = round((($fairValue - $price) / $price) * 100, 2);

            if ($mos >= 25) {
                $verdict = '🟢 UNDERVALUED (Margin of Safety Tebal)';
            } elseif ($mos >= 5) {
                $verdict = '🟡 LAYAK KOLEKSI (Terdiskon Tipis)';
            } elseif ($mos >= -10) {
                $verdict = '⚪ FAIRLY VALUED (Harga Wajar)';
            } else {
                $verdict = '🔴 OVERVALUED (Harga di Atas Nilai Wajar)';
            }

            return [
                'symbol' => $sym,
                'name' => $name,
                'is_financial' => $isFinancial,
                'price' => $price,
                'eps' => $eps !== null ? round($eps, 2) : null,
                'bvps' => $bvps !== null ? round($bvps, 2) : null,
                'per' => $per !== null ? round($per, 2) : null,
                'pbv' => $pbv !== null ? round($pbv, 2) : null,
                'm1_label' => $m1Label,
                'm1_price' => $m1Price,
                'm2_label' => $m2Label,
                'm2_price' => $m2Price,
                'm3_label' => $m3Label,
                'm3_price' => $m3Price,
                'fair_value' => $fairValue,
                'mos' => $mos,
                'upside' => $upside,
                'verdict' => $verdict,
            ];
        });
    }
    public function auditFundamentals(string $rawSymbol): array
    {
        $sym = preg_replace('/JK$/', '', strtoupper(trim(preg_replace('/[^A-Za-z0-9]/', '', $rawSymbol))));
        if (strlen($sym) < 4) {
            throw new Exception('Kode saham tidak valid (contoh: /audit ASII).');
        }

        return Cache::remember("idx_audit_v2_{$sym}", 600, function () use ($sym) {
            $auth = $this->getYahooCrumbAndCookie();
            $ticker = "{$sym}.JK";
            $url = "https://query1.finance.yahoo.com/v10/finance/quoteSummary/{$ticker}?modules=price,assetProfile,financialData,defaultKeyStatistics,incomeStatementHistory,balanceSheetHistory,cashflowStatementHistory&crumb=" . urlencode($auth['crumb']);

            $res = Http::timeout(15)
                ->withOptions(['force_ip_resolve' => 'v4'])
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
                    'Cookie' => $auth['cookie'],
                ])
                ->get($url);

            if ($res->failed()) {
                throw new Exception("Gagal mengambil laporan keuangan {$sym}.");
            }

            $summary = $res->json('quoteSummary.result.0');
            $price = $summary['price']['regularMarketPrice']['raw'] ?? null;
            if (!$summary || !$price) {
                throw new Exception("Data emiten {$sym} tidak ditemukan.");
            }

            $name = $summary['price']['shortName']['raw'] ?? ($summary['price']['shortName'] ?? $sym);
            $sector = $summary['assetProfile']['sector'] ?? '';
            $isFinancial = stripos($sector, 'Financial') !== false || in_array($sym, ['BBCA', 'BBRI', 'BMRI', 'BBNI', 'BBTN', 'BRIS', 'BNGA', 'BDMN', 'NISP', 'BJBR', 'BJTM', 'ARTO', 'BBYB', 'BTPS']);

            $fin = $summary['financialData'] ?? [];
            $stats = $summary['defaultKeyStatistics'] ?? [];
            $inc = $summary['incomeStatementHistory']['incomeStatementHistory'] ?? [];
            $bal = $summary['balanceSheetHistory']['balanceSheetStatements'] ?? [];
            $cf = $summary['cashflowStatementHistory']['cashflowStatements'] ?? [];

            $hasFullHistory = (count($inc) >= 2 && count($bal) >= 2 && ($bal[0]['totalAssets']['raw'] ?? 0) > 0);

            if ($hasFullHistory) {
                $ni0 = $inc[0]['netIncome']['raw'] ?? 0;
                $ni1 = $inc[1]['netIncome']['raw'] ?? 0;
                $rev0 = $inc[0]['totalRevenue']['raw'] ?? 0;
                $rev1 = $inc[1]['totalRevenue']['raw'] ?? 0;
                $gp0 = $inc[0]['grossProfit']['raw'] ?? null;
                $gp1 = $inc[1]['grossProfit']['raw'] ?? null;

                $ta0 = $bal[0]['totalAssets']['raw'] ?? 0;
                $ta1 = $bal[1]['totalAssets']['raw'] ?? 0;
                $ltd0 = $bal[0]['longTermDebt']['raw'] ?? 0;
                $ltd1 = $bal[1]['longTermDebt']['raw'] ?? 0;
                $ca0 = $bal[0]['totalCurrentAssets']['raw'] ?? null;
                $cl0 = $bal[0]['totalCurrentLiabilities']['raw'] ?? null;
                $ca1 = $bal[1]['totalCurrentAssets']['raw'] ?? null;
                $cl1 = $bal[1]['totalCurrentLiabilities']['raw'] ?? null;
                $shares0 = $bal[0]['commonStock']['raw'] ?? null;
                $shares1 = $bal[1]['commonStock']['raw'] ?? null;

                $ocf0 = $cf[0]['totalCashFromOperatingActivities']['raw'] ?? ($fin['operatingCashflow']['raw'] ?? 0);
                $roa0 = ($ta0 > 0) ? ($ni0 / $ta0) : ($fin['returnOnAssets']['raw'] ?? 0);
                $roa1 = ($ta1 > 0) ? ($ni1 / $ta1) : 0;

                $c1 = $roa0 > 0;
                $c2 = $ocf0 > 0;
                $c3 = $roa0 > $roa1;
                $c4 = $ocf0 > $ni0;

                $lev0 = ($ta0 > 0) ? ($ltd0 / $ta0) : 0;
                $lev1 = ($ta1 > 0) ? ($ltd1 / $ta1) : 0;
                $c5 = $lev0 <= $lev1;

                if ($ca0 !== null && $cl0 > 0 && $ca1 !== null && $cl1 > 0) {
                    $c6 = ($ca0 / $cl0) > ($ca1 / $cl1);
                    $c6Note = 'Current Ratio meningkat';
                } else {
                    $eq0 = $bal[0]['totalStockholderEquity']['raw'] ?? 0;
                    $eq1 = $bal[1]['totalStockholderEquity']['raw'] ?? 0;
                    $c6 = ($ta0 > 0 && $ta1 > 0) ? (($eq0 / $ta0) >= ($eq1 / $ta1)) : true;
                    $c6Note = 'Rasio Permodalan stabil/naik';
                }

                $c7 = ($shares0 === null || $shares1 === null) ? true : ($shares0 <= $shares1);

                if ($gp0 !== null && $rev0 > 0 && $gp1 !== null && $rev1 > 0) {
                    $c8 = ($gp0 / $rev0) > ($gp1 / $rev1);
                    $c8Note = 'Gross Margin meningkat';
                } else {
                    $c8 = ($rev0 > 0 && $rev1 > 0) ? (($ni0 / $rev0) > ($ni1 / $rev1)) : false;
                    $c8Note = 'Net Margin meningkat';
                }

                $at0 = ($ta0 > 0) ? ($rev0 / $ta0) : 0;
                $at1 = ($ta1 > 0) ? ($rev1 / $ta1) : 0;
                $c9 = $at0 > $at1;
            } else {
                // Fallback cerdas menggunakan data TTM (financialData & defaultKeyStatistics)
                $roa0 = $fin['returnOnAssets']['raw'] ?? 0;
                $roe0 = $fin['returnOnEquity']['raw'] ?? 0;
                $ocf0 = $fin['operatingCashflow']['raw'] ?? 0;
                $ni0 = $stats['netIncomeToCommon']['raw'] ?? 0;
                $earnGrowth = $fin['earningsGrowth']['raw'] ?? 0;
                $revGrowth = $fin['revenueGrowth']['raw'] ?? 0;
                $npm = $fin['profitMargins']['raw'] ?? 0;
                $gm = $fin['grossMargins']['raw'] ?? 0;
                $cr = $fin['currentRatio']['raw'] ?? null;
                $der = $fin['debtToEquity']['raw'] ?? null;

                $c1 = $roa0 > 0;
                $c2 = $isFinancial ? ($ni0 > 0) : ($ocf0 > 0);
                $c3 = $earnGrowth > 0;
                $c4 = $isFinancial ? ($roe0 >= 0.12) : ($ni0 > 0 && $ocf0 >= $ni0 * 0.8);
                $c5 = ($der === null) ? true : ($der <= 150);
                $c6 = ($cr === null) ? ($roe0 >= 0.10) : ($cr >= 1.0);
                $c6Note = ($cr === null) ? 'Solvabilitas & Permodalan sehat' : 'Current Ratio sehat (' . round($cr, 2) . 'x)';
                $c7 = true;
                $c8 = ($gm > 0.20 || $npm > 0.10);
                $c8Note = $isFinancial ? 'Net Profit Margin kuat (' . round($npm * 100, 1) . '%)' : 'Margin Laba sehat';
                $c9 = $revGrowth > 0;
            }

            // Cash Conversion Ratio (CCR)
            if ($isFinancial) {
                $ccr = ($ni0 > 0 && $ocf0 > 0) ? round($ocf0 / $ni0, 2) : null;
                $ccrStatus = 'ℹ️ Sektor Keuangan/Bank (OCF dipengaruhi penyaluran kredit)';
            } elseif ($ni0 <= 0) {
                $ccr = null;
                $ccrStatus = '🔴 Rugi Bersih (Negatif)';
            } elseif ($ocf0 > 0) {
                $ccr = round($ocf0 / $ni0, 2);
                if ($ccr >= 1.0) {
                    $ccrStatus = '🟢 Sangat Sehat (Laba 100% Tunai)';
                } elseif ($ccr >= 0.8) {
                    $ccrStatus = '🟡 Wajar (Konversi Kas Baik)';
                } else {
                    $ccrStatus = '⚠️ Waspada Laba Kertas (OCF &lt; 80% Laba)';
                }
            } else {
                $ccr = null;
                $ccrStatus = 'ℹ️ Data arus kas operasi TTM tidak tersedia';
            }

            $checks = [
                ['pass' => $c1, 'label' => 'ROA Positif (' . round($roa0 * 100, 2) . '%)'],
                ['pass' => $c2, 'label' => $isFinancial ? 'Laba Operasi & Bersih Positif' : 'Arus Kas Operasi (OCF) Positif'],
                ['pass' => $c3, 'label' => 'Profitabilitas / Laba Tumbuh YoY'],
                ['pass' => $c4, 'label' => $isFinancial ? 'Efisiensi Ekuitas Tinggi (ROE &gt;= 12%)' : 'Kualitas Laba Tunai (OCF &gt;= Laba)'],
                ['pass' => $c5, 'label' => 'Rasio Utang Terkendali'],
                ['pass' => $c6, 'label' => $c6Note],
                ['pass' => $c7, 'label' => 'Tanpa Dilusi Saham Baru'],
                ['pass' => $c8, 'label' => $c8Note],
                ['pass' => $c9, 'label' => 'Pertumbuhan Pendapatan / Turnover Positif'],
            ];

            $fScore = count(array_filter($checks, fn($c) => $c['pass']));

            if ($fScore >= 7) {
                $fVerdict = '🟢 FUNDAMENTAL SANGAT KUAT (Institutional Grade)';
            } elseif ($fScore >= 4) {
                $fVerdict = '🟡 FUNDAMENTAL MODERAT / STABIL';
            } else {
                $fVerdict = '🔴 FUNDAMENTAL LEMAH / BERISIKO';
            }

            return [
                'symbol' => $sym,
                'name' => $name,
                'price' => $price,
                'ccr' => $ccr,
                'ccr_status' => $ccrStatus,
                'f_score' => $fScore,
                'f_verdict' => $fVerdict,
                'checks' => $checks,
            ];
        });
    }
    public function getSectorRotation(): array
    {
        return Cache::remember('idx_sector_rotation_v1', 300, function () {
            $sectors = [
                '🏦 Perbankan & Keuangan' => ['BBCA', 'BBRI', 'BMRI', 'BBNI'],
                '⛏️ Energi & Batu Bara' => ['ADRO', 'PTBA', 'ITMG', 'PGAS'],
                '🪨 Tambang Mineral & Metal' => ['ANTM', 'INCO', 'MDKA', 'AMMN'],
                '🛒 Konsumer & Ritel' => ['ICBP', 'INDF', 'KLBF', 'AMRT'],
                '📡 Telko & Infrastruktur' => ['TLKM', 'ISAT', 'EXCL', 'JSMR'],
                '🏢 Properti & Konstruksi' => ['BSDE', 'PWON', 'CTRA', 'SMRA'],
                '🏭 Industri & Otomotif' => ['ASII', 'UNTR', 'SMGR', 'INTP'],
                '🌴 Agribisnis & CPO' => ['AALI', 'LSIP', 'TAPG', 'DSNG'],
            ];

            $allTickers = [];
            foreach ($sectors as $list) {
                foreach ($list as $sym) {
                    $allTickers[$sym] = true;
                }
            }
            $symbols = array_keys($allTickers);

            $responses = Http::pool(function (Pool $pool) use ($symbols) {
                $requests = [];
                foreach ($symbols as $sym) {
                    $url = "https://query1.finance.yahoo.com/v8/finance/chart/{$sym}.JK?range=2mo&interval=1d";
                    $requests[$sym] = $pool->as($sym)
                        ->timeout(12)
                        ->withOptions(['force_ip_resolve' => 'v4'])
                        ->withHeaders([
                            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
                        ])
                        ->get($url);
                }
                return $requests;
            });

            $perfMap = [];
            foreach ($symbols as $sym) {
                $res = $responses[$sym] ?? null;
                if (!$res || $res->failed()) {
                    continue;
                }

                $result = $res->json('chart.result.0');
                $closes = $result['indicators']['quote'][0]['close'] ?? [];
                $closes = array_values(array_filter($closes, fn($v) => $v !== null && $v > 0));
                $cnt = count($closes);

                if ($cnt < 6) {
                    continue;
                }

                $last = $closes[$cnt - 1];
                $prev1d = $closes[$cnt - 2];
                $prev1w = $closes[max(0, $cnt - 6)];
                $prev1m = $closes[max(0, $cnt - 21)];

                $chg1d = (($last - $prev1d) / $prev1d) * 100;
                $chg1w = (($last - $prev1w) / $prev1w) * 100;
                $chg1m = (($last - $prev1m) / $prev1m) * 100;

                $perfMap[$sym] = [
                    'symbol' => $sym,
                    'price' => $last,
                    'chg_1d' => round($chg1d, 2),
                    'chg_1w' => round($chg1w, 2),
                    'chg_1m' => round($chg1m, 2),
                    'score' => ($chg1d * 0.40) + ($chg1w * 0.35) + ($chg1m * 0.25),
                ];
            }

            $sectorResults = [];
            foreach ($sectors as $sectorName => $members) {
                $validMembers = [];
                foreach ($members as $sym) {
                    if (isset($perfMap[$sym])) {
                        $validMembers[] = $perfMap[$sym];
                    }
                }

                if (empty($validMembers)) {
                    continue;
                }

                $avg1d = round(array_sum(array_column($validMembers, 'chg_1d')) / count($validMembers), 2);
                $avg1w = round(array_sum(array_column($validMembers, 'chg_1w')) / count($validMembers), 2);
                $avg1m = round(array_sum(array_column($validMembers, 'chg_1m')) / count($validMembers), 2);
                // Urutkan anggota sektor berdasarkan performa terbaik (1D + 1W)
                usort($validMembers, fn($a, $b) => ($b['chg_1d'] + $b['chg_1w']) <=> ($a['chg_1d'] + $a['chg_1w']));
                $leader = $validMembers[0];

                // Tentukan fase rotasi sektor (RRG-style) & bonus prioritas fase aktif
                if ($avg1d > 0 && $avg1w > 0 && $avg1m >= 0) {
                    $phase = '🚀 LEADING (Inflow Kuat)';
                    $phaseBonus = 3.0;
                } elseif ($avg1d > 0 && $avg1w > 0 && $avg1m < 0) {
                    $phase = '🌱 IMPROVING (Mulai Akumulasi)';
                    $phaseBonus = 2.0;
                } elseif ($avg1m > 0 && ($avg1d < 0 || $avg1w < 0)) {
                    $phase = '⚠️ WEAKENING (Profit Taking)';
                    $phaseBonus = 0.0;
                } else {
                    $phase = '🔻 LAGGING (Outflow / Tertekan)';
                    $phaseBonus = -2.0;
                }

                $momentumScore = round(($avg1d * 0.55) + ($avg1w * 0.35) + ($avg1m * 0.10) + $phaseBonus, 2);

                $sectorResults[] = [
                    'sector' => $sectorName,
                    'avg_1d' => $avg1d,
                    'avg_1w' => $avg1w,
                    'avg_1m' => $avg1m,
                    'momentum' => $momentumScore,
                    'phase' => $phase,
                    'leader' => $leader,
                    'members' => $validMembers,
                ];
            }

            usort($sectorResults, fn($a, $b) => $b['momentum'] <=> $a['momentum']);
            return $sectorResults;
        });
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

    public function scanIntradayRadar(): array
    {
        return Cache::remember('idx_intraday_radar_v4', 180, function () {
            $universe = [
                'BBCA', 'BBRI', 'BMRI', 'BBNI', 'BRIS',
                'ADRO', 'PTBA', 'ITMG', 'PGAS', 'MEDC',
                'ANTM', 'INCO', 'MDKA', 'AMMN',
                'TLKM', 'ISAT', 'EXCL', 'ASII', 'UNTR', 'AMRT',
            ];

            $responses = Http::pool(function (Pool $pool) use ($universe) {
                $reqs = [];
                foreach ($universe as $sym) {
                    $url = "https://query1.finance.yahoo.com/v8/finance/chart/{$sym}.JK?range=5d&interval=15m";
                    $reqs[$sym] = $pool->as($sym)
                        ->connectTimeout(4)
                        ->timeout(7)
                        ->withOptions(['force_ip_resolve' => 'v4'])
                        ->withHeaders([
                            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
                        ])
                        ->get($url);
                }
                return $reqs;
            });

            $candidates = [];

            foreach ($universe as $sym) {
                $res = $responses[$sym] ?? null;
                if (!$res || $res instanceof \Throwable || $res->failed()) {
                    continue;
                }

                $result = $res->json('chart.result.0');
                $meta = $result['meta'] ?? [];
                $timestamps = $result['timestamp'] ?? [];
                $quote = $result['indicators']['quote'][0] ?? [];

                $opens = $quote['open'] ?? [];
                $highs = $quote['high'] ?? [];
                $lows = $quote['low'] ?? [];
                $closes = $quote['close'] ?? [];
                $volumes = $quote['volume'] ?? [];

                $candles = [];
                $count = count($closes);
                for ($i = 0; $i < $count; $i++) {
                    if (
                        isset($closes[$i], $volumes[$i]) &&
                        $closes[$i] > 0 && $volumes[$i] > 0
                    ) {
                        $candles[] = [
                            't' => $timestamps[$i] ?? null,
                            'o' => (float) ($opens[$i] ?? $closes[$i]),
                            'h' => (float) ($highs[$i] ?? $closes[$i]),
                            'l' => (float) ($lows[$i] ?? $closes[$i]),
                            'c' => (float) $closes[$i],
                            'v' => (float) $volumes[$i],
                        ];
                    }
                }

                $n = count($candles);
                if ($n < 16) {
                    continue;
                }

                $last = $candles[$n - 1];
                $prev = $candles[$n - 2];

                $lookback = min(20, $n - 1);
                $volSlice = array_slice($candles, $n - 1 - $lookback, $lookback);
                $avgVol = array_sum(array_column($volSlice, 'v')) / $lookback;
                if ($avgVol <= 0) {
                    continue;
                }

                $volRatio = round($last['v'] / $avgVol, 2);
                $range = $last['h'] - $last['l'];
                $clv = $range > 0 ? round((($last['c'] - $last['l']) / $range) * 100, 0) : ($last['c'] > $prev['c'] ? 100 : ($last['c'] < $prev['c'] ? 0 : 50));

                $candleChg = round((($last['c'] - $prev['c']) / $prev['c']) * 100, 2);
                $prevDayClose = $meta['chartPreviousClose'] ?? ($meta['previousClose'] ?? $prev['c']);
                $dayChg = $prevDayClose > 0 ? round((($last['c'] - $prevDayClose) / $prevDayClose) * 100, 2) : $candleChg;

                if ($volRatio >= 2.5 && $clv >= 70 && $candleChg >= 0) {
                    $tag = '🔥 AGGRESSIVE SWEEP (Haka Kuat)';
                } elseif ($volRatio >= 1.5 && $candleChg >= 0) {
                    $tag = '⚡ UNUSUAL VOLUME SPIKE';
                } elseif ($volRatio >= 1.5 && $candleChg < 0) {
                    $tag = '⚠️ INTRADAY DISTRIBUTION / FLUSH';
                } elseif ($candleChg > 0) {
                    $tag = '📈 INTRADAY ACCUMULATION';
                } else {
                    $tag = '👀 ACTIVE CLOSING / FLOW';
                }

                $score = ($volRatio * 25) + ($clv * 0.3) + (abs($candleChg) * 5);

                $candidates[] = [
                    'symbol' => $sym,
                    'price' => $last['c'],
                    'candle_chg' => $candleChg,
                    'day_chg' => $dayChg,
                    'vol_ratio' => $volRatio,
                    'clv' => $clv,
                    'tag' => $tag,
                    'score' => $score,
                ];
            }

            usort($candidates, fn($a, $b) => $b['score'] <=> $a['score']);
            return array_slice($candidates, 0, 8);
        });
    }

    protected function getUsdToIdrRate(): float
    {
        return Cache::remember('fx_usdidr_rate', 3600, function () {
            try {
                $res = Http::timeout(8)
                    ->withOptions(['force_ip_resolve' => 'v4'])
                    ->withHeaders(['User-Agent' => 'Mozilla/5.0'])
                    ->get('https://query1.finance.yahoo.com/v8/finance/chart/USDIDR=X?interval=1d&range=1d');
                $rate = $res->json('chart.result.0.meta.regularMarketPrice');
                if ($rate && $rate > 10000) {
                    return (float) $rate;
                }
            } catch (\Throwable $e) {
            }
            return 16200.0;
        });
    }

    public function getStockbitToken(): ?string
    {
        return Cache::get('stockbit_bearer_token') ?: env('STOCKBIT_BEARER_TOKEN');
    }

    public function scanTopRetailSell(array $tickers, int $limit = 18, ?string $fromDate = null, ?string $toDate = null): array
    {
        $token = $this->getStockbitToken();
        if (empty($token)) {
            return ['error' => 'NO_TOKEN'];
        }

        $retailBrokers = ['YP', 'XC', 'XL', 'PD', 'KK', 'NI', 'SQ', 'CC', 'GR', 'EP', 'DR', 'LG'];
        $today = now()->timezone('Asia/Jakarta')->format('Y-m-d');
        $from = $fromDate ?: $today;
        $to   = $toDate ?: $from;

        $cleanTickers = array_values(array_unique(array_map(
            fn($t) => str_replace('.JK', '', strtoupper(trim($t))),
            $tickers
        )));

        $sbResponses = [];
        foreach (array_chunk($cleanTickers, 4) as $chunk) {
            $batch = Http::pool(function (\Illuminate\Http\Client\Pool $pool) use ($chunk, $token, $from, $to) {
                foreach ($chunk as $sym) {
                    $pool->as($sym)
                        ->withToken($token)
                        ->withOptions(['force_ip_resolve' => 'v4'])
                        ->withHeaders([
                            'Origin'     => 'https://stockbit.com',
                            'Referer'    => 'https://stockbit.com/',
                            'User-Agent' => 'Mozilla/5.0',
                        ])
                        ->connectTimeout(5)
                        ->timeout(8)
                        ->get("https://exodus.stockbit.com/marketdetectors/{$sym}", [
                            'from'             => $from,
                            'to'               => $to,
                            'transaction_type' => 'TRANSACTION_TYPE_NET',
                            'market_board'     => 'MARKET_BOARD_REGULER',
                            'investor_type'    => 'INVESTOR_TYPE_ALL',
                        ]);
                }
            });
            foreach ($batch as $k => $v) {
                $sbResponses[$k] = $v;
            }
            usleep(250000);
        }

        $firstKey = $cleanTickers[0] ?? null;
        if ($firstKey && isset($sbResponses[$firstKey]) && $sbResponses[$firstKey] instanceof \Illuminate\Http\Client\Response) {
            if (in_array($sbResponses[$firstKey]->status(), [401, 403])) {
                return ['error' => 'TOKEN_EXPIRED'];
            }
        }

        $candidates = [];

        foreach ($cleanTickers as $sym) {
            $resp = $sbResponses[$sym] ?? null;
            if (!($resp instanceof \Illuminate\Http\Client\Response) || !$resp->successful()) {
                continue;
            }

            $data        = $resp->json('data');
            $brokersBuy  = $data['broker_summary']['brokers_buy'] ?? [];
            $brokersSell = $data['broker_summary']['brokers_sell'] ?? [];
            $totalValue  = (float) ($data['bandar_detector']['value'] ?? $data['bandar_detector']['total_value'] ?? 0);

            if ($totalValue <= 0) {
                continue;
            }

            $retailNet = 0.0;

            foreach ($brokersBuy as $b) {
                if (in_array($b['netbs_broker_code'] ?? '', $retailBrokers)) {
                    $retailNet += abs((float) ($b['bval'] ?? 0));
                }
            }

            foreach ($brokersSell as $s) {
                if (in_array($s['netbs_broker_code'] ?? '', $retailBrokers)) {
                    $retailNet -= abs((float) ($s['sval'] ?? 0));
                }
            }

            if ($retailNet < 0) {
                $nrd0Pct = (abs($retailNet) / $totalValue) * 100;
                $candidates[$sym] = round($nrd0Pct, 1);
            }
        }

        if (empty($candidates)) {
            return [];
        }

        arsort($candidates);
        $topCandidates = array_slice($candidates, 0, $limit, true);

        $yfResponses = Http::pool(function (\Illuminate\Http\Client\Pool $pool) use ($topCandidates) {
            foreach (array_keys($topCandidates) as $sym) {
                $pool->as($sym)
                    ->withOptions(['force_ip_resolve' => 'v4'])
                    ->withHeaders(['User-Agent' => 'Mozilla/5.0'])
                    ->timeout(8)
                    ->get("https://query1.finance.yahoo.com/v8/finance/chart/{$sym}.JK", [
                        'interval' => '1d',
                        'range'    => '2d',
                    ]);
            }
        });

        $results = [];
        foreach ($topCandidates as $sym => $nrd0) {
            $close = 0;
            $chg   = 0.0;

            $yfResp = $yfResponses[$sym] ?? null;
            if ($yfResp instanceof \Illuminate\Http\Client\Response && $yfResp->successful()) {
                $meta      = $yfResp->json('chart.result.0.meta');
                $close     = (float) ($meta['regularMarketPrice'] ?? 0);
                $prevClose = (float) ($meta['chartPreviousClose'] ?? $meta['previousClose'] ?? 0);

                if ($close > 0 && $prevClose > 0) {
                    $chg = (($close - $prevClose) / $prevClose) * 100;
                }
            }

            $results[] = [
                'symbol' => $sym,
                'nrd0'   => $nrd0,
                'close'  => $close,
                'chg'    => round($chg, 1),
            ];
        }

        return $results;
    }

    public function getStockBrokerSummary(string $rawSymbol): ?array
    {
        $sym = str_replace('.JK', '', strtoupper(trim($rawSymbol)));
        if ($sym === '') {
            return null;
        }

        $token = $this->getStockbitToken();
        if (empty($token)) {
            return null;
        }

        return Cache::remember("sb_broker_flow_{$sym}", 180, function () use ($sym, $token) {
            $retailBrokers = ['YP', 'XC', 'XL', 'PD', 'KK', 'NI', 'SQ', 'CC', 'GR', 'EP', 'DR', 'LG'];

            for ($i = 0; $i <= 3; $i++) {
                $candidate = now('Asia/Jakarta')->subDays($i);
                if ($candidate->isWeekend()) {
                    continue;
                }
                $dStr = $candidate->format('Y-m-d');

                try {
                    $resp = Http::withToken($token)
                        ->withOptions(['force_ip_resolve' => 'v4'])
                        ->withHeaders([
                            'Origin'     => 'https://stockbit.com',
                            'Referer'    => 'https://stockbit.com/',
                            'User-Agent' => 'Mozilla/5.0',
                        ])
                        ->connectTimeout(4)
                        ->timeout(6)
                        ->get("https://exodus.stockbit.com/marketdetectors/{$sym}", [
                            'from'             => $dStr,
                            'to'               => $dStr,
                            'transaction_type' => 'TRANSACTION_TYPE_NET',
                            'market_board'     => 'MARKET_BOARD_REGULER',
                            'investor_type'    => 'INVESTOR_TYPE_ALL',
                        ]);

                    if (!$resp->successful()) {
                        if (in_array($resp->status(), [401, 403])) {
                            return null;
                        }
                        continue;
                    }

                    $data = $resp->json('data');
                    $brokersBuy  = $data['broker_summary']['brokers_buy'] ?? [];
                    $brokersSell = $data['broker_summary']['brokers_sell'] ?? [];
                    $totalValue  = (float) ($data['bandar_detector']['value'] ?? $data['bandar_detector']['total_value'] ?? 0);

                    if ($totalValue <= 0 || (empty($brokersBuy) && empty($brokersSell))) {
                        continue;
                    }

                    $retailNet = 0.0;
                    foreach ($brokersBuy as $b) {
                        if (in_array($b['netbs_broker_code'] ?? '', $retailBrokers)) {
                            $retailNet += abs((float) ($b['bval'] ?? 0));
                        }
                    }
                    foreach ($brokersSell as $s) {
                        if (in_array($s['netbs_broker_code'] ?? '', $retailBrokers)) {
                            $retailNet -= abs((float) ($s['sval'] ?? 0));
                        }
                    }

                    $topBuyers = array_values(array_filter(array_map(
                        fn($b) => $b['netbs_broker_code'] ?? '',
                        array_slice($brokersBuy, 0, 5)
                    )));
                    $topSellers = array_values(array_filter(array_map(
                        fn($s) => $s['netbs_broker_code'] ?? '',
                        array_slice($brokersSell, 0, 5)
                    )));

                    $retailPct = round((abs($retailNet) / $totalValue) * 100, 1);
                    $absVal = abs($retailNet);
                    if ($absVal >= 1_000_000_000) {
                        $valFmt = 'Rp ' . number_format($absVal / 1_000_000_000, 1, ',', '.') . ' M';
                    } elseif ($absVal >= 1_000_000) {
                        $valFmt = 'Rp ' . number_format($absVal / 1_000_000, 0, ',', '.') . ' Jt';
                    } else {
                        $valFmt = 'Rp ' . number_format($absVal, 0, ',', '.');
                    }

                    $accDistRaw = (string) ($data['bandar_detector']['broker_accdist'] ?? 'Netral');
                    $accDist = trim(str_replace(['_', 'AccDist'], [' ', ''], $accDistRaw)) ?: 'Netral';

                    return [
                        'date_label'  => $candidate->format('d M'),
                        'accdist'     => $accDist,
                        'retail_side' => $retailNet < 0 ? 'Net Sell' : ($retailNet > 0 ? 'Net Buy' : 'Netral'),
                        'retail_pct'  => $retailPct,
                        'retail_val'  => $valFmt,
                        'is_retail_selling' => $retailNet < 0,
                        'top_buyers'  => $topBuyers,
                        'top_sellers' => $topSellers,
                    ];
                } catch (\Throwable $e) {
                    continue;
                }
            }

            return null;
        });
    }


    public function screenKronosUpside(float $minProb = 80.0, int $maxTickers = 28): array
    {
        $analysisSvc = app(StockAnalysisService::class);
        $tickers = array_slice($this->watchlist, 0, $maxTickers);
        $matches = [];

        foreach ($tickers as $rawSym) {
            $cleanSym = strtoupper(str_replace('.JK', '', trim($rawSym)));
            $forecast = $analysisSvc->forecastWithKronos($cleanSym, 5);

            if (!$forecast || !isset($forecast['upside_probability'])) {
                continue;
            }

            $prob = (float) $forecast['upside_probability'];
            if ($prob > $minProb) {
                $flow = null;
                try {
                    $flow = $this->getStockBrokerSummary($cleanSym);
                } catch (\Throwable $e) {
                    $flow = null;
                }

                $matches[] = [
                    'symbol'                   => $cleanSym,
                    'last_close'               => $forecast['last_close'],
                    'target_close'             => $forecast['target_close'],
                    'change_pct'               => $forecast['change_pct'],
                    'upside_probability'       => $prob,
                    'volatility_amplification' => $forecast['volatility_amplification'] ?? null,
                    'bias'                     => $forecast['bias'] ?? 'BULLISH',
                    'accdist'                  => $flow['accdist'] ?? '-',
                    'is_retail_selling'        => $flow['is_retail_selling'] ?? null,
                    'retail_side'              => $flow['retail_side'] ?? null,
                    'retail_pct'               => $flow['retail_pct'] ?? null,
                ];
            }
        }

        usort($matches, function ($a, $b) {
            if ($b['upside_probability'] !== $a['upside_probability']) {
                return $b['upside_probability'] <=> $a['upside_probability'];
            }
            return $b['change_pct'] <=> $a['change_pct'];
        });

        return [
            'scanned_count' => count($tickers),
            'min_prob'      => $minProb,
            'results'       => $matches,
        ];
    }

    public function getBrokerSummary(string $rawSymbol, string $fromDate, string $toDate): array
    {
        $sym = preg_replace('/JK$/', '', strtoupper(trim(preg_replace('/[^A-Za-z0-9]/', '', $rawSymbol))));
        if (strlen($sym) < 4) {
            return ['error' => 'INVALID_SYMBOL'];
        }

        $token = $this->getStockbitToken();
        if (empty($token)) {
            return ['error' => 'NO_TOKEN'];
        }

        $effectiveFrom = $fromDate;
        $effectiveTo   = $toDate;

        // Jika single-day (1D) jatuh di akhir pekan, mundur otomatis ke Jumat bursa terakhir
        if ($fromDate === $toDate) {
            $dt = \Carbon\Carbon::parse($fromDate, 'Asia/Jakarta');
            while ($dt->isWeekend()) {
                $dt->subDay();
            }
            $effectiveFrom = $dt->format('Y-m-d');
            $effectiveTo   = $effectiveFrom;
        }

        $cacheKey = "sb_brokersum_range_{$sym}_{$effectiveFrom}_{$effectiveTo}";
        return \Illuminate\Support\Facades\Cache::remember($cacheKey, 180, function () use ($sym, $token, $effectiveFrom, $effectiveTo) {
            try {
                $resp = \Illuminate\Support\Facades\Http::withToken($token)
                    ->withOptions(['force_ip_resolve' => 'v4'])
                    ->withHeaders([
                        'Origin'     => 'https://stockbit.com',
                        'Referer'    => 'https://stockbit.com/',
                        'User-Agent' => 'Mozilla/5.0',
                    ])
                    ->connectTimeout(5)
                    ->timeout(8)
                    ->get("https://exodus.stockbit.com/marketdetectors/{$sym}", [
                        'from'             => $effectiveFrom,
                        'to'               => $effectiveTo,
                        'transaction_type' => 'TRANSACTION_TYPE_NET',
                        'market_board'     => 'MARKET_BOARD_REGULER',
                        'investor_type'    => 'INVESTOR_TYPE_ALL',
                    ]);

                if (!$resp->successful()) {
                    if (in_array($resp->status(), [401, 403])) {
                        return ['error' => 'TOKEN_EXPIRED'];
                    }
                    return ['error' => 'HTTP_ERROR', 'status' => $resp->status()];
                }

                $json = $resp->json();
                $data = $json['data'] ?? [];
                $bs   = $data['broker_summary'] ?? [];
                $bandar = $data['bandar_detector'] ?? [];

                $rawBuy  = $bs['brokers_buy'] ?? [];
                $rawSell = $bs['brokers_sell'] ?? [];

                $topBuy = [];
                $netBuySum = 0.0;
                foreach (array_slice($rawBuy, 0, 5) as $idx => $b) {
                    $val = (float) ($b['bval'] ?? $b['netbs_buy_val'] ?? 0);
                    $lot = (float) ($b['blot'] ?? $b['netbs_buy_lot'] ?? 0);
                    $avg = (float) ($b['netbs_buy_avg_price'] ?? ($lot > 0 ? $val / ($lot * 100) : 0));
                    $netBuySum += $val;
                    $topBuy[] = [
                        'code' => $b['netbs_broker_code'] ?? '-',
                        'val'  => $val,
                        'lot'  => $lot,
                        'avg'  => $avg,
                    ];
                }

                $topSell = [];
                $netSellSum = 0.0;
                foreach (array_slice($rawSell, 0, 5) as $idx => $s) {
                    $val = abs((float) ($s['sval'] ?? $s['netbs_sell_val'] ?? 0));
                    $lot = abs((float) ($s['slot'] ?? $s['netbs_sell_lot'] ?? 0));
                    $avg = (float) ($s['netbs_sell_avg_price'] ?? ($lot > 0 ? $val / ($lot * 100) : 0));
                    $netSellSum += $val;
                    $topSell[] = [
                        'code' => $s['netbs_broker_code'] ?? '-',
                        'val'  => $val,
                        'lot'  => $lot,
                        'avg'  => $avg,
                    ];
                }

                return [
                    'symbol'       => $sym,
                    'from'         => $effectiveFrom,
                    'to'           => $effectiveTo,
                    'status'       => $bandar['accdist'] ?? 'Netral',
                    'buyer_count'  => (int) ($bandar['total_buyer'] ?? count($rawBuy)),
                    'seller_count' => (int) ($bandar['total_seller'] ?? count($rawSell)),
                    'net_top5'     => $netBuySum - $netSellSum,
                    'top_buy'      => $topBuy,
                    'top_sell'     => $topSell,
                ];
            } catch (\Throwable $e) {
                return ['error' => 'EXCEPTION', 'status' => $e->getMessage()];
            }
        });
    }
}
