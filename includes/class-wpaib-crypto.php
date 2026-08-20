<?php

defined('ABSPATH') || exit;

final class WPAIB_Crypto {
    public static function encrypt(string $plain): string|WP_Error {
        if ('' === $plain) return '';
        $key = self::key();

        if (function_exists('sodium_crypto_secretbox')) {
            try {
                $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
                return 'sodium:' . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $key));
            } catch (Throwable $error) {
                return new WP_Error('wpaib_encrypt', 'Unable to encrypt secret: ' . $error->getMessage());
            }
        }

        if (function_exists('openssl_encrypt')) {
            try {
                $iv = random_bytes(12);
            } catch (Throwable $error) {
                return new WP_Error('wpaib_encrypt', 'Unable to generate encryption nonce.');
            }
            $tag = '';
            $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
            if (false === $cipher) return new WP_Error('wpaib_encrypt', 'Unable to encrypt secret.');
            return 'openssl:' . base64_encode($iv . $tag . $cipher);
        }

        return new WP_Error('wpaib_encrypt_unavailable', 'Server requires Sodium or OpenSSL to store secrets safely.');
    }

    public static function decrypt(string $stored): string {
        if ('' === $stored) return '';
        $key = self::key();

        if (str_starts_with($stored, 'sodium:') && function_exists('sodium_crypto_secretbox_open')) {
            $decoded = base64_decode(substr($stored, 7), true);
            if (false === $decoded || strlen($decoded) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) return '';
            $nonce = substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $cipher = substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $plain = sodium_crypto_secretbox_open($cipher, $nonce, $key);
            return false === $plain ? '' : $plain;
        }

        if (str_starts_with($stored, 'openssl:') && function_exists('openssl_decrypt')) {
            $decoded = base64_decode(substr($stored, 8), true);
            if (false === $decoded || strlen($decoded) <= 28) return '';
            $iv = substr($decoded, 0, 12);
            $tag = substr($decoded, 12, 16);
            $cipher = substr($decoded, 28);
            $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
            return false === $plain ? '' : $plain;
        }

        return '';
    }

    private static function key(): string {
        return hash('sha256', wp_salt('auth') . '|wp-ai-bridge', true);
    }
}
