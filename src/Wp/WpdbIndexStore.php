<?php

namespace Lexa\Wp;

use Lexa\Engine\Contracts\IndexStore;

/**
 * $wpdb-backed index store — the production sibling of PdoIndexStore, with the
 * SAME schema and SQL shape. Respects the site table prefix; `postings.term`
 * is utf8mb4_bin (exact-byte match, no collation re-merge of may/máy).
 */
final class WpdbIndexStore implements IndexStore
{
    private string $docs;
    private string $zones;
    private string $postings;
    private bool $inTx = false;

    public function __construct(private \wpdb $db)
    {
        $this->docs     = $db->prefix . 'lexa_docs';
        $this->zones    = $db->prefix . 'lexa_doc_zones';
        $this->postings = $db->prefix . 'lexa_postings';
    }

    /**
     * Batch writes in a transaction so the whole index build commits with ONE
     * fsync instead of one per INSERT (the difference between ~4 minutes and a
     * few seconds for the catalog). flush() commits; reads on the same
     * connection still see uncommitted rows.
     */
    private function ensureTx(): void
    {
        if (!$this->inTx) {
            $this->db->query('START TRANSACTION');
            $this->inTx = true;
        }
    }

    public function install(): void
    {
        $this->db->query("CREATE TABLE IF NOT EXISTS `{$this->docs}` (
            doc_id BIGINT UNSIGNED NOT NULL PRIMARY KEY
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->db->query("CREATE TABLE IF NOT EXISTS `{$this->zones}` (
            doc_id BIGINT UNSIGNED NOT NULL,
            zone VARCHAR(16) NOT NULL,
            len INT UNSIGNED NOT NULL,
            PRIMARY KEY (doc_id, zone)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->db->query("CREATE TABLE IF NOT EXISTS `{$this->postings}` (
            term VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
            zone VARCHAR(16) NOT NULL,
            doc_id BIGINT UNSIGNED NOT NULL,
            kw FLOAT NOT NULL,
            tf INT UNSIGNED NOT NULL,
            PRIMARY KEY (term, zone, doc_id),
            KEY doc_idx (doc_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    public function flushAll(): void
    {
        foreach ([$this->postings, $this->zones, $this->docs] as $t) {
            $this->db->query("TRUNCATE TABLE `{$t}`");
        }
    }

    public function putDoc(int $docId, array $zoneLengths): void
    {
        $this->ensureTx();
        $this->db->query($this->db->prepare("INSERT INTO `{$this->docs}` (doc_id) VALUES (%d) ON DUPLICATE KEY UPDATE doc_id=doc_id", $docId));
        foreach ($zoneLengths as $zone => $len) {
            $this->db->query($this->db->prepare(
                "INSERT INTO `{$this->zones}` (doc_id, zone, len) VALUES (%d, %s, %d) ON DUPLICATE KEY UPDATE len=VALUES(len)",
                $docId, $zone, $len
            ));
        }
    }

    public function addPosting(int $docId, string $term, string $zone, float $kw, int $tf): void
    {
        $this->ensureTx();
        $this->db->query($this->db->prepare(
            "INSERT INTO `{$this->postings}` (term, zone, doc_id, kw, tf) VALUES (%s, %s, %d, %f, %d) ON DUPLICATE KEY UPDATE kw=VALUES(kw), tf=VALUES(tf)",
            $term, $zone, $docId, $kw, $tf
        ));
    }

    public function deleteDoc(int $docId): void
    {
        $this->ensureTx();
        foreach ([$this->postings, $this->zones, $this->docs] as $t) {
            $this->db->query($this->db->prepare("DELETE FROM `{$t}` WHERE doc_id = %d", $docId));
        }
    }

    public function flush(): void
    {
        if ($this->inTx) {
            $this->db->query('COMMIT');
            $this->inTx = false;
        }
    }

    public function docCount(): int
    {
        return (int) $this->db->get_var("SELECT COUNT(*) FROM `{$this->docs}`");
    }

    public function avgZoneLengths(): array
    {
        $n = $this->docCount();
        if ($n === 0) {
            return [];
        }
        $avg = [];
        foreach ($this->db->get_results("SELECT zone, SUM(len) AS s FROM `{$this->zones}` GROUP BY zone", ARRAY_A) ?: [] as $r) {
            $avg[$r['zone']] = ((float) $r['s']) / $n;
        }
        return $avg;
    }

    public function dfForTerms(array $terms): array
    {
        $df = array_fill_keys($terms, 0);
        if (!$terms) {
            return $df;
        }
        $in  = implode(',', array_fill(0, count($terms), '%s'));
        $sql = $this->db->prepare("SELECT term, COUNT(DISTINCT doc_id) AS c FROM `{$this->postings}` WHERE term IN ($in) GROUP BY term", $terms);
        foreach ($this->db->get_results($sql, ARRAY_A) ?: [] as $r) {
            $df[$r['term']] = (int) $r['c'];
        }
        return $df;
    }

    public function postingsForTerms(array $terms): array
    {
        if (!$terms) {
            return [];
        }
        $in  = implode(',', array_fill(0, count($terms), '%s'));
        $sql = $this->db->prepare("SELECT doc_id, term, zone, kw, tf FROM `{$this->postings}` WHERE term IN ($in)", $terms);
        $rows = [];
        foreach ($this->db->get_results($sql, ARRAY_A) ?: [] as $r) {
            $rows[] = ['doc_id' => (int) $r['doc_id'], 'term' => $r['term'], 'zone' => $r['zone'], 'kw' => (float) $r['kw'], 'tf' => (int) $r['tf']];
        }
        return $rows;
    }

    public function zoneLengthsForDocs(array $docIds): array
    {
        if (!$docIds) {
            return [];
        }
        $in  = implode(',', array_fill(0, count($docIds), '%d'));
        $sql = $this->db->prepare("SELECT doc_id, zone, len FROM `{$this->zones}` WHERE doc_id IN ($in)", array_map('intval', $docIds));
        $out = [];
        foreach ($this->db->get_results($sql, ARRAY_A) ?: [] as $r) {
            $out[(int) $r['doc_id']][$r['zone']] = (int) $r['len'];
        }
        return $out;
    }

    public function vocabulary(string $firstChar, int $limit, int $minFreq = 2): array
    {
        $like = $this->db->esc_like($firstChar) . '%';
        $sql  = $this->db->prepare(
            "SELECT term FROM `{$this->postings}` WHERE term LIKE %s GROUP BY term HAVING COUNT(DISTINCT doc_id) >= %d ORDER BY COUNT(DISTINCT doc_id) DESC LIMIT %d",
            $like, $minFreq, $limit
        );
        return array_map('strval', (array) $this->db->get_col($sql));
    }
}
