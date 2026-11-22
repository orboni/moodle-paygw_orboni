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
 * Orboni's webhook: a signed POST about a checkout session. The signature is
 * checked against each payment account's signing secret, the session is
 * read back with that account's key and applied. The answers: 200 taken or
 * not ours, 401 a signature that matches no secret, 400 not an event, 405
 * not a POST, 503 to retry later.
 *
 * @package    paygw_orboni
 * @copyright  2026 Orboni <support@orboni.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// phpcs:ignore moodle.Files.RequireLogin.Missing -- Orboni's servers call it; the signature stands for the login.
define('NO_MOODLE_COOKIES', true);
define('NO_DEBUG_DISPLAY', true);

require(__DIR__ . '/../../../config.php');

use paygw_orboni\api;
use paygw_orboni\api_exception;
use paygw_orboni\checkout;
use paygw_orboni\purchases;
use paygw_orboni\signature;
use paygw_orboni\sync;

/**
 * Ends the request with a status and a one-word result.
 *
 * @param int $code The HTTP status
 * @param string $result The result
 */
function paygw_orboni_webhook_answer(int $code, string $result): void {
    http_response_code($code);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode(['result' => $result]);
    die();
}

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    paygw_orboni_webhook_answer(405, 'post_only');
}

$configs = checkout::webhook_configs();
if ($configs === []) {
    paygw_orboni_webhook_answer(503, 'not_configured');
}

$body = (string) file_get_contents('php://input');
$header = (string) ($_SERVER['HTTP_WEBHOOK_SIGNATURE'] ?? '');
$signed = false;
foreach ($configs as $config) {
    if (signature::verify($config->webhooksecret, $body, $header)) {
        $signed = true;
        break;
    }
}
if (!$signed) {
    checkout::log('Webhook refused: the signature does not match any payment account\'s Orboni signing secret.');
    paygw_orboni_webhook_answer(401, 'bad_signature');
}

$event = json_decode($body, true);
if (!is_array($event) || !is_string($event['type'] ?? null) || !is_array($event['data']['object'] ?? null)) {
    paygw_orboni_webhook_answer(400, 'bad_event');
}
$type = $event['type'];
$object = $event['data']['object'];
if (strpos($type, 'checkout.session.') !== 0) {
    paygw_orboni_webhook_answer(200, 'ignored');
}

$sessionid = (string) ($object['id'] ?? '');
$reference = (string) ($object['client_reference_id'] ?? '');
$metadata = is_array($object['metadata'] ?? null) ? $object['metadata'] : [];
if (
    preg_match(api::SESSION_ID, $sessionid) !== 1 || !ctype_digit($reference)
        || (string) ($metadata['moodle_site'] ?? '') !== checkout::site_key()
) {
    // Another site's, or a payment link's: not ours to act on.
    paygw_orboni_webhook_answer(200, 'ignored');
}

$row = purchases::get((int) $reference);
$config = $row !== null ? checkout::config((int) $row->accountid) : null;
if ($row === null || !in_array($sessionid, $row->sessions, true) || $config === null || $config->secretkey === '') {
    paygw_orboni_webhook_answer(200, 'ignored');
}

try {
    $session = (new api($config->secretkey))->get_session($sessionid);
} catch (api_exception $e) {
    checkout::log(sprintf(
        'Webhook %s, purchase %d: could not read session %s back: %s',
        $type,
        $row->id,
        $sessionid,
        $e->getMessage()
    ));
    paygw_orboni_webhook_answer(503, 'retry_later');
}

$taken = (new sync())->apply_locked((int) $row->id, $session, $type, (string) ($event['id'] ?? ''));
if ($taken === null) {
    paygw_orboni_webhook_answer(503, 'busy');
}
purchases::save((int) $row->id, ['checkedat' => time()]);

paygw_orboni_webhook_answer(200, $taken ? 'received' : 'duplicate');
