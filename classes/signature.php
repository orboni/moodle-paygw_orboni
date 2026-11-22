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
 * `Webhook-Signature: t=<unix>,v1=<hex HMAC-SHA256 of "<t>.<body>">`, the
 * scheme Stripe uses. After a secret is rolled Orboni signs with both for a
 * while, so any `v1` may match.
 *
 * @package    paygw_orboni
 * @copyright  2026 Orboni <support@orboni.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class signature {
    /** @var int Seconds a signature stays good: an old event replayed later is refused. */
    public const TOLERANCE = 300;

    /**
     * Whether the header signs the body with the secret.
     *
     * @param string $secret The webhook signing secret
     * @param string $body The raw body
     * @param string $header The Webhook-Signature header
     * @param int|null $now The time to check against
     * @return bool
     */
    public static function verify(string $secret, string $body, string $header, ?int $now = null): bool {
        if ($secret === '' || $header === '') {
            return false;
        }

        $parts = [];
        foreach (explode(',', $header) as $pair) {
            $kv = explode('=', trim($pair, " \t"), 2);
            if (count($kv) === 2) {
                $parts[$kv[0]][] = $kv[1];
            }
        }

        $timestamp = isset($parts['t'][0]) ? (int) $parts['t'][0] : 0;
        $now = $now ?? time();
        if ($timestamp <= 0 || abs($now - $timestamp) > self::TOLERANCE) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $body, $secret);
        foreach ($parts['v1'] ?? [] as $candidate) {
            if (hash_equals($expected, (string) $candidate)) {
                return true;
            }
        }

        return false;
    }
}
