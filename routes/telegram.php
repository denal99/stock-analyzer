<?php

use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Internal\InputFile;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use App\Services\StockAnalysisService;
use App\Services\StockScreenerService;
use App\Models\Watchlist;

if (!function_exists('formatAnalysisDataSource')) {
    function formatAnalysisDataSource(array $data, ?array $flow = null): string
    {
        if (($data['data_source'] ?? 'Yahoo Finance') !== 'Stockbit RT') {
            return '🟡 Yahoo Finance (15m delay)' . (!empty($data['last_date']) ? " ({$data['last_date']})" : '');
        }

        $nowWib = now('Asia/Jakarta');
        $hhmm   = (int) $nowWib->format('Hi');
        $isMarketOpen = $nowWib->isWeekday() && $hhmm >= 845 && $hhmm <= 1615;

        if ($isMarketOpen) {
            return '🟢 Stockbit Real-Time (' . $nowWib->format('d/m/Y H:i') . ' WIB)';
        }

        $lastLabel = !empty($flow['date_label'])
            ? "Last {$flow['date_label']}"
            : (!empty($data['last_date']) ? "Last {$data['last_date']}" : $nowWib->format('d/m/Y H:i') . ' WIB');

        return "⚪ Stockbit (Market Tutup • {$lastLabel})";
    }
}

