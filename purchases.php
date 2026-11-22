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
 * The purchases paid through Orboni, newest first, each with what happened
 * to it: for the people who see the site's payments.
 *
 * @package    paygw_orboni
 * @copyright  2026 Orboni <support@orboni.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../../config.php');

use paygw_orboni\checkout;
use paygw_orboni\currencies;
use paygw_orboni\purchases;

$id = optional_param('id', 0, PARAM_INT);
$page = optional_param('page', 0, PARAM_INT);
$perpage = 50;

$context = context_system::instance();
$url = new moodle_url('/payment/gateway/orboni/purchases.php', $id ? ['id' => $id] : []);
$PAGE->set_context($context);
$PAGE->set_url($url);
require_login(null, false);
require_capability('moodle/payment:viewpayments', $context);

$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('purchases', 'paygw_orboni'));
$PAGE->set_heading(get_string('purchases', 'paygw_orboni'));

$conditions = $id ? ['id' => $id] : [];
$total = $DB->count_records(purchases::TABLE, $conditions);
$ids = array_keys($DB->get_records_select(
    purchases::TABLE,
    $id ? 'id = :id' : '1 = 1',
    $conditions,
    'id DESC',
    'id',
    $page * $perpage,
    $perpage
));

$table = new html_table();
$table->head = [
    get_string('purchase', 'paygw_orboni'),
    get_string('user'),
    get_string('purchaseitem', 'paygw_orboni'),
    get_string('purchaseamount', 'paygw_orboni'),
    get_string('purchasestatus', 'paygw_orboni'),
    get_string('purchasehistory', 'paygw_orboni'),
];
$table->attributes['class'] = 'generaltable table-sm';
foreach ($ids as $rowid) {
    $row = purchases::get((int) $rowid);
    $user = core_user::get_user((int) $row->userid);
    $lines = array_map(static function ($line) {
        return html_writer::tag('li', html_writer::tag(
            'span',
            userdate((int) $line[0], get_string('strftimedatetimeshort', 'langconfig')),
            ['class' => 'text-muted']
        ) . ' ' . s((string) $line[1]));
    }, array_reverse($row->history));
    $table->data[] = [
        html_writer::link(new moodle_url('/payment/gateway/orboni/purchases.php', ['id' => $row->id]), '#' . $row->id)
            . html_writer::div(
                userdate((int) $row->timecreated, get_string('strftimedatetimeshort', 'langconfig')),
                'small text-muted'
            ),
        $user ? html_writer::link(new moodle_url('/user/profile.php', ['id' => $user->id]), fullname($user)) : '#' . $row->userid,
        s($row->description !== '' ? $row->description : $row->component),
        currencies::format((int) $row->amount, $row->currency),
        get_string('standing' . checkout::standing($row), 'paygw_orboni'),
        html_writer::tag('ul', implode('', $lines), ['class' => 'list-unstyled mb-0 small']),
    ];
}

echo $OUTPUT->header();
if (!$table->data) {
    echo $OUTPUT->notification(get_string('purchasesnone', 'paygw_orboni'), \core\output\notification::NOTIFY_INFO, false);
} else {
    echo html_writer::table($table);
    echo $OUTPUT->paging_bar($total, $page, $perpage, $url);
}
echo $OUTPUT->footer();
