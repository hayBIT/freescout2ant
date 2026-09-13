<?php

namespace Modules\AmeiseModule\Services\Concerns;

/**
 * Entfernt Zugangsdaten aus allem, was in die activity_logs geschrieben wird.
 */
trait SanitizesLogs
{
    protected function sanitizeLogData($data)
    {
        if (is_array($data)) {
            $sanitized = [];
            foreach ($data as $key => $value) {
                if (is_string($key) && $this->isSensitiveKey($key)) {
                    $sanitized[$key] = $this->valueFingerprint($value);
                    continue;
                }
                $sanitized[$key] = $this->sanitizeLogData($value);
            }
            return $sanitized;
        }

        if (is_object($data)) {
            return $this->sanitizeLogData((array) $data);
        }

        if (is_string($data)) {
            return $this->sanitizeLogText($data);
        }

        return $data;
    }

    protected function sanitizeLogText(string $text): string
    {
        $pattern = '/(Bearer\s+)([A-Za-z0-9\-\._~\+\/]+=*)/i';
        return preg_replace_callback($pattern, function (array $matches) {
            return $matches[1] . $this->valueFingerprint($matches[2]);
        }, $text) ?? $text;
    }

    protected function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower($key);
        return in_array($normalized, ['access_token', 'refresh_token', 'id_token', 'authorization', 'token'], true);
    }

    protected function valueFingerprint($value): string
    {
        if (!is_string($value) || $value === '') {
            return '[redacted]';
        }

        return '[fingerprint:sha256:' . substr(hash('sha256', $value), 0, 12) . ']';
    }
}
