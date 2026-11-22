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
 * The Orboni payment gateway: the currencies it takes and its settings in a
 * payment account.
 *
 * @package    paygw_orboni
 * @copyright  2026 Orboni <support@orboni.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class gateway extends \core_payment\gateway {
    /**
     * The currencies Orboni takes a price in.
     *
     * @return string[]
     */
    public static function get_supported_currencies(): array {
        return array_keys(currencies::DECIMALS);
    }

    /**
     * The gateway's settings in a payment account.
     *
     * @param \core_payment\form\account_gateway $form
     */
    public static function add_configuration_to_gateway_form(\core_payment\form\account_gateway $form): void {
        $mform = $form->get_mform();

        $mform->addElement('passwordunmask', 'secretkey', get_string('secretkey', 'paygw_orboni'));
        $mform->setType('secretkey', PARAM_RAW_TRIMMED);
        $mform->addHelpButton('secretkey', 'secretkey', 'paygw_orboni');

        $mform->addElement('passwordunmask', 'webhooksecret', get_string('webhooksecret', 'paygw_orboni'));
        $mform->setType('webhooksecret', PARAM_RAW_TRIMMED);
        $mform->addHelpButton('webhooksecret', 'webhooksecret', 'paygw_orboni');

        $webhook = (new \moodle_url('/payment/gateway/orboni/webhook.php'))->out(false);
        $mform->addElement(
            'static',
            'webhookurl',
            get_string('webhookurl', 'paygw_orboni'),
            \html_writer::tag('code', s($webhook))
                . \html_writer::div(get_string('webhookurl_desc', 'paygw_orboni'), 'form-text text-muted')
        );

        $purchases = new \moodle_url('/payment/gateway/orboni/purchases.php');
        $mform->addElement(
            'static',
            'purchaseslink',
            get_string('purchases', 'paygw_orboni'),
            \html_writer::link($purchases, get_string('purchases_desc', 'paygw_orboni'))
        );
    }

    /**
     * The secret key is needed to switch the gateway on; each secret has its shape.
     *
     * @param \core_payment\form\account_gateway $form
     * @param \stdClass $data
     * @param array $files
     * @param array $errors form errors (passed by reference)
     */
    public static function validate_gateway_form(
        \core_payment\form\account_gateway $form,
        \stdClass $data,
        array $files,
        array &$errors
    ): void {
        $secretkey = trim((string) ($data->secretkey ?? ''));
        $webhooksecret = trim((string) ($data->webhooksecret ?? ''));

        if ($secretkey !== '' && preg_match(api::SECRET_KEY, $secretkey) !== 1) {
            $errors['secretkey'] = get_string('errorsecretkey', 'paygw_orboni');
        }
        if ($webhooksecret !== '' && preg_match(api::WEBHOOK_SECRET, $webhooksecret) !== 1) {
            $errors['webhooksecret'] = get_string('errorwebhooksecret', 'paygw_orboni');
        }
        if (!empty($data->enabled) && $secretkey === '') {
            $errors['enabled'] = get_string('gatewaycannotbeenabled', 'payment');
        }
    }
}
