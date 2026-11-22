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

namespace paygw_orboni\task;

/**
 * Reads back the payments still on their way, in case a webhook was lost,
 * and closes purchases whose payment page ran out unpaid.
 *
 * @package    paygw_orboni
 * @copyright  2026 Orboni <support@orboni.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class check_payments extends \core\task\scheduled_task {
    /**
     * The task's name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('taskcheckpayments', 'paygw_orboni');
    }

    /**
     * Runs the task.
     */
    public function execute(): void {
        $read = \paygw_orboni\checkout::read_open();
        mtrace('Orboni: ' . $read . ' payment(s) read back.');
    }
}
