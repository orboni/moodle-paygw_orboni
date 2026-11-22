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
 * The plugin's table: one row per purchase paid through Orboni — what is
 * bought and by whom, every checkout session made for it, what was last
 * applied and a history of what happened. The lists are JSON in text fields.
 *
 * @package    paygw_orboni
 * @copyright  2026 Orboni <support@orboni.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class purchases {
    /** @var string The table */
    public const TABLE = 'paygw_orboni';

    /** @var int Lines of history kept per purchase */
    public const HISTORY_KEPT = 50;

    /** @var int Days a purchase still waiting is read back by the scheduled task; the webhook still reaches it after */
    public const WATCHED_DAYS = 14;

    /** @var int Webhook event ids remembered per purchase, so a retry or a replay adds nothing twice */
    public const EVENTS_KEPT = 30;

    /**
     * One purchase, its lists decoded, or null.
     *
     * @param int $id The row id
     * @return \stdClass|null
     */
    public static function get(int $id): ?\stdClass {
        global $DB;

        $row = $DB->get_record(self::TABLE, ['id' => $id]);
        if (!$row) {
            return null;
        }
        foreach (['sessions', 'events', 'history'] as $field) {
            $list = json_decode((string) $row->$field, true);
            $row->$field = is_array($list) ? $list : [];
        }

        return $row;
    }

    /**
     * Writes the given fields of a purchase.
     *
     * @param int $id The row id
     * @param array $fields Field => value; the lists as arrays
     */
    public static function save(int $id, array $fields): void {
        global $DB;

        foreach (['sessions', 'events', 'history'] as $field) {
            if (array_key_exists($field, $fields)) {
                $fields[$field] = json_encode(array_values($fields[$field]));
            }
        }
        $fields['id'] = $id;
        $fields['timemodified'] = time();
        $DB->update_record(self::TABLE, (object) $fields);
    }

    /**
     * A new purchase, waiting for its first session.
     *
     * @param array $fields accountid, component, paymentarea, itemid, userid, description, amount, currency
     * @return int The row id
     */
    public static function create(array $fields): int {
        global $DB;

        $now = time();

        return (int) $DB->insert_record(self::TABLE, (object) ($fields + [
            'status' => 'pending',
            'token' => bin2hex(random_bytes(16)),
            'sessions' => '[]',
            'events' => '[]',
            'history' => '[]',
            'timecreated' => $now,
            'timemodified' => $now,
        ]));
    }

    /**
     * The user's purchase of this item still waiting to be paid, if any: the
     * payment button pressed again goes on with it.
     *
     * @param int $userid The buyer
     * @param int $accountid The payment account
     * @param string $component The component
     * @param string $paymentarea The payment area
     * @param int $itemid The item
     * @return \stdClass|null
     */
    public static function waiting(int $userid, int $accountid, string $component, string $paymentarea, int $itemid): ?\stdClass {
        global $DB;

        $rows = $DB->get_records(self::TABLE, [
            'userid' => $userid,
            'accountid' => $accountid,
            'component' => $component,
            'paymentarea' => $paymentarea,
            'itemid' => $itemid,
            'status' => 'pending',
        ], 'id DESC', 'id', 0, 1);

        return $rows ? self::get((int) reset($rows)->id) : null;
    }

    /**
     * Purchases waiting with a session to read back: not read for a while,
     * or whose page has run out since it was last read; for two weeks after
     * they were made.
     *
     * @param int $recheck Seconds between two reads of one purchase
     * @param int $limit How many
     * @return int[] Row ids, the least recently read first
     */
    public static function open(int $recheck, int $limit): array {
        global $DB;

        $now = time();
        $rows = $DB->get_records_select(
            self::TABLE,
            "status = 'pending' AND sessionid <> '' AND timecreated > :since"
                . " AND (checkedat < :due OR (expiresat > 0 AND expiresat < :now AND checkedat < expiresat))",
            ['since' => $now - self::WATCHED_DAYS * DAYSECS, 'due' => $now - $recheck, 'now' => $now],
            'checkedat ASC, id ASC',
            'id',
            0,
            $limit
        );

        return array_map('intval', array_keys($rows));
    }

    /**
     * Adds a line to a purchase's history, in the site's language.
     *
     * @param int $id The row id
     * @param string $identifier The string's identifier in the plugin's language file
     * @param mixed $a The string's arguments
     */
    public static function note(int $id, string $identifier, $a = null): void {
        global $CFG;

        $row = self::get($id);
        if ($row === null) {
            return;
        }
        $line = get_string_manager()->get_string($identifier, 'paygw_orboni', $a, $CFG->lang ?? 'en');
        $history = $row->history;
        $history[] = [time(), $line];
        self::save($id, ['history' => array_slice($history, -self::HISTORY_KEPT)]);
    }
}
