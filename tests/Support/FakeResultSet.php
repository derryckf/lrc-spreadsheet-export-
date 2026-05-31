<?php
/**
 * FakeResultSet — extends PDOStatement but sidesteps parent::__construct() requirement
 * by using reflection to invoke the protected constructor with a valid (isolated) PDO handle.
 *
 * WARNING: FakeResultSet::createWithData() must be called before any row iteration.
 *
 * Usage via FakePDO (recommended):
 *   $pdo->setFixture('key', [['col' => 'val']]);
 *   $stmt = $pdo->prepare('key-based SQL');
 *   $stmt->execute();
 *   $stmt->fetchColumn(0);  // ← works on first row
 *
 * Standalone:
 *   $rs = FakeResultSet::createWithData([['id' => 10]]);
 *   $rs->execute()->fetchColumn(0);
 */
class FakeResultSet extends \PDOStatement
{
    private array $data = [];
    private int $cursor = 0;
    private bool $executed = false;

    private function __construct() {}

    /**
     * Create a fully-initialized FakeResultSet without touching any real PDO.
     * Uses reflection to invoke the protected PDOStatement constructor with an
     * in-memory SQLite PDO that exists only during this call.
     */
    public static function createWithData(array $data): self
    {
        // Isolated in-memory PDO — only lives during this factory call
        static $holder = null;
        if ($holder === null) {
            try {
                $holder = new \PDO('sqlite::memory:');
            } catch (\Throwable) {
                $holder = new \PDO('sqlite:' . tempnam(sys_get_temp_dir(), 'fakepdo_') . '.db');
            }
        }
        $stmt = new self();
        // Invoke protected PDOStatement::__construct($pdo) via reflection
        $ref = (new ReflectionClass(\PDOStatement::class))->getConstructor();
        $ref->setAccessible(true);
        $ref->invoke($stmt, $holder);
        $stmt->data = $data;
        return $stmt;
    }

    public function execute(?array $params = null): bool
    {
        $this->executed = true;
        $this->cursor = 0;
        return true;
    }

    public function fetch(int $mode = \PDO::FETCH_DEFAULT, ...$args): mixed
    {
        if (!$this->executed) return false;
        if ($this->cursor >= count($this->data)) return false;
        if ($mode === \PDO::FETCH_NUM) return array_values($this->data[$this->cursor++]);
        if ($mode === \PDO::FETCH_BOTH) {
            $row = $this->data[$this->cursor++];
            return array_merge($row, array_values($row));
        }
        return $this->data[$this->cursor++] ?? false;
    }

    public function fetchAll(int $mode = \PDO::FETCH_DEFAULT, ...$args): array
    {
        if (!$this->executed) return [];
        if ($mode === \PDO::FETCH_NUM) {
            return array_map(fn($r) => array_values($r), $this->data);
        }
        return $this->data;
    }

    public function fetchColumn(int $column = 0): mixed
    {
        if (!$this->executed) return null;
        if ($this->cursor < count($this->data)) {
            $row = $this->data[$this->cursor];
            $this->cursor++;
        } else {
            $row = end($this->data) ?: [];
        }
        if (is_array($row)) {
            return array_values($row)[$column] ?? null;
        }
        return $row;
    }

    public function rowCount(): int { return count($this->data); }
    public function columnCount(): int { return is_array($this->data[0] ?? null) ? count($this->data[0]) : 0; }
    public function closeCursor(): bool { return true; }
    public function setAttribute(int $attr, mixed $val): bool { return false; }
    public function getAttribute(int $attr): mixed { return null; }
    public function count(): int { return count($this->data); }
}