$formatBrokerFlowBlock = function (string $cleanSym, ?array $prefetchedFlow = null, ?float $refPrice = null, int $dec = 0): string {
    try {
        $flow = $prefetchedFlow ?? app(StockScreenerService::class)->getStockBrokerSummary($cleanSym);
        if (!$flow) {
            if (\Illuminate\Support\Facades\Cache::get('sb_token_expired_flag')) {
                return "\n\n⚠️ <b>Broker Flow:</b> Token Stockbit expired — perbarui token.";
            }
            return '';
        }
        $sideIcon = $flow['is_retail_selling'] ? '🟢' : '🔴';
        $buyers   = !empty($flow['top_buyers']) ? implode(', ', $flow['top_buyers']) : '-';
        $sellers  = !empty($flow['top_sellers']) ? implode(', ', $flow['top_sellers']) : '-';

        $sbTag   = !empty($flow['retail_sb']) ? " • <b>{$flow['retail_sb']}</b>" : '';
        $forSide = $flow['foreign_side'] ?? 'Netral';
        $forVal  = $flow['foreign_val'] ?? 'Netral';
        $forIcon = $forSide === 'Net Buy' ? '🟢' : ($forSide === 'Net Sell' ? '🔴' : '⚪');
        $forPart = $forVal !== 'Netral' ? " | Asing {$forIcon} <b>{$forVal}</b>" : '';

        $statusStr = !empty($flow['accdist_5d'])
            ? "1D <b>{$flow['accdist']}</b> • 5D <b>{$flow['accdist_5d']}</b>"
            : "<b>{$flow['accdist']}</b>";

        $rSideShort = str_replace('Net ', '', (string) ($flow['retail_side'] ?? 'Netral'));
        $r5Part = '';
        if (!empty($flow['retail_side_5d']) && $flow['retail_pct_5d'] !== null) {
            $r5Icon = ($flow['is_retail_selling_5d'] ?? false) ? '🟢' : '🔴';
            $r5Part = " | 5D {$r5Icon} {$flow['retail_side_5d']} <b>{$flow['retail_pct_5d']}%</b>";
        }

        $avgParts = [];
        if (!empty($flow['top3_avg_1d']) && $flow['top3_avg_1d'] > 0) {
            $a1 = (float) $flow['top3_avg_1d'];
            $d1 = ($refPrice && $refPrice > 0) ? sprintf(' (%+.1f%%)', (($refPrice - $a1) / $a1) * 100) : '';
            $avgParts[] = '1D <b>' . number_format($a1, $dec) . '</b>' . $d1;
        }
        if (!empty($flow['top3_avg_5d']) && $flow['top3_avg_5d'] > 0) {
            $a5 = (float) $flow['top3_avg_5d'];
            $d5 = ($refPrice && $refPrice > 0) ? sprintf(' (%+.1f%%)', (($refPrice - $a5) / $a5) * 100) : '';
            $avgParts[] = '5D <b>' . number_format($a5, $dec) . '</b>' . $d5;
        }
        $avgLine = !empty($avgParts) ? "\n• Avg3: " . implode(' | ', $avgParts) : '';

        return "\n\n🏦 <b>Broker Flow ({$flow['date_label']}):</b>\n"
             . "• {$statusStr}{$forPart}\n"
             . "• Ritel: {$sideIcon} {$rSideShort} <b>{$flow['retail_pct']}%</b> ({$flow['retail_val']}{$sbTag}){$r5Part}\n"
             . "• Buy: <b>{$buyers}</b> | Sell: <b>{$sellers}</b>{$avgLine}";
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
    ?array $flow,
    ?float $price = null,
    ?float $ma20Val = null,
    ?float $ma50Val = null
): string {
    $r1Fmt   = number_format($r1, $dec);
    $s1Fmt   = number_format($s1, $dec);
    $slFmt   = number_format($sl, $dec);
    $ma50Fmt = ($ma50Val !== null && $ma50Val > 0) ? number_format($ma50Val, $dec) : null;

    $isUptrend   = str_contains($trend, 'Uptrend');
    $isDowntrend = str_contains($trend, 'Downtrend');
    $aboveMa20   = ($price !== null && $ma20Val !== null && $ma20Val > 0 && $price >= $ma20Val);

    if ($aboveMa20 && $ma20Text !== '-' && $ma20Text === $s1Fmt) {
        $supportZone = "S1/MA20 ({$s1Fmt})";
    } elseif ($aboveMa20 && $ma20Text !== '-') {
        $supportZone = "S1 ({$s1Fmt}) / MA20 ({$ma20Text})";
    } else {
        $supportZone = "S1 ({$s1Fmt})";
    }

    if (!$aboveMa20 && $ma20Text !== '-') {
        $confirmTarget = "reclaim MA20 (<b>{$ma20Text}</b>)";
        $reboundTarget = "MA20 (<b>{$ma20Text}</b>)";
    } elseif ($ma50Fmt !== null && ($price === null || $ma50Val > $price)) {
        $confirmTarget = "tembus MA50 (<b>{$ma50Fmt}</b>)";
        $reboundTarget = "MA50 (<b>{$ma50Fmt}</b>)";
    } else {
        $confirmTarget = "breakout R1 (<b>{$r1Fmt}</b>)";
        $reboundTarget = "R1 (<b>{$r1Fmt}</b>)";
    }

    if ($flow) {
        $buyers  = !empty($flow['top_buyers']) ? implode(', ', array_slice($flow['top_buyers'], 0, 3)) : 'institusi';
        $sellers = !empty($flow['top_sellers']) ? implode(', ', array_slice($flow['top_sellers'], 0, 3)) : 'institusi';
        $retailSelling = (bool) ($flow['is_retail_selling'] ?? false);
        $retailBuying  = !$retailSelling && (($flow['retail_side'] ?? '') === 'Net Buy');
        $isAccTag      = stripos($flow['accdist'], 'Acc') !== false;
        $isDistTag     = stripos($flow['accdist'], 'Dist') !== false;
        $volNote       = $volRatio < 0.8 ? ", vol sepi ({$volRatio}x)" : "";

        $ad5d       = (string) ($flow['accdist_5d'] ?? '');
        $is5dDist   = stripos($ad5d, 'Dist') !== false;
        $is5dAcc    = stripos($ad5d, 'Acc') !== false;
        $r5dBuying  = (($flow['retail_side_5d'] ?? '') === 'Buy') && (($flow['retail_pct_5d'] ?? 0) >= 15);
        $r5dSelling = (bool) ($flow['is_retail_selling_5d'] ?? false) && (($flow['retail_pct_5d'] ?? 0) >= 15);

        $warn5dDist = ($is5dDist || $r5dBuying)
            ? " ⚠️ 5D masih " . ($is5dDist ? $ad5d : "ritel buy ({$flow['retail_pct_5d']}%)") . "."
            : "";
        $note5dAcc  = ($is5dAcc && $r5dSelling)
            ? " (Catatan: 5D masih {$ad5d}.)"
            : "";

        $avgWarn = '';
        $refAvg  = !empty($flow['top3_avg_5d']) ? (float) $flow['top3_avg_5d'] : (float) ($flow['top3_avg_1d'] ?? 0);
        if ($price !== null && $price > 0 && $refAvg > 0) {
            $avgDist = round((($price - $refAvg) / $refAvg) * 100, 1);
            if ($avgDist >= 8.0) {
                $avgWarn = " ⚠️ +{$avgDist}% di atas Avg3 — rawan TP.";
            }
        }

        if (!$isDowntrend && $retailBuying && $flow['retail_pct'] >= 15) {
            $posNote = $isUptrend ? "Tren naik" : "Konsolidasi";
            return "⚠️ <b>Divergence:</b> {$posNote}, tapi ritel net buy ({$flow['retail_pct']}%) &amp; <b>{$sellers}</b> distribusi{$volNote}.{$note5dAcc} Pantau <b>{$supportZone}</b> atau BoB R1 (<b>{$r1Fmt}</b>).";
        }

        if (!$isDowntrend && $retailSelling && $isDistTag) {
            return "⚖️ <b>Mixed Flow:</b> Ritel jual ({$flow['retail_pct']}%) ditampung <b>{$buyers}</b>, tertahan jualan <b>{$sellers}</b>{$volNote}. Pantau BoW <b>{$supportZone}</b> / BoB R1 (<b>{$r1Fmt}</b>).";
        }

        if (!$isDowntrend && ($isAccTag || $retailSelling)) {
            if ($rsiVal !== null && $rsiVal >= 72) {
                return "🔥 <b>Strong Confluence:</b> Akumulasi <b>{$buyers}</b> saat ritel jual ({$flow['retail_pct']}%), RSI jenuh beli.{$warn5dDist} Tunggu pullback <b>{$supportZone}</b>.";
            }
            return "✅ <b>Bullish Confluence:</b> Penyerapan <b>{$buyers}</b> saat ritel jual ({$flow['retail_pct']}%){$volNote}.{$warn5dDist}{$avgWarn} BoW <b>{$supportZone}</b>, TP R1 (<b>{$r1Fmt}</b>).";
        }

        if ($isDowntrend && ($isAccTag || $retailSelling)) {
            $baseSup = $aboveMa20 ? $supportZone : "S1 ({$s1Fmt})";
            $volWarn = $volRatio < 0.8 ? " Vol sepi ({$volRatio}x, butuh &gt;1x)." : "";
            return "👀 <b>Early Acc:</b> Penyerapan <b>{$buyers}</b> saat ritel jual ({$flow['retail_pct']}%).{$volWarn}{$warn5dDist}{$avgWarn} Pantau bertahan di <b>{$baseSup}</b> &amp; {$confirmTarget}, SL {$slFmt}.";
        }

        if ($isDowntrend && ($isDistTag || $retailBuying)) {
            return "🚨 <b>Hindari tangkap pisau jatuh:</b> Bearish + distribusi (<b>{$sellers}</b>) sementara ritel menampung.{$note5dAcc} Wait &amp; see hingga {$confirmTarget}.";
        }
    }

    if ($isDowntrend) {
        $baseSup = $aboveMa20 ? $supportZone : "S1 ({$s1Fmt})";
        return ($rsiVal !== null && $rsiVal <= 30)
            ? "⚠️ Downtrend namun RSI <b>Oversold</b>. Pantau pantulan <b>{$baseSup}</b> menuju {$reboundTarget}, disiplin SL {$slFmt}."
            : "⚠️ Tren bearish di bawah MA. Wait &amp; see hingga {$confirmTarget}.";
    } elseif (str_contains($trend, 'Strong Uptrend')) {
        return ($rsiVal !== null && $rsiVal >= 72)
            ? "🔥 Uptrend solid tapi RSI jenuh beli. Tunggu pullback di <b>{$supportZone}</b>."
            : "✅ Uptrend sehat. BoW di <b>{$supportZone}</b>, target terdekat R1 (<b>{$r1Fmt}</b>).";
    } elseif (str_contains($trend, 'Pullback in Uptrend')) {
        return "🟡 Koreksi wajar dalam tren naik. Pantau pantulan di <b>{$supportZone}</b>.";
    }

    return "⚖️ Konsolidasi/transisi. Pantau dekat <b>{$supportZone}</b> atau tunggu BoB R1 (<b>{$r1Fmt}</b>) bervolume.";
};

$sendAnalysisChart = function (Nutgram $bot, string $ticker, StockAnalysisService $service, ?float $maxRisk = null, string $timeframe = '1D', bool $sendLoadingMsg = true) use ($formatBrokerFlowBlock, $buildConfluenceStrategy) {
    $maxRisk = ($maxRisk !== null && $maxRisk > 0)
        ? $maxRisk
        : \App\Models\UserRiskProfile::maxRiskFor($bot->chatId());
    $ticker = strtoupper(htmlspecialchars(trim($ticker)));
    if ($sendLoadingMsg) {
        $bot->sendMessage("⏳ Sedang menganalisa <b>{$ticker}</b>...", parse_mode: ParseMode::HTML);
    }

    try {
        $tf = strtoupper(trim($timeframe));
        $maxCapital = \App\Models\UserRiskProfile::forChat($bot->chatId())?->capital;
        $preSym = str_replace('.JK', '', strtoupper(trim($ticker)));
        $brokerFlow = null;
        try {
            $brokerFlow = app(StockScreenerService::class)->getStockBrokerSummary($preSym);
        } catch (\Throwable $e) {
            $brokerFlow = null;
        }
        $isEarlyAcc = (bool) ($brokerFlow && (
            stripos((string) ($brokerFlow['accdist'] ?? ''), 'Acc') !== false
            || (bool) ($brokerFlow['is_retail_selling'] ?? false)
        ));
        $bandarAvg = (float) ($brokerFlow['top3_avg_5d'] ?? $brokerFlow['top3_avg_1d'] ?? 0);
        $data = $service->analyze($ticker, $maxRisk, $tf, $maxCapital, $isEarlyAcc, bandarAvg: ($bandarAvg > 0 ? $bandarAvg : null));

        $trend = "🟡 Sideways / Konsolidasi";
        if ($data['ma200'] && $data['price'] > $data['ma50'] && $data['ma50'] > $data['ma200']) {
            $trend = "🟢 Strong Uptrend (Harga &gt; MA50 &gt; MA200)";
        } elseif ($data['price'] > $data['ma50'] && (!$data['ma200'] || $data['price'] < $data['ma200'])) {
            $trend = "🟢 Short-Term Uptrend (Above MA50" . ($data['ma200'] ? ", Below MA200" : "") . ")";
        } elseif ($data['ma200'] && $data['price'] < $data['ma50'] && $data['ma50'] > $data['ma200']) {
            $trend = ($data['price'] >= $data['ma200'])
                ? "🟡 Pullback in Uptrend (Below MA50, Above MA200)"
                : "🟠 Deep Pullback (&lt; MA50 &amp; MA200)";
        } elseif ($data['ma200'] && $data['price'] < $data['ma50'] && $data['ma50'] < $data['ma200']) {
            $trend = (!empty($data['ma20']) && $data['price'] > $data['ma20'])
                ? "🟠 Downtrend (Rebound &gt; MA20)"
                : "🔴 Strong Downtrend (Harga &lt; MA50 &lt; MA200)";
        } elseif ($data['price'] < $data['ma50']) {
            $trend = (!empty($data['ma20']) && $data['price'] > $data['ma20'])
                ? "🟠 Short-Term Downtrend (Rebound &gt; MA20)"
                : "🔴 Short-Term Downtrend (Below MA50)";
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
        $volEstTag = !empty($data['vol_is_projected']) ? ', Est.' : '';
        $atrPctVal = (float) ($data['atr_pct'] ?? 0);
        $atrPart   = $atrPctVal > 0 ? " | ATR: <b>{$atrPctVal}%</b>" : "";

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
        $allocShort = \App\Models\UserRiskProfile::formatShort((float) ($data['alloc_rp'] ?? 0));

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
            $insight .= "\n⚠️ <b>R:R 1:{$rrRatio} kurang ideal</b> — tunggu pullback.";
        }

        $cleanSym = str_replace('.JK', '', $data['symbol']);
        $ma20Val = isset($data['ma20']) && is_numeric($data['ma20']) ? (float) $data['ma20'] : null;
        $ma50Val = isset($data['ma50']) && is_numeric($data['ma50']) ? (float) $data['ma50'] : null;
        $insight = $buildConfluenceStrategy($trend, $rsiVal, $volRatio, $r1, $s1, $sl, $ma20Text, $dec, $brokerFlow, $price, $ma20Val, $ma50Val);

        $isDowntrend = str_contains($trend, 'Downtrend');
        $isEarlyAcc  = $brokerFlow && (
            stripos((string) ($brokerFlow['accdist'] ?? ''), 'Acc') !== false
            || (bool) ($brokerFlow['is_retail_selling'] ?? false)
        );
        $isOversold  = $rsiVal !== null && $rsiVal <= 30;
        $isNoEntry   = $isDowntrend && !$isEarlyAcc && !$isOversold;

        if (!$isNoEntry && $rrRatio > 0 && $rrRatio < 1.0) {
            $insight .= "\n⚠️ <b>R:R 1:{$rrRatio} kurang ideal</b> — tunggu pullback.";
        }

        $ma20Val   = isset($data['ma20']) && is_numeric($data['ma20']) ? (float) $data['ma20'] : null;
        $r1Fmt     = number_format($r1, $dec);
        $r2Fmt     = number_format($r2, $dec);
        $s1Fmt     = number_format($s1, $dec);
        $s2Fmt     = number_format($s2, $dec);
        $slFmt     = number_format($sl, $dec);
        $riskShort = \App\Models\UserRiskProfile::formatShort($maxRisk);

        if ($isNoEntry) {
            if ($ma20Val !== null && $ma20Val > $price && $ma20Val < $r1) {
                $buyAreaText = "<b>Wait &amp; See</b> (reclaim MA20 &gt; <b>{$ma20Text}</b>)";
                $planLine    = "• Plan: <b>No Entry</b> (aktif &gt; MA20 → TP <b>{$r1Fmt}</b> / <b>{$r2Fmt}</b>)\n";
            } elseif ($ma50Val !== null && $ma50Val > $price && $ma50Val < $r1) {
                $buyAreaText = "<b>Wait &amp; See</b> (tembus MA50 &gt; <b>{$ma50Text}</b>)";
                $planLine    = "• Plan: <b>No Entry</b> (aktif &gt; MA50 → TP <b>{$r1Fmt}</b> / <b>{$r2Fmt}</b>)\n";
            } else {
                $buyAreaText = "<b>Wait &amp; See</b> (BoB &gt; <b>{$r1Fmt}</b>)";
                $planLine    = "• Plan: <b>No Entry</b> (aktif &gt; R1 → TP <b>{$r2Fmt}</b>)\n";
            }
            $lotLine = "• Lot: <b>Tunda Entry</b> • Risk: <b>{$riskShort}</b>";
        } else {
            if ($isDowntrend) {
                $buyAreaText = ($ma20Val !== null && $ma20Val > $price)
                    ? "Spec. BoW <b>{$s1Fmt}</b> / Reclaim MA20 (<b>{$ma20Text}</b>)"
                    : "Spec. BoW <b>{$s1Fmt}</b> / &gt; <b>{$r1Fmt}</b> (BoB)";
            } elseif ($ma20Val !== null && $ma20Val < $price && $ma20Val > ($sl * 1.005)) {
                $lowBuy  = min($ma20Val, (float) $s1);
                $highBuy = max($ma20Val, (float) $s1);
                if ($highBuy >= $price) {
                    $highBuy = (float) $s1;
                }
                $rangeStr = (abs($highBuy - $lowBuy) > 0)
                    ? "<b>" . number_format($lowBuy, $dec) . " - " . number_format($highBuy, $dec) . "</b>"
                    : "<b>{$s1Fmt}</b>";
                $buyAreaText = "{$rangeStr} (BoW) / &gt; <b>{$r1Fmt}</b> (BoB)";
            } else {
                $buyAreaText = "<b>{$s1Fmt}</b> (BoW) / &gt; <b>{$r1Fmt}</b> (BoB)";
            }
            $tpStr    = ($r2 > $r1) ? "<b>{$r1Fmt}</b> / <b>{$r2Fmt}</b>" : "<b>{$r1Fmt}</b>";
            $planLine = "• Plan: TP {$tpStr} | SL <b>{$slFmt}</b> ({$slPct}%) | R:R <b>1:{$rrRatio}</b>\n";
            $bowLine  = '';
            if ($s1 > $sl && $s1 < ($price * 0.99)) {
                $bowRisk   = $s1 - $sl;
                $bowReward = max(0, $r1 - $s1);
                $bowRR     = $bowRisk > 0 ? round($bowReward / $bowRisk, 2) : 0;
                $bowLotNum = $bowRisk > 0 ? max(1, (int) floor(floor($maxRisk / $bowRisk) / 100)) : 0;
                if ($maxCapital !== null && $maxCapital > 0 && $s1 > 0) {
                    $bowLotNum = min($bowLotNum, max(0, (int) floor($maxCapital / (100 * $s1))));
                }
                if ($bowRR > 0 && $bowLotNum > 0) {
                    $bowLotFmt = number_format($bowLotNum);
                    $bowAlloc  = \App\Models\UserRiskProfile::formatShort($bowLotNum * 100 * $s1);
                    $bowLine   = "\n• BoW <b>{$s1Fmt}</b>: R:R <b>1:{$bowRR}</b> | <b>{$bowLotFmt} Lot</b> (±{$bowAlloc})";
                }
            }
            $lotLine = "• Now: <b>{$recLot} Lot</b> (±{$allocShort}) • Risk <b>{$riskShort}</b>{$bowLine}";
        }

        $trendIcon = ($isDowntrend || str_contains($trend, 'Deep Pullback')) ? '📉' : (str_contains($trend, 'Uptrend') ? '📈' : '➡️');
        $rsTag = '';
        if (isset($data['rs_vs_ihsg']) && $data['rs_vs_ihsg'] !== null) {
            $rsNum  = (float) $data['rs_vs_ihsg'];
            $rsSign = $rsNum >= 0 ? '+' : '';
            $rsDot  = $rsNum >= 3.0 ? '🟢' : ($rsNum <= -3.0 ? '🔴' : '⚪');
            $rsTag  = " | RS20D: {$rsDot} <b>{$rsSign}{$rsNum}%</b>";
        }
        \Illuminate\Support\Facades\Cache::put("last_plan:{$cleanSym}", [
            's1' => (float) $s1, 'r1' => (float) $r1, 'sl' => (float) $sl,
            'price' => (float) $price, 'dec' => $dec,
        ], 7200);
        $sourceStr = formatAnalysisDataSource($data, $brokerFlow);

        $adtvRpVal    = (float) ($data['adtv_rp'] ?? 0);
        $adtvShortVal = (string) ($data['adtv_short'] ?? '');
        if ($adtvRpVal > 0 && $adtvRpVal < 1_000_000_000) {
            $insight .= "\n⚠️ <b>Likuiditas sangat tipis</b> (ADTV {$adtvShortVal}) — rawan slippage.";
        } elseif ($adtvRpVal > 0 && $adtvRpVal < 5_000_000_000) {
            $insight .= "\n⚠️ <b>Likuiditas tipis</b> (ADTV {$adtvShortVal}) — jaga ukuran lot.";
        } elseif (!empty($data['high_impact_alloc']) && $adtvShortVal !== '') {
            $insight .= "\n⚠️ <b>Alokasi &gt;2% ADTV</b> ({$adtvShortVal}) — pecah order/kecilkan lot.";
        }

        $caption = "📊 <b>ANALISIS: {$cleanSym} [{$tf}]</b>\n" .
                   "━━━━━━━━━━━━━━\n" .
                   "💰 <b>Harga:</b> Rp " . number_format($price, $dec) . " (<b>{$sign}{$data['change_pct']}%</b>)\n" .
                   "{$trendIcon} <b>Tren:</b> {$trend}{$rsTag}\n\n" .
                   "🧭 <b>Indikator:</b>\n" .
                   "• MA20: <b>{$ma20Text}</b> | MA50: <b>{$ma50Text}</b> | MA200: <b>{$ma200Text}</b>\n" .
                   "• RSI: <b>{$rsiLabel}</b> | Vol: <b>{$volRatio}x</b> ({$volState}{$volEstTag}){$atrPart}\n\n" .
                   "🎯 <b>Trading Plan:</b>\n" .
                   "• Res: R1 <b>{$r1Fmt}</b> (+{$r1Pct}%) | R2 <b>{$r2Fmt}</b>\n" .
                   "• Sup: S1 <b>{$s1Fmt}</b> ({$s1Pct}%) | S2 <b>{$s2Fmt}</b>\n" .
                   "• Buy: {$buyAreaText}\n" .
                   $planLine .
                   $lotLine .
                   $formatBrokerFlowBlock($cleanSym, $brokerFlow, $price, $dec) . "\n\n" .
                   "💡 <b>Strategi:</b>\n{$insight}\n\n📡 {$sourceStr}";

        $riskInt = (int) round($maxRisk);
        $tfKeyboard = InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make($tf === '1H' ? '• 1H •' : '1H', callback_data: "tf:{$cleanSym}:1H:{$riskInt}"),
                InlineKeyboardButton::make($tf === '1D' ? '• 1D •' : '1D', callback_data: "tf:{$cleanSym}:1D:{$riskInt}"),
                InlineKeyboardButton::make($tf === '1W' ? '• 1W •' : '1W', callback_data: "tf:{$cleanSym}:1W:{$riskInt}"),
                InlineKeyboardButton::make($tf === '1M' ? '• 1M •' : '1M', callback_data: "tf:{$cleanSym}:1M:{$riskInt}")
            )
            ->addRow(
                InlineKeyboardButton::make('🤖 Kronos AI (5D)', callback_data: "forecast:{$cleanSym}"),
                InlineKeyboardButton::make('🕵️ Broksum', callback_data: "abroksum:{$cleanSym}")
            )
            ->addRow(
                InlineKeyboardButton::make('🔭 MTF', callback_data: "drill:mtf:{$cleanSym}"),
                InlineKeyboardButton::make('💎 Valuasi', callback_data: "drill:valuasi:{$cleanSym}"),
                InlineKeyboardButton::make('🧾 Audit', callback_data: "drill:audit:{$cleanSym}"),
                InlineKeyboardButton::make('🔔 Pantau', callback_data: "drill:pantau:{$cleanSym}")
            );

        $plainCap = html_entity_decode(strip_tags($caption), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $utf16Len = intdiv(strlen(mb_convert_encoding($plainCap, 'UTF-16BE', 'UTF-8')), 2);

        if ($utf16Len <= 1020) {
            $bot->sendPhoto(
                photo: InputFile::make($data['chart_path']),
                caption: $caption,
                parse_mode: ParseMode::HTML,
                reply_markup: $tfKeyboard
            );
        } else {
            $bot->sendPhoto(
                photo: InputFile::make($data['chart_path']),
                caption: "📊 <b>CHART: {$cleanSym} [{$tf}]</b> • Rp " . number_format($price, $dec) . " (<b>{$sign}{$data['change_pct']}%</b>)",
                parse_mode: ParseMode::HTML
            );
            $bot->sendMessage(
                text: $caption,
                parse_mode: ParseMode::HTML,
                reply_markup: $tfKeyboard
            );
        }

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
    $maxRisk = \App\Models\UserRiskProfile::maxRiskFor($bot->chatId());
    $riskLabel = \App\Models\UserRiskProfile::formatShort($maxRisk);

    $msg = "Halo! Saya <b>Easy Cuan</b>, asisten analisis teknikal, fundamental &amp; bandarmologi saham BEI.\n\n"
        . "<b>🌍 Market &amp; Sektor:</b>\n"
        . "🌅 <code>/brief</code> — Morning Brief &amp; IHSG Macro\n"
        . "🧭 <code>/sektor</code> — Rotasi &amp; Momentum 8 Sektor\n"
        . "📡 <code>/radar</code> — Intraday Sweep &amp; Unusual Vol (15m)\n\n"
        . "<b>📊 Teknikal &amp; Trading Plan:</b>\n"
        . "📈 <code>/analisa BBCA</code> — Chart &amp; Plan (Risk <b>{$riskLabel}</b>)\n"
        . "🧮 <code>/analisa BBCA 500000</code> — Plan Custom Max Risk\n"
        . "⚙️ <code>/setrisk</code> — Atur Modal &amp; Risk (Aktif: <b>{$riskLabel}</b>)\n\n"
        . "<b>🏦 Bandarmologi &amp; Flow:</b>\n"
        . "🕵️ <code>/brokersum BBRI</code> — Broker Summary Real-Time\n"
        . "🧲 <code>/nonritel</code> — Screener Non-Retail Flow (1D/1W/1M/Tgl)\n"
        . "📉 <code>/topritel</code> — Top Retail Jualan\n"
        . "🏦 <code>/akumulasi</code> — Deteksi Akumulasi (VSA/OBV)\n"
        . "🔥 <code>/volume</code> — Lonjakan Volume &gt;= 1.5x\n\n"
        . "<b>🔍 Screener Fundamental:</b>\n"
        . "🔎 <code>/screener</code> — Default (PER &lt; 10, PBV &lt; 5)\n"
        . "🎯 <code>/screener 10 2 1</code> — Custom PER, PBV, [DER]\n"
        . "💰 <code>/dividen 10</code> — Dividend Yield &gt;= 10%\n\n"
        . "<b>💎 Valuasi, Komparasi &amp; Audit:</b>\n"
        . "💎 <code>/valuasi BBRI</code> — Nilai Wajar &amp; MoS\n"
        . "⚖️ <code>/banding BBRI BMRI BBCA</code> — Head-to-Head\n"
        . "📑 <code>/audit ASII</code> — Kualitas Laba &amp; F-Score\n\n"
        . "<b>🔔 Watchlist &amp; Alert:</b>\n"
        . "📋 <code>/watchlist</code> | ➕ <code>/pantau BBRI</code> | ➖ <code>/unpantau BBRI</code>";

    $bot->sendMessage($msg, parse_mode: ParseMode::HTML);
});

$sendRiskProfile = function (Nutgram $bot, ?string $notice = null): void {
    $profile = \App\Models\UserRiskProfile::forChat($bot->chatId());
    $maxRisk = \App\Models\UserRiskProfile::maxRiskFor($bot->chatId());
    $riskRp = \App\Models\UserRiskProfile::formatRp($maxRisk);

    $detail = $profile && $profile->capital && $profile->risk_percent
        ? "💼 Modal: <b>" . \App\Models\UserRiskProfile::formatRp($profile->capital) . "</b>\n"
            . "📐 Risk/Trade: <b>" . rtrim(rtrim(number_format($profile->risk_percent, 2, ',', '.'), '0'), ',') . "%</b>\n"
        : "💼 Mode: <b>Nominal Langsung" . ($profile ? '' : ' (Default)') . "</b>\n";

    $header = $notice ? "{$notice}\n\n" : '';
    $msg = $header
        . "⚙️ <b>PENGATURAN RISK MANAGEMENT</b>\n"
        . "━━━━━━━━━━━━━━\n"
        . $detail
        . "🛡️ Max Risk Aktif: <b>{$riskRp}</b> / trade\n\n"
        . "<b>Cara Mengubah:</b>\n"
        . "• <code>/setrisk 50000000 1</code> — Modal Rp 50 Jt, Risk 1%\n"
        . "• <code>/setrisk 500000</code> — Set Max Risk langsung Rp 500 Rb\n"
        . "• <code>/setrisk reset</code> — Kembali ke default Rp 1 Jt\n\n"
        . "Atau pilih preset cepat di bawah:";

    $keyboard = \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup::make()
        ->addRow(
            \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make('Rp 250 Rb', callback_data: 'setrisk:preset:250000'),
            \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make('Rp 500 Rb', callback_data: 'setrisk:preset:500000'),
            \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make('Rp 1 Jt', callback_data: 'setrisk:preset:1000000'),
        )
        ->addRow(
            \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make('Rp 2 Jt', callback_data: 'setrisk:preset:2000000'),
            \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make('Rp 5 Jt', callback_data: 'setrisk:preset:5000000'),
            \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make('🔄 Reset', callback_data: 'setrisk:reset'),
        );

    $bot->sendMessage($msg, parse_mode: ParseMode::HTML, reply_markup: $keyboard);
};

$bot->onCommand('setrisk {args}', function (Nutgram $bot, string $args) use ($sendRiskProfile) {
    $trimmed = trim($args);
    if (strtolower($trimmed) === 'reset') {
        \App\Models\UserRiskProfile::resetForChat($bot->chatId());
        $sendRiskProfile($bot, '✅ Pengaturan risk dikembalikan ke default (Rp 1 Jt).');
        return;
    }

    $parts = preg_split('/\s+/', $trimmed);

    if (count($parts) >= 2) {
        $capital = (float) preg_replace('/[^0-9]/', '', $parts[0]);
        $pct = (float) str_replace(',', '.', preg_replace('/[^0-9.,]/', '', $parts[1]));

        if ($capital < 100000 || $pct <= 0 || $pct > 100) {
            $bot->sendMessage(
                "⚠️ Input tidak valid. Modal minimal Rp 100.000 dan persentase risk antara 0.1% - 100%.\nContoh: <code>/setrisk 50000000 1.5</code>",
                parse_mode: ParseMode::HTML
            );
            return;
        }

        $maxRisk = round($capital * ($pct / 100));
        \App\Models\UserRiskProfile::saveForChat($bot->chatId(), $maxRisk, $capital, $pct);
        $sendRiskProfile($bot, '✅ Profil modal &amp; risk berhasil disimpan.');
        return;
    }

    $directRisk = (float) preg_replace('/[^0-9]/', '', $parts[0] ?? '');
    if ($directRisk < 10000) {
        $bot->sendMessage(
            "⚠️ Nominal Max Risk minimal Rp 10.000.\nContoh: <code>/setrisk 500000</code> atau <code>/setrisk 50000000 1</code>",
            parse_mode: ParseMode::HTML
        );
        return;
    }

    \App\Models\UserRiskProfile::saveForChat($bot->chatId(), $directRisk);
    $sendRiskProfile($bot, '✅ Max Risk default berhasil diperbarui.');
});

$bot->onCommand('setrisk', function (Nutgram $bot) use ($sendRiskProfile) {
    $sendRiskProfile($bot);
});

$bot->onCallbackQueryData('setrisk:preset:{amount}', function (Nutgram $bot, string $amount) use ($sendRiskProfile) {
    $val = (float) preg_replace('/[^0-9]/', '', $amount);
    if ($val >= 10000) {
        \App\Models\UserRiskProfile::saveForChat($bot->chatId(), $val);
        $bot->answerCallbackQuery(text: 'Max Risk diset ke ' . \App\Models\UserRiskProfile::formatShort($val));
        $sendRiskProfile($bot, '✅ Max Risk diubah ke <b>' . \App\Models\UserRiskProfile::formatRp($val) . '</b>.');
    }
});

$bot->onCallbackQueryData('setrisk:reset', function (Nutgram $bot) use ($sendRiskProfile) {
    \App\Models\UserRiskProfile::resetForChat($bot->chatId());
    $bot->answerCallbackQuery(text: 'Risk direset ke default Rp 1 Jt');
    $sendRiskProfile($bot, '✅ Pengaturan risk dikembalikan ke default (Rp 1 Jt).');
});

// =========================================================================
// BROKER SUMMARY DENGAN INTERACTIVE INLINE CALENDAR DATE-RANGE PICKER
// =========================================================================
$fmtIdrShort = function (float $val): string {
    $abs = abs($val);
    $sign = $val < 0 ? '-' : '';
    if ($abs >= 1e12) return $sign . number_format($abs / 1e12, 2) . ' T';
    if ($abs >= 1e9)  return $sign . number_format($abs / 1e9, 2) . ' M';
    if ($abs >= 1e6)  return $sign . number_format($abs / 1e6, 1) . ' Jt';
    return $sign . number_format($abs, 0);
};

$buildBrokerCalendar = function (string $sym, int $year, int $month, ?string $startDate = null) {
    $dt = \Carbon\Carbon::createFromDate($year, $month, 1, 'Asia/Jakarta');
    $todayStr = now('Asia/Jakarta')->format('Y-m-d');

    $prev = $dt->copy()->subMonth();
    $next = $dt->copy()->addMonth();

    $monthNames = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
        5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
        9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
    ];
    $monthLabel = ($monthNames[$month] ?? $dt->format('M')) . ' ' . $year;

    $kb = \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup::make();

    // Baris navigasi bulan
    $kb->addRow(
        \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make('◀️', callback_data: "bscal:nav:{$sym}:{$prev->year}:{$prev->month}"),
        \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make("📅 {$monthLabel}", callback_data: "bscal:noop"),
        \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make('▶️', callback_data: "bscal:nav:{$sym}:{$next->year}:{$next->month}")
    );

    // Header hari (Senin - Minggu)
    $dayHeaders = [];
    foreach (['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'] as $dh) {
        $dayHeaders[] = \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make($dh, callback_data: "bscal:noop");
    }
    $kb->addRow(...$dayHeaders);

    // Grid tanggal
    $firstWeekday = $dt->dayOfWeekIso;
    $daysInMonth  = $dt->daysInMonth;
    $row = [];

    for ($i = 1; $i < $firstWeekday; $i++) {
        $row[] = \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make(' ', callback_data: "bscal:noop");
    }

    for ($d = 1; $d <= $daysInMonth; $d++) {
        $dateStr = sprintf('%04d-%02d-%02d', $year, $month, $d);
        $isFuture = $dateStr > $todayStr;

        if ($isFuture) {
            $label = '·';
            $cb = 'bscal:noop';
        } else {
            $label = ($startDate === $dateStr) ? "[{$d}]" : (string) $d;
            $cb = "bscal:pick:{$sym}:{$dateStr}";
        }

        $row[] = \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make($label, callback_data: $cb);

        if (count($row) === 7) {
            $kb->addRow(...$row);
            $row = [];
        }
    }

    if (!empty($row)) {
        while (count($row) < 7) {
            $row[] = \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make(' ', callback_data: "bscal:noop");
        }
        $kb->addRow(...$row);
    }

    // Baris tombol aksi cepat & reset
    $kb->addRow(
        \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make('⚡ Hari Ini', callback_data: "bsrun:{$sym}:1D"),
        \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make('🔄 Reset', callback_data: "bscal:reset:{$sym}:{$year}:{$month}"),
        \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make('🔙 Menu', callback_data: "bsmenu:{$sym}")
    );

    return $kb;
};

