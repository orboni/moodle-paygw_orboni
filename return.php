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
 * The buyer back from Orboni's payment page: the payment is read back once,
 * so access is given even before the webhook comes (or without one), then
 * the page of what was bought, or where the payment stands.
 *
 * @package    paygw_orboni
 * @copyright  2026 Orboni <support@orboni.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../../config.php');

use paygw_orboni\checkout;

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
$back = checkout::success_url($row);

if ($standing === 'paid') {
    redirect($back, get_string('returnpaid', 'paygw_orboni'), null, \core\output\notification::NOTIFY_SUCCESS);
}

$url = checkout::return_url($row, 'return');
$PAGE->set_url($url);
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('returnheading', 'paygw_orboni'));
$PAGE->set_heading(get_string('returnheading', 'paygw_orboni'));

$type = in_array($standing, ['closed', 'closedpartly', 'review'], true)
    ? \core\output\notification::NOTIFY_WARNING
    : \core\output\notification::NOTIFY_INFO;

echo $OUTPUT->header();
echo $OUTPUT->notification(get_string('return' . $standing, 'paygw_orboni'), $type, false);

$buttons = '';
$open = in_array($standing, ['open', 'partly', 'processing'], true) && $row->status === 'pending'
    && !empty($row->sessionurl) && (int) $row->expiresat > time();
if ($open) {
    $buttons .= $OUTPUT->single_button(
        new moodle_url($row->sessionurl),
        get_string('returnpaymentpage', 'paygw_orboni'),
        'get',
        ['type' => \core\output\single_button::BUTTON_PRIMARY]
    );
}
if (!in_array($standing, ['closed', 'closedpartly'], true)) {
    $buttons .= $OUTPUT->single_button($url, get_string('returncheckagain', 'paygw_orboni'), 'get');
}
$buttons .= $OUTPUT->single_button($back, get_string('returnback', 'paygw_orboni'), 'get');
echo html_writer::div($buttons, 'd-flex flex-wrap gap-2');

echo $OUTPUT->footer();
