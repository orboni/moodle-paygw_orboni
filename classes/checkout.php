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

use core_payment\helper as payment_helper;

/**
 * Sending a buyer to Orboni's payment page and reading the payment back:
 * the payment button, the buyer's return, the webhook and the scheduled task
 * all come through here.
 *
 * @package    paygw_orboni
 * @copyright  2026 Orboni <support@orboni.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class checkout {
    /** @var int A page that runs out sooner than this is not given to the buyer again */
    public const REUSE_MIN_SECONDS = 300;

    /** @var int Seconds after a read before the scheduled task reads the same purchase again */
    public const RECHECK_SECONDS = 3000;

    /** @var int Purchases read back per run of the scheduled task */
    public const BATCH = 50;

    /**
     * The Orboni settings of a payment account: secretkey, webhooksecret and
     * whether the account and its Orboni gateway are on. Null when it has none.
     *
     * @param int $accountid The payment account
     * @return \stdClass|null
     */
    public static function config(int $accountid): ?\stdClass {
        global $DB;

        $gateway = $DB->get_record('payment_gateways', ['accountid' => $accountid, 'gateway' => 'orboni']);
        if (!$gateway) {
            return null;
        }
        $config = json_decode((string) $gateway->config, true);
        $config = is_array($config) ? $config : [];
        $account = $DB->get_record('payment_accounts', ['id' => $accountid], 'id, enabled, archived');

        return (object) [
            'accountid' => $accountid,
            'secretkey' => trim((string) ($config['secretkey'] ?? '')),
            'webhooksecret' => trim((string) ($config['webhooksecret'] ?? '')),
            'enabled' => $account && $account->enabled && !$account->archived && $gateway->enabled,
        ];
    }

    /**
     * Every payment account's Orboni settings that have a signing secret: a
     * webhook is checked against each.
     *
     * @return \stdClass[]
     */
    public static function webhook_configs(): array {
        global $DB;

        $configs = [];
        foreach ($DB->get_records('payment_gateways', ['gateway' => 'orboni'], 'id', 'id, accountid') as $gateway) {
            $config = self::config((int) $gateway->accountid);
            if ($config !== null && $config->webhooksecret !== '') {
                $configs[] = $config;
            }
        }

        return $configs;
    }

    /**
     * A key made from this site's own identifier, sent in each session's
     * metadata: two sites on one Orboni project never act on each other's events.
     *
     * @return string
     */
    public static function site_key(): string {
        return substr(hash_hmac('sha256', 'orboni-moodle-site', get_site_identifier()), 0, 16);
    }

    /**
     * The payment button pressed: the buyer's purchase of the item, and the
     * address of its payment page — the one still open, or a new one.
     *
     * @param string $component The component
     * @param string $paymentarea The payment area
     * @param int $itemid The item
     * @param string $description What is bought, as the page that sells it says
     * @return array ['url' => …] or ['error' => …]
     */
    public static function start(string $component, string $paymentarea, int $itemid, string $description): array {
        global $USER;

        $unavailable = get_string('errorunavailable', 'paygw_orboni');
        try {
            $payable = payment_helper::get_payable($component, $paymentarea, $itemid);
        } catch (\Throwable $e) {
            return ['error' => $unavailable];
        }
        $accountid = (int) $payable->get_account_id();
        $config = self::config($accountid);
        if (
            $config === null || !$config->enabled || $config->secretkey === ''
                || !in_array('orboni', payment_helper::get_available_gateways($component, $paymentarea, $itemid), true)
        ) {
            return ['error' => $unavailable];
        }

        $currency = strtoupper($payable->get_currency());
        if (!currencies::supports($currency)) {
            return ['error' => get_string('errorcurrency', 'paygw_orboni', $currency)];
        }
        $amount = self::due($component, $paymentarea, $itemid);
        if ($amount <= 0) {
            return ['error' => $unavailable];
        }
        if ($currency === 'USD' && $amount < currencies::MIN_USD_CENTS) {
            return ['error' => get_string('errorminimumusd', 'paygw_orboni')];
        }

        $row = purchases::waiting((int) $USER->id, $accountid, $component, $paymentarea, $itemid);
        if ($row === null) {
            $id = purchases::create([
                'accountid' => $accountid,
                'component' => $component,
                'paymentarea' => $paymentarea,
                'itemid' => $itemid,
                'userid' => (int) $USER->id,
                'description' => self::fit($description, 255),
                'amount' => $amount,
                'currency' => $currency,
            ]);
            $row = purchases::get($id);
        }
        $id = (int) $row->id;
        $api = new api($config->secretkey);

        // The button pressed again for the same price: the page still open is
        // reused, unless it has run out or is about to.
        if ($row->sessionid !== '' && (int) $row->amount === $amount && $row->currency === $currency) {
            try {
                $session = $api->get_session($row->sessionid);
                if (self::reusable($session)) {
                    return ['url' => (string) $session['url']];
                }
                // Something is on its way on it: the buyer is told where it stands, not sent to pay again.
                $payment = (string) ($session['payment_status'] ?? '');
                if (in_array($payment, ['processing', 'held', 'paid'], true)) {
                    return ['url' => self::return_url($row, 'return')->out(false)];
                }
            } catch (api_exception $e) {
                self::log(sprintf('Purchase %d: could not read session %s: %s', $id, $row->sessionid, $e->getMessage()));
            }
        }

        // The key is kept while a try fails, so a session Orboni made for a try
        // whose answer was lost is the one the next try gets.
        $attempt = (int) $row->attempt + 1;
        $row->amount = $amount;
        $row->currency = $currency;
        $body = self::session_body($row);

        try {
            try {
                $session = $api->create_session($body, self::idempotency_key($row, $attempt));
            } catch (api_exception $e) {
                // That lost try was sent with other details (the buyer's language):
                // Orboni refuses the key for them, and a new one is taken.
                if ($e->type !== 'idempotency_error' || $e->status !== 400) {
                    throw $e;
                }
                $attempt++;
                $session = $api->create_session($body, self::idempotency_key($row, $attempt));
            }
        } catch (api_exception $e) {
            self::log(sprintf('Purchase %d: Orboni refused a session (%s): %s', $id, $e->type, $e->getMessage()));
            purchases::save($id, ['attempt' => $attempt]);
            purchases::note($id, 'noterefused', $e->type);

            // Below the least Orboni takes: under a network's minimum, or worth under $1 in another currency.
            if ($e->type === 'amount_too_small' || ($e->type === 'invalid_request_error' && $e->param === 'amount')) {
                return ['error' => get_string('errorminimum', 'paygw_orboni')];
            }

            return ['error' => $unavailable];
        }

        if (!isset($session['id'], $session['url']) || !is_string($session['id']) || !is_string($session['url'])) {
            self::log(sprintf('Purchase %d: Orboni answered without a session id or url.', $id));

            return ['error' => $unavailable];
        }

        $sessions = $row->sessions;
        $sessions[] = $session['id'];
        $expires = (int) ($session['expires_at'] ?? 0) > 0 ? (int) $session['expires_at'] : time() + 3600;
        purchases::save($id, [
            'sessionid' => $session['id'],
            'sessionurl' => $session['url'],
            'sessions' => $sessions,
            'amount' => $amount,
            'currency' => $currency,
            'attempt' => $attempt,
            'expiresat' => $expires,
            'checkedat' => time() - self::RECHECK_SECONDS + 600,
        ]);
        purchases::note($id, 'notesent', (object) [
            'session' => $session['id'],
            'amount' => currencies::format($amount, $currency),
        ]);

        return ['url' => $session['url']];
    }

    /**
     * What the item costs now with the gateway's surcharge, in the currency's
     * smallest unit, as Moodle's own gateways round it.
     *
     * @param string $component The component
     * @param string $paymentarea The payment area
     * @param int $itemid The item
     * @return int
     */
    public static function due(string $component, string $paymentarea, int $itemid): int {
        $payable = payment_helper::get_payable($component, $paymentarea, $itemid);
        $currency = strtoupper($payable->get_currency());
        $cost = payment_helper::get_rounded_cost(
            (float) $payable->get_amount(),
            $currency,
            payment_helper::get_gateway_surcharge('orboni')
        );

        return currencies::minor($cost, $currency);
    }

    /**
     * The purchase's current session read back and applied, then the
     * purchase as it stands. Nothing is read once it is paid.
     *
     * @param \stdClass $row The purchase
     * @return \stdClass
     */
    public static function read_back(\stdClass $row): \stdClass {
        $id = (int) $row->id;
        $config = self::config((int) $row->accountid);
        if ($config !== null && $config->secretkey !== '' && $row->status !== 'paid' && $row->sessionid !== '') {
            try {
                $session = (new api($config->secretkey))->get_session($row->sessionid);
                (new sync())->apply_locked($id, $session);
            } catch (api_exception $e) {
                self::log(sprintf('Purchase %d: could not read session %s: %s', $id, $row->sessionid, $e->getMessage()));
            }
        }

        return purchases::get($id) ?? $row;
    }

    /**
     * Reads back the purchases still waiting, in case a webhook was lost, and
     * closes those whose page ran out unpaid.
     *
     * @return int How many were read
     */
    public static function read_open(): int {
        $read = 0;
        $sync = new sync();
        $apis = [];
        foreach (purchases::open(self::RECHECK_SECONDS, self::BATCH) as $id) {
            $row = purchases::get($id);
            if ($row === null) {
                continue;
            }
            purchases::save($id, ['checkedat' => time()]);
            $config = self::config((int) $row->accountid);
            if ($config === null || $config->secretkey === '') {
                continue;
            }
            $read++;
            $apis[$row->accountid] = $apis[$row->accountid] ?? new api($config->secretkey);

            try {
                $session = $apis[$row->accountid]->get_session($row->sessionid);
            } catch (api_exception $e) {
                self::log(sprintf(
                    'Purchase %d: the scheduled task could not read session %s: %s',
                    $id,
                    $row->sessionid,
                    $e->getMessage()
                ));
                continue;
            }
            $sync->apply_locked($id, $session);
        }

        return $read;
    }

    /**
     * Where the payment stands, as last read: paid, processing (on the chain),
     * partly (part paid, the page still open), held (for the site's review),
     * review (paid, but less than the item costs now), closedpartly (ran out
     * part paid), closed (ran out or left unpaid) or open.
     *
     * @param \stdClass $row The purchase
     * @return string
     */
    public static function standing(\stdClass $row): string {
        if ($row->status === 'paid') {
            return 'paid';
        }
        $parts = explode(':', (string) $row->seen);
        $status = $parts[1] ?? '';
        $payment = $parts[2] ?? '';
        if ($payment === 'processing') {
            return 'processing';
        }
        if ($payment === 'partially_paid') {
            return $status === 'expired' ? 'closedpartly' : 'partly';
        }
        if ($payment === 'held') {
            return 'held';
        }
        if ($status === 'complete' && $payment === 'paid') {
            return 'review';
        }
        if ($row->status === 'expired' || $row->status === 'canceled' || $status === 'expired') {
            return 'closed';
        }

        return 'open';
    }

    /**
     * One of the purchase's two return addresses, with its key.
     *
     * @param \stdClass $row The purchase
     * @param string $page return or cancel
     * @return \moodle_url
     */
    public static function return_url(\stdClass $row, string $page): \moodle_url {
        return new \moodle_url('/payment/gateway/orboni/' . $page . '.php', ['id' => $row->id, 'key' => $row->token]);
    }

    /**
     * The purchase the return address names, if its key is right and it is the signed-in user's.
     *
     * @param int $id The row id
     * @param string $key The key in the address
     * @return \stdClass|null
     */
    public static function returning(int $id, string $key): ?\stdClass {
        global $USER;

        $row = purchases::get($id);
        if ($row === null || $key === '' || !hash_equals((string) $row->token, $key) || (int) $row->userid !== (int) $USER->id) {
            return null;
        }

        return $row;
    }

    /**
     * Where the buyer is sent once done: the page of what was bought.
     *
     * @param \stdClass $row The purchase
     * @return \moodle_url
     */
    public static function success_url(\stdClass $row): \moodle_url {
        try {
            return payment_helper::get_success_url($row->component, $row->paymentarea, (int) $row->itemid);
        } catch (\Throwable $e) {
            return new \moodle_url('/');
        }
    }

    /**
     * The checkout session's parameters.
     *
     * @param \stdClass $row The purchase
     * @return array
     */
    private static function session_body(\stdClass $row): array {
        global $USER, $SITE;

        $email = (string) ($USER->email ?? '');
        $body = [
            'amount' => (int) $row->amount,
            'currency' => strtolower($row->currency),
            'description' => self::fit($row->description !== '' ? $row->description : $row->component, 250),
            'details' => self::fit(get_string('detailsat', 'paygw_orboni', (object) [
                'number' => $row->id,
                'site' => format_string($SITE->fullname, true, ['escape' => false, 'context' => \context_system::instance()]),
            ]), 500),
            'client_reference_id' => (string) $row->id,
            'customer_email' => validate_email($email) ? $email : null,
            'locale' => str_replace('_', '-', current_language()),
            'success_url' => self::return_url($row, 'return')->out(false),
            'cancel_url' => self::return_url($row, 'cancel')->out(false),
            'metadata' => [
                'moodle_purchase_id' => (string) $row->id,
                'moodle_item' => $row->component . '/' . $row->paymentarea . '/' . $row->itemid,
                'moodle_site' => self::site_key(),
            ],
        ];

        return array_filter($body, static function ($value) {
            return $value !== null && $value !== '';
        });
    }

    /**
     * The site, the purchase (its id and part of its own random token: an id
     * comes back after the plugin is installed again), its price and the try.
     *
     * @param \stdClass $row The purchase
     * @param int $attempt The try
     * @return string
     */
    private static function idempotency_key(\stdClass $row, int $attempt): string {
        return sprintf(
            'mdl-%s-%d-%s-%d%s-%d',
            self::site_key(),
            $row->id,
            substr((string) $row->token, 0, 8),
            $row->amount,
            strtolower($row->currency),
            $attempt
        );
    }

    /**
     * A session the buyer can still pay on: open, with its page, and more
     * than REUSE_MIN_SECONDS before it runs out.
     *
     * @param array $session The checkout session
     * @return bool
     */
    private static function reusable(array $session): bool {
        return ($session['status'] ?? '') === 'open' && is_string($session['url'] ?? null)
            && (int) ($session['expires_at'] ?? 0) > time() + self::REUSE_MIN_SECONDS;
    }

    /**
     * Text cut to a length, whole words where it can.
     *
     * @param string $text The text
     * @param int $max Characters at most
     * @return string
     */
    public static function fit(string $text, int $max): string {
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags($text)));

        return \core_text::strlen($text) > $max ? rtrim(\core_text::substr($text, 0, $max - 1)) . '…' : $text;
    }

    /**
     * A line in PHP's error log: what went wrong talking to Orboni. Never a key or a secret.
     *
     * @param string $message The line
     */
    public static function log(string $message): void {
        // phpcs:ignore moodle.PHP.ForbiddenFunctions.FoundWithAlternative
        error_log('paygw_orboni: ' . $message);
    }
}