$renderBrokerSummary = function (\SergiX44\Nutgram\Nutgram $bot, string $sym, string $fromDate, string $toDate, bool $editMessage = false, string $investorType = 'ALL', string $activePreset = '') use ($fmtIdrShort) {
    $invKey = match (strtoupper(trim($investorType))) {
        'FOR', 'FOREIGN' => 'FOR',
        'DOM', 'DOMESTIC' => 'DOM',
        default => 'ALL',
    };
    $presetNorm = strtoupper(trim($activePreset));

    $screener = app(\App\Services\StockScreenerService::class);
    $res = $screener->getBrokerSummary($sym, $fromDate, $toDate, $invKey);

    $effFrom = (string) ($res['from'] ?? $fromDate);
    $effTo   = (string) ($res['to'] ?? $toDate);
    $fromCompact = str_replace('-', '', $effFrom);
    $toCompact   = str_replace('-', '', $effTo);
    $presetTag   = in_array($presetNorm, ['1D', '1W', '1M'], true) ? $presetNorm : 'CAL';

    $btn1D = $presetNorm === '1D' ? '• 1D •' : '1D';
    $btn1W = $presetNorm === '1W' ? '• 1W •' : '1W';
    $btn1M = $presetNorm === '1M' ? '• 1M •' : '1M';

    $btnAll = $invKey === 'ALL' ? '• 👥 All •' : '👥 All';
    $btnFor = $invKey === 'FOR' ? '• 🌍 Foreign •' : '🌍 Foreign';
    $btnDom = $invKey === 'DOM' ? '• 🇮🇩 Domestic •' : '🇮🇩 Domestic';

    $kb = \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup::make()
        ->addRow(
            \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make($btn1D, callback_data: "bsrun:{$sym}:1D:{$invKey}"),
            \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make($btn1W, callback_data: "bsrun:{$sym}:1W:{$invKey}"),
            \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make($btn1M, callback_data: "bsrun:{$sym}:1M:{$invKey}"),
            \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make('📅 Tgl', callback_data: "bscal:open:{$sym}")
        )
        ->addRow(
            \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make($btnAll, callback_data: "bsinv:{$sym}:ALL:{$fromCompact}:{$toCompact}:{$presetTag}"),
            \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make($btnFor, callback_data: "bsinv:{$sym}:FOR:{$fromCompact}:{$toCompact}:{$presetTag}"),
            \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make($btnDom, callback_data: "bsinv:{$sym}:DOM:{$fromCompact}:{$toCompact}:{$presetTag}")
        )
        ->addRow(
            \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make("📊 Chart {$sym}", callback_data: "chart:{$sym}")
        );

    if (isset($res['error'])) {
        $errMap = [
            'NO_TOKEN'       => '⚠️ Token Stockbit belum diatur. Gunakan perintah <code>/settoken TOKEN_ANDA</code>.',
            'TOKEN_EXPIRED'  => '🔴 Token Stockbit sudah expired. Silakan perbarui via <code>/settoken TOKEN_BARU</code>.',
            'INVALID_SYMBOL' => '❌ Kode saham tidak valid.',
        ];
        $errMsg = $errMap[$res['error']] ?? "❌ Gagal memuat Broker Summary {$sym} (HTTP " . ($res['status'] ?? '-') . ").";
        if ($editMessage) {
            $bot->editMessageText($errMsg, parse_mode: \SergiX44\Nutgram\Telegram\Properties\ParseMode::HTML, reply_markup: $kb);
        } else {
            $bot->sendMessage($errMsg, parse_mode: \SergiX44\Nutgram\Telegram\Properties\ParseMode::HTML, reply_markup: $kb);
        }
        return;
    }

    $fromDt = \Carbon\Carbon::parse($res['from']);
    $toDt   = \Carbon\Carbon::parse($res['to']);
    if ($res['from'] === $res['to']) {
        $periodStr = $fromDt->format('d M Y');
    } elseif ($fromDt->format('Y-m') === $toDt->format('Y-m')) {
        $periodStr = $fromDt->format('d') . '–' . $toDt->format('d M Y');
    } elseif ($fromDt->year === $toDt->year) {
        $periodStr = $fromDt->format('d M') . '–' . $toDt->format('d M y');
    } else {
        $periodStr = $fromDt->format('d M y') . '–' . $toDt->format('d M y');
    }

    $invLabel = match ($invKey) {
        'FOR' => '🌍 Foreign',
        'DOM' => '🇮🇩 Domestic',
        default => '👥 All',
    };

    $badgeAd = static function (string $ad): string {
        $low = strtolower($ad);
        if (str_contains($low, 'acc')) {
            return "🟢 <b>{$ad}</b>";
        }
        if (str_contains($low, 'dist')) {
            return "🔴 <b>{$ad}</b>";
        }
        return "⚪ {$ad}";
    };

    // Format ringkas tanpa spasi satuan agar kolom aman di layar HP (maks 6-7 karakter)
    $fmtBdIdr = static function (float $val): string {
        $sign = $val > 0 ? '+' : ($val < 0 ? '-' : '');
        $abs  = abs($val);
        if ($abs >= 1e12) {
            return $sign . number_format($abs / 1e12, 1) . 'T';
        }
        if ($abs >= 1e8) {
            return $sign . number_format($abs / 1e9, 1) . 'M';
        }
        if ($abs >= 1e6) {
            return $sign . number_format($abs / 1e6, 0) . 'Jt';
        }
        if ($abs >= 1e3) {
            return $sign . number_format($abs / 1e3, 0) . 'K';
        }
        return $sign . number_format($abs, 0);
    };

    $fmtBsVal = static function (float $val): string {
        $abs = abs($val);
        if ($abs >= 1e12) {
            return number_format($abs / 1e12, $abs >= 1e13 ? 1 : 2) . 'T';
        }
        if ($abs >= 1e10) {
            return number_format($abs / 1e9, 1) . 'M';
        }
        if ($abs >= 1e9) {
            return number_format($abs / 1e9, 2) . 'M';
        }
        if ($abs >= 1e6) {
            return number_format($abs / 1e6, 0) . 'Jt';
        }
        if ($abs >= 1e3) {
            return number_format($abs / 1e3, 0) . 'K';
        }
        return number_format($abs, 0);
    };

    $fmtLotCompact = static function (float $lot): string {
        $abs = abs($lot);
        if ($abs >= 1e6) {
            return number_format($abs / 1e6, 2) . 'M';
        }
        if ($abs >= 1e4) {
            return number_format($abs / 1e3, 1) . 'K';
        }
        return number_format($abs, 0);
    };

    $nowWib = now('Asia/Jakarta')->format('d/m H:i');
    $msg  = "🕵️ <b>BROKER SUMMARY: {$res['symbol']}</b>\n";
    $msg .= "📅 {$periodStr} • {$invLabel}\n";
    $msg .= "📡 Stockbit • {$nowWib} WIB\n";
    $msg .= "━━━━━━━━━━━━━━━━━━━━\n\n";

    $bd = $res['bandar'] ?? null;
    if (!empty($bd) && is_array($bd)) {
        $msg .= "🏦 <b>BANDAR DETECTOR</b>\n";
        $rows = [
            'Top1' => $bd['top1'] ?? null,
            'Top3' => $bd['top3'] ?? null,
            'Top5' => $bd['top5'] ?? null,
            'Avg'  => $bd['avg'] ?? null,
        ];
        foreach ($rows as $label => $item) {
            if (!is_array($item)) {
                continue;
            }
            $rpStr  = $fmtBdIdr((float) ($item['amount'] ?? 0));
            $pct    = (float) ($item['percent'] ?? 0);
            $pctStr = ($pct >= 0 ? '+' : '') . number_format($pct, 1) . '%';
            $ad     = (string) ($item['accdist'] ?? 'Neutral');
            $msg .= sprintf("<code>%-4s %6s %6s</code> %s\n", $label, $rpStr, $pctStr, $badgeAd($ad));
        }

        $bAd         = (string) ($bd['broker_accdist'] ?? 'Neutral');
        $bDiff       = (int) ($bd['broker_diff'] ?? 0);
        $diffSign    = $bDiff > 0 ? "+{$bDiff}" : (string) $bDiff;
        $netVolFmt   = number_format((float) ($bd['net_vol'] ?? 0), 0);
        $netValFmt   = $fmtBsVal((float) ($bd['net_val'] ?? 0));
        $avgPriceFmt = number_format((float) ($bd['avg_price'] ?? 0), 0);

        $avgBuyTop5  = (float) ($res['avg_buy_top5'] ?? 0);
        $avgSellTop5 = (float) ($res['avg_sell_top5'] ?? 0);
        $top5AvgStr  = ($avgBuyTop5 > 0 || $avgSellTop5 > 0)
            ? 'Buy ' . number_format($avgBuyTop5, 0) . ' | Sell ' . number_format($avgSellTop5, 0)
            : '-';

        $msg .= "\n📊 <b>RINGKASAN TRANSAKSI</b>\n";
        $msg .= "• <b>Broker:</b> {$res['buyer_count']}B vs {$res['seller_count']}S ({$diffSign}) • " . $badgeAd($bAd) . "\n";
        $msg .= "• <b>Vol:</b> {$netVolFmt} lot • <b>Val:</b> {$netValFmt}\n";
        $msg .= "• <b>Avg:</b> Rp {$avgPriceFmt}\n";
        $msg .= "• <b>Top 5 Avg:</b> {$top5AvgStr}\n";
    } else {
        $statusRaw = (string) ($res['status'] ?? 'Neutral');
        $msg .= "🏦 <b>Status Bandar:</b> " . $badgeAd($statusRaw) . "\n";
        $msg .= "📊 <b>Partisipan:</b> {$res['buyer_count']} Buyer vs {$res['seller_count']} Seller\n";
    }

    $msg .= "\n🟢 <b>TOP 5 NET BUYER</b>\n";
    if (empty($res['top_buy'])) {
        $msg .= "   <i>Tidak ada data transaksi pada periode ini.</i>\n";
    } else {
        foreach ($res['top_buy'] as $idx => $b) {
            $no  = $idx + 1;
            $tag = (string) ($b['tag'] ?? 'L');
            $val = $fmtBsVal((float) $b['val']);
            $avg = number_format((float) $b['avg'], 0);
            $lot = $fmtLotCompact((float) $b['lot']);
            $msg .= sprintf("<code>%d.%-2s[%s] %6s</code> • %s @ %s\n", $no, (string) $b['code'], $tag, $val, $lot, $avg);
        }
    }

    $msg .= "\n🔴 <b>TOP 5 NET SELLER</b>\n";
    if (empty($res['top_sell'])) {
        $msg .= "   <i>Tidak ada data transaksi pada periode ini.</i>\n";
    } else {
        foreach ($res['top_sell'] as $idx => $s) {
            $no  = $idx + 1;
            $tag = (string) ($s['tag'] ?? 'L');
            $val = $fmtBsVal((float) $s['val']);
            $avg = number_format((float) $s['avg'], 0);
            $lot = $fmtLotCompact((float) $s['lot']);
            $msg .= sprintf("<code>%d.%-2s[%s] %6s</code> • %s @ %s\n", $no, (string) $s['code'], $tag, $val, $lot, $avg);
        }
    }

    $msg .= "\n<i>🏷 F: Asing • L: Lokal • G: BUMN</i>";

    if ($editMessage) {
        $bot->editMessageText($msg, parse_mode: \SergiX44\Nutgram\Telegram\Properties\ParseMode::HTML, reply_markup: $kb);
    } else {
        $bot->sendMessage($msg, parse_mode: \SergiX44\Nutgram\Telegram\Properties\ParseMode::HTML, reply_markup: $kb);
    }
};

