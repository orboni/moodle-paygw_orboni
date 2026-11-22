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

namespace paygw_orboni\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * What the gateway keeps about a buyer (its purchases, in the user's
 * context) and what it sends to Orboni.
 *
 * @package    paygw_orboni
 * @copyright  2026 Orboni <support@orboni.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_payment\privacy\paygw_provider,
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * The data the gateway keeps and sends.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('paygw_orboni', [
            'userid' => 'privacy:metadata:paygw_orboni:userid',
            'description' => 'privacy:metadata:paygw_orboni:description',
            'amount' => 'privacy:metadata:paygw_orboni:amount',
            'currency' => 'privacy:metadata:paygw_orboni:currency',
            'sessionid' => 'privacy:metadata:paygw_orboni:sessionid',
            'status' => 'privacy:metadata:paygw_orboni:status',
            'history' => 'privacy:metadata:paygw_orboni:history',
            'timecreated' => 'privacy:metadata:paygw_orboni:timecreated',
        ], 'privacy:metadata:paygw_orboni');

        $collection->add_external_location_link('orboni', [
            'email' => 'privacy:metadata:orboni:email',
            'description' => 'privacy:metadata:orboni:description',
            'amount' => 'privacy:metadata:orboni:amount',
            'locale' => 'privacy:metadata:orboni:locale',
        ], 'privacy:metadata:orboni');

        return $collection;
    }

    /**
     * The user's own context, when the user has purchases.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $contextlist->add_from_sql(
            'SELECT ctx.id FROM {context} ctx JOIN {paygw_orboni} p ON p.userid = ctx.instanceid
              WHERE ctx.contextlevel = :contextlevel AND p.userid = :userid',
            ['contextlevel' => CONTEXT_USER, 'userid' => $userid]
        );

        return $contextlist;
    }

    /**
     * The user whose context it is, when the user has purchases.
     *
     * @param userlist $userlist
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if ($context instanceof \context_user) {
            $userlist->add_from_sql(
                'userid',
                'SELECT userid FROM {paygw_orboni} WHERE userid = :userid',
                ['userid' => $context->instanceid]
            );
        }
    }

    /**
     * The user's purchases.
     *
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        $userid = (int) $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_user || (int) $context->instanceid !== $userid) {
                continue;
            }
            $purchases = [];
            foreach ($DB->get_records('paygw_orboni', ['userid' => $userid], 'id') as $row) {
                $purchases[] = self::export_row($row);
            }
            if ($purchases) {
                writer::with_context($context)->export_data(
                    [get_string('gatewayname', 'paygw_orboni')],
                    (object) ['purchases' => $purchases]
                );
            }
        }
    }

    /**
     * Deletes the purchases of the user whose context it is.
     *
     * @param \context $context
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;

        if ($context instanceof \context_user) {
            $DB->delete_records('paygw_orboni', ['userid' => $context->instanceid]);
        }
    }

    /**
     * Deletes the user's purchases.
     *
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;

        $userid = (int) $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context instanceof \context_user && (int) $context->instanceid === $userid) {
                $DB->delete_records('paygw_orboni', ['userid' => $userid]);
            }
        }
    }

    /**
     * Deletes the purchases of the users listed, in their own context.
     *
     * @param approved_userlist $userlist
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;

        $context = $userlist->get_context();
        $userids = array_map('intval', $userlist->get_userids());
        if ($context instanceof \context_user && in_array((int) $context->instanceid, $userids, true)) {
            $DB->delete_records('paygw_orboni', ['userid' => $context->instanceid]);
        }
    }

    /**
     * The purchase that made a payment, with the payment's export.
     *
     * @param \context $context Context
     * @param array $subcontext The location within the current context that the payment data belongs
     * @param \stdClass $payment The payment record
     */
    public static function export_payment_data(\context $context, array $subcontext, \stdClass $payment) {
        global $DB;

        $row = $DB->get_record('paygw_orboni', ['paymentid' => $payment->id]);
        if ($row) {
            $subcontext[] = get_string('gatewayname', 'paygw_orboni');
            writer::with_context($context)->export_data($subcontext, self::export_row($row));
        }
    }

    /**
     * Deletes the purchases that made the payments.
     *
     * @param string $paymentsql SQL query that selects payment.id field for the payments
     * @param array $paymentparams Array of parameters for $paymentsql
     */
    public static function delete_data_for_payment_sql(string $paymentsql, array $paymentparams) {
        global $DB;

        $DB->delete_records_select('paygw_orboni', "paymentid IN ({$paymentsql})", $paymentparams);
    }

    /**
     * One purchase as exported.
     *
     * @param \stdClass $row The purchase
     * @return \stdClass
     */
    private static function export_row(\stdClass $row): \stdClass {
        $history = json_decode((string) $row->history, true);

        return (object) [
            'description' => $row->description,
            'amount' => \paygw_orboni\currencies::format((int) $row->amount, $row->currency),
            'status' => $row->status,
            'sessionid' => $row->sessionid,
            'timecreated' => transform::datetime((int) $row->timecreated),
            'history' => array_map(static function ($line) {
                return transform::datetime((int) $line[0]) . ' ' . $line[1];
            }, is_array($history) ? $history : []),
        ];
    }
}
