<?php
/**
 * Small display helpers shared by the admin pages.
 * Usage: require_once __DIR__ . '/../includes/admin_helpers.php';
 */

if (!function_exists('fmt_dt')) {
    /** Safe date formatter: tolerates NULL / invalid dates (strtotime(null) is deprecated in PHP 8.1+). */
    function fmt_dt(?string $value, string $format = 'M j, Y g:i A', string $fallback = '—'): string
    {
        $ts = $value ? strtotime($value) : false;
        return $ts ? date($format, $ts) : $fallback;
    }
}

if (!function_exists('css_slug')) {
    /** "In Progress" => "in-progress" for building badge class names. */
    function css_slug(?string $value, string $fallback = 'unknown'): string
    {
        $slug = strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '-', (string) $value), '-'));
        return $slug !== '' ? $slug : $fallback;
    }
}

if (!function_exists('role_label')) {
    function role_label(?string $role): string
    {
        return ucwords(str_replace('_', ' ', (string) ($role ?: 'Unknown')));
    }
}

if (!function_exists('badge')) {
    /** Renders a priority/status badge. $type is "priority" or "status". */
    function badge(string $type, ?string $value): string
    {
        $label = $value !== null && $value !== '' ? $value : 'N/A';
        return '<span class="admin-badge ' . $type . '-' . css_slug($label) . '">'
             . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';
    }
}