$sendBrokerMenu = function (\SergiX44\Nutgram\Nutgram $bot, string $rawSym, bool $editMessage = false) {
    $sym = preg_replace('/JK$/', '', strtoupper(trim(preg_replace('/[^A-Za-z0-9]/', '', $rawSym))));
    if (strlen($sym) < 4) {
        $bot->sendMessage('❌ Kode saham tidak valid. Contoh: <code>/brokersum BBRI</code>', parse_mode: \SergiX44\Nutgram\Telegram\Properties\ParseMode::HTML);
        return;
    }

    $text = "🕵️ <b>MENU BROKER SUMMARY: {$sym}</b>\n"
          . "━━━━━━━━━━━━━━\n"
          . "Pilih periode cepat di bawah ini atau klik <b>📅 Pilih Tanggal (Kalender)</b> untuk menentukan rentang tanggal mulai dan akhir secara manual:";

    $kb = \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup::make()
        ->addRow(
            \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make(now('Asia/Jakarta')->isWeekend() ? '⚡ Terakhir (1D)' : '⚡ Hari Ini (1D)', callback_data: "bsrun:{$sym}:1D"),
            \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make('📆 7 Hari (1W)', callback_data: "bsrun:{$sym}:1W"),
            \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make('🗓 30 Hari (1M)', callback_data: "bsrun:{$sym}:1M")
        )
        ->addRow(
            \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make('📅 Pilih Tanggal (Kalender)', callback_data: "bscal:open:{$sym}")
        );

    if ($editMessage) {
        $bot->editMessageText($text, parse_mode: \SergiX44\Nutgram\Telegram\Properties\ParseMode::HTML, reply_markup: $kb);
    } else {
        $bot->sendMessage($text, parse_mode: \SergiX44\Nutgram\Telegram\Properties\ParseMode::HTML, reply_markup: $kb);
    }
};

// Command /brokersum, /broker, /bandar
// Tombol cepat Broksum dari /analisa -> buka /brokersum di pesan baru
$bot->onCallbackQueryData('abroksum:{symbol}', function (\SergiX44\Nutgram\Nutgram $bot, string $symbol) use ($sendBrokerMenu) {
    $bot->answerCallbackQuery(text: '⏳ Memuat Broksum ' . strtoupper(trim($symbol)) . '...');
    $sendBrokerMenu($bot, $symbol, false);
});

$bot->onCommand("brokersum {symbol}", function (\SergiX44\Nutgram\Nutgram $bot, string $symbol) use ($sendBrokerMenu) {
    $sendBrokerMenu($bot, $symbol, false);
});
$bot->onCommand("brokersum", function (\SergiX44\Nutgram\Nutgram $bot) {
    $bot->sendMessage("🕵️ <b>Format Broker Summary:</b>\nKetik <code>/brokersum KODE</code> (contoh: <code>/brokersum BBRI</code>)", parse_mode: \SergiX44\Nutgram\Telegram\Properties\ParseMode::HTML);
});
$bot->onCommand("broker {symbol}", function (\SergiX44\Nutgram\Nutgram $bot, string $symbol) use ($sendBrokerMenu) {
    $sendBrokerMenu($bot, $symbol, false);
});
$bot->onCommand("bandar {symbol}", function (\SergiX44\Nutgram\Nutgram $bot, string $symbol) use ($sendBrokerMenu) {
    $sendBrokerMenu($bot, $symbol, false);
});

// Callback: Kembali ke menu utama broker summary
$bot->onCallbackQueryData('bsmenu:{sym}', function (\SergiX44\Nutgram\Nutgram $bot, string $sym) use ($sendBrokerMenu) {
    $bot->answerCallbackQuery();
    $sendBrokerMenu($bot, $sym, true);
});

// Callback: Preset 1D / 1W / 1M
$bot->onCallbackQueryData('bsrun:{sym}:{preset}', function (\SergiX44\Nutgram\Nutgram $bot, string $sym, string $preset) use ($renderBrokerSummary) {
    $bot->answerCallbackQuery(text: "⏳ Memuat data {$sym} ({$preset})...");
    $toDt = now('Asia/Jakarta');
    while ($toDt->isWeekend()) {
        $toDt->subDay();
    }
    $to = $toDt->format('Y-m-d');
    $from = match (strtoupper($preset)) {
        '1W' => $toDt->copy()->subDays(7)->format('Y-m-d'),
        '1M' => $toDt->copy()->subDays(30)->format('Y-m-d'),
        default => $to,
    };
    $renderBrokerSummary($bot, $sym, $from, $to, false, 'ALL', strtoupper($preset));
});

// Callback: Preset 1D / 1W / 1M dengan filter investor (ALL / FOR / DOM)
$bot->onCallbackQueryData('bsrun:{sym}:{preset}:{inv}', function (\SergiX44\Nutgram\Nutgram $bot, string $sym, string $preset, string $inv) use ($renderBrokerSummary) {
    $bot->answerCallbackQuery(text: "⏳ Memuat {$sym} ({$preset} • {$inv})...");
    $toDt = now('Asia/Jakarta');
    while ($toDt->isWeekend()) {
        $toDt->subDay();
    }
    $to = $toDt->format('Y-m-d');
    $from = match (strtoupper($preset)) {
        '1W' => $toDt->copy()->subDays(7)->format('Y-m-d'),
        '1M' => $toDt->copy()->subDays(30)->format('Y-m-d'),
        default => $to,
    };
    $renderBrokerSummary($bot, $sym, $from, $to, false, $inv, strtoupper($preset));
});

// Callback: Ganti filter investor (All / Foreign / Domestic) pada rentang tanggal yang sama
$bot->onCallbackQueryData('bsinv:{sym}:{inv}:{fromYmd}:{toYmd}:{preset}', function (\SergiX44\Nutgram\Nutgram $bot, string $sym, string $inv, string $fromYmd, string $toYmd, string $preset) use ($renderBrokerSummary) {
    $label = match (strtoupper($inv)) {
        'FOR' => 'Foreign',
        'DOM' => 'Domestic',
        default => 'All',
    };
    $bot->answerCallbackQuery(text: "⏳ Filter {$label} untuk {$sym}...");
    $from = preg_replace('/^(\d{4})(\d{2})(\d{2})$/', '$1-$2-$3', $fromYmd);
    $to   = preg_replace('/^(\d{4})(\d{2})(\d{2})$/', '$1-$2-$3', $toYmd);
    $activePreset = strtoupper($preset) === 'CAL' ? '' : strtoupper($preset);
    $renderBrokerSummary($bot, $sym, $from, $to, false, $inv, $activePreset);
});

// Callback: Buka kalender bulan berjalan
// === BEGIN NON-RETAIL FLOW SCREENER ===
$parseNrDate = static function (string $raw): ?string {
    $s = trim($raw);
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m)) {
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? "{$m[1]}-{$m[2]}-{$m[3]}" : null;
    }
    if (preg_match('/^(\d{2})[-\/](\d{2})[-\/](\d{4})$/', $s, $m)) {
        return checkdate((int) $m[2], (int) $m[1], (int) $m[3]) ? "{$m[3]}-{$m[2]}-{$m[1]}" : null;
    }
    if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $s, $m)) {
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? "{$m[1]}-{$m[2]}-{$m[3]}" : null;
    }
    return null;
};

$renderNonRetailFlow = function (
    \SergiX44\Nutgram\Nutgram $bot,
    string $preset = '1D',
    float $minValM = 0.5,
    bool $editCallback = false,
    bool $forceRefresh = false,
    ?int $editMessageId = null,
    ?string $fixedFrom = null,
    ?string $fixedTo = null
) {
    $hasFixed   = ($fixedFrom !== null && $fixedTo !== null);
    $presetNorm = in_array(strtoupper($preset), ['1D', '1W', '1M', 'CAL'], true)
        ? strtoupper($preset)
        : ($hasFixed ? 'CAL' : '1D');

    $minKey = match (true) {
        $minValM >= 5.0 => '5',
        $minValM >= 2.0 => '2',
        default         => '0.5',
    };
    $minValClean = (float) $minKey;
    $minLbl = match ($minKey) {
        '5'     => '≥5M',
        '2'     => '≥2M',
        default => '≥500Jt',
    };

    if ($hasFixed) {
        $from = $fixedFrom;
        $to   = $fixedTo;
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
    } else {
        $toDt = now('Asia/Jakarta');
        while ($toDt->isWeekend()) {
            $toDt->subDay();
        }
        $to   = $toDt->format('Y-m-d');
        $from = match ($presetNorm) {
            '1W' => $toDt->copy()->subDays(7)->format('Y-m-d'),
            '1M' => $toDt->copy()->subDays(30)->format('Y-m-d'),
            default => $to,
        };
    }

    $screener = app(\App\Services\StockScreenerService::class);
    $res = $screener->scanNonRetailFlow($from, $to, $minValClean, 20, $forceRefresh);

    $effFrom     = (string) ($res['from'] ?? $from);
    $effTo       = (string) ($res['to'] ?? $to);
    $fromCompact = str_replace('-', '', $effFrom);
    $toCompact   = str_replace('-', '', $effTo);
    $calYm       = substr($toCompact, 0, 6);
    $presetTag   = in_array($presetNorm, ['1D', '1W', '1M'], true) ? $presetNorm : 'CAL';

    $btn1D  = $presetNorm === '1D'  ? '• 1D •' : '1D';
    $btn1W  = $presetNorm === '1W'  ? '• 1W •' : '1W';
    $btn1M  = $presetNorm === '1M'  ? '• 1M •' : '1M';
    $btnCal = $presetNorm === 'CAL' ? '• 📅 •' : '📅 Tgl';

    $btn05 = $minKey === '0.5' ? '•500Jt•' : '500Jt';
    $btn2  = $minKey === '2'   ? '•2M•'    : '2M';
    $btn5  = $minKey === '5'   ? '•5M•'    : '5M';

    // Baris 1 (ganti periode/tanggal) -> pesan baru (tidak menimpa hasil sebelumnya)
    // Baris 2 (filter likuiditas & refresh) -> terkunci pada periode pesan ini
    $kb = \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup::make()
        ->addRow(
            \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make($btn1D, callback_data: "nrrun:1D:{$minKey}:0"),
            \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make($btn1W, callback_data: "nrrun:1W:{$minKey}:0"),
            \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make($btn1M, callback_data: "nrrun:1M:{$minKey}:0"),
            \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make($btnCal, callback_data: "nrcal:open:{$minKey}:{$calYm}")
        )
        ->addRow(
            \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make($btn05, callback_data: "nrf:{$fromCompact}:{$toCompact}:{$presetTag}:0.5:0"),
            \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make($btn2, callback_data: "nrf:{$fromCompact}:{$toCompact}:{$presetTag}:2:0"),
            \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make($btn5, callback_data: "nrf:{$fromCompact}:{$toCompact}:{$presetTag}:5:0"),
            \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make('🔄', callback_data: "nrf:{$fromCompact}:{$toCompact}:{$presetTag}:{$minKey}:1")
        );

    $deliver = static function (string $text) use ($bot, $kb, $editCallback, $editMessageId): void {
        try {
            if ($editMessageId !== null) {
                $bot->editMessageText(
                    text: $text,
                    chat_id: $bot->chatId(),
                    message_id: $editMessageId,
                    parse_mode: \SergiX44\Nutgram\Telegram\Properties\ParseMode::HTML,
                    reply_markup: $kb
                );
            } elseif ($editCallback) {
                $bot->editMessageText(
                    text: $text,
                    parse_mode: \SergiX44\Nutgram\Telegram\Properties\ParseMode::HTML,
                    reply_markup: $kb
                );
            } else {
                $bot->sendMessage(
                    text: $text,
                    parse_mode: \SergiX44\Nutgram\Telegram\Properties\ParseMode::HTML,
                    reply_markup: $kb
                );
            }
        } catch (\Throwable $e) {
            if (!str_contains(strtolower($e->getMessage()), 'message is not modified')) {
                $bot->sendMessage(
                    text: $text,
                    parse_mode: \SergiX44\Nutgram\Telegram\Properties\ParseMode::HTML,
                    reply_markup: $kb
                );
            }
        }
    };

    if (isset($res['error'])) {
        $errMap = [
            'NO_TOKEN'      => '⚠️ Token Stockbit belum diatur. Gunakan <code>/settoken TOKEN_ANDA</code>.',
            'TOKEN_EXPIRED' => '🔴 Token Stockbit sudah expired. Perbarui via <code>/settoken TOKEN_BARU</code>.',
        ];
        $deliver($errMap[$res['error']] ?? '❌ Gagal memindai Non-Retail Flow.');
        return;
    }

    // Top 12 tombol analisa cepat ke /brokersum (susunan 4+4+2, pesan baru sesuai periode aktif)
    $top12 = array_slice($res['items'] ?? [], 0, 12);
    foreach (array_chunk($top12, 4) as $rowChunk) {
        $btns = [];
        foreach ($rowChunk as $it) {
            $s = (string) $it['symbol'];
            $btns[] = \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make(
                "🔍{$s}",
                callback_data: "nrbs:{$s}:{$fromCompact}:{$toCompact}:{$presetTag}"
            );
        }
        if (!empty($btns)) {
            $kb->addRow(...$btns);
        }
    }

    $fromDt = \Carbon\Carbon::parse($effFrom);
    $toDt   = \Carbon\Carbon::parse($effTo);
    if ($effFrom === $effTo) {
        $periodStr = $fromDt->format('d M Y');
    } elseif ($fromDt->format('Y-m') === $toDt->format('Y-m')) {
        $periodStr = $fromDt->format('d') . '–' . $toDt->format('d M Y');
    } elseif ($fromDt->year === $toDt->year) {
        $periodStr = $fromDt->format('d M') . '–' . $toDt->format('d M y');
    } else {
        $periodStr = $fromDt->format('d M y') . '–' . $toDt->format('d M y');
    }

    $fmtValCompact = static function (float $val): string {
        $abs = abs($val);
        if ($abs >= 1e12) {
            return number_format($abs / 1e12, 1) . 'T';
        }
        if ($abs >= 1e10) {
            return number_format($abs / 1e9, 0) . 'M';
        }
        if ($abs >= 1e9) {
            return number_format($abs / 1e9, 1) . 'M';
        }
        if ($abs >= 1e6) {
            return number_format($abs / 1e6, 0) . 'Jt';
        }
        return number_format($abs, 0);
    };

    $titleMode = $presetNorm === 'CAL' ? ($effFrom === $effTo ? 'CUSTOM 1D' : 'PERIODE') : $presetNorm;
    $nowWib = now('Asia/Jakarta')->format('d/m H:i');
    $msg  = "🧲 <b>NON-RETAIL FLOW • {$titleMode}</b>\n";
    $msg .= "📅 {$periodStr} • Min {$minLbl}\n";
    $msg .= "📡 Stockbit • {$res['scanned']} emiten ({$nowWib} WIB)\n";
    $msg .= "━━━━━━━━━━━━━━━━━━━━\n\n";

    if (empty($res['items'])) {
        $msg .= "<i>Tidak ada emiten lolos filter (Min {$minLbl}) pada periode ini.</i>\n";
    } else {
        $msg .= "<b>TOP " . count($res['items']) . " RITEL NET SELL</b> (dari {$res['matched']} lolos)\n";
        foreach ($res['items'] as $i => $it) {
            $no   = $i + 1;
            $sym  = (string) $it['symbol'];
            $pct  = (int) round((float) $it['r_pct']);
            $val  = $fmtValCompact((float) $it['val']);
            $sb   = "{$it['r_sell']}S/{$it['r_buy']}B";
            $tier = (string) ($it['tier'] ?? '🟡');
            $msg .= sprintf("<code>%2d.%-4s %+4d%%</code> %s • %s %s\n", $no, $sym, $pct, $sb, $val, $tier);
        }
    }

    if (!empty($res['rate_limited'])) {
        $msg .= "\n⚠️ <i>Sebagian scan berhenti lebih awal (rate limit API).</i>\n";
    }

    $msg .= "\n<i>🔥 Strong • 🟢 Solid • 🟡 Early</i>\n";
    $msg .= "<i>🏷 R12 • Cap ±20% • S/B ≥2% (smooth)</i>";

    $deliver($msg);
};

