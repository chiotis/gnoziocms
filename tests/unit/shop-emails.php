<?php
/*
 * The shop's HTML emails and the Emails tab (docs/shop-plan.md, section 8): the mail's body as text alone or text and HTML
 * (multipart/alternative, the HTML in base64); each email built once and sent as both, in the shop's colour, logo and footer,
 * everything in it escaped; plain text only when HTML is off; a subject and text changed on the tab, per language, with its
 * placeholders, the theme's words otherwise; the settings kept tidy; the preview and the test.
 *   php tests/unit/shop-emails.php
 */
$repo = dirname(__DIR__, 2);
require $repo . '/vendor/autoload.php';
use GnozioCMS\{Mailer, SystemDatabase, SystemMetaRepository};
use GnozioCMS\Shop\{ShopMail, ShopSettings};
use GnozioCMS\Shop\Payments\BankTransfer;

$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . "\n"; }

$root = sys_get_temp_dir() . '/shopmail' . getmypid();
mkdir("$root/storage", 0775, true);
register_shutdown_function(function () use ($root) { @exec('rm -rf ' . escapeshellarg($root)); });
$db = new SystemDatabase("$root/storage");
$db->initialize();
$meta = new SystemMetaRepository($db);
$shop = new ShopSettings($meta);
$shop->saveGeneral(['store_name' => 'Aleon & Co', 'store_email' => 'orders@aleon.test', 'store_phone' => '+30 210 0000', 'order_prefix' => 'AL']);
$shop->savePayments(['bank_transfer' => ['enabled' => '1', 'accounts' => "Eurobank · GR16 0260 0000 · Aleon"]]);

// ---- the mail's body
$plain = Mailer::bodyPart("Hello\r\nthere", null);
check('text alone: one part', [$plain[0], end($plain)], ['Content-Type: text/plain; charset=UTF-8', "Hello\nthere"]);
$html = '<p>' . str_repeat('Γειά ', 400) . '</p>';
$both = Mailer::bodyPart('Hello', $html);
preg_match('/boundary="([^"]+)"/', $both[0], $b);
$joined = implode("\n", $both);
check('text and HTML: multipart/alternative, the text first', [str_starts_with($both[0], 'Content-Type: multipart/alternative'), strpos($joined, 'text/plain') < strpos($joined, 'text/html'), substr_count($joined, '--' . $b[1]), str_ends_with($joined, '--' . $b[1] . '--')], [true, true, 3, true]);
$encoded = substr($joined, strpos($joined, "base64\n\n") + 8);
$encoded = substr($encoded, 0, strpos($encoded, "\n--"));
check('the HTML in base64, no line longer than 76', [base64_decode(str_replace("\n", '', $encoded)) === $html, max(array_map('strlen', explode("\n", $encoded)))], [true, 76]);

// ---- the shop's emails
$sent = [];
$t = function (string $key, string $lang, array $values): string {
    $words = ['shop.mail_paid_subject' => '{store}: order {number} is paid', 'shop.mail_paid_text' => 'We received the payment for order {number}.', 'shop.mail_hello' => 'Hello {name},', 'shop.mail_view_button' => 'See your order', 'shop.mail_received_subject' => '{store}: we have your order {number}', 'shop.mail_received_text' => 'Thank you for your order {number}.', 'shop.bank_intro' => 'Send {total} with {number}:', 'shop.mail_downloads' => 'Your files:'];
    $text = $words[$key] ?? ($lang . ':' . $key);
    foreach ($values as $k => $v) {
        $text = str_replace('{' . $k . '}', $v, $text);
    }
    return $text;
};
$mail = new ShopMail($shop, fn(): array => ['title' => 'Site'], $t, function (string $to, string $subject, string $text, array $headers, ?string $html = null) use (&$sent): bool { $sent[] = compact('to', 'subject', 'text', 'headers', 'html'); return true; }, fn(string $id) => $id === 'bank_transfer' ? new BankTransfer() : null, fn(int $id): array => [['name' => 'guide.pdf', 'url' => 'https://aleon.test/_shop/download/1-abc', 'title' => 'The guide', 'usable' => true, 'max_downloads' => 3, 'expires_at' => 0]], 'https://aleon.test');
$order = ['id' => 7, 'number' => 'AL-2026-00007', 'created_at' => time(), 'email' => 'maria@example.com', 'lang' => 'el', 'items_cents' => 12400, 'discount_cents' => 0, 'shipping_cents' => 0, 'fee_cents' => 0, 'tax_cents' => 2400, 'total_cents' => 12400, 'coupon' => '', 'payment_method' => 'bank_transfer', 'shipping_label' => 'Courier', 'gift_note' => '<script>x</script>', 'gift_wrap' => 0, 'billing' => ['first_name' => 'Maria <b>', 'last_name' => 'Patera'], 'shipping' => ['first_name' => 'Maria', 'last_name' => 'Patera', 'address' => 'Ermou 1', 'city' => 'Athens', 'postcode' => '10563', 'country' => 'GR'], 'carrier' => '', 'tracking' => ''];
$lines = [['quantity' => 1, 'title' => 'Earrings "Balos"', 'variant' => 'Gold', 'total_cents' => 12400]];
$mail->orderPlaced($order, $lines, 'https://aleon.test/checkout/order/AL-2026-00007?key=k', 'https://aleon.test/admin/shop-orders?id=7');
[$customer, $notice] = $sent;
check('the customer\'s email: its subject, text and HTML', [$customer['to'], $customer['subject'], $customer['html'] !== null, str_contains($customer['text'], 'Thank you for your order AL-2026-00007.')], ['maria@example.com', 'Aleon & Co: we have your order AL-2026-00007', true, true]);
check('the text has the accounts, the files, the order and the link', [str_contains($customer['text'], 'GR16 0260'), str_contains($customer['text'], 'https://aleon.test/_shop/download/1-abc'), str_contains($customer['text'], 'Earrings "Balos" (Gold)'), str_contains($customer['text'], 'See your order' . "\n" . 'https://aleon.test/checkout/order/AL-2026-00007?key=k')], [true, true, true, true]);
$h = $customer['html'];
check('the HTML: the same, in a layout of its own', [str_starts_with($h, '<!doctype html>'), str_contains($h, 'GR16 0260'), str_contains($h, 'href="https://aleon.test/_shop/download/1-abc"'), str_contains($h, 'href="https://aleon.test/checkout/order/AL-2026-00007?key=k"'), str_contains($h, '#1f2937')], [true, true, true, true, true]);
check('everything in it escaped', [str_contains($h, '<script>'), str_contains($h, '&lt;script&gt;'), str_contains($h, 'Maria &lt;b&gt;'), str_contains($h, 'Aleon &amp; Co'), str_contains($h, 'Earrings &quot;Balos&quot;')], [false, true, true, true, true]);
check('the shop\'s notice, in English, replies to the customer', [$notice['to'], $notice['headers']['Reply-To'], str_starts_with($notice['subject'], 'New order AL-2026-00007'), $notice['html'] !== null], ['orders@aleon.test', 'maria@example.com', true, true]);
check('from the store, replies to the shop', [$customer['headers']['From'], $customer['headers']['Reply-To']], ['Aleon & Co <noreply@localhost>', 'orders@aleon.test']);

