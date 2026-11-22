<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace paygw_orboni;

/**
 * The currencies Orboni takes a price in, and the decimals of each one's
 * smallest unit (ISO 4217): Orboni turns a price into dollars at the rate of
 * the day, the ECB's or the market's.
 *
 * @package    paygw_orboni
 * @copyright  2026 Orboni <support@orboni.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class currencies {
    /** @var int[] Decimals by currency */
    public const DECIMALS = [
        'USD' => 2, 'EUR' => 2, 'GBP' => 2, 'CHF' => 2, 'PLN' => 2, 'CZK' => 2,
        'HUF' => 2, 'RON' => 2, 'DKK' => 2, 'SEK' => 2, 'NOK' => 2, 'ISK' => 0,
        'TRY' => 2, 'JPY' => 0, 'KRW' => 0, 'CNY' => 2, 'HKD' => 2, 'SGD' => 2,
        'INR' => 2, 'IDR' => 2, 'MYR' => 2, 'PHP' => 2, 'THB' => 2, 'ILS' => 2,
        'AUD' => 2, 'NZD' => 2, 'CAD' => 2, 'MXN' => 2, 'BRL' => 2, 'ZAR' => 2,
        'UAH' => 2, 'ARS' => 2, 'CLP' => 0, 'AED' => 2, 'SAR' => 2, 'TWD' => 2,
        'VND' => 0,
    ];

    /** @var int Orboni's least payment is worth $1: checked here for a dollar price, by Orboni in any other. */
    public const MIN_USD_CENTS = 100;

    /**
     * Whether Orboni takes a price in it.
     *
     * @param string $currency ISO 4217 code
     * @return bool
     */
    public static function supports(string $currency): bool {
        return isset(self::DECIMALS[strtoupper($currency)]);
    }

    /**
     * Decimals of the currency's smallest unit.
     *
     * @param string $currency ISO 4217 code
     * @return int
     */
    public static function decimals(string $currency): int {
        return self::DECIMALS[strtoupper($currency)] ?? 2;
    }

    /**
     * A price in the currency's smallest unit, as Orboni takes it: 1250 for 12.50 EUR, 1500 for 1500 JPY.
     *
     * @param float $amount The price
     * @param string $currency ISO 4217 code
     * @return int
     */
    public static function minor(float $amount, string $currency): int {
        return (int) round($amount * (10 ** self::decimals($currency)));
    }

    /**
     * A price in the smallest unit as a decimal: 12.5 for 1250 euro cents.
     *
     * @param int $amount The smallest units
     * @param string $currency ISO 4217 code
     * @return float
     */
    public static function major(int $amount, string $currency): float {
        return $amount / (10 ** self::decimals($currency));
    }

    /**
     * "12.50 EUR" for 1250 euro cents, "1500 JPY" for 1500 yen.
     *
     * @param int $amount The smallest units
     * @param string $currency ISO 4217 code
     * @return string
     */
    public static function format(int $amount, string $currency): string {
        $decimals = self::decimals($currency);

        return number_format($amount / (10 ** $decimals), $decimals, '.', '') . ' ' . strtoupper($currency);
    }

    /**
     * One of a session's decimal amounts with its currency: "25.00 EUR", "1500 JPY".
     *
     * @param array $session The checkout session
     * @param string $field amount_received, amount_due…
     * @return string
     */
    public static function money(array $session, string $field): string {
        $currency = strtoupper((string) ($session['currency'] ?? 'usd'));

        return number_format((float) ($session[$field] ?? 0), self::decimals($currency), '.', '') . ' ' . $currency;
    }
}
