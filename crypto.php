<?php
// crypto.php - AES-256-GCM encryption/decryption for medical data
// Store encrypted data as base64: base64( iv(12) || tag(16) || ciphertext )

declare(strict_types=1);

if (!function_exists('get_medical_key')) {
    function get_medical_key(): string {
        // Cách 1 (khuyến nghị): lấy từ ENV
        $hex = getenv('MEDICAL_KEY_HEX');

        // Cách 2 (đồ án): nếu không có ENV thì set tạm ở đây (ĐỪNG commit nếu làm thật)
        if (!$hex) {
            // TODO: thay key của bạn (64 hex chars)
            $hex = 'PUT_YOUR_64_HEX_CHARS_KEY_HERE_PUT_YOUR_64_HEX_CHARS_KEY_HERE';
        }

        $hex = trim($hex);
        if (!preg_match('/^[0-9a-fA-F]{64}$/', $hex)) {
            throw new RuntimeException('MEDICAL_KEY_HEX must be 64 hex characters (32 bytes).');
        }

        $key = hex2bin($hex);
        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException('Invalid encryption key.');
        }
        return $key;
    }
}

if (!function_exists('encrypt_text')) {
    function encrypt_text(?string $plain): ?string {
        if ($plain === null) return null;
        $plain = (string)$plain;
        if ($plain === '') return ''; // giữ rỗng cho tiện

        $key = get_medical_key();
        $iv  = random_bytes(12); // 96-bit nonce for GCM
        $tag = '';

        $cipher = openssl_encrypt(
            $plain,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '' /* AAD */,
            16 /* tag length */
        );

        if ($cipher === false || strlen($tag) !== 16) {
            throw new RuntimeException('Encryption failed.');
        }

        return base64_encode($iv . $tag . $cipher);
    }
}

if (!function_exists('decrypt_text')) {
    function decrypt_text(?string $encoded): ?string {
        if ($encoded === null) return null;
        $encoded = (string)$encoded;
        if ($encoded === '') return '';

        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) < (12 + 16 + 1)) {
            // Dữ liệu không phải ciphertext hợp lệ
            // Nếu bạn đang migrate dần, có thể chọn: return $encoded; (fallback plaintext)
            throw new RuntimeException('Ciphertext is invalid or corrupted.');
        }

        $iv   = substr($raw, 0, 12);
        $tag  = substr($raw, 12, 16);
        $ct   = substr($raw, 28);

        $key = get_medical_key();

        $plain = openssl_decrypt(
            $ct,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '' /* AAD */
        );

        if ($plain === false) {
            throw new RuntimeException('Decryption failed (wrong key or corrupted data).');
        }

        return $plain;
    }
}