# Shop: the demo install checklist

The shop's code (`docs/shop-plan.md`, phases 1–7, the HTML emails and the WooCommerce import) was tested against fakes on one
machine. This list is what is checked once on a real install, on a public https domain, before a shop takes real money. Tick
each line; write down anything that differs (an answer, a status name, a field) and it is fixed in the code before going on.

**Keys** are typed only into Shop settings › Payments on the demo site, or in a terminal with `read -s`. Never in a chat, an
email or a ticket. Use the providers' **test/demo** accounts only.

## 0. The install

- [ ] A clean install of the latest release on a real domain, with https (a valid certificate, not self-signed).
- [ ] Admin › System is green: PHP 8.5, the extensions, writable folders.
- [ ] **Cron:** `*/5 * * * * php /path/to/site/src/cron-backup.php` (Admin › System › *Cron job* shows the exact line). After
      ten minutes the card says when it was last seen.
- [ ] **Email:** SMTP or SES set in Settings, and a test email received. The sender's domain has SPF and DKIM, so mail does not
      land in spam.
- [ ] Admin › Content types: **Products** on. The Shop group shows in the sidebar.
- [ ] Shop settings › General: store name, email, phone, address, order prefix (e.g. `DM`), guest checkout on.
- [ ] Shop settings › Shipping: one zone (Greece) with a flat rate and free over an amount; one pickup store.
- [ ] Shop settings › Accounts: as you want them.
- [ ] Terms and returns pages exist and are chosen in General.
- [ ] Products: one physical product with variants and stock (e.g. 3 in stock), one digital product with a file uploaded in
      its Downloads card, one subscription product (monthly).

## 1. Without a gateway (bank transfer, cash on delivery)

- [ ] Bank transfer on, with an IBAN. Order as a guest → the order is *on hold*, stock lowered, the received email shows the
      IBAN; the shop's notice arrives.
- [ ] Mark it paid in Shop › Orders → the paid email arrives.
- [ ] Mark it shipped with a carrier and tracking number → the shipped email shows them.
- [ ] Cancel another one → stock comes back, the coupon's use too.
- [ ] Cash on delivery with its fee → the fee is on the order and in the email.
- [ ] A coupon (percentage, then free shipping) is taken and counted.
- [ ] Orders CSV opens in a spreadsheet with the VAT columns right.
- [ ] Packing slip prints on one page.

## 2. Emails, looked at in real mail clients

- [ ] Shop settings › Emails: HTML on, the colour, the logo (from Media), the footer.
- [ ] *Send a test* of each kind to Gmail, Outlook (web and desktop if possible) and Apple Mail (iPhone).
- [ ] Each one: the logo shows, the button works, the Greek reads right, nothing is cut on a phone, dark mode is readable.
- [ ] Change one subject and text for Greek → the next email of that kind uses it; English keeps the theme's words.
- [ ] Turn HTML off → plain text only arrives.

## 3. Viva Wallet (demo account)

Setup:
- [ ] A Viva **demo** account (demo.vivapayments.com). Make a payment source for the site, as the Payments tab says, with its
      success and failure addresses; note its 4-digit code.
- [ ] Settings › API Access: the Smart Checkout Client ID and secret, the Merchant ID and API key.
- [ ] Shop settings › Payments › Viva: Test on, the keys, the source code. **Check connection** says the token and the webhook key work.
- [ ] Viva › Webhooks: add the address the tab shows for *Transaction Payment Created* and *Transaction Failed*. Viva's
      verification of the address succeeds (it asks the site for its key).

Payments:
- [ ] Pay with Viva's demo card → back on the order page, the order is *paid*, stock taken, the paid email arrives.
- [ ] The order's timeline shows the payment. Webhook calls are in the table `shop_gateway_events` (`webhook 1796` paid, `webhook 1798` failed).
- [ ] A refused card (Viva's demo card for a refusal) → back with a message, the order still waits, **Pay now** works.
- [ ] Leave Viva's page without paying (it has no cancel button) → the order waits; after the hold runs out (or set it to a few
      minutes) it is cancelled and the stock comes back.
- [ ] **Closed browser:** pay, then close the tab before coming back → the webhook alone makes the order paid.
- [ ] Installments: set `100 = 3` and order above €100 → Viva offers up to 3.
- [ ] Refund half from the order's screen → *partially refunded*, and Viva shows the refund. Refund the rest → *refunded*.
- [ ] Digital product: pay → the email has the download link; it counts down; after the limit it answers "used up".
- [ ] Refund the digital order → the link stops working.

Subscriptions (Viva recurring):
- [ ] Signed in, buy the subscription → it is *active* in Shop › Subscriptions and in the account.
- [ ] Let it come due (a product charged every week, and wait the week; there is no "charge now" button) and let cron
      run → a renewal order is made and **paid by Viva without the customer**; the renewed email arrives.
