<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * HMAC shared-secret verification stub for SAMEH bridge.
 */
class Sameh_Connector_HMAC {
    public static function shared_secret() {
        return (string) get_option(SAMEH_CONNECTOR_OPT_SECRET, '');
    }

    /**
     * @return true|WP_Error
     */
    public static function verify_request(WP_REST_Request $request) {
        $secret = self::shared_secret();
        if ($secret === '') {
            return new WP_Error('sameh_no_secret', 'Connector secret not configured', array('status' => 503));
        }
        $ts = $request->get_header('x_sameh_timestamp');
        $sig = $request->get_header('x_sameh_signature');
        if (!$ts || !$sig) {
            return new WP_Error('sameh_missing_sig', 'Missing signature headers', array('status' => 401));
        }
        if (!ctype_digit((string) $ts)) {
            return new WP_Error('sameh_bad_ts', 'Invalid timestamp', array('status' => 401));
        }
        if (abs(time() - (int) $ts) > 300) {
            return new WP_Error('sameh_ts_skew', 'Timestamp outside window', array('status' => 401));
        }
        $method = strtoupper($request->get_method());
        $path = $request->get_route();
        $body = $request->get_body();
        $base = $ts . chr(10) . $method . chr(10) . $path . chr(10) . $body;
        $expect = hash_hmac('sha256', $base, $secret);
        if (!hash_equals($expect, strtolower($sig)) && !hash_equals($expect, $sig)) {
            return new WP_Error('sameh_bad_sig', 'Invalid signature', array('status' => 401));
        }
        return true;
    }
}
