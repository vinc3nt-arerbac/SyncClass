<?php

declare(strict_types=1);

namespace App\Helpers;

use Closure;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Server-side input validation (NFR-07, MD-08, every form).
 *
 * The validator is declarative and framework-free:
 *
 *     $validator = Validator::make($_POST, [
 *         'email'      => 'required|email|email_domain:sac.edu.ph',
 *         'start_time' => ['required', 'time'],
 *         'end_time'   => ['required', 'time', 'after:start_time'],
 *         'units'      => 'required|integer|between:1,6',
 *     ], ['email' => 'Institutional email']);
 *
 *     if ($validator->fails()) {
 *         $errors = $validator->errors();      // ['email' => 'The ...', ...]
 *     }
 *     $clean = $validator->validated();        // typed, rule-approved values only
 *
 * Design notes:
 *  - `validated()` returns only fields that have rules and passed, which is the
 *    application's mass-assignment guard: raw request arrays never reach a
 *    repository.
 *  - Values are not mutated. Trimming or lower-casing is an explicit decision
 *    at the controller level; passwords are never trimmed or normalised.
 *  - Client-side validation (Phase 17) mirrors these rules for convenience;
 *    the server is always authoritative.
 *  - Uniqueness and other database-aware rules belong in repositories/services,
 *    not here, so validation stays free of SQL.
 */
final class Validator
{
    /**
     * Default English messages. Override per rule ('email' => ...) or per field
     * ('users.email.email' => ...) through the $messages constructor argument.
     *
     * @var array<string, string>
     */
    private const DEFAULT_MESSAGES = [
        'required' => 'The :attribute field is required.',
        'string' => 'The :attribute must be text.',
        'email' => 'The :attribute must be a valid email address.',
        'email_domain' => 'The :attribute must use an institutional address (:domains).',
        'integer' => 'The :attribute must be a whole number.',
        'numeric' => 'The :attribute must be a number.',
        'boolean' => 'The :attribute must be yes or no.',
        'array' => 'The :attribute must be a list.',
        'min' => 'The :attribute must be at least :min.',
        'max' => 'The :attribute may not be greater than :max.',
        'between' => 'The :attribute must be between :min and :max.',
        'in' => 'The :attribute must be one of: :values.',
        'not_in' => 'The :attribute may not be one of: :values.',
        'regex' => 'The :attribute format is invalid.',
        'date' => 'The :attribute must be a valid date.',
        'date_format' => 'The :attribute must match the format :format.',
        'time' => 'The :attribute must be a valid 24-hour time (HH:MM).',
        'after' => 'The :attribute must be after :other.',
        'before' => 'The :attribute must be before :other.',
        'same' => 'The :attribute must match :other.',
        'different' => 'The :attribute must be different from :other.',
        'confirmed' => 'The :attribute confirmation does not match.',
        'alpha_dash' => 'The :attribute may only contain letters, numbers, dashes and underscores.',
        'password' => 'The password does not meet the security policy.',
        'password_min' => 'The password must be at least :min characters.',
        'password_max' => 'The password may not be longer than :max bytes.',
        'password_case' => 'The password must contain both uppercase and lowercase letters.',
        'password_number' => 'The password must contain at least one number.',
        'password_symbol' => 'The password must contain at least one symbol.',
        'invalid' => 'The :attribute is invalid.',
    ];

    /**
     * Fallback password policy; config/security.php provides the real values.
     *
     * @var array<string, mixed>
     */
    private const DEFAULT_PASSWORD_POLICY = [
        'min_length' => 10,
        'max_length' => 72,
        'require_mixed_case' => true,
        'require_number' => true,
        'require_symbol' => false,
    ];

    /** @var array<string, mixed> */
    private array $data;

    /** @var array<string, string|array<int, string|Closure>> */
    private array $rules;

    /** @var array<string, string> */
    private array $labels;

    /** @var array<string, string> */
    private array $messages;

    /** @var array<string, mixed> */
    private array $passwordPolicy;

