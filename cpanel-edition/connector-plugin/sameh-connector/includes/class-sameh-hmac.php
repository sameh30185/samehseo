<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * HMAC verification: timestamp.nonce.METHOD.path.body_hash
 * Anti-replay via WP transients (durable for TTL window).
 */
class Sameh_Connector_HMAC
{
    const MAX_SKEW = 300;
    const MAX_FUTURE = 60;
    const NONCE_TTL = 600;

    public static function body_hash($body)
    {
        return hash('sha256', (string) $body);
    }

    public static function sign($secret, $timestamp, $nonce, $method, $path, $body)
    {
        $payload = $timestamp . '.' . $nonce . '.' . strtoupper($method) . '.' . $path . '.' . self::body_hash($body);
        return hash_hmac('sha256', $payload, $secret);
    }

    /**
     * @return true|WP_Error
     */
    public static function verify_request($request, $secret, $max_skew = self::MAX_SKEW)
    {
        $timestamp = (string) $request->get_header('x-sameh-timestamp');
        $nonce = (string) $request->get_header('x-sameh-nonce');
        $signature = (string) $request->get_header('x-sameh-signature');

        if ($timestamp === '' || $nonce === '' || $signature === '') {
            return new WP_Error('sameh_missing_sig', 'Missing SAMEH signature headers', ['status' => 401]);
        }
        if (!ctype_digit($timestamp)) {
            return new WP_Error('sameh_bad_ts', 'Invalid timestamp', ['status' => 401]);
        }
        $now = time();
        $ts = (int) $timestamp;
        if ($ts > ($now + self::MAX_FUTURE)) {
            return new WP_Error('sameh_future_ts', 'Timestamp unreasonably in the future', ['status' => 401]);
        }
        if (($now - $ts) > $max_skew) {
            return new WP_Error('sameh_stale', 'Stale timestamp (>5 min)', ['status' => 401]);
        }
        if (abs($now - $ts) > $max_skew) {
            return new WP_Error('sameh_skew', 'Timestamp outside allowed skew', ['status' => 401]);
        }
        if ($secret === '') {
            return new WP_Error('sameh_no_secret', 'Shared secret not configured', ['status' => 401]);
        }
        if (strlen($nonce) < 8 || strlen($nonce) > 128) {
            return new WP_Error('sameh_bad_nonce', 'Invalid nonce', ['status' => 401]);
        }

        $method = $request->get_method();
        $route = $request->get_route();
        $path = '/wp-json' . $route;

        $body = $request->get_body();
        $expected = self::sign($secret, $timestamp, $nonce, $method, $path, $body);

        if (!hash_equals($expected, $signature)) {
            return new WP_Error('sameh_bad_sig', 'Invalid signature', ['status' => 401]);
        }

        // Anti-replay: consume nonce once
        if (!self::consume_nonce($nonce)) {
            return new WP_Error('sameh_replay', 'Nonce already used (replay)', ['status' => 401]);
        }

        return true;
    }

    /**
     * Store nonce for TTL; return false if already present.
     */
    public static function consume_nonce($nonce)
    {
        $key = 'sameh_nonce_' . hash('sha256', (string) $nonce);
        if (function_exists('get_transient') && function_exists('set_transient')) {
            if (false !== get_transient($key)) {
                return false;
            }
            set_transient($key, '1', self::NONCE_TTL);
            return true;
        }
        // Fallback for unit tests without WordPress
        if (!isset($GLOBALS['sameh_test_nonces'])) {
            $GLOBALS['sameh_test_nonces'] = [];
        }
        if (isset($GLOBALS['sameh_test_nonces'][$key])) {
            return false;
        }
        $GLOBALS['sameh_test_nonces'][$key] = time() + self::NONCE_TTL;
        return true;
    }
}
