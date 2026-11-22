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
 * The payment button pressed: the buyer goes on to Orboni's payment page.
 *
 * @package    paygw_orboni
 * @copyright  2026 Orboni <support@orboni.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../../config.php');

$component = required_param('component', PARAM_COMPONENT);
$paymentarea = required_param('paymentarea', PARAM_AREA);
$itemid = required_param('itemid', PARAM_INT);
$description = optional_param('description', '', PARAM_TEXT);

require_login(null, false);
$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/payment/gateway/orboni/pay.php'));
if (isguestuser()) {
    redirect(get_login_url());
}
require_sesskey();

$result = \paygw_orboni\checkout::start($component, $paymentarea, $itemid, $description);
if (isset($result['url'])) {
    redirect($result['url']);
}

try {
    $back = \core_payment\helper::get_success_url($component, $paymentarea, $itemid);
} catch (\Throwable $e) {
    $back = new moodle_url('/');
}

$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('gatewayname', 'paygw_orboni'));
$PAGE->set_heading(get_string('gatewayname', 'paygw_orboni'));

echo $OUTPUT->header();
echo $OUTPUT->notification($result['error'], \core\output\notification::NOTIFY_ERROR);
echo $OUTPUT->continue_button($back);
echo $OUTPUT->footer();
