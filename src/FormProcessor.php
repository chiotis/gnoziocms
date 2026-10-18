<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * What happens to a form someone fills in on the site: the values a form starts with, checking what was sent against
 * the fields (required, email, address, number, allowed options), the record kept of a submission, and the emails it
 * causes (a notification for the site, an automatic reply for the visitor, with the reply-to that can be worked out).
 * Sending, storing, the session, and the redirect are the caller's.
 */
final class FormProcessor
{
    /** @param callable(string, string): string $translate a theme string: key, fallback */
    public function __construct(private $translate)
    {
    }

    /**
     * The values a form shows before anything was sent.
     *
     * @param array<int, array<string, mixed>> $fields
     * @return array<string, mixed>
     */
    public function defaults(array $fields): array
    {
        $values = [];
        foreach ($fields as $field) {
            $name = (string)($field['name'] ?? '');
            $type = (string)($field['type'] ?? '');
            if ($name === '' || FormFields::isDisplay($type)) {
                continue;
            }
            $default = $field['default'] ?? '';
            if ($type === 'file') {
                $values[$name] = [];
            } elseif ($type === 'checkboxes') {
                $values[$name] = is_array($default) ? $default : FormFields::parseOptions((string)$default);
            } elseif ($type === 'checkbox') {
                $values[$name] = Format::isTruthy($default) ? '1' : '';
            } else {
                $values[$name] = $default;
            }
        }
        return $values;
    }

    /**
     * The values sent, cleaned, and what is wrong with them (field name => message). Options that are not on the
     * list are dropped from checkboxes and refused for a select or radio.
     *
     * @param array<int, array<string, mixed>> $fields
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $files what was sent as files ($_FILES); a file field is checked by `$uploads`
     * @return array{values: array<string, mixed>, errors: array<string, string>, uploads: array<string, array<int, array{tmp: string, original: string, ext: string, mime: string, size: int}>>} uploads: the files that are fine, by field
     */
    public function collect(array $fields, array $payload, array $files = [], ?FormUploads $uploads = null): array
    {
        $values = [];
        $errors = [];
        $accepted = [];
        $totalBytes = 0;
        $required = fn(): string => ($this->translate)('form.error.required', 'This field is required.');
        foreach ($fields as $field) {
            $name = (string)($field['name'] ?? '');
            $type = (string)($field['type'] ?? 'text');
            if ($name === '' || FormFields::isDisplay($type)) {
                continue;
            }
            $isRequired = (bool)($field['required'] ?? false);
            if ($type === 'file') {
                $values[$name] = [];
                if ($uploads === null) {
                    continue;
                }
                $checked = $uploads->check($field, FormUploads::normalizeFiles($files[$name] ?? null), $this->translate);
                if ($checked['error'] !== '') {
                    $errors[$name] = $checked['error'];
                } elseif ($checked['accepted'] !== []) {
                    $accepted[$name] = $checked['accepted'];
                    $totalBytes += array_sum(array_column($checked['accepted'], 'size'));
                }
                continue;
            }
            $options = $field['options'] ?? [];
            $optionValues = array_map(static fn($option) => $option['value'], is_array($options) ? $options : []);

            if ($type === 'checkboxes') {
                $raw = $payload[$name] ?? [];
                $clean = [];
                foreach (is_array($raw) ? $raw : [] as $value) {
                    $value = trim((string)$value);
                    if ($value === '' || (!empty($optionValues) && !in_array($value, $optionValues, true))) {
                        continue;
                    }
                    $clean[] = $value;
                }
                $values[$name] = $clean;
                if ($isRequired && empty($clean)) {
                    $errors[$name] = $required();
                }
                continue;
            }

            if ($type === 'checkbox') {
                $checked = isset($payload[$name]) && (string)$payload[$name] !== '';
                $values[$name] = $checked ? '1' : '';
                if ($isRequired && !$checked) {
                    $errors[$name] = $required();
                }
                continue;
            }

            $value = trim((string)($payload[$name] ?? ''));
            if ($type === 'email' && $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                $errors[$name] = ($this->translate)('form.error.email', 'Please enter a valid email.');
            }
            if ($type === 'url' && $value !== '' && !filter_var($value, FILTER_VALIDATE_URL)) {
                $errors[$name] = ($this->translate)('form.error.url', 'Please enter a valid URL.');
            }
            if (in_array($type, ['number', 'range'], true) && $value !== '' && !is_numeric($value)) {
                $errors[$name] = ($this->translate)('form.error.numeric', 'Please enter a numeric value.');
            }
            if (in_array($type, ['select', 'radio'], true) && $value !== '' && !empty($optionValues) && !in_array($value, $optionValues, true)) {
                $errors[$name] = ($this->translate)('form.error.option', 'Please select a valid option.');
            }
            if ($isRequired && $value === '') {
                $errors[$name] = $required();
            }
            $values[$name] = $value;
        }
        if ($errors === [] && $accepted !== [] && $uploads !== null && !$uploads->hasRoom($totalBytes)) {
            $errors[array_key_first($accepted)] = ($this->translate)('form.error.file_storage', 'The files could not be saved: there is no room left on this site. Please contact the site owner.');
        }
        if ($errors !== [] && $uploads !== null) {
            // A refused submission keeps nothing: the temporary files go now.
            $uploads->discard($accepted);
            $accepted = [];
        }
        return ['values' => $values, 'errors' => $errors, 'uploads' => $accepted];
    }

