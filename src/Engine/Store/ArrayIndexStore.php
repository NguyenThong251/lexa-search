<?php

namespace Lexa\Engine\Store;

use Lexa\Engine\Contracts\IndexStore;

/**
 * In-memory index store — used to unit-test the BM25F scorer/engine with plain
 * `php`, no database. The MySQL store mirrors this behavior over SQL.
 */
final class ArrayIndexStore implements IndexStore
{
    private array $docs     = []; // docId => [zone => len]
    private array $postings = []; // term => zone => docId => ['kw'=>float,'tf'=>int]
    private array $docTerms = []; // docId => [[term,zone], ...]

    public function putDoc(int $docId, array $zoneLengths): void
    {
        $this->docs[$docId] = $zoneLengths;
    }

    public function addPosting(int $docId, string $term, string $zone, float $kw, int $tf): void
    {
        $this->postings[$term][$zone][$docId] = ['kw' => $kw, 'tf' => $tf];
        $this->docTerms[$docId][] = [$term, $zone];
    }

    public function deleteDoc(int $docId): void
    {
        foreach ($this->docTerms[$docId] ?? [] as [$term, $zone]) {
            unset($this->postings[$term][$zone][$docId]);
            if (empty($this->postings[$term][$zone])) {
                unset($this->postings[$term][$zone]);
            }
            if (empty($this->postings[$term])) {
                unset($this->postings[$term]);
            }
        }
        unset($this->docTerms[$docId], $this->docs[$docId]);
    }

    public function flush(): void {}

    public function docCount(): int { return count($this->docs); }

    public function avgZoneLengths(): array
    {
        $sum = [];
        $count = count($this->docs);
        foreach ($this->docs as $zoneLengths) {
            foreach ($zoneLengths as $zone => $len) {
                $sum[$zone] = ($sum[$zone] ?? 0) + $len;
            }
        }
        $avg = [];
        foreach ($sum as $zone => $total) {
            $avg[$zone] = $count > 0 ? $total / $count : 0.0;
        }
        return $avg;
    }

    public function dfForTerms(array $terms): array
    {
        $df = [];
        foreach ($terms as $t) {
            $docs = [];
            foreach ($this->postings[$t] ?? [] as $byDoc) {
                foreach ($byDoc as $docId => $_) {
                    $docs[$docId] = true;
                }
            }
            $df[$t] = count($docs);
        }
        return $df;
    }

    public function postingsForTerms(array $terms): array
    {
        $rows = [];
        foreach ($terms as $t) {
            foreach ($this->postings[$t] ?? [] as $zone => $byDoc) {
                foreach ($byDoc as $docId => $pd) {
                    $rows[] = ['doc_id' => (int) $docId, 'term' => $t, 'zone' => $zone, 'kw' => $pd['kw'], 'tf' => $pd['tf']];
                }
            }
        }
        return $rows;
    }

    public function zoneLengthsForDocs(array $docIds): array
    {
        $out = [];
        foreach ($docIds as $docId) {
            if (isset($this->docs[$docId])) {
                $out[$docId] = $this->docs[$docId];
            }
        }
        return $out;
    }

    public function vocabulary(string $firstChar, int $limit, int $minFreq = 2): array
    {
        $freq = [];
        foreach ($this->postings as $term => $zones) {
            $term = (string) $term;
            if (mb_substr($term, 0, 1, 'UTF-8') !== $firstChar) {
                continue;
            }
            $docs = [];
            foreach ($zones as $byDoc) {
                foreach ($byDoc as $docId => $_) {
                    $docs[$docId] = true;
                }
            }
            $f = count($docs);
            if ($f >= $minFreq) {
                $freq[$term] = $f;
            }
        }
        arsort($freq); // most frequent first
        return array_slice(array_keys($freq), 0, $limit);
    }
}
