<?php

declare(strict_types=1);

namespace NetherByte\NetherPerms\storage;

use NetherByte\NetherPerms\storage\StorageInterface;
use SQLite3;

/**
 * Simple SQLite key-value storage for NetherPerms data blobs.
 * Keys used:
 *  - users
 *  - groups
 *  - tracks
 */
final class DbStorage implements StorageInterface
{
    private SQLite3 $db;

    public function __construct(private string $dbPath)
    {
        if (!is_dir(dirname($dbPath))) @mkdir(dirname($dbPath), 0777, true);
        $this->db = new SQLite3($dbPath);
        // Performance pragmas suitable for server plugins
        $this->db->busyTimeout(1000);
        $this->db->exec('PRAGMA journal_mode=WAL;');
        $this->db->exec('PRAGMA synchronous=NORMAL;');
        $this->db->exec('PRAGMA temp_store=MEMORY;');
        $this->initSchema();
    }

    private function initSchema() : void
    {
        $this->db->exec('CREATE TABLE IF NOT EXISTS netherperms_store (
            key TEXT PRIMARY KEY,
            value TEXT NOT NULL
        )');
    }

    public function reload() : void
    {
        // no-op: we read on demand
    }

    public function save() : void
    {
        // no-op: we save on each set to ensure durability
    }

    public function getUsers() : array { return $this->readJson('users'); }
    public function setUsers(array $users) : void { $this->writeJson('users', $users); }

    public function getGroups() : array { return $this->readJson('groups'); }
    public function setGroups(array $groups) : void { $this->writeJson('groups', $groups); }

    public function getTracks() : array { return $this->readJson('tracks'); }
    public function setTracks(array $tracks) : void { $this->writeJson('tracks', $tracks); }

    private function readJson(string $key) : array
    {
        $stmt = $this->db->prepare('SELECT value FROM netherperms_store WHERE key = :k');
        $stmt->bindValue(':k', $key, SQLITE3_TEXT);
        $res = $stmt->execute();
        $row = $res?->fetchArray(SQLITE3_ASSOC) ?: null;
        if ($row === null) return [];
        $decoded = json_decode($row['value'], true);
        return is_array($decoded) ? $decoded : [];
    }

    private function writeJson(string $key, array $value) : void
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $stmt = $this->db->prepare('REPLACE INTO netherperms_store(key, value) VALUES (:k, :v)');
        $stmt->bindValue(':k', $key, SQLITE3_TEXT);
        $stmt->bindValue(':v', $json, SQLITE3_TEXT);
        $stmt->execute();
    }
}