    /**
     * The record kept of a submission.
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    public function record(ContentItem $form, array $values, string $ip, string $userAgent, array $files = []): array
    {
        $record = [
            'form' => $form->slug,
            'lang' => $form->lang,
            'translation_id' => (string)($form->meta['translation_id'] ?? ''),
            'submitted_at' => date('c'),
            'ip' => $ip,
            'user_agent' => $userAgent,
            'fields' => $values,
        ];
        if ($files !== []) {
            // The names of the files kept with the submission (their folder is storage/form-uploads/<form>/<id>/).
            $record['files'] = $files;
        }
        return $record;
    }

    /**
     * The emails a submission causes: one for the site (when the form has notifications on and somewhere to send
     * them) and one automatic reply to the visitor (when it is on and the visitor gave an email).
     *
     * @param array<string, mixed> $values
     * @param array<int, array<string, mixed>> $fields
     * @param array<string, mixed> $siteMail the site's mail settings (forms.notifications): from, from_name
     * @param array{files?: array<string, array<int, array{name: string, original?: string, size?: int, mime?: string}>>, urls?: array<string, string>, paths?: array<string, string>, zip?: string} $upload the files kept with the submission: their data, an admin address for each (by name), their paths, and the address of all of them as a ZIP
     * @return array<int, array{to: string, subject: string, body: string, headers: array<string, string>, attachments: array<int, array{path: string, name: string, mime: string}>}>
     */
    public function emails(ContentItem $form, array $values, array $fields, array $siteMail, string $formUrl, array $upload = []): array
    {
        $notifications = is_array($form->meta['notifications'] ?? null) ? $form->meta['notifications'] : [];
        $subject = self::fill(trim((string)($notifications['subject'] ?? '')), $values, $fields);
        if ($subject === '') {
            $subject = 'New submission: ' . (string)($form->meta['title'] ?? $form->slug);
        }
        $body = $this->body($form, $values, $fields, $formUrl);
        // The visitor's copy lists what they answered (the names of their files too) and never carries a link into the admin.
        $visitorBody = $body;
        $attachments = [];
        $notice = '';
        $kept = is_array($upload['files'] ?? null) ? $upload['files'] : [];
        if ($kept !== []) {
            $total = 0;
            foreach ($kept as $list) {
                $total += array_sum(array_map(static fn(array $file): int => (int)($file['size'] ?? 0), $list));
            }
            if (Format::isTruthy($notifications['attach_files'] ?? false)) {
                $cap = max(0.0, (float)($notifications['attach_max_mb'] ?? 10)) ?: 10.0;
                if ($total <= (int)round($cap * 1048576)) {
                    foreach ($kept as $list) {
                        foreach ($list as $file) {
                            $path = (string)($upload['paths'][$file['name']] ?? '');
                            if ($path !== '') {
                                $attachments[] = ['path' => $path, 'name' => (string)$file['name'], 'mime' => (string)($file['mime'] ?? 'application/octet-stream')];
                            }
                        }
                    }
                    $notice = 'The files are attached to this email, and can also be downloaded with the links above.';
                } else {
                    $notice = 'The files are ' . Format::bytes($total) . ' together, more than the ' . rtrim(rtrim(number_format($cap, 1), '0'), '.') . ' MB that is attached to an email, so they are not attached. Download them with the links above.';
                }
            }
            $body .= $this->fileLinks($upload, $kept) . ($notice !== '' ? "\n\n" . $notice : '');
        }

        $from = trim((string)($siteMail['from'] ?? ''));
        $fromName = trim((string)($siteMail['from_name'] ?? ''));
        if ($from === '') {
            $from = 'noreply@localhost';
        }
        $fromHeader = $fromName !== '' ? $fromName . ' <' . $from . '>' : $from;
        $headers = ['From' => $fromHeader];
        $replyEmail = self::replyTo($values, $fields, trim((string)($notifications['reply_to_field'] ?? '')));
        if ($replyEmail !== '') {
            $headers['Reply-To'] = $replyEmail;
        }
        foreach (['Cc' => 'cc', 'Bcc' => 'bcc'] as $header => $key) {
            $value = trim((string)($notifications[$key] ?? ''));
            if ($value !== '') {
                $headers[$header] = $value;
            }
        }

        $messages = [];
        $to = trim((string)($notifications['to'] ?? ''));
        if (Format::isTruthy($notifications['enabled'] ?? false) && $to !== '') {
            $messages[] = ['to' => $to, 'subject' => $subject, 'body' => $body, 'headers' => $headers, 'attachments' => $attachments];
        }
        if (Format::isTruthy($notifications['auto_reply'] ?? false) && $replyEmail !== '') {
            $autoSubject = self::fill(trim((string)($notifications['auto_reply_subject'] ?? '')), $values, $fields);
            $autoMessage = self::fill(trim((string)($notifications['auto_reply_message'] ?? '')), $values, $fields);
            if ($autoMessage === '') {
                $autoMessage = "Thanks for contacting us.\n\nWe received your submission and will get back to you soon.";
            }
            if (Format::isTruthy($notifications['auto_reply_include'] ?? false)) {
                $autoMessage .= "\n\n---\n\n" . $visitorBody;
            }
            $messages[] = ['to' => $replyEmail, 'subject' => $autoSubject !== '' ? $autoSubject : 'Thanks for your message', 'body' => $autoMessage, 'headers' => ['From' => $fromHeader], 'attachments' => []];
        }
        return $messages;
    }

