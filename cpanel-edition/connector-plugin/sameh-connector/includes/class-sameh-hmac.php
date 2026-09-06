<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * HMAC verification: timestamp.nonce.METHOD.path.body_hash
 */
class Sameh_Connector_HMAC
{
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
    public static function verify_request($request, $secret, $max_skew = 300)
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
        if (abs(time() - (int) $timestamp) > $max_skew) {
            return new WP_Error('sameh_stale', 'Stale timestamp (>5 min)', ['status' => 401]);
        }
        if ($secret === '') {
            return new WP_Error('sameh_no_secret', 'Shared secret not configured', ['status' => 401]);
        }

        $method = $request->get_method();
        $route = $request->get_route();
        $path = '/wp-json' . $route;

        $body = $request->get_body();
        $expected = self::sign($secret, $timestamp, $nonce, $method, $path, $body);

        if (!hash_equals($expected, $signature)) {
            return new WP_Error('sameh_bad_sig', 'Invalid signature', ['status' => 401]);
        }
        return true;
    }
}
