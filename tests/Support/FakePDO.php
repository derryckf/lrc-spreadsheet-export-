<?php
/**
 * FakeResultSet — extends PDOStatement, bypasses parent::__construct() requirement
 * by calling the protected constructor via reflection with a static SQLite handle.
 * Static handle is held per-process and reused for all statements.
 */
class FakeResultSet extends \PDOStatement
{
    /** @var mixed */
    private $data = [];
    private int $cursor = 0;
    private bool $executed = false;

    private function __construct() {}

    /** @var \PDO|null Static PDO instance reused across all FakeResultSet instances */
    private static ?\PDO $staticPdo = null;

    private static function getStaticPdo(): \PDO
    {
        if (self::$staticPdo !== null) {
            return self::$staticPdo;
        }
        $drivers = \PDO::getAvailableDrivers();
        if (in_array('sqlite', $drivers)) {
            try {
                self::$staticPdo = new \PDO('sqlite::memory:');
                return self::$staticPdo;
            } catch (\Throwable) {
                // fall through
            }
        }
        // Use anonymous temp file as fallback SQLite DB
        self::$staticPdo = new \PDO('sqlite:' . tempnam(sys_get_temp_dir(), 'fpdo_') . '.db');
        return self::$staticPdo;
    }

    /**
     * @param mixed $data  array of rows for fetchAll, or scalar for single fetchColumn value
     */
    public static function createWithData(mixed $data): self
    {
        $stmt = new self();
        try {
            $pdo = self::getStaticPdo();
            $ref = (new \ReflectionClass(\PDOStatement::class))->getConstructor();
            $ref->setAccessible(true);
            $ref->invoke($stmt, $pdo);
        } catch (\Throwable) {
            // Ignore — parent::__construct failure only affects debugPrint in PHP 8.x
        }
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
        if (!is_array($this->data)) {
            $val = $this->data;
            $this->cursor++;
            return $mode === \PDO::FETCH_NUM ? [$val] : ['scalar' => $val, 0 => $val];
        }
        // Associative array (single row): convert to indexed for cursor-based access
        $indexed = array_values($this->data);
        if ($this->cursor >= count($indexed)) return false;
        $row = $indexed[$this->cursor++];
        if ($mode === \PDO::FETCH_NUM) return array_values($row);
        if ($mode === \PDO::FETCH_BOTH) return array_merge($row, array_values($row));
        return $row;
    }

    public function fetchAll(int $mode = \PDO::FETCH_DEFAULT, ...$args): array
    {
        if (!$this->executed) return [];
        if (!is_array($this->data)) {
            return [$this->data];
        }
        // Associative array (single row): wrap in array for fetchAll
        $indexed = array_values($this->data);
        if ($mode === \PDO::FETCH_NUM) {
            return array_map(fn($r) => array_values($r), $indexed);
        }
        // For FETCH_ASSOC or default: if first element is scalar, wrap
        if (!is_array($indexed[0] ?? null)) {
            return [$this->data];
        }
        return $indexed;
    }

    public function fetchColumn(int $column = 0): mixed
    {
        if (!$this->executed) return null;
        // Scalar data: return as-is
        if (!is_array($this->data)) {
            $val = $this->data;
            $this->cursor++;
            return $val;
        }
        if ($this->cursor < count($this->data)) {
            $row = $this->data[$this->cursor++];
        } else {
            $row = end($this->data) ?: [];
        }
        return is_array($row) ? (array_values($row)[$column] ?? null) : $row;
    }

    public function rowCount(): int { return is_array($this->data) ? count($this->data) : 1; }
    public function columnCount(): int {
        if (!is_array($this->data)) return 1;
        return is_array($this->data[0] ?? null) ? count($this->data[0]) : 0;
    }
    public function bindValue(mixed $param, mixed $var, int $type = \PDO::PARAM_STR): bool { return true; }
    public function bindParam(mixed $param, mixed &$var, int $type = \PDO::PARAM_STR, mixed $length = null, mixed $options = null): bool { return true; }
    public function closeCursor(): bool { return true; }
    public function setAttribute(int $attr, mixed $val): bool { return false; }
    public function getAttribute(int $attr): mixed { return null; }
    public function count(): int { return is_array($this->data) ? count($this->data) : 1; }
}

/**
 * FakePDO — extends \PDO to satisfy type hints, returns FakeResultSet via factory.
 */
class FakePDO extends \PDO
{
    private array $fixtures = [];
    private $resolver = null;

    public function __construct()
    {
        $this->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
    }

    public function setFixtureResolver(callable $fn): void { $this->resolver = $fn; }
    public function setFixture(string $key, mixed $data): void { $this->fixtures[$key] = $data; }

    public function prepare(string $sql, array $options = []): \PDOStatement
    {
        $key = $this->resolver ? ($this->resolver)($sql) : $this->defaultKey($sql);
        return FakeResultSet::createWithData($this->fixtures[$key] ?? []);
    }

    public function query(string $sql, ...$args): \PDOStatement
    {
        return $this->prepare($sql);
    }

    public function exec(string $sql): int { return 0; }
    public function quote(string $str, int $type = \PDO::PARAM_STR): string
    {
        return "'" . str_replace("'", "''", $str) . "'";
    }
    public function lastInsertId(?string $name = null): string { return '0'; }
    public function beginTransaction(): bool { return true; }
    public function commit(): bool { return true; }
    public function rollBack(): bool { return true; }
    public function inTransaction(): bool { return false; }
    public function setAttribute(int $attr, mixed $val): bool { return true; }
    public function getAttribute(int $attr): mixed { return null; }
    public function errorCode(): ?string { return null; }
    public function errorInfo(): array { return ['', '', null]; }
    public static function getAvailableDrivers(): array { return \PDO::getAvailableDrivers(); }

    private function defaultKey(string $sql): string
    {
        $u = strtoupper($sql);
        if (str_contains($u, 'SELECT E.ID')) return 'loadEvent';
        if (str_contains($u, 'SELECT EE.ID AS ENTRY_ID')) return 'loadEntriesByDivision';
        if (str_contains($u, 'SELECT M.REGNO')) return 'buildMemberIdMap';
        if (str_contains($u, 'SELECT EE.ID, EE.MEMBER_ID')) return 'loadAllEntries';
        if (str_contains($u, 'SELECT EE.MEMBER_ID, EE.EXPECTEDPACE')) return 'buildOriginalPaceMap';
        if (str_contains($u, 'UPDATE EVENTENTRY')) return 'writeEventEntry';
        if (str_contains($u, 'SELECT ER.EVENTDATE')) return 'loadHistoryForMember';
        if (str_contains($u, 'SELECT ID FROM MEMBER')) return 'resolveMemberId';
        return 'rows';
    }
}