    /**
     * A text with what was answered put in: {full-name} becomes the answer of the field called full-name. A word in
     * braces that is not a field of the form is left as it is.
     *
     * @param array<string, mixed> $values
     * @param array<int, array<string, mixed>> $fields
     */
    public static function fill(string $text, array $values, array $fields): string
    {
        if ($text === '' || !str_contains($text, '{')) {
            return $text;
        }
        $known = [];
        foreach ($fields as $field) {
            $name = (string)($field['name'] ?? '');
            if ($name !== '' && !FormFields::isDisplay((string)($field['type'] ?? ''))) {
                $known['{' . $name . '}'] = str_replace(["\r", "\n"], ' ', self::text($values[$name] ?? ''));
            }
        }
        return strtr($text, $known);
    }

    /** The text of the notification: the form, where it is, and each field with what was sent. @param array<string, mixed> $values @param array<int, array<string, mixed>> $fields */
    public function body(ContentItem $form, array $values, array $fields, string $formUrl): string
    {
        $lines = ['Form: ' . (string)($form->meta['title'] ?? $form->slug), 'URL: ' . $formUrl, ''];
        foreach ($fields as $field) {
            $name = (string)($field['name'] ?? '');
            if ($name === '' || FormFields::isDisplay((string)($field['type'] ?? ''))) {
                continue;
            }
            $lines[] = (string)($field['label'] ?? $name) . ': ' . self::text($values[$name] ?? '');
        }
        return implode("\n", $lines);
    }

    /** The part of the notification that lets the site's people get to the files: one admin address for each, and one for all of them. @param array<string, mixed> $upload @param array<string, array<int, array<string, mixed>>> $kept */
    private function fileLinks(array $upload, array $kept): string
    {
        $lines = ['', '', 'Files (sign in to the admin to download them):'];
        foreach ($kept as $list) {
            foreach ($list as $file) {
                $lines[] = '- ' . $file['name'] . ' (' . Format::bytes((int)($file['size'] ?? 0)) . '): ' . (string)($upload['urls'][$file['name']] ?? '');
            }
        }
        if ((string)($upload['zip'] ?? '') !== '' && array_sum(array_map('count', $kept)) > 1) {
            $lines[] = 'All files as a ZIP: ' . $upload['zip'];
        }
        return implode("\n", $lines);
    }

    /**
     * The address to reply to: the field the form names, else the first email field with a valid address, else a
     * field called "email". '' when there is none.
     *
     * @param array<string, mixed> $values
     * @param array<int, array<string, mixed>> $fields
     */
    public static function replyTo(array $values, array $fields, string $replyToField): string
    {
        if ($replyToField !== '' && isset($values[$replyToField])) {
            $candidate = trim((string)$values[$replyToField]);
            if (filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
                return $candidate;
            }
        }
        foreach ($fields as $field) {
            if (($field['type'] ?? '') === 'email') {
                $candidate = trim((string)($values[(string)($field['name'] ?? '')] ?? ''));
                if (filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
                    return $candidate;
                }
            }
        }
        if (isset($values['email']) && filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
            return (string)$values['email'];
        }
        return '';
    }

    /** A value as one line of text: a list of choices is joined with commas. */
    public static function text(mixed $value): string
    {
        return is_array($value) ? implode(', ', array_map('strval', $value)) : trim((string)$value);
    }

    /** What to call a submission: the name, else the full name, else the email, else "Submission". */
    public static function title(mixed $fields): string
    {
        if (is_array($fields)) {
            foreach (['name', 'full_name', 'email'] as $key) {
                if (isset($fields[$key]) && trim((string)$fields[$key]) !== '') {
                    return trim((string)$fields[$key]);
                }
            }
        }
        return 'Submission';
    }

    /** A date and time as the admin shows it; text that is not a date is shown as it is. */
    public static function date(string $value): string
    {
        if ($value === '') {
            return '';
        }
        $timestamp = strtotime($value);
        return $timestamp === false ? $value : date('Y-m-d H:i', $timestamp);
    }
}
