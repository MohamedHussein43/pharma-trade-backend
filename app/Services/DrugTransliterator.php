<?php

namespace App\Services;

use App\Models\Drug;

/**
 * DrugTransliterator
 *
 * Converts drug names between Arabic and English using:
 *   1. Direct dataset match (trusted, exact)
 *   2. Transliteration → dataset correction (best-effort)
 *   3. Raw transliteration placeholder (never null)
 *
 * Usage:
 *   $result = DrugTransliterator::arabicToEnglish('أموكسيل');
 *   // → ['value' => 'Amoxil', 'source' => 'dataset']
 *
 *   $result = DrugTransliterator::englishToArabic('Amoxil');
 *   // → ['value' => 'أموكسيل', 'source' => 'dataset']
 *
 * Result 'source' values:
 *   'dataset'         → matched directly in drugs table (most trusted)
 *   'dataset_via_transliteration' → transliteration matched a dataset entry (corrected)
 *   'transliteration' → no dataset match, stored as-is (placeholder)
 */
class DrugTransliterator
{
    // ──────────────────────────────────────────────────────────
    // Arabic → English
    // ──────────────────────────────────────────────────────────

    /**
     * Given an Arabic drug name, return the best English equivalent.
     *
     * @return array{value: string, source: string}
     */
    public static function arabicToEnglish(string $arabicName): array
    {
        $arabicName = trim($arabicName);

        // Step 1 — Direct dataset match on commercial_name_ar
        $drug = Drug::where('commercial_name_ar', 'like', "%{$arabicName}%")
            ->whereNotNull('trade_name')
            ->first(['trade_name']);

        if ($drug && ! empty($drug->trade_name)) {
            return ['value' => $drug->trade_name, 'source' => 'dataset'];
        }

        // Step 2 — Transliterate then try to correct via dataset
        $transliterated = self::transliterateArabicToLatin($arabicName);

        $drug = Drug::where('trade_name', 'like', "%{$transliterated}%")
            ->whereNotNull('trade_name')
            ->first(['trade_name']);

        if ($drug && ! empty($drug->trade_name)) {
            return ['value' => $drug->trade_name, 'source' => 'dataset_via_transliteration'];
        }

        // Step 3 — Use transliteration as placeholder
        return ['value' => $transliterated, 'source' => 'transliteration'];
    }

    /**
     * Given an English drug name, return the best Arabic equivalent.
     *
     * @return array{value: string, source: string}
     */
    public static function englishToArabic(string $englishName): array
    {
        $englishName = trim($englishName);

        // Step 1 — Direct dataset match on trade_name
        $drug = Drug::where('trade_name', 'like', "%{$englishName}%")
            ->whereNotNull('commercial_name_ar')
            ->first(['commercial_name_ar']);

        if ($drug && ! empty($drug->commercial_name_ar)) {
            return ['value' => $drug->commercial_name_ar, 'source' => 'dataset'];
        }

        // Step 2 — Transliterate then try to correct via dataset
        $transliterated = self::transliterateLatinToArabic($englishName);

        $drug = Drug::where('commercial_name_ar', 'like', "%{$transliterated}%")
            ->whereNotNull('commercial_name_ar')
            ->first(['commercial_name_ar']);

        if ($drug && ! empty($drug->commercial_name_ar)) {
            return ['value' => $drug->commercial_name_ar, 'source' => 'dataset_via_transliteration'];
        }

        // Step 3 — Use transliteration as placeholder
        return ['value' => $transliterated, 'source' => 'transliteration'];
    }

    // ──────────────────────────────────────────────────────────
    // Transliteration tables
    // Tuned for pharmaceutical trade names (borrowed words,
    // brand names, compound ingredient strings).
    // ──────────────────────────────────────────────────────────

