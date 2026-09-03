<?php

namespace Lexa\Analysis\Packs;

use Lexa\Analysis\Contracts\LanguagePack;
use Lexa\Analysis\Folder;
use Lexa\Analysis\TokenClass;

/**
 * Vietnamese pack. Claims ONLY diacritic words, so it can never touch a Latin
 * brand or a code. Emits a folded (diacritic-stripped) variant alongside the
 * original-accented token, so "may cua" (no marks) and "máy cưa" both match.
 * Telex/tone typo expansion is added in P0.5; syllable bigrams are emitted by
 * the analyzer across adjacent Vietnamese tokens.
 */
final class VietnamesePack implements LanguagePack
{
    public const VERSION = 'vi-1';

    public function id(): string { return 'vi'; }
    public function priority(): int { return 20; }

    public function claims(string $surface, string $class): bool
    {
        return $class === TokenClass::WORD_DIACRITIC;
    }

    public function variants(string $surface, string $lower, string $class, string $mode): array
    {
        $fold = Folder::vietnamese($lower);
        if ($fold !== '' && $fold !== $lower) {
            return [['term' => $fold, 'field' => 'text', 'kind' => 'fold']];
        }
        return [];
    }

    /**
     * Vietnamese function words (accented forms — checked against the lowercased
     * token before folding). Critical for recall precision: folding makes "của"
     * → "cua" (collides with "cưa"=saw) and "có" → "co" (collides with "cơ"),
     * so leaving these in floods every query. Conservative list — grammatical
     * words only, never product nouns/attributes.
     */
    public function stopwords(): array
    {
        return [
            'và', 'của', 'có', 'là', 'các', 'cho', 'trong', 'với', 'một', 'này',
            'đó', 'để', 'từ', 'theo', 'những', 'hay', 'hoặc', 'nếu', 'thì', 'mà',
            'đã', 'sẽ', 'đang', 'cũng', 'vẫn', 'ở', 'về', 'được', 'bị', 'do',
            'vì', 'tại', 'bởi', 'khi', 'rất', 'quá', 'lại', 'còn', 'đến', 'nên',
            'như', 'nó', 'tôi', 'bạn', 'họ', 'chúng', 'mình', 'mỗi', 'mọi', 'thế',
        ];
    }

    public function configSignature(): string { return self::VERSION . ':' . Folder::VERSION . ':sw' . count($this->stopwords()); }
}
