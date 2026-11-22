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
 * An Orboni checkout session, read back, onto its purchase. The one place a
 * purchase changes for a payment: the webhook, the buyer's return and the
 * scheduled task all come here with the session as Orboni shows it now, so
 * the purchase follows the payment whatever arrives first, twice or out of order.
 *
 * - complete + paid → Moodle's payment record and the item delivered (for a
 *   course: the enrolment), once;
 * - on its way (processing), short of the amount (partially_paid) or held for
 *   the site's review (held) → a line in the purchase's history;
 * - expired with nothing paid → the purchase closed, if it is still its
 *   current session;
 * - money that paid it left the chain (payment_reversed) → the site's
 *   administrators told: Moodle has no way to take a delivered item back.
 *
 * @package    paygw_orboni
 * @copyright  2026 Orboni <support@orboni.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sync {
    /** @var string The event of money that paid a session leaving the chain */
    public const REVERSED = 'checkout.session.payment_reversed';

    /** @var string The event of more money after a session was paid */
    public const EXTRA = 'checkout.session.extra_received';

    /** @var string[] Orboni's network keys as people write them; a network added later reads as its key */
    public const CHAIN_NAMES = [
        'tron' => 'TRON', 'ethereum' => 'Ethereum', 'bsc' => 'BNB Chain', 'solana' => 'Solana', 'ton' => 'TON',
        'polygon' => 'Polygon', 'base' => 'Base', 'arbitrum' => 'Arbitrum', 'optimism' => 'Optimism',
        'avalanche' => 'Avalanche', 'bitcoin' => 'Bitcoin', 'litecoin' => 'Litecoin', 'dogecoin' => 'Dogecoin',
        'bitcoincash' => 'Bitcoin Cash', 'xrp' => 'XRP Ledger', 'stellar' => 'Stellar',
    ];

    /**
     * apply() under a lock, with the purchase read fresh: the webhook and the
     * buyer's return can land in the same second.
     *
     * @param int $id The purchase
     * @param array $session The checkout session as Orboni shows it now
     * @param string $event The webhook's type, when a webhook brought it
     * @param string $eventid The webhook's id
     * @return bool|null true applied, false an event already taken (or no such purchase), null the purchase is busy
     */
    public function apply_locked(int $id, array $session, string $event = '', string $eventid = ''): ?bool {
        $factory = \core\lock\lock_config::get_lock_factory('paygw_orboni');
        $lock = $factory->get_lock('purchase_' . $id, 5);
        if (!$lock) {
            return null;
        }

        try {
            $row = purchases::get($id);
            if ($row === null) {
                return false;
            }
            if ($eventid !== '') {
                if (in_array($eventid, $row->events, true)) {
                    return false;
                }
                $events = $row->events;
                $events[] = $eventid;
                purchases::save($id, ['events' => array_slice($events, -purchases::EVENTS_KEPT)]);
            }

            $this->apply($row, $session, $event);

            return true;
        } finally {
            $lock->release();
        }
    }

    /**
     * This purchase's session: made for it by this site. The webhook checks
     * the same before reading anything back, and a session read back is
     * checked again here.
     *
     * @param \stdClass $row The purchase
     * @param array $session The checkout session
     * @return bool
     */
    public static function belongs(\stdClass $row, array $session): bool {
        $id = (string) ($session['id'] ?? '');
        $metadata = is_array($session['metadata'] ?? null) ? $session['metadata'] : [];

        return $id !== ''
            && in_array($id, $row->sessions, true)
            && (string) ($session['client_reference_id'] ?? '') === (string) $row->id
            && (string) ($metadata['moodle_site'] ?? '') === checkout::site_key();
    }

    /**
     * The session onto the purchase.
     *
     * @param \stdClass $row The purchase
     * @param array $session The checkout session
     * @param string $event The webhook's type
     */
    private function apply(\stdClass $row, array $session, string $event): void {
        $id = (int) $row->id;
        $sessionid = (string) ($session['id'] ?? '');
        if (!self::belongs($row, $session)) {
            checkout::log(sprintf('Purchase %d: session %s is not this purchase\'s; left alone.', $id, $sessionid));

            return;
        }

        $status = (string) ($session['status'] ?? '');
        $payment = (string) ($session['payment_status'] ?? '');
        $seen = $sessionid . ':' . $status . ':' . $payment;
        $paidhere = $status === 'complete' && $payment === 'paid';

        if ($row->paidsession !== '') {
            $this->after_paid($row, $session, $event, $paidhere, $seen);

            return;
        }

        if ($row->seen === $seen) {
            return;
        }
        purchases::save($id, ['seen' => $seen]);

        if ($paidhere) {
            $this->complete($row, $session);

            return;
        }

        switch ($payment) {
            case 'processing':
                purchases::note($id, 'noteprocessing');

                return;

            case 'partially_paid':
                purchases::note($id, $status === 'expired' ? 'notepartlyclosed' : 'notepartly', (object) [
                    'received' => currencies::money($session, 'amount_received'),
                    'due' => currencies::money($session, 'amount_due'),
                ]);
                if ($status === 'expired') {
                    $this->tell($row, 'attentionpartly', (object) [
                        'received' => currencies::money($session, 'amount_received'),
                        'due' => currencies::money($session, 'amount_due'),
                    ]);
                }

                return;

            case 'held':
                $reason = (string) ($session['review_reason'] ?? '—');
                purchases::note($id, 'noteheld', $reason);
                $this->tell($row, 'attentionheld', $reason);

                return;
        }

        // A newer session (the buyer pressed the button again) still counts.
        if ($status === 'expired' && $payment === 'unpaid' && $row->sessionid === $sessionid && $row->status === 'pending') {
            purchases::save($id, ['status' => 'expired']);
            purchases::note($id, 'noteexpired');
        }
    }

    /**
     * Paid in full: Moodle's payment record, then the item delivered.
     *
     * @param \stdClass $row The purchase
     * @param array $session The checkout session
     */
    private function complete(\stdClass $row, array $session): void {
        $id = (int) $row->id;
        $asked = (int) ($session['amount_total'] ?? 0);
        $askedin = strtoupper((string) ($session['currency'] ?? ''));
        try {
            $due = checkout::due($row->component, $row->paymentarea, (int) $row->itemid);
            $payable = payment_helper::get_payable($row->component, $row->paymentarea, (int) $row->itemid);
            $currency = strtoupper($payable->get_currency());
        } catch (\Throwable $e) {
            // The item is gone (a deleted course or enrolment method): nothing left to deliver.
            $due = $asked;
            $currency = $askedin;
        }

        // The price was raised (or its currency changed) after the buyer was sent to pay.
        if ($askedin !== $currency || $asked < $due) {
            $a = (object) ['paid' => currencies::format($asked, $askedin), 'due' => currencies::format($due, $currency)];
            purchases::note($id, 'notepricechanged', $a);
            $this->tell($row, 'attentionpricechanged', $a);

            return;
        }

        purchases::save($id, ['paidsession' => (string) $session['id']]);
        purchases::note($id, 'notepaid', $this->paid_line($session));

        if (!empty($row->paymentid)) {
            // Paid again after its payment was reversed: it was delivered the first time.
            purchases::save($id, ['status' => 'paid']);

            return;
        }

        try {
            $paymentid = payment_helper::save_payment(
                (int) $row->accountid,
                $row->component,
                $row->paymentarea,
                (int) $row->itemid,
                (int) $row->userid,
                currencies::major($asked, $askedin),
                $askedin,
                'orboni'
            );
            purchases::save($id, ['status' => 'paid', 'paymentid' => $paymentid]);
            payment_helper::deliver_order($row->component, $row->paymentarea, (int) $row->itemid, $paymentid, (int) $row->userid);
            purchases::note($id, 'notedelivered');
        } catch (\Throwable $e) {
            checkout::log(sprintf('Purchase %d: paid, but could not be delivered: %s', $id, $e->getMessage()));
            purchases::note($id, 'notenotdelivered', $e->getMessage());
            $this->tell($row, 'attentionnotdelivered', $e->getMessage());
        }
    }

    /**
     * A session of a purchase already paid.
     *
     * @param \stdClass $row The purchase
     * @param array $session The checkout session
     * @param string $event The webhook's type
     * @param bool $paidhere Whether this session is paid in full
     * @param string $seen session:status:payment_status
     */
    private function after_paid(\stdClass $row, array $session, string $event, bool $paidhere, string $seen): void {
        $id = (int) $row->id;
        $sessionid = (string) $session['id'];

        if ($row->paidsession === $sessionid) {
            if ($event === self::REVERSED && !$paidhere) {
                // Not paid any more: the session paid in full again marks it paid again.
                purchases::save($id, ['paidsession' => '', 'seen' => $seen, 'status' => 'pending']);
                $received = currencies::money($session, 'amount_received');
                purchases::note($id, 'notereversed', $received);
                $this->tell($row, 'attentionreversed', $received);
                checkout::log(sprintf('Purchase %d: payment reversed on session %s.', $id, $sessionid));
            } else if ($event === self::EXTRA) {
                purchases::note($id, 'noteextra', currencies::money($session, 'amount_received'));
            }

            return;
        }

        // Another of the purchase's sessions paid too: the buyer paid twice.
        if ($paidhere && $row->seen !== $seen) {
            purchases::save($id, ['seen' => $seen]);
            $a = (object) ['session' => $sessionid, 'amount' => currencies::money($session, 'amount_received')];
            purchases::note($id, 'notepaidtwice', $a);
            $this->tell($row, 'attentionpaidtwice', $a);
        }
    }

    /**
     * "479.65 DOGE on Dogecoin (24.00 EUR), session cs_test_…".
     *
     * @param array $session The checkout session
     * @return string
     */
    private function paid_line(array $session): string {
        $payment = is_array($session['payment'] ?? null) ? $session['payment'] : [];
        $line = currencies::money($session, 'amount_received');
        if (isset($payment['coin'], $payment['amount'])) {
            $chain = (string) ($payment['chain'] ?? '');
            $line = $payment['amount'] . ' ' . $payment['coin'] . ' ' . get_string('on', 'paygw_orboni') . ' '
                . (self::CHAIN_NAMES[$chain] ?? ucfirst($chain)) . ' (' . $line . ')';
        }
        $line .= ', ' . $session['id'];
        if (empty($session['livemode'])) {
            $line .= ' ' . get_string('notetest', 'paygw_orboni');
        }
        if (!empty($session['paid_late'])) {
            $line .= ' ' . get_string('notelate', 'paygw_orboni');
        }

        return $line;
    }

    /**
     * Tells the site's administrators about a purchase that needs a person:
     * a Moodle notification (email and the bell, by their preferences).
     *
     * @param \stdClass $row The purchase
     * @param string $identifier The string of what happened
     * @param mixed $a Its arguments
     */
    private function tell(\stdClass $row, string $identifier, $a = null): void {
        $buyer = \core_user::get_user((int) $row->userid);
        $what = (object) [
            'id' => $row->id,
            'item' => $row->description !== '' ? $row->description : $row->component,
            'buyer' => $buyer ? fullname($buyer) : '#' . $row->userid,
        ];
        $url = new \moodle_url('/payment/gateway/orboni/purchases.php', ['id' => $row->id]);

        foreach (get_admins() as $admin) {
            $message = new \core\message\message();
            $message->component = 'paygw_orboni';
            $message->name = 'attention';
            $message->userfrom = \core_user::get_noreply_user();
            $message->userto = $admin;
            $message->subject = get_string('attentionsubject', 'paygw_orboni', $what);
            $text = get_string($identifier, 'paygw_orboni', $a) . "\n\n" . get_string('attentionfooter', 'paygw_orboni', $what);
            $message->fullmessage = $text . "\n" . $url->out(false);
            $message->fullmessageformat = FORMAT_PLAIN;
            $message->fullmessagehtml = '';
            $message->smallmessage = $message->subject;
            $message->notification = 1;
            $message->contexturl = $url->out(false);
            $message->contexturlname = get_string('purchases', 'paygw_orboni');
            try {
                message_send($message);
            } catch (\Throwable $e) {
                checkout::log(sprintf('Purchase %d: could not notify an administrator: %s', $row->id, $e->getMessage()));
            }
        }
    }
}