// ---- the Emails tab
$shop->saveEmails(['emails' => ['html' => '1', 'accent' => '#B4532A', 'logo' => 'javascript:alert(1)', 'footer' => "Ermou 1\r\nAthens", 'texts' => [
    'paid' => ['el' => ['subject' => 'Πληρώθηκε η {number} — {store}', 'text' => "Ευχαριστούμε, {name}!\nΕτοιμάζουμε την παραγγελία."], 'en' => ['subject' => '', 'text' => '']],
    'nonsense' => ['el' => ['subject' => 'x']],
    'shipped' => ['../x' => ['subject' => 'x']],
]]]);
$em = $shop->all()['emails'];
check('kept tidy: the colour, no unsafe logo, only known emails and languages, blanks dropped', [$em['accent'], $em['logo'], array_keys($em['texts']), array_keys($em['texts']['paid']), $em['footer']], ['#b4532a', '', ['paid'], ['el'], "Ermou 1\nAthens"]);
$sent = [];
$mail->statusChanged($order, $lines, 'paid', 'https://aleon.test/o');
check('a changed subject and text, with their placeholders', [$sent[0]['subject'], str_contains($sent[0]['text'], "Ευχαριστούμε, Maria <b> Patera!\nΕτοιμάζουμε την παραγγελία.")], ['Πληρώθηκε η AL-2026-00007 — Aleon & Co', true]);
check('in the colour, with the footer', [str_contains($sent[0]['html'], '#b4532a'), str_contains($sent[0]['html'], 'Ermou 1<br />'), str_contains($sent[0]['text'], "Ermou 1\nAthens")], [true, true, true]);
$english = $order;
$english['lang'] = 'en';
$sent = [];
$mail->statusChanged($english, $lines, 'paid', 'https://aleon.test/o');
check('another language keeps the theme\'s words', $sent[0]['subject'], 'Aleon & Co: order AL-2026-00007 is paid');
$shop->saveEmails(['emails' => ['logo' => '/uploads/media/logo.png']]);
$sent = [];
$mail->statusChanged($order, $lines, 'shipped', 'https://aleon.test/o');
check('HTML off: plain text only; a logo from Media made whole', [$sent[0]['html'], $shop->all()['emails']['logo'], $shop->all()['emails']['texts']], [null, '/uploads/media/logo.png', []]);
$shop->saveEmails(['emails' => ['html' => '1', 'logo' => '/uploads/media/logo.png']]);
$preview = $mail->preview('received', 'el');
check('the preview: a made-up order, the logo', [str_contains($preview['html'], 'AL-2026-00042'), str_contains($preview['html'], 'src="https://aleon.test/uploads/media/logo.png"'), str_contains($preview['text'], 'Balos earrings')], [true, true, true]);
check('a preview of every kind', array_map(fn(string $k): bool => str_starts_with($mail->preview($k, 'el')['html'], '<!doctype html>'), ShopMail::KINDS), array_fill(0, count(ShopMail::KINDS), true));
$sent = [];
check('the test goes to the address, marked as a test', [$mail->sendTest('sub_failed', 'el', 'me@aleon.test'), $sent[0]['to'], str_starts_with($sent[0]['subject'], '[Test] ')], [true, 'me@aleon.test', true]);
file_put_contents($argv[1] ?? '/dev/null', $preview['html']);

echo $fail === 0 ? "ALL PASSED\n" : "$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