    /**
     * Arabic script → Latin letters.
     *
     * Walks the string character-by-character (using mb_str_split for
     * multibyte safety). After mapping each consonant it checks whether
     * the next character is also a consonant and, if so, inserts an 'e'
     * between them — the most common vowel sound in Arabic-origin drug
     * trade names written in Latin script (e.g. تكنو → "techno" not "tkno").
     *
     * Special cases handled:
     *   ي after a consonant cluster → 'y'  (ميثايل → methyl not mithail)
     *   و after a consonant          → 'o'  (تكنو   → techno)
     *   ال at word start             → 'al-'
     *   shadda (ّ) doubles the letter
     *   taa marbuta (ة) at word end  → 'a'
     */
    public static function transliterateArabicToLatin(string $text): string
    {
        // Strip all diacritics including shadda — drug trade names don't need them
        $text = preg_replace('/[\x{064B}-\x{065F}\x{0670}]/u', '', $text);

        // Normalise definite article لا → la, ال → al
        $text = preg_replace('/\bال/u', 'al-', $text);
        $text = preg_replace('/\bلا/u', 'la', $text);

        // Map: Arabic character → Latin string
        // Consonants (C), Long vowels (V), Ambiguous (depends on context)
        $map = [
            // Hamzas / alifs
            'ا' => ['l' => 'a',  'v' => true],
            'أ' => ['l' => 'a',  'v' => true],
            'إ' => ['l' => 'i',  'v' => true],
            'آ' => ['l' => 'aa', 'v' => true],
            'ء' => ['l' => '',   'v' => true],
            'ئ' => ['l' => 'i',  'v' => true],
            'ؤ' => ['l' => 'u',  'v' => true],
            // Long vowels / semivowels
            'و' => ['l' => 'o',       'v' => true],
            'ي' => ['l' => '__YA__',  'v' => true],   // resolved in post-pass below
            'ى' => ['l' => 'a',       'v' => true],
            'ة' => ['l' => 'a',       'v' => true],
            // Consonants
            'ب' => ['l' => 'b',   'v' => false],
            'ت' => ['l' => 't',   'v' => false],
            'ث' => ['l' => 'th',  'v' => false],
            'ج' => ['l' => 'j',   'v' => false],
            'ح' => ['l' => 'h',   'v' => false],
            'خ' => ['l' => 'kh',  'v' => false],
            'د' => ['l' => 'd',   'v' => false],
            'ذ' => ['l' => 'dh',  'v' => false],
            'ر' => ['l' => 'r',   'v' => false],
            'ز' => ['l' => 'z',   'v' => false],
            'س' => ['l' => 's',   'v' => false],
            'ش' => ['l' => 'sh',  'v' => false],
            'ص' => ['l' => 's',   'v' => false],
            'ض' => ['l' => 'd',   'v' => false],
            'ط' => ['l' => 't',   'v' => false],
            'ظ' => ['l' => 'dh',  'v' => false],
            'ع' => ['l' => 'a',   'v' => true],   // ayn → vowel-like
            'غ' => ['l' => 'gh',  'v' => false],
            'ف' => ['l' => 'f',   'v' => false],
            'ق' => ['l' => 'q',   'v' => false],
            'ك' => ['l' => 'k',   'v' => false],
            'ل' => ['l' => 'l',   'v' => false],
            'م' => ['l' => 'm',   'v' => false],
            'ن' => ['l' => 'n',   'v' => false],
            'ه' => ['l' => 'h',   'v' => false],
        ];

        $chars   = mb_str_split($text, 1, 'UTF-8');
        $output  = [];   // latin segments
        $isVowel = [];   // parallel bool: is this segment a vowel?

        foreach ($chars as $ch) {
            if ($ch === ' ') {
                $output[]  = ' ';
                $isVowel[] = true;
                continue;
            }
            if (isset($map[$ch])) {
                if ($map[$ch]['l'] === '__double__' && ! empty($output)) {
                    // Shadda — double the last consonant segment
                    for ($k = count($output) - 1; $k >= 0; $k--) {
                        if (! $isVowel[$k] && $output[$k] !== ' ') {
                            $output[]  = $output[$k];
                            $isVowel[] = false;
                            break;
                        }
                    }
                } else {
                    $output[]  = $map[$ch]['l'];
                    $isVowel[] = $map[$ch]['v'];
                }
            } else {
                $output[]  = $ch;
                $isVowel[] = true;
            }
        }

        // Resolve ي context:
        //   after a consonant → 'i'  (ميثايل → mithail, closer to methyl)
        //   at word start / after vowel → 'y'  (يافا → yafa)
        for ($i = 0; $i < count($output); $i++) {
            if ($output[$i] === '__YA__') {
                $prevIsConsonant = ($i > 0 && ! $isVowel[$i - 1] && $output[$i - 1] !== ' ');
                $output[$i] = $prevIsConsonant ? 'i' : 'y';
            }
        }

        // Insert 'e' between consecutive consonants to make names pronounceable
        // e.g. تكنو: ت(C) ك(C) → insert 'e' → "tekno"
        $result = '';
        $n      = count($output);
        for ($i = 0; $i < $n; $i++) {
            $result .= $output[$i];
            if ($i + 1 < $n
                && ! $isVowel[$i]
                && ! $isVowel[$i + 1]
                && $output[$i]     !== ' '
                && $output[$i + 1] !== ' '
            ) {
                $result .= 'e';
            }
        }

        // Capitalise each word, collapse spaces
        $result = preg_replace('/\s+/', ' ', trim($result));
        return mb_convert_case($result, MB_CASE_TITLE, 'UTF-8');
    }

    /**
     * Latin letters → Arabic script.
     * Reverse map — good enough for dataset correction step.
     * Handles the most common patterns in Egyptian drug trade names.
     */
    public static function transliterateLatinToArabic(string $text): string
    {
        // Process longest sequences first to avoid partial replacements
        $map = [
            // Digraphs first
            'kh' => 'خ',  'sh' => 'ش',  'gh' => 'غ',  'dh' => 'ذ',
            'th' => 'ث',  'aa' => 'آ',  'ee' => 'ي',  'oo' => 'و',
            'ph' => 'ف',  'ch' => 'ش',  'ck' => 'ك',
            // Single vowels
            'a'  => 'ا',  'e'  => 'ي',  'i'  => 'ي',  'o'  => 'و',
            'u'  => 'و',
            // Consonants
            'b'  => 'ب',  'c'  => 'ك',  'd'  => 'د',  'f'  => 'ف',
            'g'  => 'ج',  'h'  => 'ه',  'j'  => 'ج',  'k'  => 'ك',
            'l'  => 'ل',  'm'  => 'م',  'n'  => 'ن',  'p'  => 'ب',
            'q'  => 'ق',  'r'  => 'ر',  's'  => 'س',  't'  => 'ت',
            'v'  => 'ف',  'w'  => 'و',  'x'  => 'كس', 'y'  => 'ي',
            'z'  => 'ز',
        ];

        $lower  = strtolower(trim($text));
        $result = '';
        $len    = strlen($lower);
        $i      = 0;

        while ($i < $len) {
            $matched = false;
            // Try digraph first
            if ($i + 1 < $len) {
                $pair = substr($lower, $i, 2);
                if (isset($map[$pair])) {
                    $result  .= $map[$pair];
                    $i       += 2;
                    $matched  = true;
                }
            }
            if (! $matched) {
                $char = $lower[$i];
                $result .= $map[$char] ?? $char;
                $i++;
            }
        }

        return trim($result);
    }
}
