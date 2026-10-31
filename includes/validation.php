<?php

declare(strict_types=1);

/**
 * Validate and normalize public customer/tailor registration input.
 *
 * @return array{values: array<string, string>, errors: array<string, string>}
 */
function validate_registration(array $input): array
{
    $values = [
        'name' => trim((string) ($input['name'] ?? '')),
        'email' => strtolower(trim((string) ($input['email'] ?? ''))),
        'phone' => trim((string) ($input['phone'] ?? '')),
        'role' => (string) ($input['role'] ?? 'customer'),
    ];

    $password = (string) ($input['password'] ?? '');
    $passwordConfirmation = (string) ($input['password_confirmation'] ?? '');
    $errors = [];

    if (mb_strlen($values['name']) < 2 || mb_strlen($values['name']) > 100) {
        $errors['name'] = 'Name must be between 2 and 100 characters.';
    }

    if (
        $values['email'] === ''
        || mb_strlen($values['email']) > 150
        || filter_var($values['email'], FILTER_VALIDATE_EMAIL) === false
    ) {
        $errors['email'] = 'Enter a valid email address.';
    }

    if (
        $values['phone'] !== ''
        && preg_match('/^[0-9+()\-\s]{7,20}$/', $values['phone']) !== 1
    ) {
        $errors['phone'] = 'Enter a valid phone number or leave it empty.';
    }

    if (!in_array($values['role'], ['customer', 'tailor'], true)) {
        $errors['role'] = 'Choose either customer or tailor.';
    }

    if (strlen($password) < 8) {
        $errors['password'] = 'Password must contain at least 8 characters.';
    }

    if ($password !== $passwordConfirmation) {
        $errors['password_confirmation'] = 'Password confirmation does not match.';
    }

    return ['values' => $values, 'errors' => $errors];
}

