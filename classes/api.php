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
 * Orboni's API, as much of it as a site selling through Moodle needs: make a
 * checkout session, read one back. `Authorization: Bearer sk_…`, JSON both
 * ways, over Moodle's own HTTP client (its proxy settings and its list of
 * blocked hosts).
 *
 * @package    paygw_orboni
 * @copyright  2026 Orboni <support@orboni.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class api {
    /** @var string The plugin's version, sent in the User-Agent. */
    public const VERSION = '1.0.1';

    /** @var string The shape of a checkout session id. */
    public const SESSION_ID = '/^cs_(live|test)_[A-Za-z0-9]+$/';

    /** @var string The shape of a secret key. */
    public const SECRET_KEY = '/^sk_(live|test)_[A-Za-z0-9]+$/';

    /** @var string The shape of a webhook signing secret. */
    public const WEBHOOK_SECRET = '/^whsec_[A-Za-z0-9]+$/';

    /** @var int Seconds Orboni's own "updating, retry" answer may make a buyer wait, once. */
    public const MAX_RETRY_AFTER = 5;

    /** @var string */
    private $secret;

    /** @var string */
    private $base;

    /** @var bool Another Orboni for trying the plugin out, which may be on this machine. */
    private $local;

    /**
     * Constructor.
     *
     * @param string $secret The secret key
     */
    public function __construct(string $secret) {
        $this->secret = $secret;
        // Another Orboni only for trying the plugin out (ORBONI_API_URL in the web server's environment).
        $env = getenv('ORBONI_API_URL');
        $this->local = is_string($env) && $env !== '';
        $this->base = rtrim($this->local ? $env : 'https://orboni.com', '/');
    }

    /**
     * A new checkout session. The key makes a retry return the session the
     * first try made instead of a second one.
     *
     * @param array $body The session's parameters
     * @param string $idempotencykey The Idempotency-Key header
     * @return array The session
     * @throws api_exception
     */
    public function create_session(array $body, string $idempotencykey): array {
        return $this->request('POST', '/v1/checkout/sessions', $body, $idempotencykey);
    }

    /**
     * A checkout session as Orboni shows it now.
     *
     * @param string $id The session id
     * @return array The session
     * @throws api_exception
     */
    public function get_session(string $id): array {
        if (preg_match(self::SESSION_ID, $id) !== 1) {
            throw new api_exception('Not an Orboni checkout session id.', 'bad_session_id', 0);
        }

        return $this->request('GET', '/v1/checkout/sessions/' . rawurlencode($id));
    }

    /**
     * One call, tried twice when that is safe: a GET, or a POST under an idempotency key.
     *
     * @param string $method GET or POST
     * @param string $path The API path
     * @param array|null $body The JSON body
     * @param string|null $idempotencykey The Idempotency-Key header
     * @return array The answer's JSON
     * @throws api_exception
     */
    private function request(string $method, string $path, ?array $body = null, ?string $idempotencykey = null): array {
        global $CFG;

        $headers = [
            'Authorization: Bearer ' . $this->secret,
            'Accept: application/json',
            'User-Agent: orboni-moodle/' . self::VERSION . '; Moodle/' . ($CFG->release ?? '?'),
        ];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
        }
        if ($idempotencykey !== null) {
            $headers[] = 'Idempotency-Key: ' . $idempotencykey;
        }
        $retryable = $method === 'GET' || $idempotencykey !== null;

        $answer = null;
        for ($try = 0; $try < 2; $try++) {
            $answer = $this->send($method, $this->base . $path, $headers, $body === null ? null : (string) json_encode($body));
            if ($answer['status'] === 0) {
                if ($retryable && $try === 0) {
                    continue;
                }

                throw new api_exception('Orboni could not be reached: ' . $answer['error'], 'connection_error', 0);
            }
            // Orboni's own deploy: a few seconds' "503, retry after".
            if ($answer['status'] === 503 && $retryable && $try === 0) {
                sleep(min($answer['retryafter'] > 0 ? $answer['retryafter'] : 1, self::MAX_RETRY_AFTER));
                continue;
            }

            break;
        }

        return self::answer($answer);
    }

    /**
     * Sends one request through Moodle's curl class.
     *
     * @param string $method GET or POST
     * @param string $url The address
     * @param array $headers The headers, as "Name: value"
     * @param string|null $body The body
     * @return array status, retryafter, body and error
     */
    private function send(string $method, string $url, array $headers, ?string $body = null): array {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');

        // The local Orboni of a test is on a private address, which Moodle's list of blocked hosts refuses.
        $curl = new \curl(['ignoresecurity' => $this->local]);
        $curl->setHeader($headers);
        // No redirects followed: Orboni's API never sends one, and a key must not follow it elsewhere.
        $options = ['CURLOPT_FOLLOWLOCATION' => false, 'CURLOPT_TIMEOUT' => 20, 'CURLOPT_CONNECTTIMEOUT' => 10];
        $response = $method === 'POST' ? $curl->post($url, (string) $body, $options) : $curl->get($url, [], $options);

        if ($curl->get_errno()) {
            return ['status' => 0, 'retryafter' => 0, 'body' => '', 'error' => (string) $curl->error];
        }
        $info = $curl->get_info();
        $retryafter = 0;
        foreach ((array) $curl->getResponse() as $name => $value) {
            if (strtolower((string) $name) === 'retry-after') {
                $retryafter = (int) $value;
            }
        }

        return [
            'status' => (int) ($info['http_code'] ?? 0),
            'retryafter' => $retryafter,
            'body' => (string) $response,
            'error' => ($info['http_code'] ?? 0) ? '' : 'no answer',
        ];
    }

    /**
     * The answer's JSON, or the error Orboni gave.
     *
     * @param array|null $answer From send()
     * @return array The JSON
     * @throws api_exception
     */
    private static function answer(?array $answer = null): array {
        if ($answer === null) {
            throw new api_exception('Orboni could not be reached.', 'connection_error', 0);
        }
        $data = json_decode($answer['body'], true);
        if ($answer['status'] >= 200 && $answer['status'] < 300 && is_array($data)) {
            return $data;
        }

        $error = is_array($data) && isset($data['error']) && is_array($data['error']) ? $data['error'] : [];

        throw new api_exception(
            isset($error['message']) && is_string($error['message'])
                ? $error['message']
                : 'Orboni answered HTTP ' . $answer['status'] . '.',
            isset($error['type']) && is_string($error['type']) ? $error['type'] : 'http_' . $answer['status'],
            $answer['status'],
            isset($error['param']) && is_string($error['param']) ? $error['param'] : ''
        );
    }
}
