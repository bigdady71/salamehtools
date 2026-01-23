<?php

declare(strict_types=1);

/**
 * Language and Translation Helper Functions
 * Supports English and Arabic translations
 */

// Store translations in memory cache
$GLOBALS['translations_cache'] = [];
$GLOBALS['current_language'] = null;

/**
 * Get current user's preferred language
 * @return string 'en' or 'ar'
 */
function get_user_language(): string {
    if ($GLOBALS['current_language'] !== null) {
        return $GLOBALS['current_language'];
    }

    // Check if language is set in session
    if (isset($_SESSION['language']) && in_array($_SESSION['language'], ['en', 'ar'])) {
        $GLOBALS['current_language'] = $_SESSION['language'];
        return $_SESSION['language'];
    }

    // Check if user is logged in and has preference
    if (isset($_SESSION['user_id'])) {
        try {
            $pdo = db();
            $userId = (int)$_SESSION['user_id'];

            // Check if it's a customer
            if (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'customer') {
                $stmt = $pdo->prepare("SELECT preferred_language FROM customers WHERE user_id = ?");
                $stmt->execute([$userId]);
                $lang = $stmt->fetchColumn();
                if ($lang && in_array($lang, ['en', 'ar'])) {
                    $_SESSION['language'] = $lang;
                    $GLOBALS['current_language'] = $lang;
                    return $lang;
                }
            } else {
                // Regular user
                $stmt = $pdo->prepare("SELECT preferred_language FROM users WHERE id = ?");
                $stmt->execute([$userId]);
                $lang = $stmt->fetchColumn();
                if ($lang && in_array($lang, ['en', 'ar'])) {
                    $_SESSION['language'] = $lang;
                    $GLOBALS['current_language'] = $lang;
                    return $lang;
                }
            }
        } catch (Exception $e) {
            error_log("Error fetching user language: " . $e->getMessage());
        }
    }

    // Default to Arabic for sales portal
    $GLOBALS['current_language'] = 'ar';
    $_SESSION['language'] = 'ar';
    return 'ar';
}

/**
 * Set user's language preference
 * @param string $lang 'en' or 'ar'
 * @return bool Success status
 */
function set_user_language(string $lang): bool {
    if (!in_array($lang, ['en', 'ar'])) {
        return false;
    }

    $_SESSION['language'] = $lang;
    $GLOBALS['current_language'] = $lang;

    // Update database if user is logged in
    if (isset($_SESSION['user_id'])) {
        try {
            $pdo = db();
            $userId = (int)$_SESSION['user_id'];

            if (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'customer') {
                $stmt = $pdo->prepare("UPDATE customers SET preferred_language = ? WHERE user_id = ?");
                $stmt->execute([$lang, $userId]);
            } else {
                $stmt = $pdo->prepare("UPDATE users SET preferred_language = ? WHERE id = ?");
                $stmt->execute([$lang, $userId]);
            }
            return true;
        } catch (Exception $e) {
            error_log("Error setting user language: " . $e->getMessage());
            return false;
        }
    }

    return true;
}

/**
 * Get text direction based on current language
 * @return string 'ltr' or 'rtl'
 */
function get_direction(): string {
    return get_user_language() === 'ar' ? 'rtl' : 'ltr';
}

/**
 * Translate a key to current language
 * @param string $key Translation key
 * @param string|null $default Default text if translation not found
 * @return string Translated text
 */
function t(string $key, ?string $default = null): string {
    $lang = get_user_language();

    // Check cache first
    if (isset($GLOBALS['translations_cache'][$key][$lang])) {
        return $GLOBALS['translations_cache'][$key][$lang];
    }

    // Fetch from database
    try {
        $pdo = db();
        $stmt = $pdo->prepare("SELECT english_text, arabic_text FROM translations WHERE translation_key = ?");
        $stmt->execute([$key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            // Cache both languages
            $GLOBALS['translations_cache'][$key]['en'] = $row['english_text'];
            $GLOBALS['translations_cache'][$key]['ar'] = $row['arabic_text'];

            return $lang === 'ar' ? $row['arabic_text'] : $row['english_text'];
        }
    } catch (Exception $e) {
        error_log("Translation error for key '{$key}': " . $e->getMessage());
    }

    // Return default or key itself
    return $default ?? $key;
}

/**
 * Translate with sprintf-style formatting
 * @param string $key Translation key
 * @param mixed ...$args Arguments for sprintf
 * @return string Formatted translated text
 */
function tf(string $key, ...$args): string {
    $text = t($key);
    return sprintf($text, ...$args);
}

/**
 * Get language name
 * @param string|null $lang Language code (null = current)
 * @return string Language name
 */
function get_language_name(?string $lang = null): string {
    $lang = $lang ?? get_user_language();
    return $lang === 'ar' ? 'العربية' : 'English';
}

/**
 * Get opposite language code
 * @return string Opposite language code
 */
function get_other_language(): string {
    return get_user_language() === 'ar' ? 'en' : 'ar';
}

/**
 * Format number based on current language
 * @param float $number Number to format
 * @param int $decimals Decimal places
 * @return string Formatted number
 */
function format_number(float $number, int $decimals = 2): string {
    $formatted = number_format($number, $decimals);

    // Convert to Arabic numerals if Arabic language
    if (get_user_language() === 'ar') {
        $arabicNumerals = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
        $westernNumerals = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
        return str_replace($westernNumerals, $arabicNumerals, $formatted);
    }

    return $formatted;
}

/**
 * Format date based on current language
 * @param string $date Date string
 * @param string $format Format string
 * @return string Formatted date
 */
function format_date(string $date, string $format = 'Y-m-d'): string {
    $timestamp = strtotime($date);

    if (get_user_language() === 'ar') {
        // Arabic month names
        $arabicMonths = [
            1 => 'يناير', 2 => 'فبراير', 3 => 'مارس', 4 => 'أبريل',
            5 => 'مايو', 6 => 'يونيو', 7 => 'يوليو', 8 => 'أغسطس',
            9 => 'سبتمبر', 10 => 'أكتوبر', 11 => 'نوفمبر', 12 => 'ديسمبر'
        ];

        $day = date('d', $timestamp);
        $month = (int)date('m', $timestamp);
        $year = date('Y', $timestamp);

        return "$day {$arabicMonths[$month]} $year";
    }

    return date($format, $timestamp);
}

/**
 * Check if current language is Arabic
 * @return bool True if Arabic
 */
function is_arabic(): bool {
    return get_user_language() === 'ar';
}

/**
 * Check if current language is English
 * @return bool True if English
 */
function is_english(): bool {
    return get_user_language() === 'en';
}

/**
 * Batch insert translations
 * @param array $translations Array of ['key' => ['en' => 'text', 'ar' => 'text']]
 * @return int Number of translations inserted
 */
function insert_translations(array $translations): int {
    $pdo = db();
    $inserted = 0;

    foreach ($translations as $key => $texts) {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO translations (translation_key, english_text, arabic_text)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    english_text = VALUES(english_text),
                    arabic_text = VALUES(arabic_text)
            ");
            $stmt->execute([
                $key,
                $texts['en'] ?? $key,
                $texts['ar'] ?? $key
            ]);
            $inserted++;
        } catch (Exception $e) {
            error_log("Error inserting translation '{$key}': " . $e->getMessage());
        }
    }

    return $inserted;
}
