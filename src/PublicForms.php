<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * Forms on the public site: what happens when one is sent (the honeypot, the wait between submissions, checking
 * the fields, storing the submission, the emails, where the visitor goes next), the variables a form needs to be
 * shown, and the [form slug="..."] shortcode that places one inside text. Nothing here reads the request, the
 * session or sends mail itself: the caller passes the request in and the things to do in as closures.
 */
final class PublicForms
{
    /**
     * @param \Closure(): array<string, mixed> $settings
     * @param \Closure(string, ?string): string $translate a theme string, with what to show when the theme has none
     * @param \Closure(string): string $absoluteUrl the full address of a public path
     * @param \Closure(string, string, string, array<string, string>, array<int, array{path: string, name: string, mime: string}>): void $send sends one email: to, subject, body, headers, files to attach
     * @param \Closure(string, int): bool $rateLimited whether this visitor sent that form less than that many seconds ago
     * @param \Closure(string): void $markSent notes that this visitor just sent that form
     */
    public function __construct(
        private FormProcessor $processor,
        private FormSubmissionRepository $submissions,
        private \Closure $settings,
        private \Closure $translate,
        private \Closure $absoluteUrl,
        private \Closure $send,
        private \Closure $rateLimited,
        private \Closure $markSent
    ) {
    }

    /**
     * The state of a form for this request: its fields, what to show in them, the errors, whether it was just sent.
     * A form that was sent is checked and kept; sending is done only for the form the request is about.
     *
     * @param array{method: string, get: array<string, mixed>, post: array<string, mixed>, ip: string, agent: string, files?: array<string, mixed>, overflow?: bool} $request files: $_FILES; overflow: the request was bigger than the server takes, so nothing of it arrived
     * @return array{state: array<string, mixed>, location: string} the state, and where to send the visitor when the form was sent and a way back was given
     */
    public function handle(ContentItem $form, string $lang, string $currentPath, array $request): array
    {
        $settings = ($this->settings)();
        $paths = new ContentPaths($settings);
        // The form is shown (and its submission recorded) in the language of the page; what has no translation is in the first language.
        $default = (string)($settings['languages']['default'] ?? 'en');
        $form = FormTranslations::localizeItem($form, $lang, $default);
        $fields = $this->withLimits(FormTranslations::localizeFields(FormFields::normalize($form->meta['fields'] ?? []), $lang, $default));
        $values = $this->processor->defaults($fields);
        $errors = [];
        $message = (string)($form->meta['success_message'] ?? '');
        if ($message === '') {
            $message = ($this->translate)('form.success', 'Thanks! Your submission was received.');
        }
        $honeypot = (string)($form->meta['antispam']['honeypot'] ?? $settings['forms']['antispam']['honeypot'] ?? 'website');
        $rateLimit = (int)($form->meta['antispam']['rate_limit_seconds'] ?? $settings['forms']['antispam']['rate_limit_seconds'] ?? 0);
        $redirectDefault = (string)($form->meta['redirect_url'] ?? '');
        if ($redirectDefault === '') {
            $redirectDefault = '/' . ltrim($currentPath, '/');
        }
        $success = isset($request['get']['sent']) && (string)($request['get']['form'] ?? '') === $form->slug;
        // By reference: the values, errors and success change as the request is dealt with.
        $state = function () use (&$fields, &$values, &$errors, &$success, &$message, &$honeypot, &$redirectDefault, $paths, $form, $lang): array {
            return [
                'fields' => $fields,
                'values' => $values,
                'errors' => $errors,
                'success' => $success,
                'message' => $message,
                'action' => $paths->publicPath('forms', $form->slug, $lang),
                'honeypot' => $honeypot,
                'redirect' => $redirectDefault,
            ];
        };
        $result = fn(string $location = ''): array => ['state' => $state(), 'location' => $location];

        $hasFileField = $this->hasFileField($fields);
        if ($request['method'] === 'POST' && !empty($request['overflow']) && $hasFileField) {
            // The files were more than the server takes in one request: PHP threw the whole request away, so there is nothing to check.
            $errors['_form'] = ($this->translate)('form.error.file_post', 'The files are too large to be sent together. Please send fewer or smaller files.');
            return $result();
        }
        if ($request['method'] !== 'POST' || (string)($request['post']['form_slug'] ?? '') !== $form->slug) {
            return $result();
        }
        if ($honeypot !== '' && trim((string)($request['post'][$honeypot] ?? '')) !== '') {
            // A bot filled the hidden field: it is told it worked, and nothing is kept.
            $success = true;
            return $result();
        }
        if ($rateLimit > 0 && ($this->rateLimited)($form->slug, $rateLimit)) {
            $errors['_form'] = ($this->translate)('form.error.rate_limit', 'Please wait before submitting again.');
            return $result();
        }

        $uploads = $this->submissions->uploads();
        $collected = $this->processor->collect($fields, $request['post'], $request['files'] ?? [], $uploads);
        $values = $collected['values'];
        $errors = $collected['errors'];
        if (!empty($errors)) {
            return $result();
        }

        // A form with a file field always keeps its submissions: the files are kept with them and reached from them.
        $stores = $hasFileField || Format::isTruthy($form->meta['store_submissions'] ?? $settings['forms']['store_submissions'] ?? true);
        $id = $stores ? $this->submissions->newId() : '';
        $kept = [];
        if ($collected['uploads'] !== []) {
            // Only now, with the rest of the submission valid, is anything written.
            $plan = FormUploads::plan($fields, $values, $collected['uploads'], $form->slug, $lang, $id, time());
            $saved = $uploads->save($form->slug, $id, $plan);
            if ($saved === null) {
                $uploads->discard($collected['uploads']);
                $errors['_form'] = ($this->translate)('form.error.file_save', 'The files could not be saved. Please try again, or contact the site owner.');
                return $result();
            }
            $kept = $saved;
            foreach ($kept as $name => $list) {
                $values[$name] = array_column($list, 'name');
            }
        }
        if ($stores) {
            $this->submissions->store($form->slug, $this->processor->record($form, $values, $request['ip'], $request['agent'], $kept), $id);
        }
        $formUrl = ($this->absoluteUrl)($paths->publicPath('forms', $form->slug, $lang));
        $siteMail = is_array($settings['forms']['notifications'] ?? null) ? $settings['forms']['notifications'] : [];
        $upload = [];
        if ($kept !== []) {
            $upload = ['files' => $kept, 'urls' => [], 'paths' => [], 'zip' => ($this->absoluteUrl)('/admin/form-files-zip?' . http_build_query(['slug' => $form->slug, 'id' => $id]))];
            foreach ($kept as $list) {
                foreach ($list as $file) {
                    $upload['urls'][$file['name']] = ($this->absoluteUrl)('/admin/form-file?' . http_build_query(['slug' => $form->slug, 'id' => $id, 'f' => $file['name']]));
                    $upload['paths'][$file['name']] = (string)$uploads->path($form->slug, $id, $file['name']);
                }
            }
        }
        foreach ($this->processor->emails($form, $values, $fields, $siteMail, $formUrl, $upload) as $mail) {
            ($this->send)($mail['to'], $mail['subject'], $mail['body'], $mail['headers'], $mail['attachments']);
        }
        ($this->markSent)($form->slug);
        $success = true;

        $redirect = $this->safeRedirect((string)($request['post']['redirect_url'] ?? ''), (string)($settings['base_url'] ?? ''));
        if ($redirect !== '') {
            return $result($redirect . (str_contains($redirect, '?') ? '&' : '?') . 'form=' . urlencode($form->slug) . '&sent=1');
        }
        return $result();
    }

