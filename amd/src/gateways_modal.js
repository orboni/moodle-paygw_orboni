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
 * Orboni in the payment modal: the buyer goes on to Orboni's payment page,
 * through the site's own pay.php, which makes the checkout session.
 *
 * @module     paygw_orboni/gateways_modal
 * @copyright  2026 Orboni <support@orboni.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Config from 'core/config';
import Url from 'core/url';
import Modal from 'core/modal';
import {getString} from 'core/str';

/**
 * Process the payment.
 *
 * @param {string} component Name of the component that the itemId belongs to
 * @param {string} paymentArea The area of the component that the itemId belongs to
 * @param {number} itemId An internal identifier that is used by the component
 * @param {string} description Description of the payment
 * @returns {Promise<string>} Never settles: the page is left for the payment page.
 */
export const process = async(component, paymentArea, itemId, description) => {
    await Modal.create({
        body: await getString('redirecting', 'paygw_orboni'),
        show: true,
        removeOnClose: true,
    });

    window.location.href = Url.relativeUrl('/payment/gateway/orboni/pay.php', {
        component: component,
        paymentarea: paymentArea,
        itemid: itemId,
        description: description,
        sesskey: Config.sesskey,
    });

    return new Promise(() => null);
};
