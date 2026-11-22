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
 * The payment page's back link: a purchase with nothing on its way is
 * canceled and the buyer is back where the payment button is, to pay
 * another way; with money on its way, the buyer is told where it stands.
 *
 * @package    paygw_orboni
 * @copyright  2026 Orboni <support@orboni.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../../config.php');

use paygw_orboni\checkout;
use paygw_orboni\purchases;

$id = required_param('id', PARAM_INT);
$key = required_param('key', PARAM_ALPHANUM);

require_login(null, false);
$PAGE->set_context(context_system::instance());

$row = checkout::returning($id, $key);
if ($row === null) {
    throw new moodle_exception('errorreturn', 'paygw_orboni');
}
$row = checkout::read_back($row);
$standing = checkout::standing($row);

if (!in_array($standing, ['open', 'closed'], true)) {
    redirect(checkout::return_url($row, 'return'));
}

if ($row->status === 'pending') {
    purchases::save((int) $row->id, ['status' => 'canceled']);
    purchases::note((int) $row->id, 'notecanceled');
}

redirect(checkout::success_url($row), get_string('cancelled', 'paygw_orboni'), null, \core\output\notification::NOTIFY_INFO);