$renderNonRetailCalendar = function (
    \SergiX44\Nutgram\Nutgram $bot,
    string $minKey,
    string $ym,
    string $startYmd = '0',
    bool $editMessage = true
) {
    $todayWib = now('Asia/Jakarta')->startOfDay();
    if (!preg_match('/^\d{6}$/', $ym)) {
        $ym = $todayWib->format('Ym');
    }

    $calDt = \Carbon\Carbon::createFromFormat('Ym', $ym, 'Asia/Jakarta')->startOfMonth();
    $monthNames = [
        1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'Mei', 6 => 'Jun',
        7 => 'Jul', 8 => 'Agu', 9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des',
    ];
    $hdrMonth = ($monthNames[(int) $calDt->month] ?? $calDt->format('M')) . ' ' . $calDt->year;

    $prevYm  = $calDt->copy()->subMonthNoOverflow()->format('Ym');
    $nextYm  = $calDt->copy()->addMonthNoOverflow()->format('Ym');
    $canNext = ($nextYm <= $todayWib->format('Ym'));

    $kb = \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup::make();
    $kb->addRow(
        \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make('◀️', callback_data: "nrcal:nav:{$minKey}:{$prevYm}:{$startYmd}"),
        \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make("📅 {$hdrMonth}", callback_data: 'nrcal:noop:hdr'),
        \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make($canNext ? '▶️' : ' ', callback_data: $canNext ? "nrcal:nav:{$minKey}:{$nextYm}:{$startYmd}" : 'nrcal:noop:fut')
    );

    $kb->addRow(
        \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make('Sn', callback_data: 'nrcal:noop:dow'),
        \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make('Sl', callback_data: 'nrcal:noop:dow'),
        \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make('Rb', callback_data: 'nrcal:noop:dow'),
        \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make('Km', callback_data: 'nrcal:noop:dow'),
        \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make('Jm', callback_data: 'nrcal:noop:dow'),
        \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make('Sb', callback_data: 'nrcal:noop:wknd'),
        \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make('Mg', callback_data: 'nrcal:noop:wknd')
    );

    $firstDow    = (int) $calDt->dayOfWeekIso;
    $daysInMonth = (int) $calDt->daysInMonth;
    $row         = [];

    for ($pad = 1; $pad < $firstDow; $pad++) {
        $row[] = \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make(' ', callback_data: 'nrcal:noop:pad');
    }

    for ($d = 1; $d <= $daysInMonth; $d++) {
        $dObj = $calDt->copy()->day($d);
        $dYmd = $dObj->format('Ymd');

        if ($dObj->isWeekend()) {
            $row[] = \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make('·', callback_data: 'nrcal:noop:wknd');
        } elseif ($dObj->gt($todayWib)) {
            $row[] = \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make('·', callback_data: 'nrcal:noop:fut');
        } else {
            $lbl = ($startYmd === $dYmd) ? "[{$d}]" : (string) $d;
            $row[] = \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make($lbl, callback_data: "nrcal:pick:{$minKey}:{$dYmd}:{$startYmd}");
        }

        if (count($row) === 7) {
            $kb->addRow(...$row);
            $row = [];
        }
    }

    if (!empty($row)) {
        while (count($row) < 7) {
            $row[] = \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make(' ', callback_data: 'nrcal:noop:pad');
        }
        $kb->addRow(...$row);
    }

    $text = "📅 <b>KALENDER NON-RETAIL FLOW</b>\n";
    if ($startYmd !== '0' && strlen($startYmd) === 8 && ctype_digit($startYmd)) {
        $startDt  = \Carbon\Carbon::createFromFormat('Ymd', $startYmd, 'Asia/Jakarta');
        $startLbl = $startDt->format('d M Y');
        $shortLbl = $startDt->format('d M');

        $kb->addRow(
            \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make("✅ Scan {$shortLbl} (1 Hari)", callback_data: "nrcal:go:{$minKey}:{$startYmd}:{$startYmd}")
        );
        $kb->addRow(
            \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make('↩️ Reset', callback_data: "nrcal:nav:{$minKey}:{$ym}:0"),
            \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make('❌ Tutup', callback_data: 'nrcal:close')
        );

        $text .= "🔹 Awal: <b>{$startLbl}</b>\n";
        $text .= "👉 Tap <b>tanggal akhir</b> untuk scan periode, atau tap <b>✅ Scan {$shortLbl}</b> untuk 1 hari.";
    } else {
        $kb->addRow(
            \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make('❌ Tutup', callback_data: 'nrcal:close')
        );
        $text .= "👉 Tap <b>tanggal awal</b>, lalu <b>tanggal akhir</b> (tap 2x tanggal yg sama untuk 1 hari).";
    }

    if ($editMessage) {
        try {
            $bot->editMessageText(
                text: $text,
                parse_mode: \SergiX44\Nutgram\Telegram\Properties\ParseMode::HTML,
                reply_markup: $kb
            );
        } catch (\Throwable $e) {
            if (!str_contains(strtolower($e->getMessage()), 'message is not modified')) {
                $bot->sendMessage(
                    text: $text,
                    parse_mode: \SergiX44\Nutgram\Telegram\Properties\ParseMode::HTML,
                    reply_markup: $kb
                );
            }
        }
    } else {
        $bot->sendMessage(
            text: $text,
            parse_mode: \SergiX44\Nutgram\Telegram\Properties\ParseMode::HTML,
            reply_markup: $kb
        );
    }
};

$runNonRetailCmd = function (\SergiX44\Nutgram\Nutgram $bot, string $arg1 = '1D', string $arg2 = '0.5') use ($renderNonRetailFlow, $parseNrDate) {
    $d1 = $parseNrDate($arg1);
    $d2 = $parseNrDate($arg2);

    if ($d1 !== null) {
        $from = $d1;
        $to   = $d2 ?? $d1;
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        $minVal = ($d2 === null && is_numeric($arg2)) ? (float) $arg2 : 0.5;
        $lbl    = $from === $to
            ? \Carbon\Carbon::parse($from)->format('d M Y')
            : \Carbon\Carbon::parse($from)->format('d M') . '–' . \Carbon\Carbon::parse($to)->format('d M Y');

        $loading = $bot->sendMessage(
            "⏳ <i>Scanning ~340 emiten IDX (Non-Retail Flow {$lbl})...</i>",
            parse_mode: \SergiX44\Nutgram\Telegram\Properties\ParseMode::HTML
        );
        $renderNonRetailFlow($bot, 'CAL', $minVal, false, false, $loading?->message_id, $from, $to);
        return;
    }

    $presetNorm = in_array(strtoupper(trim($arg1)), ['1D', '1W', '1M'], true) ? strtoupper(trim($arg1)) : '1D';
    $minVal     = is_numeric($arg2) ? (float) $arg2 : 0.5;
    $loading    = $bot->sendMessage(
        "⏳ <i>Scanning ~340 emiten IDX (Non-Retail Flow {$presetNorm})...</i>",
        parse_mode: \SergiX44\Nutgram\Telegram\Properties\ParseMode::HTML
    );
    $renderNonRetailFlow($bot, $presetNorm, $minVal, false, false, $loading?->message_id);
};

$bot->onCommand('nonritel', function (\SergiX44\Nutgram\Nutgram $bot) use ($runNonRetailCmd) {
    $runNonRetailCmd($bot, '1D', '0.5');
});

$bot->onCommand('nonritel {arg1}', function (\SergiX44\Nutgram\Nutgram $bot, string $arg1) use ($runNonRetailCmd) {
    $runNonRetailCmd($bot, $arg1, '0.5');
});

$bot->onCommand('nonritel {arg1} {arg2}', function (\SergiX44\Nutgram\Nutgram $bot, string $arg1, string $arg2) use ($runNonRetailCmd) {
    $runNonRetailCmd($bot, $arg1, $arg2);
});

// Ganti preset 1D / 1W / 1M -> pesan baru (tidak menimpa hasil sebelumnya)
$bot->onCallbackQueryData('nrrun:{preset}:{minVal}:{refresh}', function (\SergiX44\Nutgram\Nutgram $bot, string $preset, string $minVal, string $refresh) use ($renderNonRetailFlow) {
    $force = ($refresh === '1');
    $bot->answerCallbackQuery(text: $force ? "🔄 Refresh {$preset}..." : "⏳ Scanning {$preset}...");
    $loading = $bot->sendMessage(
        "⏳ <i>Scanning ~340 emiten IDX (Non-Retail Flow {$preset})...</i>",
        parse_mode: \SergiX44\Nutgram\Telegram\Properties\ParseMode::HTML
    );
    $renderNonRetailFlow($bot, $preset, (float) $minVal, false, $force, $loading?->message_id);
});

// Filter likuiditas (500Jt/2M/5M) & refresh -> edit pesan ini sesuai periode yang sudah terkunci
$bot->onCallbackQueryData('nrf:{fromYmd}:{toYmd}:{presetTag}:{minVal}:{refresh}', function (\SergiX44\Nutgram\Nutgram $bot, string $fromYmd, string $toYmd, string $presetTag, string $minVal, string $refresh) use ($renderNonRetailFlow, $parseNrDate) {
    $from  = $parseNrDate($fromYmd);
    $to    = $parseNrDate($toYmd);
    $force = ($refresh === '1');
    if ($from === null || $to === null) {
        $bot->answerCallbackQuery(text: '❌ Tanggal tidak valid.');
        return;
    }
    $minLbl = match ($minVal) {
        '5'     => '≥5M',
        '2'     => '≥2M',
        default => '≥500Jt',
    };
    $bot->answerCallbackQuery(text: $force ? '🔄 Refresh periode ini...' : "⏳ Memuat filter {$minLbl}...");
    // Filter nominal (500Jt/2M/5M) kirim pesan baru; 🔄 refresh di tempat
    $renderNonRetailFlow($bot, $presetTag, (float) $minVal, $force, $force, null, $from, $to);
});

// Tombol analisa cepat Top 12 -> buka /brokersum di pesan baru sesuai periode aktif
$bot->onCallbackQueryData('nrbs:{sym}:{fromYmd}:{toYmd}:{presetTag}', function (\SergiX44\Nutgram\Nutgram $bot, string $sym, string $fromYmd, string $toYmd, string $presetTag) use ($renderBrokerSummary, $parseNrDate) {
    $from = $parseNrDate($fromYmd);
    $to   = $parseNrDate($toYmd);
    if ($from === null || $to === null) {
        $bot->answerCallbackQuery(text: '❌ Tanggal tidak valid.');
        return;
    }
    $bot->answerCallbackQuery(text: "🔍 Memuat Broker Summary {$sym}...");
    $activePreset = in_array($presetTag, ['1D', '1W', '1M'], true) ? $presetTag : '';
    $renderBrokerSummary($bot, strtoupper($sym), $from, $to, false, 'ALL', $activePreset);
});

// Buka kalender sebagai pesan baru (langsung mode Periode Dari -> Sampai)
$bot->onCallbackQueryData('nrcal:open:{minKey}:{ym}', function (\SergiX44\Nutgram\Nutgram $bot, string $minKey, string $ym) use ($renderNonRetailCalendar) {
    $bot->answerCallbackQuery(text: '📅 Pilih tanggal awal & akhir');
    $renderNonRetailCalendar($bot, $minKey, $ym, '0', false);
});

$bot->onCallbackQueryData('nrcal:nav:{minKey}:{ym}:{startYmd}', function (\SergiX44\Nutgram\Nutgram $bot, string $minKey, string $ym, string $startYmd) use ($renderNonRetailCalendar) {
    $bot->answerCallbackQuery();
    $renderNonRetailCalendar($bot, $minKey, $ym, $startYmd, true);
});

$bot->onCallbackQueryData('nrcal:pick:{minKey}:{ymd}:{startYmd}', function (\SergiX44\Nutgram\Nutgram $bot, string $minKey, string $ymd, string $startYmd) use ($renderNonRetailCalendar, $renderNonRetailFlow, $parseNrDate) {
    $picked = $parseNrDate($ymd);
    if ($picked === null) {
        $bot->answerCallbackQuery(text: '❌ Tanggal tidak valid.');
        return;
    }

    // Klik pertama -> tandai tanggal awal
    if ($startYmd === '0') {
        $lbl = \Carbon\Carbon::parse($picked)->format('d M Y');
        $bot->answerCallbackQuery(text: "Awal: {$lbl}. Pilih tanggal akhir.");
        $renderNonRetailCalendar($bot, $minKey, substr($ymd, 0, 6), $ymd, true);
        return;
    }

    // Klik kedua -> jalankan scan periode (kalau sama, otomatis 1 hari)
    $startIso = $parseNrDate($startYmd) ?? $picked;
    $from     = min($startIso, $picked);
    $to       = max($startIso, $picked);
    $lbl      = $from === $to
        ? \Carbon\Carbon::parse($from)->format('d M Y')
        : \Carbon\Carbon::parse($from)->format('d M') . '–' . \Carbon\Carbon::parse($to)->format('d M Y');

    $bot->answerCallbackQuery(text: "⏳ Scanning {$lbl}...");
    try {
        $bot->editMessageText(
            text: "⏳ <i>Scanning ~340 emiten IDX (Non-Retail Flow {$lbl})...</i>",
            parse_mode: \SergiX44\Nutgram\Telegram\Properties\ParseMode::HTML
        );
    } catch (\Throwable $e) {
    }
    $renderNonRetailFlow($bot, 'CAL', (float) $minKey, true, false, null, $from, $to);
});

$bot->onCallbackQueryData('nrcal:go:{minKey}:{fromYmd}:{toYmd}', function (\SergiX44\Nutgram\Nutgram $bot, string $minKey, string $fromYmd, string $toYmd) use ($renderNonRetailFlow, $parseNrDate) {
    $from = $parseNrDate($fromYmd);
    $to   = $parseNrDate($toYmd);
    if ($from === null || $to === null) {
        $bot->answerCallbackQuery(text: '❌ Tanggal tidak valid.');
        return;
    }
    if ($from > $to) {
        [$from, $to] = [$to, $from];
    }
    $lbl = $from === $to
        ? \Carbon\Carbon::parse($from)->format('d M Y')
        : \Carbon\Carbon::parse($from)->format('d M') . '–' . \Carbon\Carbon::parse($to)->format('d M Y');

    $bot->answerCallbackQuery(text: "⏳ Scanning {$lbl}...");
    try {
        $bot->editMessageText(
            text: "⏳ <i>Scanning ~340 emiten IDX (Non-Retail Flow {$lbl})...</i>",
            parse_mode: \SergiX44\Nutgram\Telegram\Properties\ParseMode::HTML
        );
    } catch (\Throwable $e) {
    }
    $renderNonRetailFlow($bot, 'CAL', (float) $minKey, true, false, null, $from, $to);
});

$bot->onCallbackQueryData('nrcal:close', function (\SergiX44\Nutgram\Nutgram $bot) {
    $bot->answerCallbackQuery(text: 'Kalender ditutup');
    try {
        $bot->deleteMessage($bot->chatId(), $bot->messageId());
    } catch (\Throwable $e) {
        try {
            $bot->editMessageText('📅 <i>Kalender ditutup.</i>', parse_mode: \SergiX44\Nutgram\Telegram\Properties\ParseMode::HTML);
        } catch (\Throwable $ignored) {
        }
    }
});

$bot->onCallbackQueryData('nrcal:noop:{reason}', function (\SergiX44\Nutgram\Nutgram $bot, string $reason) {
    $msg = match ($reason) {
        'wknd' => 'Bursa libur (akhir pekan).',
        'fut'  => 'Tanggal belum tersedia.',
        default => null,
    };
    $bot->answerCallbackQuery(text: $msg);
});
// === END NON-RETAIL FLOW SCREENER ===
$bot->onCallbackQueryData('bscal:open:{sym}', function (\SergiX44\Nutgram\Nutgram $bot, string $sym) use ($buildBrokerCalendar) {
    $bot->answerCallbackQuery();
    $userId = $bot->userId() ?? 0;
    \Illuminate\Support\Facades\Cache::forget("bs_pick_{$userId}_{$sym}");

    $now = now('Asia/Jakarta');
    $kb = $buildBrokerCalendar($sym, $now->year, $now->month, null);
    $bot->sendMessage(
        "📅 <b>PILIH TANGGAL BROKER SUMMARY: {$sym}</b>\n"
        . "━━━━━━━━━━━━━━\n"
        . "1️⃣ Klik <b>Tanggal Mulai (From)</b> pada kalender di bawah.\n"
        . "2️⃣ Lalu klik <b>Tanggal Akhir (To)</b> (klik tanggal yang sama 2x untuk 1 hari).",
        parse_mode: \SergiX44\Nutgram\Telegram\Properties\ParseMode::HTML,
        reply_markup: $kb
    );
});

// Callback: Navigasi bulan kalender (prev/next)
$bot->onCallbackQueryData('bscal:nav:{sym}:{year}:{month}', function (\SergiX44\Nutgram\Nutgram $bot, string $sym, string $year, string $month) use ($buildBrokerCalendar) {
    $bot->answerCallbackQuery();
    $userId = $bot->userId() ?? 0;
    $startDate = \Illuminate\Support\Facades\Cache::get("bs_pick_{$userId}_{$sym}");

    $kb = $buildBrokerCalendar($sym, (int) $year, (int) $month, $startDate);
    $statusInfo = $startDate
        ? "✅ Tanggal Mulai: <b>{$startDate}</b>\n2️⃣ Sekarang pilih <b>Tanggal Akhir (To)</b>:"
        : "1️⃣ Klik <b>Tanggal Mulai (From)</b> pada kalender di bawah:";

    $bot->editMessageText(
        "📅 <b>PILIH TANGGAL BROKER SUMMARY: {$sym}</b>\n"
        . "━━━━━━━━━━━━━━\n"
        . $statusInfo,
        parse_mode: \SergiX44\Nutgram\Telegram\Properties\ParseMode::HTML,
        reply_markup: $kb
    );
});

