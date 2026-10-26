<?php
// required/json_sanitizer.php
// Utility helpers to ensure data structures are safe for json_encode without UTF-8/control char issues.

if (!function_exists('json_sanitize_for_encoding')) {
    function json_sanitize_for_encoding($value)
    {
        if (is_array($value)) {
            $clean = [];
            foreach ($value as $k => $v) {
                // Preserve keys as strings/ints
                $clean[$k] = json_sanitize_for_encoding($v);
            }
            return $clean;
        }

        if (is_object($value)) {
            if ($value instanceof \JsonSerializable) {
                return json_sanitize_for_encoding($value->jsonSerialize());
            }
            if ($value instanceof \DateTimeInterface) {
                return $value->format(DATE_ATOM);
            }
            return json_sanitize_for_encoding(get_object_vars($value));
        }

        if (is_string($value)) {
            return json_clean_string($value);
        }

        if (is_resource($value)) {
            return '[resource:' . get_resource_type($value) . ']';
        }

        return $value;
    }
}

if (!function_exists('json_clean_string')) {
    function json_clean_string(string $string): string
    {
        // Normalize line endings
        $string = str_replace(["\r\n", "\r"], "\n", $string);

        if (!mb_check_encoding($string, 'UTF-8')) {
            $converted = @iconv('UTF-8', 'UTF-8//IGNORE', $string);
            if ($converted !== false) {
                $string = $converted;
            } else {
                $string = mb_convert_encoding($string, 'UTF-8', 'UTF-8');
            }
        }

        // Strip non-printable control characters except tab/newline
        $string = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $string);

        return $string;
    }
}

