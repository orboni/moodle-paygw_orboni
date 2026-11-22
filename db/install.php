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

/**
 * The gateway switched on in the site's list of payment gateways when it is
 * installed. It still needs to be set up and switched on in a payment account.
 *
 * @package    paygw_orboni
 * @copyright  2026 Orboni <support@orboni.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Switches the gateway on at installation.
 *
 * @return bool
 */
function xmldb_paygw_orboni_install() {
    global $CFG;

    // Written straight, as Moodle's own PayPal gateway does: enable_plugin() keeps only
    // gateways already recorded as installed, which this one is not until this returns.
    $order = !empty($CFG->paygw_plugins_sortorder) ? explode(',', $CFG->paygw_plugins_sortorder) : [];
    if (!in_array('orboni', $order, true)) {
        set_config('paygw_plugins_sortorder', implode(',', array_merge($order, ['orboni'])));
    }

    return true;
}