// Callback: Reset pilihan tanggal
$bot->onCallbackQueryData('bscal:reset:{sym}:{year}:{month}', function (\SergiX44\Nutgram\Nutgram $bot, string $sym, string $year, string $month) use ($buildBrokerCalendar) {
    $bot->answerCallbackQuery(text: '🔄 Pilihan tanggal di-reset');
    $userId = $bot->userId() ?? 0;
    \Illuminate\Support\Facades\Cache::forget("bs_pick_{$userId}_{$sym}");

    $kb = $buildBrokerCalendar($sym, (int) $year, (int) $month, null);
    $bot->editMessageText(
        "📅 <b>PILIH TANGGAL BROKER SUMMARY: {$sym}</b>\n"
        . "━━━━━━━━━━━━━━\n"
        . "1️⃣ Klik <b>Tanggal Mulai (From)</b> pada kalender di bawah.",
        parse_mode: \SergiX44\Nutgram\Telegram\Properties\ParseMode::HTML,
        reply_markup: $kb
    );
});

// Callback: Klik tanggal pada kalender (Klik 1 = Start Date, Klik 2 = End Date & Eksekusi)
$bot->onCallbackQueryData('bscal:pick:{sym}:{date}', function (\SergiX44\Nutgram\Nutgram $bot, string $sym, string $date) use ($buildBrokerCalendar, $renderBrokerSummary) {
    $userId = $bot->userId() ?? 0;
    $cacheKey = "bs_pick_{$userId}_{$sym}";
    $startDate = \Illuminate\Support\Facades\Cache::get($cacheKey);

    if (!$startDate) {
        // Langkah 1: Simpan Start Date
        \Illuminate\Support\Facades\Cache::put($cacheKey, $date, 600);
        $bot->answerCallbackQuery(text: "📌 Mulai: {$date}. Pilih tanggal akhir.");

        $dt = \Carbon\Carbon::parse($date);
        $kb = $buildBrokerCalendar($sym, $dt->year, $dt->month, $date);
        $bot->editMessageText(
            "📅 <b>PILIH TANGGAL BROKER SUMMARY: {$sym}</b>\n"
            . "━━━━━━━━━━━━━━\n"
            . "✅ <b>Tanggal Mulai:</b> <code>{$date}</code>\n"
            . "2️⃣ Sekarang klik <b>Tanggal Akhir (To)</b> pada kalender (atau klik <code>[{$dt->day}]</code> lagi untuk 1 hari saja):",
            parse_mode: \SergiX44\Nutgram\Telegram\Properties\ParseMode::HTML,
            reply_markup: $kb
        );
    } else {
        // Langkah 2: End Date terpilih -> Eksekusi Broker Summary
        \Illuminate\Support\Facades\Cache::forget($cacheKey);
        $fromDate = $startDate;
        $toDate   = $date;
        if ($fromDate > $toDate) {
            [$fromDate, $toDate] = [$toDate, $fromDate];
        }

        $bot->answerCallbackQuery(text: "⏳ Mengambil data {$fromDate} s/d {$toDate}...");
        $renderBrokerSummary($bot, $sym, $fromDate, $toDate, true);
    }
});

// Callback dummy untuk label header kalender
$bot->onCallbackQueryData('bscal:noop', function (\SergiX44\Nutgram\Nutgram $bot) {
    $bot->answerCallbackQuery();
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

    $probLines = "";
    if (isset($forecast['upside_probability'])) {
        $upProb = number_format((float) $forecast['upside_probability'], 1);
        $probIcon = $forecast['upside_probability'] >= 60 ? '🟢' : ($forecast['upside_probability'] <= 40 ? '🔴' : '🟡');
        $probLines .= "🎲 Upside Probability (5D): {$probIcon} <b>{$upProb}%</b>\n";
    }
    if (isset($forecast['volatility_amplification'])) {
        $volProb = number_format((float) $forecast['volatility_amplification'], 1);
        $probLines .= "⚡ Volatility Amplification: <b>{$volProb}%</b>\n";
    }

    $msg = "🤖 <b>KRONOS AI FORECAST: {$cleanSym} (5D)</b>\n"
         . "━━━━━━━━━━━━━━\n"
         . "💰 Harga Terakhir: <b>Rp {$lastFmt}</b>\n"
         . "🎯 Proyeksi H+5: <b>Rp {$targetFmt} ({$chgSign}{$chg}%)</b>\n"
         . "🧭 Arah Bias AI: <b>{$biasIcon}</b>\n"
         . $probLines
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
    $defaultRisk = \App\Models\UserRiskProfile::maxRiskFor($bot->chatId());
    $cleanRisk = isset($parts[1]) ? (float) preg_replace('/[^0-9]/', '', $parts[1]) : $defaultRisk;
    $sendAnalysisChart($bot, $sym, $service, $cleanRisk > 0 ? $cleanRisk : $defaultRisk);
});

$bot->onCallbackQueryData('chart:{ticker}', function (Nutgram $bot, string $ticker, StockAnalysisService $service) use ($sendAnalysisChart) {
    $bot->answerCallbackQuery(text: "Memuat chart {$ticker}...");
    $sendAnalysisChart($bot, $ticker, $service, \App\Models\UserRiskProfile::maxRiskFor($bot->chatId()));
});

$bot->onCallbackQueryData('analisa:{ticker}', function (Nutgram $bot, string $ticker, StockAnalysisService $service) use ($sendAnalysisChart) {
    $bot->answerCallbackQuery(text: "Memuat chart {$ticker}...");
    $sendAnalysisChart($bot, $ticker, $service, \App\Models\UserRiskProfile::maxRiskFor($bot->chatId()));
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
    $rows = Watchlist::where('telegram_id', $userId)->orderBy('symbol')->get();

    if ($rows->isEmpty()) {
        $bot->sendMessage("📭 Watchlist Anda masih kosong.\nKetik <code>/pantau BBCA</code> atau klik tombol <b>🔔 Pantau</b> pada hasil <code>/analisa</code>.", parse_mode: ParseMode::HTML);
        return;
    }

    $stocks = [];
    $lines  = [];
    foreach ($rows as $row) {
        $stocks[] = ['symbol' => $row->symbol];
        $mode = $row->alert_mode ?: 'all';
        $bow  = $row->target_bow ? number_format((float) $row->target_bow, 0) : 'Auto S1';
        $bob  = $row->target_bob ? number_format((float) $row->target_bob, 0) : 'Auto R1';
        $sl   = $row->target_sl  ? number_format((float) $row->target_sl, 0)  : 'Auto SL';
        $vol  = number_format((float) ($row->min_vol_ratio ?: 1.2), 1);

        $desc = match ($mode) {
            'bow'   => "🟢 BoW ≤ <b>{$bow}</b> | SL {$sl}",
            'bob'   => "🚀 BoB ≥ <b>{$bob}</b> (Vol ≥ {$vol}x) | SL {$sl}",
            'both'  => "⚡ BoW ≤ <b>{$bow}</b> / BoB ≥ <b>{$bob}</b> | SL {$sl}",
            default => "🔔 Auto (R1/S1/SL/Vol)",
        };
        $lines[] = "• <b>{$row->symbol}</b> — {$desc}";
    }

    $bot->sendMessage(
        "📋 <b>SMART WATCHLIST PRIBADI (" . count($rows) . "/15):</b>\n\n" .
        implode("\n", $lines) . "\n\n" .
        "<i>Ubah mode via tombol 🔔 Pantau di chart, atau hapus dengan</i> <code>/unpantau KODE</code>:",
        parse_mode: ParseMode::HTML,
        reply_markup: $buildTickerKeyboard($stocks)
    );
});

$bot->onCallbackQueryData('quick:watchlist', function (Nutgram $bot) use ($buildTickerKeyboard) {
    $bot->answerCallbackQuery(text: "📋 Memuat Smart Watchlist...");
    $userId = $bot->userId();
    $rows = Watchlist::where('telegram_id', $userId)->orderBy('symbol')->get();

    if ($rows->isEmpty()) {
        $bot->sendMessage("📭 Watchlist Anda masih kosong.\nKetik <code>/pantau BBCA</code> atau klik tombol <b>🔔 Pantau</b> pada hasil <code>/analisa</code>.", parse_mode: ParseMode::HTML);
        return;
    }

    $stocks = [];
    $lines  = [];
    foreach ($rows as $row) {
        $stocks[] = ['symbol' => $row->symbol];
        $mode = $row->alert_mode ?: 'all';
        $bow  = $row->target_bow ? number_format((float) $row->target_bow, 0) : 'Auto S1';
        $bob  = $row->target_bob ? number_format((float) $row->target_bob, 0) : 'Auto R1';
        $sl   = $row->target_sl  ? number_format((float) $row->target_sl, 0)  : 'Auto SL';
        $vol  = number_format((float) ($row->min_vol_ratio ?: 1.2), 1);

        $desc = match ($mode) {
            'bow'   => "🟢 BoW ≤ <b>{$bow}</b> | SL {$sl}",
            'bob'   => "🚀 BoB ≥ <b>{$bob}</b> (Vol ≥ {$vol}x) | SL {$sl}",
            'both'  => "⚡ BoW ≤ <b>{$bow}</b> / BoB ≥ <b>{$bob}</b> | SL {$sl}",
            default => "🔔 Auto (R1/S1/SL/Vol)",
        };
        $lines[] = "• <b>{$row->symbol}</b> — {$desc}";
    }

    $bot->sendMessage(
        "📋 <b>SMART WATCHLIST PRIBADI (" . count($rows) . "/15):</b>\n\n" .
        implode("\n", $lines) . "\n\n" .
        "<i>Ubah mode via tombol 🔔 Pantau di chart, atau hapus dengan</i> <code>/unpantau KODE</code>:",
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
        // Market & Sektor
        new \SergiX44\Nutgram\Telegram\Types\Command\BotCommand('brief', 'Morning Market Brief & IHSG Macro Radar'),
        new \SergiX44\Nutgram\Telegram\Types\Command\BotCommand('sesi1', 'Market Report Sesi 1 (Gainer, Asing, Rebound, Vol)'),
        new \SergiX44\Nutgram\Telegram\Types\Command\BotCommand('sektor', 'Rotasi & Momentum 8 Sektor BEI'),
        new \SergiX44\Nutgram\Telegram\Types\Command\BotCommand('radar', 'Intraday Sweep & Unusual Vol (15m)'),

        // Teknikal, Trading Plan & AI
        new \SergiX44\Nutgram\Telegram\Types\Command\BotCommand('analisa', 'Analisa teknikal, chart & trading plan'),
        new \SergiX44\Nutgram\Telegram\Types\Command\BotCommand('setrisk', 'Atur Modal & Max Risk default per trade'),
        new \SergiX44\Nutgram\Telegram\Types\Command\BotCommand('mtf', 'Multi-Timeframe Confluence (1H/1D/1W)'),
        new \SergiX44\Nutgram\Telegram\Types\Command\BotCommand('forecast', 'Proyeksi harga 5 hari ke depan (Kronos AI)'),

        // Bandarmologi & Flow
        new \SergiX44\Nutgram\Telegram\Types\Command\BotCommand('brokersum', 'Broker Summary & Bandar Detector Real-Time'),
        new \SergiX44\Nutgram\Telegram\Types\Command\BotCommand('nonritel', 'Screener Non-Retail Flow (Ritel Net Sell)'),
        new \SergiX44\Nutgram\Telegram\Types\Command\BotCommand('topritel', 'Screener Top Retail Jualan (Stockbit)'),
        new \SergiX44\Nutgram\Telegram\Types\Command\BotCommand('akumulasi', 'Deteksi saham akumulasi bandar (VSA/OBV)'),
        new \SergiX44\Nutgram\Telegram\Types\Command\BotCommand('volume', 'Deteksi lonjakan volume >= 1.5x'),

        // Screener Fundamental
        new \SergiX44\Nutgram\Telegram\Types\Command\BotCommand('screener', 'Screener fundamental (PER, PBV, DER)'),
        new \SergiX44\Nutgram\Telegram\Types\Command\BotCommand('dividen', 'Screener saham dividen yield tinggi'),

        // Valuasi, Komparasi & Audit
        new \SergiX44\Nutgram\Telegram\Types\Command\BotCommand('valuasi', 'Nilai wajar intrinsik (Graham, EPV, DCF) & MoS'),
        new \SergiX44\Nutgram\Telegram\Types\Command\BotCommand('banding', 'Komparasi head-to-head emiten satu sektor'),
        new \SergiX44\Nutgram\Telegram\Types\Command\BotCommand('audit', 'Audit kualitas laba & Piotroski F-Score'),

        // Watchlist & Pengaturan
        new \SergiX44\Nutgram\Telegram\Types\Command\BotCommand('watchlist', 'Lihat daftar saham yang dipantau'),
        new \SergiX44\Nutgram\Telegram\Types\Command\BotCommand('pantau', 'Tambah saham ke watchlist alert'),
        new \SergiX44\Nutgram\Telegram\Types\Command\BotCommand('unpantau', 'Hapus saham dari watchlist'),
        new \SergiX44\Nutgram\Telegram\Types\Command\BotCommand('settoken', 'Update Bearer Token Stockbit'),
        new \SergiX44\Nutgram\Telegram\Types\Command\BotCommand('help', 'Daftar lengkap perintah Easy Cuan'),
    ];
    $bot->deleteMyCommands();
    $bot->setMyCommands($commands);
    $bot->setMyCommands($commands, scope: new \SergiX44\Nutgram\Telegram\Types\Command\BotCommandScopeAllPrivateChats());
    $bot->sendMessage("✅ Menu perintah (/) berhasil diperbarui (" . count($commands) . " perintah)!");
});