    /** @var array<string, string> field => first error message */
    private array $errors = [];

    /** @var array<string, mixed> */
    private array $validated = [];

    private bool $ran = false;

    /**
     * @param array<string, mixed> $data
     * @param array<string, string|array<int, string|Closure>> $rules
     * @param array<string, string> $labels Human names for messages.
     * @param array<string, string> $messages Message overrides.
     * @param array<string, mixed> $passwordPolicy config/security.php password block.
     */
    public function __construct(
        array $data,
        array $rules,
        array $labels = [],
        array $messages = [],
        array $passwordPolicy = []
    ) {
        $this->data = $data;
        $this->rules = $rules;
        $this->labels = $labels;
        $this->messages = $messages;
        $this->passwordPolicy = array_replace(self::DEFAULT_PASSWORD_POLICY, $passwordPolicy);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string|array<int, string|Closure>> $rules
     * @param array<string, string> $labels
     * @param array<string, string> $messages
     * @param array<string, mixed> $passwordPolicy
     */
    public static function make(
        array $data,
        array $rules,
        array $labels = [],
        array $messages = [],
        array $passwordPolicy = []
    ): self {
        return new self($data, $rules, $labels, $messages, $passwordPolicy);
    }

    /**
     * Run every rule. Idempotent: later calls return the first result.
     */
    public function validate(): self
    {
        if ($this->ran) {
            return $this;
        }

        $this->ran = true;

        foreach ($this->rules as $field => $rules) {
            $field = (string) $field;
            $ruleList = is_string($rules) ? explode('|', $rules) : array_values((array) $rules);

            $this->validateField($field, $ruleList);
        }

        return $this;
    }

    public function passes(): bool
    {
        return $this->validate()->errors === [];
    }

    public function fails(): bool
    {
        return !$this->passes();
    }

    /**
     * @return array<string, string> field => first error message
     */
    public function errors(): array
    {
        $this->validate();

        return $this->errors;
    }

    public function firstError(string $field): ?string
    {
        return $this->errors()[$field] ?? null;
    }

    /**
     * Rule-approved values, cast to their declared scalar type.
     *
     * Only keys present in the input and free of errors are returned, so the
     * caller can pass the array straight to a service without mass assignment.
     *
     * @return array<string, mixed>
     */
    public function validated(): array
    {
        $this->validate();

        return $this->validated;
    }

    /**
     * Raw input for a field (for repopulating a form after a 422).
     */
    public function input(string $field, mixed $default = null): mixed
    {
        return $this->data[$field] ?? $default;
    }

    // -----------------------------------------------------------------------
    // Field validation
    // -----------------------------------------------------------------------

    /**
     * @param array<int, string|Closure> $rules
     */
    private function validateField(string $field, array $rules): void
    {
        $names = [];

        foreach ($rules as $rule) {
            if ($rule instanceof Closure) {
                $names[] = 'closure';
                continue;
            }

            $names[] = explode(':', (string) $rule, 2)[0];
        }

        $value = $this->data[$field] ?? null;
        $present = array_key_exists($field, $this->data)
            && $value !== null
            && !(is_string($value) && trim($value) === '');

        if (!$present) {
            if (in_array('required', $names, true)) {
                $this->errors[$field] = $this->message('required', $field);
            }

            return;
        }

        foreach ($rules as $rule) {
            if ($rule instanceof Closure) {
                $result = $rule($value, $this->data);

                if ($result !== true) {
                    $this->errors[$field] = is_string($result) && $result !== ''
                        ? $result
                        : $this->message('invalid', $field);
                }

                if (isset($this->errors[$field])) {
                    return;
                }

                continue;
            }

            [$name, $parameter] = array_pad(explode(':', (string) $rule, 2), 2, null);

            // Presence handled above; nullable only suppresses rules for
            // absent values, which are already skipped.
            if ($name === 'required' || $name === 'nullable') {
                continue;
            }

            $error = $this->applyRule($name, $parameter, $field, $value, $names);

            if ($error !== null) {
                $this->errors[$field] = $error;

                return;
            }
        }

        $this->validated[$field] = $this->castValue($value, $names);
    }

    /**
     * @param array<int, string> $names Already-parsed rule names for the field
     *                                     (needed to decide length vs. value
     *                                     semantics for min/max/between).
     */
    private function applyRule(
        string $rule,
        ?string $parameter,
        string $field,
        mixed $value,
        array $names
    ): ?string {
        switch ($rule) {
            case 'string':
                return is_string($value) ? null : $this->message('string', $field);

            case 'email':
                return is_string($value)
                    && mb_strlen($value) <= 254
                    && filter_var($value, FILTER_VALIDATE_EMAIL) !== false
                        ? null
                        : $this->message('email', $field);

            case 'email_domain':
                $domains = array_values(array_filter(array_map(
                    static fn (string $domain): string => strtolower(trim($domain)),
                    explode(',', (string) $parameter)
                )));

                $at = is_string($value) ? strrpos($value, '@') : false;
                $domain = $at === false ? '' : strtolower(substr((string) $value, $at + 1));

                return $domain !== '' && in_array($domain, $domains, true)
                    ? null
                    : $this->message('email_domain', $field, [':domains' => implode(', ', $domains)]);

            case 'integer':
                return filter_var($value, FILTER_VALIDATE_INT) !== false
                    ? null
                    : $this->message('integer', $field);

            case 'numeric':
                return is_numeric($value) ? null : $this->message('numeric', $field);

            case 'boolean':
                return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== null
                    ? null
                    : $this->message('boolean', $field);

            case 'array':
                return is_array($value) ? null : $this->message('array', $field);

            case 'min':
            case 'max':
                $limit = (float) $parameter;
                $size = $this->measure($value, $names);
                $ok = $rule === 'min' ? $size >= $limit : $size <= $limit;

                return $ok ? null : $this->message($rule, $field, [
                    ':min' => (string) $parameter,
                    ':max' => (string) $parameter,
                ]);

            case 'between':
                [$min, $max] = array_pad(explode(',', (string) $parameter), 2, '0');
                $size = $this->measure($value, $names);

                return $size >= (float) $min && $size <= (float) $max
                    ? null
                    : $this->message('between', $field, [':min' => $min, ':max' => $max]);

            case 'in':
                $allowed = array_map('trim', explode(',', (string) $parameter));

                return in_array((string) $value, $allowed, true)
                    ? null
                    : $this->message('in', $field, [':values' => implode(', ', $allowed)]);

            case 'not_in':
                $forbidden = array_map('trim', explode(',', (string) $parameter));

                return in_array((string) $value, $forbidden, true)
                    ? $this->message('not_in', $field, [':values' => implode(', ', $forbidden)])
                    : null;

            case 'regex':
                $pattern = (string) $parameter;

                if (@preg_match($pattern, '') === false) {
                    throw new InvalidArgumentException(
                        sprintf('Invalid regular expression in validation rule for "%s": %s', $field, $pattern)
                    );
                }

                return preg_match($pattern, (string) $value) === 1
                    ? null
                    : $this->message('regex', $field);

            case 'date':
                return is_string($value) && strtotime($value) !== false
                    ? null
                    : $this->message('date', $field);

            case 'date_format':
                $format = (string) $parameter;
                $parsed = is_string($value) ? DateTimeImmutable::createFromFormat($format, $value) : false;

                return $parsed !== false && $parsed->format($format) === $value
                    ? null
                    : $this->message('date_format', $field, [':format' => $format]);

            case 'time':
                return is_string($value) && preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $value) === 1
                    ? null
                    : $this->message('time', $field);

            case 'after':
            case 'before':
                $otherField = (string) $parameter;
                $otherValue = $this->data[$otherField] ?? null;
                $left = strtotime((string) $value);
                $right = is_scalar($otherValue) ? strtotime((string) $otherValue) : false;

                if ($left === false || $right === false) {
                    return $this->message($rule, $field, [':other' => $this->label($otherField)]);
                }

                $ok = $rule === 'after' ? $left > $right : $left < $right;

                return $ok ? null : $this->message($rule, $field, [':other' => $this->label($otherField)]);

            case 'same':
            case 'different':
                $otherField = (string) $parameter;
                $equal = array_key_exists($otherField, $this->data)
                    && (string) $this->data[$otherField] === (string) $value;
                $ok = $rule === 'same' ? $equal : !$equal;

                return $ok ? null : $this->message($rule, $field, [':other' => $this->label($otherField)]);

            case 'confirmed':
                $confirmation = $field . '_confirmation';
                $ok = array_key_exists($confirmation, $this->data)
                    && (string) $this->data[$confirmation] === (string) $value;

                return $ok ? null : $this->message('confirmed', $field);

            case 'alpha_dash':
                return preg_match('/^[A-Za-z0-9_-]+$/', (string) $value) === 1
                    ? null
                    : $this->message('alpha_dash', $field);

            case 'password':
                return $this->passwordError((string) $value, $field);

            default:
                throw new InvalidArgumentException(
                    sprintf('Unknown validation rule "%s" for field "%s".', $rule, $field)
                );
        }
    }

