<?php

namespace App\Services;

/**
 * ArabicNormalizer
 *
 * Normalises Arabic text so that searches and comparisons are
 * insensitive to:
 *   • Alif variants  (أ إ آ ٱ  →  ا)
 *   • Taa marbuta   (ة  →  ه)
 *   • Alif maqsura  (ى  →  ي)
 *   • Diacritics / harakat (fatha, kasra, damma, sukun, shadda, …)
 *
 * Usage — normalise before storing:
 *   $drug->commercial_name_ar = ArabicNormalizer::normalize($input);
 *
 * Usage — normalise both sides before searching:
 *   $term = ArabicNormalizer::normalize($request->search);
 *   Drug::where('commercial_name_ar_normalized', 'LIKE', "%{$term}%")
 *
 * Simplest approach (no extra column needed):
 *   Apply normalize() to the search term and also store normalised
 *   values in commercial_name_ar from the start.  Existing rows can
 *   be back-filled with the Artisan command at the bottom of this file.
 */
class ArabicNormalizer
{
    /**
     * Normalise an Arabic string.
     * Safe to call on purely Latin or mixed strings — Latin chars pass through unchanged.
     */
    public static function normalize(string $text): string
    {
        // 1. Strip diacritics (harakat + shadda + superscript alif + tatweel)
        //    Range U+064B–U+065F covers all standard Arabic diacritics.
        //    U+0670 = superscript alif.  U+0640 = tatweel (kashida).
        $text = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $text);

        // 2. Normalise alif variants → bare alif (ا)
        //    U+0622 = آ (alif + madda)
        //    U+0623 = أ (alif + hamza above)
        //    U+0625 = إ (alif + hamza below)
        //    U+0671 = ٱ (alif wasla)
        $text = preg_replace('/[\x{0622}\x{0623}\x{0625}\x{0671}]/u', 'ا', $text);

        // 3. Taa marbuta (ة U+0629) → haa (ه U+0647)
        //    Speakers often write one for the other at word-end.
        $text = str_replace('ة', 'ه', $text);

        // 4. Alif maqsura (ى U+0649) → yaa (ي U+064A)
        $text = str_replace('ى', 'ي', $text);

        // 5. Collapse multiple spaces
        $text = preg_replace('/\s+/u', ' ', trim($text));

        return $text;
    }

    /**
     * Check whether a string contains Arabic characters.
     * Convenience wrapper so callers don't repeat the regex.
     */
    public static function isArabic(string $text): bool
    {
        return (bool) preg_match('/\p{Arabic}/u', $text);
    }
}
