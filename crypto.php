<?php
// crypto.php
// Hỗ trợ:
// 1) Key mới: MEDICAL_KEY_HEX (hex 64 ký tự) - AES-256-GCM
// 2) Key cũ: DB_ENC_KEY (base64 của 32 bytes) - AES-256-GCM
//
// Format ciphertext:
// base64( iv(12) || tag(16) || ciphertext )

declare(strict_types=1);

/* =========================================================
   KEY MỚI: MEDICAL_KEY_HEX
========================================================= */
if (!function_exists('get_medical_key')) {
    function get_medical_key(): string {
        $hex = getenv('MEDICAL_KEY_HEX');

        // fallback local nếu ENV chưa đọc được
        if (!$hex) {
            $hex = '8955dd59f56762fcb35498e9388bfff11d61f961fcd246c7cf59fc2e8b62b86a';
        }

        $hex = trim((string)$hex);

        if (!preg_match('/^[0-9a-fA-F]{64}$/', $hex)) {
            throw new RuntimeException('MEDICAL_KEY_HEX must be 64 hex characters (32 bytes).');
        }

        $key = hex2bin($hex);
        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException('Invalid MEDICAL_KEY_HEX.');
        }

        return $key;
    }
}

/* =========================================================
   KEY CŨ: DB_ENC_KEY
========================================================= */
if (!function_exists('enc_key')) {
    function enc_key(): string {
        $b64 = getenv('DB_ENC_KEY');

        // fallback local nếu ENV chưa đọc được
        if (!$b64) {
            $b64 = 'Yin9yJtFmsfssW5ayz6OLkvCJ4CADuzgpFvJN3y1ARk=';
        }

        $b64 = trim((string)$b64);
        $raw = base64_decode($b64, true);

        if ($raw === false || strlen($raw) !== 32) {
            throw new RuntimeException('Missing/invalid DB_ENC_KEY (must be base64 of 32 bytes).');
        }

        return $raw;
    }
}

/* =========================================================
   HÀM CHUNG: ENCRYPT / DECRYPT AES-256-GCM
========================================================= */
if (!function_exists('gcm_encrypt_with_key')) {
    function gcm_encrypt_with_key(string $plain, string $key): string {
        $iv  = random_bytes(12); // 96-bit nonce
        $tag = '';

        $cipher = openssl_encrypt(
            $plain,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            16
        );

        if ($cipher === false || strlen($tag) !== 16) {
            throw new RuntimeException('Encryption failed.');
        }

        return base64_encode($iv . $tag . $cipher);
    }
}

if (!function_exists('gcm_decrypt_with_key')) {
    function gcm_decrypt_with_key(string $encoded, string $key): string {
        $raw = base64_decode($encoded, true);

        if ($raw === false || strlen($raw) < 29) {
            throw new RuntimeException('Ciphertext is invalid or corrupted.');
        }

        $iv  = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $ct  = substr($raw, 28);

        $plain = openssl_decrypt(
            $ct,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            ''
        );

        if ($plain === false) {
            throw new RuntimeException('Decryption failed (wrong key or corrupted data).');
        }

        return $plain;
    }
}

/* =========================================================
   CƠ CHẾ MỚI
========================================================= */
if (!function_exists('encrypt_text')) {
    function encrypt_text(?string $plain): ?string {
        if ($plain === null) return null;
        $plain = (string)$plain;
        if ($plain === '') return '';

        $key = get_medical_key();
        return gcm_encrypt_with_key($plain, $key);
    }
}

if (!function_exists('decrypt_text')) {
    function decrypt_text(?string $encoded): ?string {
        if ($encoded === null) return null;
        $encoded = (string)$encoded;
        if ($encoded === '') return '';

        $key = get_medical_key();
        return gcm_decrypt_with_key($encoded, $key);
    }
}

/* =========================================================
   CƠ CHẾ CŨ (DB_ENC_KEY)
========================================================= */
if (!function_exists('encrypt_db')) {
    function encrypt_db(?string $plain): ?string {
        if ($plain === null) return null;
        $plain = (string)$plain;
        if (trim($plain) === '') return null;

        $key = enc_key();
        return gcm_encrypt_with_key($plain, $key);
    }
}

if (!function_exists('decrypt_text_old')) {
    function decrypt_text_old(?string $encoded): ?string {
        if ($encoded === null) return null;
        $encoded = (string)$encoded;
        if ($encoded === '') return '';

        $key = enc_key();
        return gcm_decrypt_with_key($encoded, $key);
    }
}

/* =========================================================
   DECRYPT TỰ ĐỘNG: thử key mới trước, fail thì thử key cũ
========================================================= */
if (!function_exists('decrypt_text_auto')) {
    function decrypt_text_auto(?string $encoded): ?string {
        if ($encoded === null) return null;
        $encoded = (string)$encoded;
        if ($encoded === '') return '';

        try {
            return decrypt_text($encoded); // MEDICAL_KEY_HEX
        } catch (Throwable $e) {
            return decrypt_text_old($encoded); // DB_ENC_KEY
        }
    }
}