    // -----------------------------------------------------------------------
    // Password policy (AUTH-02)
    // -----------------------------------------------------------------------

    /**
     * Apply the configured policy and return the first violated rule.
     */
    private function passwordError(string $value, string $field): ?string
    {
        $min = (int) $this->passwordPolicy['min_length'];
        $max = (int) $this->passwordPolicy['max_length'];

        if (mb_strlen($value) < $min) {
            return $this->message('password_min', $field, [':min' => (string) $min]);
        }

        // Byte length, not character length: bcrypt truncates at 72 bytes.
        if (strlen($value) > $max) {
            return $this->message('password_max', $field, [':max' => (string) $max]);
        }

        if ((bool) $this->passwordPolicy['require_mixed_case']
            && (preg_match('/\p{Lu}/u', $value) !== 1 || preg_match('/\p{Ll}/u', $value) !== 1)
        ) {
            return $this->message('password_case', $field);
        }

        if ((bool) $this->passwordPolicy['require_number'] && preg_match('/\d/', $value) !== 1) {
            return $this->message('password_number', $field);
        }

        if ((bool) $this->passwordPolicy['require_symbol']
            && preg_match('/[^\p{L}\p{N}]/u', $value) !== 1
        ) {
            return $this->message('password_symbol', $field);
        }

        return null;
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Compare length (text) or value (numeric rules).
     *
     * @param array<int, string> $names
     */
    private function measure(mixed $value, array $names): float
    {
        if (in_array('integer', $names, true) || in_array('numeric', $names, true)) {
            return (float) $value;
        }

        return (float) mb_strlen((string) $value);
    }

    /**
     * Cast a validated value to the type its rules declare.
     *
     * @param array<int, string> $names
     */
    private function castValue(mixed $value, array $names): mixed
    {
        if (in_array('integer', $names, true)) {
            return (int) $value;
        }

        if (in_array('numeric', $names, true)) {
            return is_numeric($value) ? $value + 0 : $value;
        }

        if (in_array('boolean', $names, true)) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
        }

        if (in_array('array', $names, true)) {
            return $value;
        }

        return is_string($value) ? $value : $value;
    }

    private function label(string $field): string
    {
        return $this->labels[$field] ?? ucfirst(str_replace(['_', '.'], ' ', $field));
    }

    /**
     * Render a message, preferring a field-specific override, then a global
     * override, then the built-in default.
     *
     * @param array<string, string> $replace
     */
    private function message(string $rule, string $field, array $replace = []): string
    {
        $template = $this->messages[$field . '.' . $rule]
            ?? $this->messages[$rule]
            ?? self::DEFAULT_MESSAGES[$rule]
            ?? self::DEFAULT_MESSAGES['invalid'];

        return strtr($template, array_merge([':attribute' => $this->label($field)], $replace));
    }
}
