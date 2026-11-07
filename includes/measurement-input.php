<?php
declare(strict_types=1);

/** Validate structured fields before passing them to the existing version parser. */
function measurement_rows_to_lines(mixed $names, mixed $values, mixed $unit): array
{
    $fail = ['lines'=>'', 'error'=>'Enter 1–30 measurement names and positive values, with up to two decimal places.'];
    if (!is_array($names) || !is_array($values) || count($names) !== count($values) || count($names) > 30 || !in_array($unit, ['cm','inch'], true)) return $fail;
    $names = array_values($names);
    $values = array_values($values);
    $lines = [];
    foreach ($names as $i=>$name) {
        $value = $values[$i];
        if (!is_string($name) || !is_string($value)) return $fail;
        $name = trim($name); $value = trim($value);
        if ($name === '' && $value === '') continue;
        if (preg_match('/\A[\p{L}\p{N}][\p{L}\p{N} _-]{0,49}\z/u', $name) !== 1 || preg_match('/\A[0-9]{1,3}(?:\.[0-9]{1,2})?\z/', $value) !== 1) return $fail;
        $cm = (float) $value * ($unit === 'inch' ? 2.54 : 1);
        if ($cm <= 0 || $cm > 999.99 || round($cm, 2) <= 0) return ['lines'=>'', 'error'=>'Each measurement must be greater than 0 and no more than 999.99 cm (393.69 inches).'];
        $lines[] = $name . ' = ' . number_format($cm, 2, '.', '');
    }
    return ['lines'=>implode("\n", $lines), 'error'=>null];
}

/** @return array{values: array<string, string>, error: string|null} */
function parse_measurement_lines(string $input): array
{
    $measurements = [];
    $normalizedNames = [];

    foreach (preg_split('/\R/u', $input) ?: [] as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }

        if (preg_match('/\A(.{1,50}?)\s*=\s*([0-9]{1,3}(?:\.[0-9]{1,2})?)\z/u', $line, $matches) !== 1) {
            return ['values' => [], 'error' => 'Use one measurement per line in the format Chest = 92.'];
        }

        $name = trim($matches[1]);
        $amount = $matches[2];
        if (preg_match('/\A[\p{L}\p{N}][\p{L}\p{N} _-]{0,49}\z/u', $name) !== 1) {
            return ['values' => [], 'error' => 'Measurement names may use letters, numbers, spaces, underscores, and hyphens.'];
        }
        if ((float) $amount <= 0) {
            return ['values' => [], 'error' => 'Measurement values must be greater than 0 cm.'];
        }

        $normalizedName = mb_strtolower($name, 'UTF-8');
        if (isset($normalizedNames[$normalizedName])) {
            return ['values' => [], 'error' => 'Each measurement name must be unique within a version.'];
        }

        $normalizedNames[$normalizedName] = true;
        $measurements[$name] = $amount;
        if (count($measurements) > 30) {
            return ['values' => [], 'error' => 'Add no more than 30 measurements to one version.'];
        }
    }

    if ($measurements === []) {
        return ['values' => [], 'error' => 'Add at least one measurement.'];
    }

    return ['values' => $measurements, 'error' => null];
}