    /**
     * Each file field with the limits that really apply to it here (the form builder asks for them, the server and the site cap them), as the
     * form shows them and the browser can check them before anything is sent.
     *
     * @param array<int, array<string, mixed>> $fields
     * @return array<int, array<string, mixed>>
     */
    private function withLimits(array $fields): array
    {
        $uploads = $this->submissions->uploads();
        foreach ($fields as $i => $field) {
            if (($field['type'] ?? '') !== 'file') {
                continue;
            }
            $settings = FormUploads::settings($field);
            $limits = $uploads->limits($settings);
            $fields[$i]['upload_limits'] = [
                'multiple' => $settings['multiple'],
                'min' => $settings['min'],
                'max' => $limits['count'],
                'per_file' => $limits['per_file'],
                'per_file_text' => Format::bytes($limits['per_file']),
                'total' => $limits['total'],
                'total_text' => $limits['total'] > 0 ? Format::bytes($limits['total']) : '',
                'extensions' => $settings['extensions'],
                'accept' => implode(',', array_map(static fn(string $e): string => '.' . $e, $settings['extensions'])),
                'types_text' => implode(', ', array_map(static fn(string $e): string => '.' . $e, $settings['extensions'])),
            ];
        }
        return $fields;
    }

    /** @param array<int, array<string, mixed>> $fields */
    private function hasFileField(array $fields): bool
    {
        foreach ($fields as $field) {
            if (($field['type'] ?? '') === 'file') {
                return true;
            }
        }
        return false;
    }

    /** The form as it is shown on a page in a language (its own page uses this too). */
    public function localized(ContentItem $form, string $lang, ?\Closure $markdown = null): ContentItem
    {
        return FormTranslations::localizeItem($form, $lang, (string)((($this->settings)())['languages']['default'] ?? 'en'), $markdown);
    }

