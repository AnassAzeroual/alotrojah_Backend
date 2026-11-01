<?php

declare(strict_types=1);

namespace App\Core;

final class Validator
{
    private array $data;
    private array $rules;
    private array $errors = [];

    public function __construct(array $data, array $rules = [])
    {
        $this->data = $data;
        $this->rules = $rules;
    }

    public static function make(array $data, array $rules = []): self
    {
        return new self($data, $rules);
    }

    public function required(string $field): self
    {
        $this->rules[$field][] = 'required';
        return $this;
    }

    public function integer(string $field): self
    {
        $this->rules[$field][] = 'integer';
        return $this;
    }

    public function numeric(string $field): self
    {
        $this->rules[$field][] = 'numeric';
        return $this;
    }

    public function email(string $field): self
    {
        $this->rules[$field][] = 'email';
        return $this;
    }

    public function boolean(string $field): self
    {
        $this->rules[$field][] = 'boolean';
        return $this;
    }

    public function in(string $field, array $allowed): self
    {
        $this->rules[$field][] = 'in:' . implode(',', $allowed);
        return $this;
    }

    public function min(string $field, int|float $val): self
    {
        $this->rules[$field][] = 'min:' . $val;
        return $this;
    }

    public function max(string $field, int|float $val): self
    {
        $this->rules[$field][] = 'max:' . $val;
        return $this;
    }

    public function validate(): array
    {
        foreach ($this->rules as $field => $fieldRules) {
            $value = $this->data[$field] ?? null;
            if (is_string($fieldRules)) {
                $ruleList = explode('|', $fieldRules);
            } else {
                $ruleList = (array) $fieldRules;
            }

            $isRequired = in_array('required', $ruleList, true);
            $isNullable = in_array('nullable', $ruleList, true);

            if ($value === null || $value === '') {
                if ($isRequired) {
                    $this->addError($field, "The {$field} field is required.");
                }
                continue;
            }

            foreach ($ruleList as $rule) {
                if ($rule === 'required' || $rule === 'nullable') {
                    continue;
                }

                if ($rule === 'email' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $this->addError($field, "The {$field} must be a valid email address.");
                } elseif ($rule === 'integer' && filter_var($value, FILTER_VALIDATE_INT) === false) {
                    $this->addError($field, "The {$field} must be an integer.");
                } elseif ($rule === 'numeric' && !is_numeric($value)) {
                    $this->addError($field, "The {$field} must be a number.");
                } elseif ($rule === 'boolean' && !is_bool($value) && !in_array($value, [0, 1, '0', '1', 'true', 'false'], true)) {
                    $this->addError($field, "The {$field} must be true or false.");
                } elseif ($rule === 'date' && strtotime((string) $value) === false) {
                    $this->addError($field, "The {$field} is not a valid date.");
                } elseif (str_starts_with($rule, 'in:')) {
                    $allowed = explode(',', substr($rule, 3));
                    if (!in_array((string) $value, $allowed, true)) {
                        $this->addError($field, "The selected {$field} is invalid.");
                    }
                } elseif (str_starts_with($rule, 'min:')) {
                    $min = (float) substr($rule, 4);
                    if (is_numeric($value) && (float) $value < $min) {
                        $this->addError($field, "The {$field} must be at least {$min}.");
                    } elseif (is_string($value) && mb_strlen($value) < $min) {
                        $this->addError($field, "The {$field} must be at least {$min} characters.");
                    }
                } elseif (str_starts_with($rule, 'max:')) {
                    $max = (float) substr($rule, 4);
                    if (is_numeric($value) && (float) $value > $max) {
                        $this->addError($field, "The {$field} may not be greater than {$max}.");
                    } elseif (is_string($value) && mb_strlen($value) > $max) {
                        $this->addError($field, "The {$field} may not be greater than {$max} characters.");
                    }
                }
            }
        }

        return $this->errors;
    }

    public function passes(): bool
    {
        return empty($this->validate());
    }

    public function fails(): bool
    {
        return !$this->passes();
    }

    public function errors(): array
    {
        return $this->errors;
    }

    public function addError(string $field, string $message): void
    {
        $this->errors[$field][] = $message;
    }
}
