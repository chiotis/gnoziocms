<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * Parts of a form that are shown only when another field has a certain answer, and forms in several steps.
 *
 * A field (or a heading, a paragraph, a step) may carry `condition: {field, op, value}`: it is shown only when the field it
 * names is shown and its answer meets the test. The tests: `is` (the answer is the value; for a field with several choices,
 * the value is one of those ticked), `not` (the opposite), `filled` (anything was answered) and `empty` (nothing was).
 * A field that is not shown is not checked, not kept and not in the emails. A `step` starts a new step of the form; when a
 * step is not shown, nothing in it is. The form on the site follows the same rules in the browser (form-logic.js), and the
 * server decides again from what was sent.
 */
final class FormConditions
{
    public const OPS = ['is', 'not', 'filled', 'empty'];

    /**
     * A condition as it is stored, or null when there is none worth keeping (no field named, an unknown test, `is`/`not`
     * without a value).
     *
     * @return array{field: string, op: string, value?: string}|null
     */
    public static function clean(mixed $raw): ?array
    {
        if (!is_array($raw)) {
            return null;
        }
        $field = FormFields::sanitizeName((string)($raw['field'] ?? ''));
        $op = strtolower(trim((string)($raw['op'] ?? 'is')));
        if ($field === '' || !in_array($op, self::OPS, true)) {
            return null;
        }
        $condition = ['field' => $field, 'op' => $op];
        if ($op === 'is' || $op === 'not') {
            $value = trim(str_replace(["\r", "\n"], ' ', (string)($raw['value'] ?? '')));
            if ($value === '') {
                return null;
            }
            $condition['value'] = mb_substr($value, 0, 500);
        }
        return $condition;
    }

    /**
     * The conditions of a form with what leads nowhere taken out: one that names no field of the form that takes an
     * answer, or names the field itself.
     *
     * @param array<int, array<string, mixed>> $fields
     * @return array<int, array<string, mixed>>
     */
    public static function prune(array $fields): array
    {
        $answers = [];
        foreach ($fields as $field) {
            $type = (string)($field['type'] ?? '');
            if (!FormFields::isDisplay($type) && $type !== 'file') {
                $answers[(string)($field['name'] ?? '')] = true;
            }
        }
        foreach ($fields as $i => $field) {
            $target = (string)($field['condition']['field'] ?? '');
            if (isset($field['condition']) && ($target === '' || !isset($answers[$target]) || $target === (string)($field['name'] ?? ''))) {
                unset($fields[$i]['condition']);
            }
        }
        return $fields;
    }

    /**
     * Which parts of the form are shown for these answers (name => shown). A field after a step that is not shown is not
     * shown either; a condition on a field that is itself not shown is not met.
     *
     * @param array<int, array<string, mixed>> $fields
     * @param array<string, mixed> $values the answers, as `FormProcessor::collect` cleans them (or as they were sent)
     * @return array<string, bool>
     */
    public static function visible(array $fields, array $values): array
    {
        $shown = [];
        $stepShown = true;
        foreach ($fields as $field) {
            $name = (string)($field['name'] ?? '');
            $type = (string)($field['type'] ?? '');
            $own = self::met($field['condition'] ?? null, $values, $shown);
            if ($type === 'step') {
                $stepShown = $own;
                $shown[$name] = $own;
                continue;
            }
            $shown[$name] = $stepShown && $own;
        }
        return $shown;
    }

    /** @param array<string, mixed> $values @param array<string, bool> $shown what was decided so far */
    private static function met(mixed $condition, array $values, array $shown): bool
    {
        if (!is_array($condition) || (string)($condition['field'] ?? '') === '') {
            return true;
        }
        $target = (string)$condition['field'];
        $op = (string)($condition['op'] ?? 'is');
        // A field that is not shown has no answer, whatever was sent for it.
        $answer = ($shown[$target] ?? true) ? ($values[$target] ?? '') : '';
        $list = is_array($answer) ? array_map(static fn($v): string => trim((string)$v), $answer) : [trim((string)$answer)];
        $list = array_values(array_filter($list, static fn(string $v): bool => $v !== ''));
        $wanted = trim((string)($condition['value'] ?? ''));
        return match ($op) {
            'filled' => $list !== [],
            'empty' => $list === [],
            'not' => !in_array($wanted, $list, true),
            default => in_array($wanted, $list, true),
        };
    }
}