    /**
     * What the form template needs to show a form inside a page.
     *
     * @param array<string, mixed>|null $state what handling this request found out about this form, if it was the one sent
     * @param array<string, mixed> $get the address's query
     * @param string $lang the language of the page the form is shown in
     * @return array<string, mixed>
     */
    public function embed(ContentItem $form, string $currentPath, ?array $state, array $get, string $lang = '', bool $overflow = false): array
    {
        $settings = ($this->settings)();
        if ($lang !== '') {
            $default = (string)($settings['languages']['default'] ?? 'en');
            $form = FormTranslations::localizeItem($form, $lang, $default);
            $fields = FormTranslations::localizeFields(FormFields::normalize($form->meta['fields'] ?? []), $lang, $default);
        } else {
            $fields = FormFields::normalize($form->meta['fields'] ?? []);
        }
        $fields = $this->withLimits($fields);
        $values = $this->processor->defaults($fields);
        $errors = [];
        $success = isset($get['sent']) && (string)($get['form'] ?? '') === $form->slug;
        $message = (string)($form->meta['success_message'] ?? '');
        if ($message === '') {
            $message = ($this->translate)('form.success', 'Thanks! Your submission was received.');
        }
        if ($state !== null) {
            $values = $state['values'] ?? $values;
            $errors = $state['errors'] ?? $errors;
            $success = $state['success'] ?? $success;
            $message = $state['message'] ?? $message;
        }
        if ($overflow && $state === null && $this->hasFileField($fields)) {
            $errors['_form'] = ($this->translate)('form.error.file_post', 'The files are too large to be sent together. Please send fewer or smaller files.');
        }
        $action = '/' . ltrim($currentPath, '/');
        $redirect = (string)($form->meta['redirect_url'] ?? '');
        return [
            'form' => $form,
            'form_fields' => $fields,
            'form_values' => $values,
            'form_errors' => $errors,
            'form_success' => $success,
            'form_message' => $message,
            'form_action' => $action === '/' ? '' : $action,
            'form_honeypot' => (string)($form->meta['antispam']['honeypot'] ?? $settings['forms']['antispam']['honeypot'] ?? 'website'),
            'form_redirect' => $redirect !== '' ? $redirect : '/' . ltrim($currentPath, '/'),
        ];
    }

    /**
     * Puts the form named in each [form slug="..."] shortcode where the shortcode is.
     *
     * @param \Closure(string): string $render the form of that slug as HTML, or nothing when there is none
     */
    public static function replaceShortcodes(string $html, \Closure $render): string
    {
        if (!str_contains($html, '[form')) {
            return $html;
        }
        $result = preg_replace_callback('/<p>\s*\[form\s+([^\]]+)\]\s*<\/p>|\[form\s+([^\]]+)\]/i', function (array $matches) use ($render): string {
            // Markdown output may entity-encode the quotes (&quot;), so they are decoded before the attributes are read.
            $raw = html_entity_decode($matches[1] !== '' ? $matches[1] : ($matches[2] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $slug = Slug::plain((string)(self::attributes($raw)['slug'] ?? ''));
            return $slug === '' ? $matches[0] : $render($slug);
        }, $html);
        return $result ?? $html;
    }

    /** @return array<string, string> the name="value" pairs of a shortcode */
    public static function attributes(string $raw): array
    {
        $attrs = [];
        if (preg_match_all('/(\w+)\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s]+))/', $raw, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $attrs[$match[1]] = $match[3] !== '' ? $match[3] : ($match[4] !== '' ? $match[4] : $match[5]);
            }
        }
        return $attrs;
    }

    /** Where a visitor may be sent after a form: a path of the site, or a full address on the site's own host; anything else is nothing. */
    private function safeRedirect(string $url, string $baseUrl): string
    {
        $url = trim($url);
        // A backslash or a control character is read by a browser as a slash or dropped, so "/\\host" would lead off the site.
        if ($url === '' || preg_match('/[\x00-\x1f\x7f\\\\]/', $url) === 1 || str_starts_with($url, '//')) {
            return '';
        }
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $url) === 1) {
            if (preg_match('#^https?://#i', $url) !== 1) {
                return '';
            }
            $host = strtolower((string)(parse_url($url, PHP_URL_HOST) ?? ''));
            $baseHost = trim($baseUrl) !== '' ? strtolower((string)(parse_url(trim($baseUrl), PHP_URL_HOST) ?? '')) : '';
            // Without an address of the site to compare with, no full address is the site's own.
            return $host !== '' && $baseHost !== '' && $host === $baseHost ? $url : '';
        }
        return str_starts_with($url, '/') ? $url : '/' . $url;
    }
}
