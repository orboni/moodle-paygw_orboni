# Orboni for Moodle

A Moodle payment gateway for crypto payments through [Orboni](https://orboni.com). Learners pay with USDT, USDC, BTC, ETH and other coins on Orboni's payment page, and the money goes straight to your own wallets. Orboni never holds it.

- Moodle 4.5 LTS to 5.3, PHP 8.1 or later.
- Works wherever Moodle takes payments: course fees ("Enrolment on payment"), and any plugin built on Moodle's payment API.
- Prices in USD, EUR, GBP and 34 other currencies. Orboni turns them into coins at the rate of the day.
- A purchase is delivered (the learner enrolled) once the payment is final. That happens on a signed webhook, when the learner comes back to your site, or when Moodle's scheduled task runs, whichever comes first.

## What it needs

Orboni is an external service: the plugin shows nothing to pay with until a payment account has an Orboni secret key. Opening an Orboni account is free, and test payments (a `sk_test_` key) cost nothing. On live payments Orboni takes 0.5% of each completed payment, invoiced once a month, as a card processor takes its fee; there is no subscription, no setup fee and nothing to buy in the plugin itself. The plugin is free, under the GPL. Current pricing: [orboni.com/pricing](https://orboni.com/pricing).

## Install

1. Download `paygw_orboni.zip` from the [latest release](https://github.com/orboni/moodle-paygw_orboni/releases/latest), or install it from the Moodle plugins directory.
2. In Moodle, go to **Site administration → Plugins → Install plugins** and upload it. You can also unzip it into `payment/gateway/orboni` (`public/payment/gateway/orboni` on Moodle 5.1 and later) and visit **Site administration → Notifications**. Moodle switches the gateway on in **Site administration → Plugins → Payment gateways → Manage payment gateways**.
3. In **Site administration → General → Payments → Payment accounts**, open a payment account (or create one), and press **Orboni** in its payment gateways column.
4. Paste your **Secret key** from the Orboni dashboard (Developers → API keys). Use `sk_test_…` to try it out and `sk_live_…` for real payments.
5. In the Orboni dashboard, under **Developers → Webhooks**, add an endpoint with the **Webhook address** the form shows. Paste its signing secret (`whsec_…`) into **Webhook signing secret**, tick **Enable** and save.
6. Use the payment account where you sell. For a course fee: enable **Enrolment on payment** under **Site administration → Plugins → Enrolments → Manage enrol plugins**, then in the course go to **Participants → Enrolment methods → Add method → Enrolment on payment**, and choose the account, the fee and the currency.

The webhook is recommended but not required. Without it, a payment is still recorded when the learner comes back from the payment page, and Moodle's scheduled task (**Read back Orboni payments still on their way**, every 15 minutes) reads back the rest. Keep Moodle's cron running, as every Moodle site should.

## How a purchase moves

- The learner presses **Select payment type**, chooses **Crypto (USDT, USDC, BTC, ETH and more)** and goes to Orboni's payment page. Pressing it again while the page is open opens the same page.
- Paid in full and confirmed on the chain: Moodle records the payment and delivers the purchase (the learner is enrolled), as with any gateway.
- A payment on its way or part paid: the learner is told so when they come back, with the way back to the payment page. Nothing is delivered yet.
- Held for your review in the Orboni dashboard, a page that ran out part paid, a price raised after the learner was sent to pay, money that left the chain after delivery, a second payment for the same purchase: nothing more is done by itself, and Moodle's site administrators get a notification (**An Orboni payment that needs you**, in their notification preferences) with a link to the purchase.
- The payment page ran out unpaid, or the learner left it through its back link: the purchase is closed. The next press of the button starts a new one. A payment that still arrives for a closed page is recorded and delivered.

**Site administration → Plugins → Payment gateways → Orboni** links to **Orboni purchases**: every purchase, its price, where it stands and what happened to it. The same page sets the gateway's surcharge, as for any Moodle gateway. Orboni takes payments worth $1 or more; a smaller price is refused with a sentence before anything is made.

## Privacy

The plugin keeps, for each purchase, the learner, the item, the price and what happened to it. It sends Orboni the learner's email address, the item's description, the price and the learner's language, to show the payment page and the receipt. Both are declared to Moodle's privacy API, and exported and deleted with the learner's data.

## Support

support@orboni.com · [orboni.com/docs/moodle](https://orboni.com/docs/moodle)

## License

GNU General Public License version 3 or later (see `LICENSE`), as Moodle itself.
