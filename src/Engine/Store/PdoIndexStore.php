<?php

namespace Lexa\Engine\Store;

use Lexa\Engine\Contracts\IndexStore;
use PDO;

/**
 * MySQL inverted-index store (PDO). Same behavior as ArrayIndexStore, persisted
 * across three tables: docs, doc_zones (per-zone field lengths), and postings
 * (term → doc in a zone, with kind-weight + term frequency).
 *
 * The `postings.term` column is utf8mb4_bin so term matching is exact-byte —
 * the analyzer already folds diacritics deterministically, so we must NOT let
 * the DB collation re-merge "may"/"máy".
 *
 * Writes use a lazy transaction committed by flush(); reads on the same
 * connection see uncommitted writes, so query() works before flush().
 *
 * P2 will add a $wpdb-backed sibling with identical SQL; this PDO store keeps
 * P1 testable standalone against a real MySQL.
 */
final class PdoIndexStore implements IndexStore
{
    private bool $inTx = false;

    public function __construct(private PDO $pdo, private string $prefix = 'lexa_')
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    private function t(string $name): string { return $this->prefix . $name; }

    private function ensureTx(): void
    {
        if (!$this->inTx) {
            $this->pdo->beginTransaction();
            $this->inTx = true;
        }
    }

    public function install(): void
    {
        $docs = $this->t('docs');
        $zones = $this->t('doc_zones');
        $postings = $this->t('postings');
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS `{$docs}` (
            doc_id BIGINT UNSIGNED NOT NULL PRIMARY KEY
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS `{$zones}` (
            doc_id BIGINT UNSIGNED NOT NULL,
            zone VARCHAR(16) NOT NULL,
            len INT UNSIGNED NOT NULL,
            PRIMARY KEY (doc_id, zone)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS `{$postings}` (
            term VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
            zone VARCHAR(16) NOT NULL,
            doc_id BIGINT UNSIGNED NOT NULL,
            kw FLOAT NOT NULL,
            tf INT UNSIGNED NOT NULL,
            PRIMARY KEY (term, zone, doc_id),
            KEY doc_idx (doc_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    public function reset(): void
    {
        foreach (['postings', 'doc_zones', 'docs'] as $name) {
            $this->pdo->exec('TRUNCATE TABLE `' . $this->t($name) . '`');
        }
    }

    public function dropTables(): void
    {
        foreach (['postings', 'doc_zones', 'docs'] as $name) {
            $this->pdo->exec('DROP TABLE IF EXISTS `' . $this->t($name) . '`');
        }
    }

    public function putDoc(int $docId, array $zoneLengths): void
    {
        $this->ensureTx();
        $this->pdo->prepare('INSERT INTO `' . $this->t('docs') . '` (doc_id) VALUES (?) ON DUPLICATE KEY UPDATE doc_id=doc_id')
            ->execute([$docId]);
        $stmt = $this->pdo->prepare('INSERT INTO `' . $this->t('doc_zones') . '` (doc_id, zone, len) VALUES (?,?,?) ON DUPLICATE KEY UPDATE len=VALUES(len)');
        foreach ($zoneLengths as $zone => $len) {
            $stmt->execute([$docId, $zone, $len]);
        }
    }

    public function addPosting(int $docId, string $term, string $zone, float $kw, int $tf): void
    {
        $this->ensureTx();
        $this->pdo->prepare('INSERT INTO `' . $this->t('postings') . '` (term, zone, doc_id, kw, tf) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE kw=VALUES(kw), tf=VALUES(tf)')
            ->execute([$term, $zone, $docId, $kw, $tf]);
    }

    public function deleteDoc(int $docId): void
    {
        $this->ensureTx();
        foreach (['postings', 'doc_zones', 'docs'] as $name) {
            $this->pdo->prepare('DELETE FROM `' . $this->t($name) . '` WHERE doc_id = ?')->execute([$docId]);
        }
    }

    public function flush(): void
    {
        if ($this->inTx) {
            $this->pdo->commit();
            $this->inTx = false;
        }
    }

    public function docCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM `' . $this->t('docs') . '`')->fetchColumn();
    }

    public function avgZoneLengths(): array
    {
        $n = $this->docCount();
        if ($n === 0) {
            return [];
        }
        $rows = $this->pdo->query('SELECT zone, SUM(len) AS s FROM `' . $this->t('doc_zones') . '` GROUP BY zone')->fetchAll(PDO::FETCH_ASSOC);
        $avg = [];
        foreach ($rows as $r) {
            $avg[$r['zone']] = ((float) $r['s']) / $n; // average over ALL docs (zone-absent => 0)
        }
        return $avg;
    }

    public function dfForTerms(array $terms): array
    {
        $df = array_fill_keys($terms, 0);
        if (!$terms) {
            return $df;
        }
        $in = implode(',', array_fill(0, count($terms), '?'));
        $stmt = $this->pdo->prepare('SELECT term, COUNT(DISTINCT doc_id) AS c FROM `' . $this->t('postings') . "` WHERE term IN ($in) GROUP BY term");
        $stmt->execute(array_values($terms));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $df[$r['term']] = (int) $r['c'];
        }
        return $df;
    }

    public function postingsForTerms(array $terms): array
    {
        if (!$terms) {
            return [];
        }
        $in = implode(',', array_fill(0, count($terms), '?'));
        $stmt = $this->pdo->prepare('SELECT doc_id, term, zone, kw, tf FROM `' . $this->t('postings') . "` WHERE term IN ($in)");
        $stmt->execute(array_values($terms));
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rows[] = ['doc_id' => (int) $r['doc_id'], 'term' => $r['term'], 'zone' => $r['zone'], 'kw' => (float) $r['kw'], 'tf' => (int) $r['tf']];
        }
        return $rows;
    }

    public function zoneLengthsForDocs(array $docIds): array
    {
        if (!$docIds) {
            return [];
        }
        $in = implode(',', array_fill(0, count($docIds), '?'));
        $stmt = $this->pdo->prepare('SELECT doc_id, zone, len FROM `' . $this->t('doc_zones') . "` WHERE doc_id IN ($in)");
        $stmt->execute(array_map('intval', array_values($docIds)));
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['doc_id']][$r['zone']] = (int) $r['len'];
        }
        return $out;
    }

    public function vocabulary(string $firstChar, int $limit, int $minFreq = 2): array
    {
        $like = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $firstChar) . '%';
        $sql  = 'SELECT term FROM `' . $this->t('postings') . '` WHERE term LIKE ? '
            . 'GROUP BY term HAVING COUNT(DISTINCT doc_id) >= ? ORDER BY COUNT(DISTINCT doc_id) DESC LIMIT ' . (int) $limit;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$like, $minFreq]);
        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}
