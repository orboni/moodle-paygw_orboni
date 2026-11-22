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
 * Strings for the Orboni payment gateway.
 *
 * @package    paygw_orboni
 * @copyright  2026 Orboni <support@orboni.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['attentionfooter'] = 'Purchase #{$a->id}: {$a->item}, by {$a->buyer}. Its history is on the Orboni purchases page:';
$string['attentionheld'] = 'Orboni holds the payment for your review ({$a}). Accept it in the Orboni dashboard and access is given by itself; refuse it there and nothing is given.';
$string['attentionnotdelivered'] = 'The payment arrived and is recorded in Moodle, but the purchase could not be delivered: {$a}. Give access by hand (for a course: enrol the user).';
$string['attentionpaidtwice'] = 'The buyer paid a second time on another payment page ({$a->amount}, session {$a->session}). Send the extra back from your wallet if the buyer asks.';
$string['attentionpartly'] = 'The payment page ran out part paid: {$a->received} arrived of {$a->due}. Nothing was given; settle the rest with the buyer, then give access by hand.';
$string['attentionpricechanged'] = 'Paid {$a->paid}, but the item now costs {$a->due}: the price changed after the buyer was sent to pay. Nothing was given; give access by hand, or send the money back.';
$string['attentionreversed'] = 'The money that paid it left the chain: {$a} counts now. Access was already given and Moodle has no way to take it back: remove it by hand if the payment does not come back.';
$string['attentionsubject'] = 'Orboni: purchase #{$a->id} needs you';
$string['cancelled'] = 'You left the payment page and nothing was paid. You can choose another way to pay.';
$string['detailsat'] = 'Purchase {$a->number} at {$a->site}';
$string['errorcurrency'] = 'Orboni does not take prices in {$a}.';
$string['errorminimum'] = 'This price is below the least Orboni takes, which is worth $1. Please choose another way to pay.';
$string['errorminimumusd'] = 'Orboni takes payments of $1 or more. Please choose another way to pay.';
$string['errorreturn'] = 'This payment address is not yours, or its key is wrong.';
$string['errorsecretkey'] = 'This is not an Orboni secret key: it starts with sk_live_ or sk_test_.';
$string['errorunavailable'] = 'Paying with crypto is not available right now. Please try again in a few minutes, or choose another way to pay.';
$string['errorwebhooksecret'] = 'This is not a webhook signing secret: it starts with whsec_.';
$string['gatewaydescription'] = 'Crypto payments (USDT, USDC, BTC, ETH and more) straight to your own wallets, through Orboni.';
$string['gatewayname'] = 'Crypto (USDT, USDC, BTC, ETH and more)';
$string['messageprovider:attention'] = 'An Orboni payment that needs you';
$string['notecanceled'] = 'The buyer left the payment page with nothing paid: canceled.';
$string['notedelivered'] = 'Delivered.';
$string['noteexpired'] = 'The payment page ran out unpaid: closed.';
$string['noteextra'] = 'More money came after it was paid: {$a} in all. Send the extra back from your wallet if the buyer asks.';
$string['noteheld'] = 'Orboni holds the payment for review: {$a}. Nothing is given until it is accepted in the Orboni dashboard.';
$string['notelate'] = '(paid after the page ran out)';
$string['notenotdelivered'] = 'Paid, but could not be delivered: {$a}';
$string['notepaid'] = 'Paid with {$a}.';
$string['notepaidtwice'] = 'Paid again on another payment page: {$a->amount}, session {$a->session}.';
$string['notepartly'] = 'Part paid: {$a->received} of {$a->due}. The payment page asks for the rest.';
$string['notepartlyclosed'] = 'The payment page ran out part paid: {$a->received} of {$a->due}.';
$string['notepricechanged'] = 'Paid {$a->paid}, but the item now costs {$a->due}: not given.';
$string['noteprocessing'] = 'A payment is on the chain, not final yet.';
$string['noterefused'] = 'Orboni refused a payment page ({$a}).';
$string['notereversed'] = 'The money that paid it left the chain: {$a} counts now.';
$string['notesent'] = 'Sent to the payment page for {$a->amount} (session {$a->session}).';
$string['notetest'] = '(test payment)';
$string['on'] = 'on';
$string['pluginname'] = 'Orboni';
$string['pluginname_desc'] = 'Crypto payments through Orboni, straight to the wallets you set in the Orboni dashboard. Add the gateway to a payment account under Site administration → General → Payments → Payment accounts. The purchases paid through it: {$a}';
$string['privacy:metadata:orboni'] = 'The buyer goes on to Orboni\'s payment page; the site sends Orboni what is needed to make it.';
$string['privacy:metadata:orboni:amount'] = 'The price.';
$string['privacy:metadata:orboni:description'] = 'What is bought.';
$string['privacy:metadata:orboni:email'] = 'The buyer\'s email address, where Orboni sends the receipt when the site\'s Orboni project sends receipts.';
$string['privacy:metadata:orboni:locale'] = 'The buyer\'s language.';
$string['privacy:metadata:paygw_orboni'] = 'The purchases paid through Orboni.';
$string['privacy:metadata:paygw_orboni:amount'] = 'The price.';
$string['privacy:metadata:paygw_orboni:currency'] = 'The price\'s currency.';
$string['privacy:metadata:paygw_orboni:description'] = 'What is bought.';
$string['privacy:metadata:paygw_orboni:history'] = 'What happened to the purchase.';
$string['privacy:metadata:paygw_orboni:sessionid'] = 'Orboni\'s checkout session.';
$string['privacy:metadata:paygw_orboni:status'] = 'Whether it is paid.';
$string['privacy:metadata:paygw_orboni:timecreated'] = 'When the payment button was pressed.';
$string['privacy:metadata:paygw_orboni:userid'] = 'The buyer.';
$string['purchase'] = 'Purchase';
$string['purchaseamount'] = 'Price';
$string['purchasehistory'] = 'What happened';
$string['purchaseitem'] = 'Item';
$string['purchases'] = 'Orboni purchases';
$string['purchases_desc'] = 'The purchases paid through Orboni, with what happened to each';
$string['purchasesnone'] = 'No purchase has been paid through Orboni yet.';
$string['purchasestatus'] = 'Payment';
$string['redirecting'] = 'Taking you to the payment page…';
$string['returnback'] = 'Back';
$string['returncheckagain'] = 'Check the payment again';
$string['returnclosed'] = 'The payment page closed, and nothing was paid. You can start again with the payment button.';
$string['returnclosedpartly'] = 'The payment page ran out before the whole amount arrived. The site has been told and will settle the rest with you.';
$string['returnheading'] = 'Your payment';
$string['returnheld'] = 'Your payment arrived and is being reviewed. You get access as soon as it is accepted.';
$string['returnopen'] = 'The payment has not arrived yet. If you have sent it, it shows here within a few minutes; otherwise go back to the payment page.';
$string['returnpaid'] = 'Thank you, your payment has arrived.';
$string['returnpartly'] = 'Part of the payment has arrived. Go back to the payment page to send the rest.';
$string['returnpaymentpage'] = 'Back to the payment page';
$string['returnprocessing'] = 'Your payment is on its way. You get access once it is final, usually within minutes: check again in a moment.';
$string['returnreview'] = 'Your payment arrived. The site checks it before giving access, and has been told.';
$string['secretkey'] = 'Secret key';
$string['secretkey_help'] = 'A secret key from the Orboni dashboard, under Developers → API keys: sk_test_… to try it out, sk_live_… to be paid. The key decides test or live.';
$string['standingclosed'] = 'Closed, unpaid';
$string['standingclosedpartly'] = 'Closed, part paid';
$string['standingheld'] = 'Held for review';
$string['standingopen'] = 'Waiting';
$string['standingpaid'] = 'Paid';
$string['standingpartly'] = 'Part paid';
$string['standingprocessing'] = 'On its way';
$string['standingreview'] = 'Paid, price changed';
$string['taskcheckpayments'] = 'Read back Orboni payments still on their way';
$string['webhooksecret'] = 'Webhook signing secret';
$string['webhooksecret_help'] = 'Add the webhook address below as an endpoint in the Orboni dashboard, under Developers → Webhooks, with every event or at least the checkout.session ones, and paste its signing secret (whsec_…) here.';
$string['webhookurl'] = 'Webhook address';
$string['webhookurl_desc'] = 'Add it as an endpoint in the Orboni dashboard, under Developers → Webhooks. Without it a payment is still recorded when the buyer comes back, and Moodle\'s scheduled task reads back the rest every 15 minutes.';