$bot->onCallbackQueryData('tf:{ticker}:{tf}:{risk}', function (Nutgram $bot, string $ticker, string $tf, string $risk) use ($sendAnalysisChart) {
    $service  = app(StockAnalysisService::class);
    $tf       = strtoupper(trim($tf));
    $cleanSym = strtoupper(trim($ticker));
    $maxRisk  = (float) $risk > 0 ? (float) $risk : \App\Models\UserRiskProfile::maxRiskFor($bot->chatId());

    $bot->answerCallbackQuery(text: "⏳ Memuat analisis {$cleanSym} [{$tf}]...");
    $sendAnalysisChart($bot, $cleanSym, $service, $maxRisk, $tf, false);
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

        $bankTickers = ['BBCA', 'BBRI', 'BMRI', 'BBNI', 'BRIS', 'BNGA', 'BDMN', 'BNII', 'NISP', 'BBTN', 'BJBR', 'BJTM', 'BTPS', 'ARTO', 'BBYB', 'AGRO', 'MEGA', 'PNBN', 'BTPN'];

        $isHealthy = function (array $row): bool {
            $loss = ($row['roe'] !== null && $row['roe'] < 0) || ($row['npm'] !== null && $row['npm'] < 0) || $row['per'] === null || $row['per'] <= 0;
            $deepDrop = ($row['earn_growth'] !== null && $row['earn_growth'] < -25);
            return !$loss && !$deepDrop;
        };

        $validPers = array_column(array_filter($items, fn($r) => $isHealthy($r) && $r['per'] !== null && $r['per'] > 0), 'per');
        $bestPer = (count($validPers) >= 2 && min($validPers) <= 15) ? min($validPers) : null;

        $validPbvs = array_column(array_filter($items, fn($r) => $isHealthy($r) && $r['pbv'] !== null && $r['pbv'] > 0), 'pbv');
        $bestPbv = (count($validPbvs) >= 2 && min($validPbvs) <= 2.5) ? min($validPbvs) : null;

        $validRoes = array_column(array_filter($items, fn($r) => $r['roe'] !== null && $r['roe'] > 0), 'roe');
        $bestRoe = count($validRoes) >= 2 ? max($validRoes) : null;

        $validNpms = array_column(array_filter($items, fn($r) => $r['npm'] !== null && $r['npm'] > 0), 'npm');
        $bestNpm = count($validNpms) >= 2 ? max($validNpms) : null;

        $validDivs = array_column(array_filter($items, fn($r) => $isHealthy($r) && $r['dividend_yield'] !== null && $r['dividend_yield'] > 0), 'dividend_yield');
        $bestDiv = count($validDivs) >= 2 ? max($validDivs) : null;

        $validEarns = array_column(array_filter($items, fn($r) => $r['earn_growth'] !== null && $r['earn_growth'] > 0), 'earn_growth');
        $bestEarn = count($validEarns) >= 2 ? max($validEarns) : null;

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
            $isBank = in_array($sym, $bankTickers, true);

            $isLoss = (($s['roe'] !== null && $s['roe'] < 0) || ($s['npm'] !== null && $s['npm'] < 0) || $s['per'] === null || $s['per'] <= 0);
            $isDeepDrop = ($s['earn_growth'] !== null && $s['earn_growth'] < -25);
            $isTrap = $isLoss || $isDeepDrop;

            // PER
            if ($s['per'] !== null && $s['per'] > 0) {
                $perBadge = (!$isTrap && $s['per'] === $bestPer) ? ' 👑' : ($isDeepDrop ? ' ⚠️' : '');
                $perStr = $s['per'] . 'x' . $perBadge;
            } else {
                $perStr = $isLoss ? 'N/A (Rugi) ⚠️' : 'N/A';
            }

            // PBV
            if ($s['pbv'] !== null && $s['pbv'] > 0) {
                $pbvBadge = (!$isTrap && $s['pbv'] === $bestPbv) ? ' 👑' : ($isLoss ? ' ⚠️' : '');
                $pbvStr = $s['pbv'] . 'x' . $pbvBadge;
            } else {
                $pbvStr = 'N/A';
            }

            // ROE & NPM
            $roeBadge = ($s['roe'] !== null && $s['roe'] < 0) ? ' ⚠️' : (($s['roe'] !== null && $s['roe'] === $bestRoe) ? ' 👑' : '');
            $roeStr = $s['roe'] !== null ? $s['roe'] . '%' . $roeBadge : 'N/A';

            $npmBadge = ($s['npm'] !== null && $s['npm'] < 0) ? ' ⚠️' : (($s['npm'] !== null && $s['npm'] === $bestNpm) ? ' 👑' : '');
            $npmStr = $s['npm'] !== null ? $s['npm'] . '%' . $npmBadge : 'N/A';

            // DER
            if ($isBank) {
                $derStr = $s['der'] !== null ? $s['der'] . 'x (Bank)' : 'N/A (Bank)';
            } else {
                $derWarn = ($s['der'] !== null && $s['der'] > 1.5) ? ' ⚠️' : '';
                $derStr = $s['der'] !== null ? $s['der'] . 'x' . $derWarn : 'N/A';
            }

            // Laba YoY
            $earnBadge = ($s['earn_growth'] !== null && $s['earn_growth'] < -25) ? ' ⚠️' : (($bestEarn !== null && $s['earn_growth'] === $bestEarn) ? ' 👑' : '');
            $earnStr = $s['earn_growth'] !== null ? (($s['earn_growth'] >= 0 ? '+' : '') . $s['earn_growth'] . '%' . $earnBadge) : 'N/A';

            // Dividen
            if ($s['dividend_yield'] !== null && $s['dividend_yield'] > 0) {
                $isTrailingDiv = $isLoss || ($s['earn_growth'] !== null && $s['earn_growth'] < -40);
                $divBadge = (!$isTrap && $s['dividend_yield'] === $bestDiv) ? ' 👑' : ($isTrailingDiv ? ' ⚠️' : '');
                $divStr = $s['dividend_yield'] . '%' . ($isTrailingDiv ? ' (Trailing)' : '') . $divBadge;
            } else {
                $divStr = '-';
            }

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

        // Asumsi $items sudah terurut dari skor tertinggi & $bankTickers sudah didefinisikan
        $winner   = $items[0];
        $runnerUp = $items[1] ?? null;
        $winSym   = $winner['symbol'];
        $winScore = (int) $winner['score'];
        $diff     = $runnerUp ? ($winScore - (int) $runnerUp['score']) : 0;

        // 1. Deteksi keunggulan utama pemenang
        $winStrengths = [];
        $winIsBank = in_array($winSym, $bankTickers, true);

        if (!$winIsBank && $winner['der'] !== null && $winner['der'] <= 1.0) {
            $winStrengths[] = 'neraca sehat';
        }
        if ($winner['dividend_yield'] !== null && $winner['dividend_yield'] >= 5.0 && ($winner['earn_growth'] === null || $winner['earn_growth'] >= -40)) {
            $winStrengths[] = 'dividen menarik';
        }
        if ($winner['roe'] !== null && $winner['roe'] >= 15.0) {
            $winStrengths[] = 'profitabilitas solid';
        }
        if ($winner['earn_growth'] !== null && $winner['earn_growth'] >= 10.0) {
            $winStrengths[] = 'pertumbuhan laba kuat';
        }
        $strengthText = !empty($winStrengths)
            ? implode(' &amp; ', array_slice($winStrengths, 0, 2))
            : 'profil risiko lebih terjaga';

        // 2. Deteksi risiko utama runner-up (jika ada)
        $runnerRisk = '';
        if ($runnerUp) {
            $rIsBank = in_array($runnerUp['symbol'], $bankTickers, true);
            $rIsLoss = ($runnerUp['roe'] !== null && $runnerUp['roe'] < 0) || ($runnerUp['npm'] !== null && $runnerUp['npm'] < 0);

            if ($rIsLoss) {
                $runnerRisk = "{$runnerUp['symbol']} masih merugi";
            } elseif ($runnerUp['earn_growth'] !== null && $runnerUp['earn_growth'] < -25) {
                $runnerRisk = "{$runnerUp['symbol']} terbebani kontraksi laba tajam";
            } elseif (!$rIsBank && $runnerUp['der'] !== null && $runnerUp['der'] > 1.5) {
                $runnerRisk = "{$runnerUp['symbol']} memiliki beban utang tinggi";
            }
        }

        // 3. Rangkai 1 kalimat kesimpulan berdasarkan selisih skor
        if ($winScore < 45) {
            $summary = "Seluruh emiten di grup ini masih berisiko tinggi (skor &lt; 45); sebaiknya tunggu perbaikan fundamental.";
        } elseif ($runnerUp && $diff <= 5) {
            $summary = "Persaingan sangat ketat dengan {$runnerUp['symbol']} (selisih {$diff} poin); {$winSym} sedikit lebih unggul lewat {$strengthText}.";
        } elseif ($runnerUp && $diff >= 20) {
            $riskClause = $runnerRisk ? ", sementara {$runnerRisk}" : '';
            $summary = "{$winSym} unggul telak (+{$diff} poin) berkat {$strengthText}{$riskClause}.";
        } elseif ($runnerUp) {
            $riskClause = $runnerRisk ? " dibandingkan {$runnerRisk}" : " atas {$runnerUp['symbol']}";
            $summary = "{$winSym} memimpin (+{$diff} poin) dengan {$strengthText}{$riskClause}.";
        } else {
            $summary = "{$winSym} mencatatkan skor {$winScore}/100 dengan {$strengthText}.";
        }

        $msg .= "🏆 <b>Unggul Keseluruhan: {$winSym}</b> (Skor {$winScore}/100)\n";
        $msg .= "💡 <i>{$summary}</i>\n";
        $msg .= "<i>👑 = Terbaik (lolos filter) | ⚠️ = Risiko trap / negatif</i>";

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
        $msg .= "━━━━━━━━━━━━━━\n";
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
        $msg .= "━━━━━━━━━━━━━━\n";
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

        // 1. Tombol Chart semua leader sektor (grid 2x4)
        $leaderBtns = [];
        $seenLeaders = [];
        foreach ($sectors as $sec) {
            $sym = trim((string) ($sec['leader']['symbol'] ?? ''));
            if ($sym === '' || isset($seenLeaders[$sym])) {
                continue;
            }
            $seenLeaders[$sym] = true;
            $leaderBtns[] = InlineKeyboardButton::make("📊 {$sym}", callback_data: "chart:{$sym}");
        }
        foreach (array_chunk($leaderBtns, 4) as $chunk) {
            $keyboard->addRow(...$chunk);
        }

        // 2. Tombol Bandingkan semua sektor (grid 4x2)
        $bandingBtns = [];
        foreach ($sectors as $sec) {
            $members = array_column($sec['members'] ?? [], 'symbol');
            if (count($members) < 2) {
                continue;
            }
            $tickersStr = implode(' ', array_slice($members, 0, 4));
            $bandingBtns[] = InlineKeyboardButton::make("⚖️ {$sec['sector']}", callback_data: "banding_list:{$tickersStr}");
        }
        foreach (array_chunk($bandingBtns, 2) as $chunk) {
            $keyboard->addRow(...$chunk);
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

        $keyboard = $briefService->buildTelegramKeyboard();

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
$runRadar = function (Nutgram $bot, bool $forceRefresh = false) {
    $bot->sendMessage('⏳ Memindai aktivitas intraday (15m) & unusual volume sweep di BEI...');

    try {
        $screener = app(\App\Services\StockScreenerService::class);
        $items = $screener->scanIntradayRadar($forceRefresh);
        $nowWib = now('Asia/Jakarta')->format('H:i') . ' WIB';

        if (empty($items)) {
            $bot->sendMessage(
                "📡 <b>INTRADAY UNUSUAL RADAR ({$nowWib})</b>\n" .
                "━━━━━━━━━━━━━━━━━━━━\n\n" .
                "ℹ️ Belum terdeteksi lonjakan volume intraday abnormal (Vol &gt;= 1.25x atau gerak signifikan) saat ini.",
                parse_mode: ParseMode::HTML
            );
            return;
        }

        $first     = $items[0];
        $barSlot   = $first['bar_slot'] ?? '-';
        $barLast   = $first['bar_last'] ?? $barSlot;
        $barDate   = $first['bar_date'] ?? '';
        $isLive    = (bool) ($first['is_live'] ?? true);
        $sbCnt     = count(array_filter($items, fn($x) => ($x['source'] ?? '') === 'Stockbit'));
        $srcLabel  = $sbCnt === count($items)
            ? '🟢 Stockbit Real-Time'
            : ($sbCnt > 0 ? "🟢 Stockbit ({$sbCnt}/" . count($items) . ") • 🟡 Yahoo Fallback" : '🟡 Yahoo Fallback');

        $msg = "📡 <b>INTRADAY UNUSUAL RADAR (15m)</b>\n";
        $msg .= "🕒 <i>Scan: {$nowWib} | Bar: {$barSlot}–{$barLast}" . (!$isLive && $barDate ? " ({$barDate} - Sesi Terakhir)" : '') . "</i>\n";
        $msg .= "━━━━━━━━━━━━━━━━━━━━\n\n";

        $keyboard = InlineKeyboardMarkup::make();
        $chartBtns = [];

        foreach ($items as $idx => $it) {
            $no = $idx + 1;
            $priceFmt = number_format($it['price'], 0);
            $daySign = $it['day_chg'] >= 0 ? '+' : '';
            $cSign = $it['candle_chg'] >= 0 ? '+' : '';
            $valIdr = (float) ($it['val_idr'] ?? 0);
            $valFmt = $valIdr >= 1_000_000_000
                ? 'Rp ' . number_format($valIdr / 1_000_000_000, 1) . ' M'
                : 'Rp ' . number_format($valIdr / 1_000_000, 0) . ' Jt';

            $msg .= "{$no}. <b>{$it['symbol']}</b> — Rp <b>{$priceFmt}</b> (Day: {$daySign}{$it['day_chg']}%)\n";
            $msg .= "   • {$it['tag']}\n";
            $msg .= "   • Vol: <b>{$it['vol_ratio']}x</b> ({$valFmt}) | CLV: <b>{$it['clv']}%</b> | 15m: {$cSign}{$it['candle_chg']}%\n\n";

            if ($idx < 10) {
                $chartBtns[] = InlineKeyboardButton::make("📊 {$it['symbol']}", callback_data: "chart:{$it['symbol']}");
            }
        }

        if (!empty($chartBtns)) {
            $totalBtns = count($chartBtns);
            $perRow = $totalBtns <= 5 ? $totalBtns : ($totalBtns === 6 ? 3 : ($totalBtns <= 8 ? 4 : 5));
            foreach (array_chunk($chartBtns, $perRow) as $chunk) {
                $keyboard->addRow(...$chunk);
            }
        }
        $keyboard->addRow(InlineKeyboardButton::make("🔄 Refresh Radar", callback_data: "quick:radar"));

        $msg .= "📡 <i>Sumber: {$srcLabel}</i>";
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
    $runRadar($bot, true);
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

        $msg .= "━━━━━━━━━━━━━━\n";
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
    $bot->answerCallbackQuery();

    $plan = \Illuminate\Support\Facades\Cache::get("last_plan:{$symbol}");
    if (!$plan) {
        try {
            $tmp = app(StockAnalysisService::class)->analyze($symbol);
            if (!empty($tmp['chart_path']) && file_exists($tmp['chart_path'])) @unlink($tmp['chart_path']);
            $plan = [
                's1' => (float) ($tmp['s1'] ?? 0), 'r1' => (float) ($tmp['r1'] ?? 0),
                'sl' => (float) ($tmp['stoploss'] ?? 0), 'price' => (float) ($tmp['price'] ?? 0),
                'dec' => str_ends_with($tmp['symbol'] ?? '', '.JK') ? 0 : 2,
            ];
            \Illuminate\Support\Facades\Cache::put("last_plan:{$symbol}", $plan, 7200);
        } catch (\Throwable $e) {
            $plan = ['s1' => 0, 'r1' => 0, 'sl' => 0, 'price' => 0, 'dec' => 0];
        }
    }

    $dec   = (int) ($plan['dec'] ?? 0);
    $s1Fmt = $plan['s1'] > 0 ? number_format($plan['s1'], $dec) : 'Auto S1';
    $r1Fmt = $plan['r1'] > 0 ? number_format($plan['r1'], $dec) : 'Auto R1';
    $slFmt = $plan['sl'] > 0 ? number_format($plan['sl'], $dec) : 'Auto SL';

    $existing = Watchlist::where('telegram_id', $userId)->where('symbol', $symbol)->first();
    $curStatus = $existing
        ? "Status saat ini: <b>Aktif (" . strtoupper((string) ($existing->alert_mode ?: 'all')) . ")</b>\n"
        : "Status saat ini: <i>Belum dipantau</i>\n";

    $kb = InlineKeyboardMarkup::make()
        ->addRow(
            InlineKeyboardButton::make("🟢 BoW ≤ {$s1Fmt}", callback_data: "spantau:{$symbol}:bow"),
            InlineKeyboardButton::make("🚀 BoB ≥ {$r1Fmt}", callback_data: "spantau:{$symbol}:bob")
        )
        ->addRow(InlineKeyboardButton::make("⚡ BoW + BoB + SL", callback_data: "spantau:{$symbol}:both"));

    if ($existing) {
        $kb->addRow(InlineKeyboardButton::make("🗑️ Hapus dari Watchlist", callback_data: "spantau:{$symbol}:del"));
    }

    $bot->sendMessage(
        text: "🔔 <b>SMART ALERT: {$symbol}</b>\n━━━━━━━━━━━━━━\n{$curStatus}" .
              "• Area BoW (S1): <b>Rp {$s1Fmt}</b>\n" .
              "• Breakout BoB (R1): <b>Rp {$r1Fmt}</b> (Vol ≥ 1.2x)\n" .
              "• Batas Stoploss: <b>Rp {$slFmt}</b>\n\n" .
              "Pilih mode pantauan otomatis (dicek berkala saat jam bursa):",
        parse_mode: ParseMode::HTML,
        reply_markup: $kb
    );
});

$bot->onCallbackQueryData('spantau:{ticker}:{mode}', function (Nutgram $bot, string $ticker, string $mode) {
    $symbol = strtoupper(preg_replace('/[^a-zA-Z0-9.]/', '', trim($ticker)));
    $mode   = strtolower(trim($mode));
    $userId = $bot->userId();

    if ($mode === 'del') {
        $deleted = Watchlist::where('telegram_id', $userId)->where('symbol', $symbol)->delete();
        $bot->answerCallbackQuery(text: $deleted ? "🗑️ {$symbol} dihapus." : "ℹ️ Tidak ditemukan.");
        $bot->sendMessage("🗑️ Smart Alert <b>{$symbol}</b> dihapus dari watchlist.", parse_mode: ParseMode::HTML);
        return;
    }

    if (!in_array($mode, ['bow', 'bob', 'both'], true)) {
        $bot->answerCallbackQuery(text: 'Mode alert tidak valid.', show_alert: true);
        return;
    }

    $existing = Watchlist::where('telegram_id', $userId)->where('symbol', $symbol)->first();
    if (!$existing && Watchlist::where('telegram_id', $userId)->count() >= 15) {
        $bot->answerCallbackQuery(text: '⚠️ Maksimal watchlist pribadi adalah 15 saham.', show_alert: true);
        return;
    }

    $plan = \Illuminate\Support\Facades\Cache::get("last_plan:{$symbol}") ?? [];
    $s1   = !empty($plan['s1']) ? (float) $plan['s1'] : null;
    $r1   = !empty($plan['r1']) ? (float) $plan['r1'] : null;
    $sl   = !empty($plan['sl']) ? (float) $plan['sl'] : null;

    Watchlist::updateOrCreate(
        ['telegram_id' => $userId, 'symbol' => $symbol],
        ['alert_mode' => $mode, 'target_bow' => $s1, 'target_bob' => $r1, 'target_sl' => $sl, 'min_vol_ratio' => 1.2]
    );

    $s1Txt = $s1 ? 'Rp ' . number_format($s1, 0) : 'Auto S1';
    $r1Txt = $r1 ? 'Rp ' . number_format($r1, 0) : 'Auto R1';
    $slTxt = $sl ? 'Rp ' . number_format($sl, 0) : 'Auto SL';

    $summary = match ($mode) {
        'bow'  => "🟢 <b>Mode BoW Aktif:</b> Notif saat harga ≤ <b>{$s1Txt}</b> (atau SL ≤ {$slTxt}).",
        'bob'  => "🚀 <b>Mode BoB Aktif:</b> Notif saat harga ≥ <b>{$r1Txt}</b> dengan Vol ≥ <b>1.2x</b> (atau SL ≤ {$slTxt}).",
        'both' => "⚡ <b>Mode BoW + BoB Aktif:</b> Notif saat BoW ≤ <b>{$s1Txt}</b>, BoB ≥ <b>{$r1Txt}</b> (Vol ≥ 1.2x), atau SL ≤ {$slTxt}.",
    };

    $bot->answerCallbackQuery(text: "✅ Smart Alert {$symbol} aktif!");
    $bot->sendMessage(
        text: "✅ <b>SMART ALERT DISIMPAN: {$symbol}</b>\n{$summary}\n\nLihat semua pantauan: <code>/watchlist</code>",
        parse_mode: ParseMode::HTML
    );
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
    $now = now()->timezone($tz);
    $latestBursa = $now->copy();
    while ($latestBursa->isWeekend()) {
        $latestBursa->subDay();
    }
    $lastBursaStr = $latestBursa->format('Y-m-d');
    $isAutoDefault = ($fromDate === null && $toDate === null);
    $from = $fromDate ?: $lastBursaStr;
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

    // Auto-fallback maksimal 3 hari bursa ke belakang jika default kosong (libur nasional / sebelum data tersedia)
    if ($isAutoDefault && !isset($result['error']) && empty($result)) {
        $cursor = \Carbon\Carbon::parse($from, $tz);
        for ($i = 0; $i < 3; $i++) {
            $cursor = $cursor->subWeekday();
            $candidate = $cursor->format('Y-m-d');
            $tryResult = $screener->scanTopRetailSell($universe, 18, $candidate, $candidate);
            if (isset($tryResult['error']) || !empty($tryResult)) {
                $from = $candidate;
                $to = $candidate;
                $result = $tryResult;
                break;
            }
        }
    }

    $prevDay = \Carbon\Carbon::parse($from, $tz)->subWeekday()->format('Y-m-d');
    $nextDay = \Carbon\Carbon::parse($to, $tz)->addWeekday()->format('Y-m-d');
    $yesterday = $latestBursa->copy()->subWeekday()->format('Y-m-d');
    $d3From = $latestBursa->copy()->subWeekdays(2)->format('Y-m-d');
    $w1From = $latestBursa->copy()->subWeekdays(4)->format('Y-m-d');
    $latestLabel = $now->isWeekend() ? 'Terakhir' : 'Hari Ini';

    $keyboard = \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup::make()
        ->addRow(
            \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make($latestLabel, callback_data: "ritel:range:{$lastBursaStr}:{$lastBursaStr}"),
            \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make('Kemarin', callback_data: "ritel:range:{$yesterday}:{$yesterday}"),
            \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make('3 Hari', callback_data: "ritel:range:{$d3From}:{$lastBursaStr}"),
            \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make('1 Minggu', callback_data: "ritel:range:{$w1From}:{$lastBursaStr}")
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

    $topPickChunks = array_chunk(array_slice($result, 0, 10), 5);
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






$bot->onCommand('sesi1', function (\SergiX44\Nutgram\Nutgram $bot) {
    $waitMsg = $bot->sendMessage("⏳ <i>Menyusun Market Report Sesi 1 (IHSG, Top Mover, Foreign Flow, Rebound 3D & Lonjakan Volume)...</i>", parse_mode: \SergiX44\Nutgram\Telegram\Properties\ParseMode::HTML);
    try {
        $service = app(\App\Services\MarketSessionReportService::class);
        $report = $service->generateReport(true);
        $bot->editMessageText(
            text: $report,
            chat_id: $waitMsg->chat->id,
            message_id: $waitMsg->message_id,
            parse_mode: \SergiX44\Nutgram\Telegram\Properties\ParseMode::HTML
        );
    } catch (\Throwable $e) {
        $bot->editMessageText(
            text: "❌ Gagal menyusun Market Report Sesi 1: " . $e->getMessage(),
            chat_id: $waitMsg->chat->id,
            message_id: $waitMsg->message_id
        );
    }
})->description('Market Report Sesi 1 (IHSG, Top Gainer/Loser, Foreign Flow, Rebound, Volume)');

$bot->onCommand('Sesi1', function (\SergiX44\Nutgram\Nutgram $bot) {
    $waitMsg = $bot->sendMessage("⏳ <i>Menyusun Market Report Sesi 1...</i>", parse_mode: \SergiX44\Nutgram\Telegram\Properties\ParseMode::HTML);
    $report = app(\App\Services\MarketSessionReportService::class)->generateReport(true);
    $bot->editMessageText(text: $report, chat_id: $waitMsg->chat->id, message_id: $waitMsg->message_id, parse_mode: \SergiX44\Nutgram\Telegram\Properties\ParseMode::HTML);
});

// Helper daftar perintah lengkap (/help & tombol Daftar Perintah)
$sendFullHelp = function (\SergiX44\Nutgram\Nutgram $bot) {
    $maxRisk = \App\Models\UserRiskProfile::maxRiskFor($bot->chatId());
    $riskLabel = \App\Models\UserRiskProfile::formatShort($maxRisk);

    $helpText = "📋 <b>DAFTAR LENGKAP PERINTAH EASY CUAN</b>\n"
              . "━━━━━━━━━━━━━━\n"
              . "🌍 <b>Market &amp; Sektor:</b>\n"
              . "• <code>/brief</code> — Morning Brief &amp; IHSG Macro\n"
              . "• <code>/sesi1</code> — Market Report Sesi 1 (Gainer, Asing, Rebound, Vol)\n"
              . "• <code>/sektor</code> — Rotasi &amp; Momentum 8 Sektor BEI\n"
              . "• <code>/radar</code> — Intraday Sweep &amp; Unusual Vol (15m)\n\n"
              . "📊 <b>Teknikal, Trading Plan &amp; AI:</b>\n"
              . "• <code>/analisa KODE</code> — Chart &amp; Plan (Risk <b>{$riskLabel}</b>)\n"
              . "• <code>/analisa KODE 500000</code> — Plan Custom Max Risk\n"
              . "• <code>/setrisk</code> — Atur Modal &amp; Risk (Aktif: <b>{$riskLabel}</b>)\n"
              . "• <code>/mtf KODE</code> — Multi-Timeframe Confluence (1H/1D/1W)\n"
              . "• <code>/forecast KODE</code> (atau <code>/ai KODE</code>) — Proyeksi 5D Kronos AI\n\n"
              . "🏦 <b>Bandarmologi &amp; Flow:</b>\n"
              . "• <code>/brokersum KODE</code> — Broker Summary Real-Time\n"
              . "• <code>/nonritel</code> — Screener Non-Retail Flow (1D/1W/1M/Tgl)\n"
              . "• <code>/topritel</code> — Top Retail Jualan (Stockbit)\n"
              . "• <code>/akumulasi</code> — Deteksi Akumulasi (VSA/OBV)\n"
              . "• <code>/volume</code> — Lonjakan Volume &gt;= 1.5x\n\n"
              . "🔍 <b>Screener Fundamental:</b>\n"
              . "• <code>/screener</code> — Default (PER &lt; 10, PBV &lt; 5)\n"
              . "• <code>/screener 10 2 1</code> — Custom PER, PBV, [DER]\n"
              . "• <code>/dividen 10</code> — Dividend Yield &gt;= 10%\n\n"
              . "💎 <b>Valuasi, Komparasi &amp; Audit:</b>\n"
              . "• <code>/valuasi KODE</code> — Fair Value (Graham, EPV, DCF) &amp; MoS\n"
              . "• <code>/banding KODE1 KODE2</code> — Komparasi Head-to-Head\n"
              . "• <code>/audit KODE</code> — Kualitas Laba &amp; Piotroski F-Score\n\n"
              . "🔔 <b>Watchlist &amp; Pengaturan:</b>\n"
              . "• <code>/watchlist</code> — Lihat daftar saham yang dipantau\n"
              . "• <code>/pantau KODE</code> — Tambah saham ke alert otomatis\n"
              . "• <code>/unpantau KODE</code> — Hapus saham dari watchlist\n"
              . "• <code>/settoken TOKEN</code> — Update Bearer Token Stockbit\n"
              . "• <code>/setmenu</code> — Segarkan tombol menu perintah bot\n\n"
              . "💡 <i>Tips: Ketik langsung kode saham (misal <code>BBRI</code>) untuk analisa cepat, atau paste JWT token Stockbit langsung di chat.</i>";

    $bot->sendMessage($helpText, parse_mode: \SergiX44\Nutgram\Telegram\Properties\ParseMode::HTML);
};

$bot->onCommand('help', $sendFullHelp);
$bot->onCommand('Help', $sendFullHelp);

$bot->onCallbackQueryData('menu:help', function (\SergiX44\Nutgram\Nutgram $bot) use ($sendFullHelp) {
    $bot->answerCallbackQuery();
    $sendFullHelp($bot);
});

$bot->onCallbackQueryData('quick:brief', function (\SergiX44\Nutgram\Nutgram $bot, \App\Services\MarketBriefService $briefService) {
    $bot->answerCallbackQuery(text: "⏳ Memuat Market Brief...");
    try {
        $brief = $briefService->generateBrief();
        $msg   = $briefService->formatTelegramMessage($brief);
        $bot->sendMessage($msg, parse_mode: \SergiX44\Nutgram\Telegram\Properties\ParseMode::HTML, reply_markup: $briefService->buildTelegramKeyboard());
    } catch (\Throwable $e) {
        $bot->sendMessage("❌ Gagal memuat Market Brief: " . $e->getMessage());
    }
});

$bot->onCallbackQueryData('quick:brief_refresh', function (\SergiX44\Nutgram\Nutgram $bot, \App\Services\MarketBriefService $briefService) {
    $bot->answerCallbackQuery(text: "🔄 Mengambil data makro terbaru...");
    try {
        $brief = $briefService->generateBrief(true);
        $msg   = $briefService->formatTelegramMessage($brief);
        $bot->sendMessage($msg, parse_mode: \SergiX44\Nutgram\Telegram\Properties\ParseMode::HTML, reply_markup: $briefService->buildTelegramKeyboard());
    } catch (\Throwable $e) {
        $bot->sendMessage("❌ Gagal memuat ulang Market Brief: " . $e->getMessage());
    }
});

// KRONOS AI UPSIDE SCREENER (>80% Upside Probability 5D)
$runKronosScan = function (\SergiX44\Nutgram\Nutgram $bot) {
    $waitMsg = $bot->sendMessage(
        "🤖 <b>Memindai Screener Kronos AI (Upside Probability &gt; 80%)...</b>\n<i>Menganalisa simulasi Monte Carlo 5D pada emiten likuid BEI (±30–45 detik)...</i>",
        parse_mode: \SergiX44\Nutgram\Telegram\Properties\ParseMode::HTML
    );

    try {
        $scan = app(\App\Services\StockScreenerService::class)->screenKronosUpside(80.0, 100);
        $results = $scan['results'] ?? [];
        $scanned = $scan['scanned_count'] ?? 0;

        if (empty($results)) {
            $reply = "🤖 <b>KRONOS AI SCREENER (Upside Prob &gt; 80%)</b>\n"
                   . "━━━━━━━━━━━━━━\n"
                   . "Tidak ditemukan emiten dengan <b>Upside Probability (5D) &gt; 80%</b> dari {$scanned} emiten likuid BEI yang dipindai saat ini.\n\n"
                   . "<i>Gunakan <code>/forecast KODE</code> untuk mengecek probabilitas emiten spesifik.</i>";
        } else {
            $lines = "";
            foreach ($results as $idx => $item) {
                $no     = $idx + 1;
                $sym    = $item['symbol'];
                $last   = number_format((float) $item['last_close'], 0);
                $target = number_format((float) $item['target_close'], 0);
                $chg    = $item['change_pct'];
                $sign   = $chg >= 0 ? '+' : '';
                $prob   = number_format((float) $item['upside_probability'], 1);
                $volAmp = isset($item['volatility_amplification']) ? number_format((float) $item['volatility_amplification'], 1) . '%' : '-';

                $flowBadge = '';
                if (!empty($item['accdist']) && $item['accdist'] !== '-') {
                    $rIcon = ($item['is_retail_selling'] ?? false) ? '🟢' : '🔴';
                    $flowBadge = " | Bandarmologi: <b>{$item['accdist']}</b> ({$rIcon} Ritel {$item['retail_side']} {$item['retail_pct']}%)";
                }

                $lines .= "{$no}. <b>{$sym}</b> — Prob Naik: 🟢 <b>{$prob}%</b>\n"
                        . "   • Rp {$last} ➔ H+5: <b>Rp {$target} ({$sign}{$chg}%)</b> | VolAmp: {$volAmp}{$flowBadge}\n";
            }

            $reply = "🤖 <b>KRONOS AI SCREENER (Upside Prob &gt; 80%)</b>\n"
                   . "━━━━━━━━━━━━━━\n"
                   . "Ditemukan <b>" . count($results) . " emiten</b> (dari {$scanned} emiten likuid BEI) dengan probabilitas kenaikan 5 hari &gt; 80%:\n\n"
                   . $lines
                   . "\n<i>💡 Ketik <code>/forecast KODE</code> untuk melihat chart lintasan harian &amp; konfirmasi R1/S1.</i>";
        }

        if ($waitMsg) {
            try {
                $bot->deleteMessage($waitMsg->chat->id, $waitMsg->message_id);
            } catch (\Throwable $e) {}
        }

        $keyboard = \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup::make();
        if (!empty($results)) {
            $rowCount = 0;
            $row = [];
            foreach ($results as $item) {
                $sym = $item['symbol'];
                $row[] = \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make("📊 {$sym}", callback_data: "analisa:{$sym}");
                $rowCount++;
                if ($rowCount % 3 === 0) {
                    $keyboard->addRow(...$row);
                    $row = [];
                }
            }
            if (!empty($row)) {
                $keyboard->addRow(...$row);
            }
        }
        $keyboard->addRow(
            \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make("🔄 Scan Ulang", callback_data: "quick:kronos_scan")
        );

        $bot->sendMessage(
            $reply,
            parse_mode: \SergiX44\Nutgram\Telegram\Properties\ParseMode::HTML,
            reply_markup: $keyboard
        );
    } catch (\Throwable $e) {
        $bot->sendMessage("❌ Gagal menjalankan Kronos Screener: " . htmlspecialchars($e->getMessage()), parse_mode: \SergiX44\Nutgram\Telegram\Properties\ParseMode::HTML);
    }
};

$bot->onCommand('kronos_scan', $runKronosScan);
$bot->onCommand('aiscan', $runKronosScan);
$bot->onCallbackQueryData('quick:kronos_scan', function (\SergiX44\Nutgram\Nutgram $bot) use ($runKronosScan) {
    $bot->answerCallbackQuery();
    $runKronosScan($bot);
});

// UNKNOWN_COMMAND_FALLBACK: Tangani perintah atau pesan yang tidak dikenali
$bot->fallback(function (\SergiX44\Nutgram\Nutgram $bot) {
    if ($bot->callbackQuery() !== null) {
        $bot->answerCallbackQuery();
        return;
    }

    $text = trim((string) ($bot->message()?->text ?? ""));
    if ($text === "") {
        return;
    }

    if (str_starts_with($text, "/")) {
        $cmd = htmlspecialchars(explode(" ", $text)[0]);
        $msg = "❓ Perintah <code>{$cmd}</code> tidak dikenali.\n\n"
             . "Gunakan <b>/help</b> untuk melihat daftar perintah yang tersedia, atau langsung ketik kode saham (contoh: <code>BBRI</code>) untuk analisa cepat.";
    } else {
        $msg = "❓ Format pesan tidak dikenali.\n\n"
             . "Ketik kode saham (contoh: <code>BBRI</code>) untuk analisa chart & sinyal, atau ketik <b>/help</b> untuk melihat semua fitur.";
    }

    $keyboard = \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup::make()
        ->addRow(
            \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make("📋 Daftar Perintah", callback_data: "menu:help"),
            \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make("🌅 Market Brief", callback_data: "quick:brief")
        );

    $bot->sendMessage($msg, parse_mode: \SergiX44\Nutgram\Telegram\Properties\ParseMode::HTML, reply_markup: $keyboard);
});