- [ ] A renewal Viva refuses (if the demo account lets you force one) → *past due*, the failed email, retried after a day.
- [ ] The customer pays a past-due one with **Pay now** → active again.
- [ ] The customer cancels from the account → cancelled, no more charges.

Write down: anything the API answered that the code did not expect (status, field, error).

## 4. Cardlink (test host)

Setup:
- [ ] A test Merchant ID and shared secret from the acquirer (Cardlink, Nexi/Alpha or Worldline/Eurobank). Ask them whether
      the shop's domain has to be allowed for the return addresses, and give them `/_shop/pay/cardlink/confirm` and
      `/_shop/pay/cardlink/cancel` if they do.
- [ ] Shop settings › Payments › Cardlink: the acquirer, Test on, the Merchant ID, the secret. **Check connection** answers
      "not found" (the keys work), not a digest or merchant error.

Payments:
- [ ] Pay with the acquirer's test card → the order is *paid*, the paid email arrives.
- [ ] Greek and English: the bank's page opens in the order's language.
- [ ] A refused test card → back with a message, the order waits, **Pay now** works (a new Cardlink order id each time).
- [ ] Cancel on the bank's page → back on the cancel address, the order waits.
- [ ] **Closed browser:** pay, close the tab before coming back. After the hold runs out, cron's status check finds the payment
      and the order is *paid* (the timeline says so). The paid email does not go by itself in this case: resend it from the order.
- [ ] Installments: `100 = 3`, an order above €100 → the site asks how many, the bank shows the plan.
- [ ] **Same day:** refund the whole of a payment → it is voided (a cancel), the order *refunded*.
- [ ] **Next day:** refund half of a settled payment → *partially refunded*; the back office shows it.

Write down: the field length the bank refused (if any), the status answer's shape, the refund and void answers.

## 5. Accounts and the site

- [ ] Sign up → the confirmation email; confirm; sign in; reset the password.
- [ ] A guest order with the same email joins the account once confirmed.
- [ ] Account › Delete my account → gone; its orders stay in Shop › Orders, unlinked.
- [ ] Wrong passwords several times → sign-in is slowed down.
- [ ] Product pages are still cached for visitors (no shop cookie on an ordinary page); the cart count updates in the header.
- [ ] Mobile: product page, cart, checkout and the bank's page are usable on a phone.
- [ ] Shop › Orders opened by an editor without `shop.orders` → refused; Shop settings only for `shop.manage`.

## 6. The WooCommerce import (on a copy)

On a **copy** of the demo site, never the live one:
- [ ] Read keys made in the WooCommerce shop (Settings › Advanced › REST API, *Read*).
- [ ] In a terminal: `read -s WC_KEY; read -s WC_SECRET; export WC_KEY WC_SECRET`, then
      `php scripts/import-woocommerce.php https://old-shop.example` (the dry run). Read the counts and `storage/import/report.txt`.
- [ ] With the customers' CSV: `--customers=users.csv`. Delete the CSV afterwards.
- [ ] `--apply --redirects=apply`. Then look: categories nested right, variants and prices, pictures, stock.
- [ ] An imported customer signs in with their **old** password.
- [ ] An old `/product/…` and `/product-category/…` address redirects to the new page.

## 6b. Meta and Instagram Shopping

- [ ] Shop settings › Marketing: **Publish the product feed** on; open the feed address: each variant is an item, prices and pictures right.
- [ ] Meta Business settings: the domain verified (its code in SEO › Ownership).
- [ ] Commerce Manager › Catalog › Data sources › **Scheduled feed** with the feed address, every hour. The catalogue shows the products with no errors.
- [ ] A Shop with checkout on the website, the Instagram business account connected, submitted for review.
- [ ] The Pixel ID and a Conversions API access token in Marketing. **Save and send a test event** with a test code: it shows in Events Manager › Test events.
- [ ] On the site, signed out: the banner asks; after *I agree*, Meta Pixel Helper shows PageView, ViewContent on a product, AddToCart, InitiateCheckout.
- [ ] A paid order: Purchase from the browser and from the server, counted once (deduplicated) in Events Manager; the order's timeline says it was sent.
- [ ] Empty the test code afterwards.
- [ ] The privacy policy names Meta's cookies and the Conversions API.

## 7. Before real money

- [ ] Everything above ticked, every difference fixed in the code and released.
- [ ] A security review of the shop code: checkout, the payment returns and webhook, download links, accounts.
- [ ] The accountant has seen the order export and the VAT.
- [ ] Switch each provider to its **live** keys and Test off; one small real payment and its refund.
- [ ] Backups on (Settings › Backup) and one restored on another folder.
