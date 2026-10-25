<?php

namespace App\Services;

/**
 * DrugMatcher
 *
 * Picks the right drug among candidates that share a brand name,
 * using the strength numbers in the uploaded name as a HARD rule:
 *
 *   - Every strength number in the uploaded name (2.5, 10, 500 ...) must
 *     appear in the candidate's trade name, or the candidate is rejected.
 *   - Among the survivors, prefer extra matching words ("cor", "plus"),
 *     then fewer unrelated numbers, then the shorter name.
 *
 * "concor 2.5" -> CONCOR COR 2.5 MG 30 F.C. TABS.   (never CONCOR 10 MG)
 */
class DrugMatcher
{
    private const DIGITS = [
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٫' => '.',
    ];

    /** "CONCOR COR 2.5MG 30 F.C. TABS." -> [concor, cor, 2.5, mg, 30, f.c, tabs] */
    public static function tokenize(string $text): array
    {
        $text = mb_strtolower(strtr($text, self::DIGITS), 'UTF-8');
        $text = preg_replace('/(\d)(\p{L})/u', '$1 $2', $text);      // "2.5mg" -> "2.5 mg"
        $tokens = preg_split('/[\s\-\/,()+]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        $tokens = array_map(fn ($t) => trim($t, '.,:;'), $tokens);    // dots INSIDE "2.5" are kept

        return array_values(array_filter($tokens, fn ($t) => $t !== ''));
    }

    /** Numeric tokens, normalised so "2.50" == "2.5" */
    public static function strengths(array $tokens): array
    {
        $out = [];
        foreach ($tokens as $t) {
            if (preg_match('/^\d+(\.\d+)?$/', $t)) {
                $out[] = (string) (float) $t;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param array    $uploadedTokens  tokenize() of the uploaded name (index 0 = brand)
     * @param iterable $candidates      [drug_id => trade_name]
     * @return int|null  best drug id, or null if no candidate has the right strength
     */
    public static function pickBest(array $uploadedTokens, iterable $candidates): ?int
    {
        $upStrengths = self::strengths($uploadedTokens);
        $upWords = array_slice(
            array_values(array_filter($uploadedTokens, fn ($t) => ! preg_match('/^\d+(\.\d+)?$/', $t))),
            1   // skip the brand word — every candidate already shares it
        );

        $bestId    = null;
        $bestScore = PHP_INT_MIN;

        foreach ($candidates as $id => $name) {
            if (empty($name)) continue;

            $ct         = self::tokenize($name);
            $cStrengths = self::strengths($ct);

            // HARD RULE: uploaded strength(s) must all exist in the candidate
            if (array_diff($upStrengths, $cStrengths)) continue;

            $score = 0;
            foreach ($upWords as $w) {
                if (in_array($w, $ct, true)) $score += 10;               // "cor", "plus", "amlo" ...
            }
            $score -= 3 * count(array_diff($cStrengths, $upStrengths)); // unrelated numbers
            $score -= count($ct);                                        // prefer the simpler name

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestId    = (int) $id;
            }
        }

        return $bestId;
    }
}
