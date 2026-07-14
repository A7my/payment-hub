<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Enums;

/**
 * ISO 4217 currency codes supported by the payment framework.
 *
 * Design decisions:
 * - Amounts are ALWAYS stored as non-negative integers in the smallest
 *   currency unit to avoid floating-point arithmetic errors:
 *     USD 10.00  → 1000 cents
 *     KWD  1.000 → 1000 fils
 *     JPY 100    → 100  yen  (zero decimal)
 * - The `subunitExponent()` method encodes how many decimal places the
 *   currency uses, enabling correct formatting and conversion without
 *   hard-coded magic numbers scattered across the codebase.
 * - The framework ships with the most common currencies for MENA + major
 *   international markets. New currencies can be added in a minor release
 *   without breaking any existing driver or application code.
 * - Application-level currency restrictions are enforced via the
 *   `payment.currencies` config key, not by removing cases from this enum.
 *
 * @see https://www.iso.org/iso-4217-currency-codes.html
 */
enum Currency: string
{
    // ── Major international ───────────────────────────────────────────────────

    /** United States Dollar — 2 decimal places (cents). */
    case USD = 'USD';

    /** Euro — 2 decimal places (cents). */
    case EUR = 'EUR';

    /** British Pound Sterling — 2 decimal places (pence). */
    case GBP = 'GBP';

    // ── Gulf Cooperation Council ──────────────────────────────────────────────

    /** Saudi Riyal — 2 decimal places (halalas). */
    case SAR = 'SAR';

    /** UAE Dirham — 2 decimal places (fils). */
    case AED = 'AED';

    /** Kuwaiti Dinar — 3 decimal places (fils). Highest subunit precision in GCC. */
    case KWD = 'KWD';

    /** Bahraini Dinar — 3 decimal places (fils). */
    case BHD = 'BHD';

    /** Omani Rial — 3 decimal places (baisa). */
    case OMR = 'OMR';

    /** Qatari Riyal — 2 decimal places (dirham). */
    case QAR = 'QAR';

    // ── Other MENA ───────────────────────────────────────────────────────────

    /** Egyptian Pound — 2 decimal places (piastres). */
    case EGP = 'EGP';

    /** Jordanian Dinar — 3 decimal places (fils). */
    case JOD = 'JOD';

    /** Turkish Lira — 2 decimal places (kuruş). */
    case TRY = 'TRY';

    /** Moroccan Dirham — 2 decimal places (centimes). */
    case MAD = 'MAD';

    // ── Asia-Pacific ─────────────────────────────────────────────────────────

    /** Japanese Yen — 0 decimal places (no subunit). */
    case JPY = 'JPY';

    /** Indian Rupee — 2 decimal places (paise). */
    case INR = 'INR';

    /** Pakistani Rupee — 2 decimal places (paisa). */
    case PKR = 'PKR';

    /** Malaysian Ringgit — 2 decimal places (sen). */
    case MYR = 'MYR';

    /** Singapore Dollar — 2 decimal places (cents). */
    case SGD = 'SGD';

    // =========================================================================
    // Helper methods
    // =========================================================================

    /**
     * ISO 4217 numeric exponent: the number of decimal places for this currency.
     *
     * This is the single authoritative source of "how many subunits" for each
     * currency. Use this when formatting amounts or converting between subunit
     * integers and decimal representations.
     *
     * Examples:
     *   Currency::USD->subunitExponent() === 2  → 1 USD = 100 cents
     *   Currency::KWD->subunitExponent() === 3  → 1 KWD = 1000 fils
     *   Currency::JPY->subunitExponent() === 0  → 1 JPY = 1 yen (no subunit)
     *
     * @return int<0, 3> The exponent (0, 2, or 3).
     */
    public function subunitExponent(): int
    {
        return match ($this) {
            self::JPY            => 0,
            self::KWD,
            self::BHD,
            self::OMR,
            self::JOD            => 3,
            default              => 2,
        };
    }

    /**
     * Number of subunits per major unit (10^subunitExponent).
     *
     * Use this for converting between integer storage and display values.
     *
     * Examples:
     *   Currency::USD->subunitsPerUnit() === 100
     *   Currency::KWD->subunitsPerUnit() === 1000
     *   Currency::JPY->subunitsPerUnit() === 1
     *
     * @return int<1, 1000>
     */
    public function subunitsPerUnit(): int
    {
        return (int) (10 ** $this->subunitExponent());
    }

    /**
     * Human-readable currency name.
     *
     * @return string
     */
    public function label(): string
    {
        return match ($this) {
            self::USD => 'US Dollar',
            self::EUR => 'Euro',
            self::GBP => 'British Pound Sterling',
            self::SAR => 'Saudi Riyal',
            self::AED => 'UAE Dirham',
            self::KWD => 'Kuwaiti Dinar',
            self::BHD => 'Bahraini Dinar',
            self::OMR => 'Omani Rial',
            self::QAR => 'Qatari Riyal',
            self::EGP => 'Egyptian Pound',
            self::JOD => 'Jordanian Dinar',
            self::TRY => 'Turkish Lira',
            self::MAD => 'Moroccan Dirham',
            self::JPY => 'Japanese Yen',
            self::INR => 'Indian Rupee',
            self::PKR => 'Pakistani Rupee',
            self::MYR => 'Malaysian Ringgit',
            self::SGD => 'Singapore Dollar',
        };
    }

    /**
     * Format an integer subunit amount into a human-readable decimal string.
     *
     * Examples:
     *   Currency::USD->format(1050) === '10.50'
     *   Currency::KWD->format(1050) === '1.050'
     *   Currency::JPY->format(1050) === '1050'
     *
     * @param int $subunitAmount Amount in smallest currency unit.
     *
     * @return string Decimal string representation.
     */
    public function format(int $subunitAmount): string
    {
        $exponent = $this->subunitExponent();

        if ($exponent === 0) {
            return (string) $subunitAmount;
        }

        $divisor = $this->subunitsPerUnit();
        $major   = intdiv($subunitAmount, $divisor);
        $minor   = abs($subunitAmount % $divisor);

        return sprintf('%d.%0' . $exponent . 'd', $major, $minor);
    }

    /**
     * Whether this currency is a zero-decimal currency (no subunit).
     *
     * Some payment providers (e.g., Stripe) require amounts for zero-decimal
     * currencies to be provided in the major unit, not subunit. Drivers can
     * use this to apply the correct conversion.
     *
     * @return bool
     */
    public function isZeroDecimal(): bool
    {
        return $this->subunitExponent() === 0;
    }

    /**
     * Currencies with 3 decimal places (high-precision).
     *
     * @return list<self>
     */
    public static function highPrecisionCurrencies(): array
    {
        return array_filter(
            self::cases(),
            static fn (self $c): bool => $c->subunitExponent() === 3,
        );
    }
}
