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
 * Orboni said no, or did not answer (type `connection_error`, status 0).
 *
 * @package    paygw_orboni
 * @copyright  2026 Orboni <support@orboni.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class api_exception extends \moodle_exception {
    /** @var string Orboni's error type */
    public $type;

    /** @var int The HTTP status, 0 when nothing answered */
    public $status;

    /** @var string The parameter the error is about */
    public $param;

    /**
     * Constructor.
     *
     * @param string $message Orboni's message
     * @param string $type Orboni's error type
     * @param int $status The HTTP status
     * @param string $param The parameter
     */
    public function __construct(string $message, string $type, int $status, string $param = '') {
        parent::__construct('error', 'error', '', null, $message);
        $this->message = $message;
        $this->type = $type;
        $this->status = $status;
        $this->param = $param;
    }
}
