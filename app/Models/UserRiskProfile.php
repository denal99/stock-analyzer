<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserRiskProfile extends Model
{
    public const DEFAULT_MAX_RISK = 1_000_000.0;

    protected $fillable = [
        'chat_id',
        'capital',
        'risk_percent',
        'max_risk',
    ];

    protected $casts = [
        'capital' => 'float',
        'risk_percent' => 'float',
        'max_risk' => 'float',
    ];

    public static function forChat(int|string|null $chatId): ?self
    {
        if ($chatId === null || $chatId === '') {
            return null;
        }

        return static::where('chat_id', (string) $chatId)->first();
    }

    public static function maxRiskFor(int|string|null $chatId): float
    {
        $profile = static::forChat($chatId);

        if ($profile && $profile->max_risk > 0) {
            return (float) $profile->max_risk;
        }

        return self::DEFAULT_MAX_RISK;
    }

    public static function saveForChat(
        int|string $chatId,
        float $maxRisk,
        ?float $capital = null,
        ?float $riskPercent = null
    ): self {
        return static::updateOrCreate(
            ['chat_id' => (string) $chatId],
            [
                'capital' => $capital !== null ? (int) round($capital) : null,
                'risk_percent' => $riskPercent !== null ? round($riskPercent, 2) : null,
                'max_risk' => (int) round($maxRisk),
            ]
        );
    }

    public static function resetForChat(int|string $chatId): void
    {
        static::where('chat_id', (string) $chatId)->delete();
    }

    public static function formatShort(float $val): string
    {
        if ($val >= 1_000_000) {
            $num = rtrim(rtrim(number_format($val / 1_000_000, 2, ',', '.'), '0'), ',');
            return "Rp {$num} Jt";
        }
        if ($val >= 1_000) {
            $num = rtrim(rtrim(number_format($val / 1_000, 1, ',', '.'), '0'), ',');
            return "Rp {$num} Rb";
        }

        return 'Rp ' . number_format($val, 0, ',', '.');
    }

    public static function formatRp(float $val): string
    {
        return 'Rp ' . number_format($val, 0, ',', '.');
    }
}
