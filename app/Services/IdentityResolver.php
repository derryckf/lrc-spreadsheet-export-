<?php
declare(strict_types=1);
namespace App\Services;

use PDO;

/**
 * IdentityResolver — matches Webscorer CSV rows against the member table.
 *
 * Reads a normalised CSV (from WebscorerParser) and for each row determines
 * the best matching member_id, a confidence score, and a match type.
 *
 * Match hierarchy:
 *   Tier 1 — exact firstName + lastName + DOB          → confidence 1.00
 *   Tier 2 — exact firstName + lastName + DOB ±30d       → confidence 0.85
 *   Tier 3 — exact firstName + lastName + DOB ±365d     → confidence 0.70
 *   Tier 4 — alias firstName + lastName + DOB ±365d     → confidence 0.65
 *
 * Support signals (stack on Tier 2-4):
 *   tagNo matches a past eventResult tagNo → +0.15
 *   email matches a past member email      → +0.10
 *
 * Output: CSV manifest at storage/app/handicapping/{eventId}/identity/{eventId}_manifest.csv
 *
 * Usage:
 *   $resolver = new IdentityResolver($pdo, $logger, $config);
 *   $manifestPath = $resolver->resolve($csvPath, $eventId);
 */
class IdentityResolver
{
    private PDO $db;
    /** @var object|null */
    private $logger;
    private array $config;

    /** Known first-name aliases: canonical → variants */
    private const FIRST_NAME_ALIASES = [
        'Tim'     => ['Timothy', 'Timmy'],
        'Fred'    => ['Freddie', 'Frederick'],
        'Sam'     => ['Samantha', 'Samuel'],
        'Liz'     => ['Elizabeth', 'Lizzy', 'Lizbet'],
        'Mick'    => ['Michael', 'Mickey'],
        'Rob'     => ['Robert', 'Bob', 'Bobby'],
        'Sue'     => ['Susan', 'Suzanne'],
        'Deb'     => ['Debra', 'Deborah', 'Debbie'],
        'Colin'   => ['Collin'],
        'Phil'    => ['Philip'],
        'Steve'   => ['Stephen', 'Steven'],
        'Pat'     => ['Patrick', 'Patty'],
        'Jake'    => ['Jacob'],
        'Neil'    => ['Neill', 'Nellie'],
        'Alex'    => ['Alexander', 'Alexandria'],
        'Glen'    => ['Glenn'],
        'Ant'     => ['Anthony', 'Anton'],
        'Rich'    => ['Richard'],
        'Dee'     => ['Diane', 'Diana'],
        'Annie'   => ['Anne-Marie'],
        'Daemon'  => ['Damon'],
        'Joseph'  => ['Joe'],
        'Will'    => ['William'],
    ];

    private static array $inverseAliasesCache = [];

    /**
     * @return array<string, string>  variant → canonical
     */
    private static function buildInverseAliases(): array
    {
        $map = [];
        foreach (self::FIRST_NAME_ALIASES as $canonical => $variants) {
            foreach ($variants as $v) {
                $map[strtolower($v)] = strtolower($canonical);
                $map[strtolower($canonical)] = strtolower($canonical);
            }
        }
        return $map;
    }

    private static function getInverseAliases(): array
    {
        if (empty(self::$inverseAliasesCache)) {
            self::$inverseAliasesCache = self::buildInverseAliases();
        }
        return self::$inverseAliasesCache;
    }

    public function __construct(
        PDO $db,
        $logger = null,
        array $config = []
    ) {
        $this->db = $db;
        $this->logger = $logger;
        $this->config = array_merge([
            'dob_month_tolerance' => 30,
            'dob_year_tolerance' => 365,
            'storage_path' => 'handicapping',
        ], $config);
    }

    /**
     * Resolve identities in a CSV file.
     *
     * @param string $csvPath       Path to normalised CSV from WebscorerParser
     * @param int    $eventId       Event ID (used for storage path)
     * @return string  Path to manifest CSV
     */
    public function resolve(string $csvPath, int $eventId): string
    {
        if (!file_exists($csvPath)) {
            throw new \RuntimeException("CSV not found: {$csvPath}");
        }

        $outputDir = $this->getOutputDir($eventId);
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }
        $manifestPath = $outputDir . '/' . $eventId . '_manifest.csv';

        $this->logger->info("Resolving identities from: {$csvPath}");
        $this->logger->info("Output manifest: {$manifestPath}");

        // Pre-load tagNo history for all members (for confidence signal + backfill)
        $tagNoData = $this->loadTagNoHistory();

        // Pre-load email history for all members (for support signal)
        $emailHistory = $this->loadEmailHistory();

        $handle = fopen($csvPath, 'r');
        if ($handle === false) {
            throw new \RuntimeException("Cannot open CSV: {$csvPath}");
        }

        $headers = fgetcsv($handle);
        if ($headers === false) {
            fclose($handle);
            throw new \RuntimeException("CSV has no headers: {$csvPath}");
        }
        $headers = array_map('trim', $headers);
        $headerMap = array_flip($headers);

        // Write manifest CSV
        $out = fopen($manifestPath, 'w');
        $manifestHeaders = [
            'tmp_id', 'webscorer_tagNo', 'firstName', 'lastName', 'DOB', 'gender',
            'email', 'phone', 'distance', 'category', 'eventfee', 'registrationtime',
            'webscorer_tagNo_conflict', 'tagNo_resolved', 'member_id', 'match_type',
            'confidence_score', 'notes',
        ];
        fputcsv($out, $manifestHeaders);

        $rowNum = 1; // header is row 1, data starts at row 2
        while (($row = fgetcsv($handle)) !== false) {
            $rowNum++;
            // Defensive: pad or truncate row to match header count
            $headerCount = count($headers);
            $rowCount = count($row);
            if ($rowCount < $headerCount) {
                $row = array_pad($row, $headerCount, '');
            } elseif ($rowCount > $headerCount) {
                $row = array_slice($row, 0, $headerCount);
            }
            $data = array_combine($headers, $row);

            $resolved = $this->resolveRow($data, $tagNoData, $emailHistory);

            fputcsv($out, [
                $resolved['tmp_id'],
                $data['tagNo'] ?? '',
                $data['firstName'] ?? '',
                $data['lastName'] ?? '',
                $data['DOB'] ?? '',
                $data['gender'] ?? '',
                $data['email'] ?? '',
                $data['phone'] ?? '',
                $data['distance'] ?? '',
                $data['category'] ?? '',
                $data['eventfee'] ?? '',
                $data['registrationtime'] ?? '',
                $resolved['webscorer_tagNo_conflict'] ?? 'no',
                $resolved['tagNo_resolved'] ?? '',
                $resolved['member_id'],
                $resolved['match_type'],
                $resolved['confidence_score'],
                $resolved['notes'] ?? '',
            ]);

            $this->logger->debug("Row {$rowNum}: {$resolved['match_type']} → {$resolved['member_id']} ({$resolved['confidence_score']})");
        }

        fclose($handle);
        fclose($out);

        $this->logger->info("Identity resolution complete — {$rowNum} rows processed");

        return $manifestPath;
    }

    /**
     * Resolve a single CSV row against the member table.
     */
    private function resolveRow(array $data, array $tagNoData, array $emailHistory): array
    {
        $firstName = $this->normaliseName($data['firstName'] ?? '');
        $lastName  = $this->normaliseName($data['lastName'] ?? '');
        $dob       = $this->parseDob($data['DOB'] ?? '');
        $gender    = strtoupper(substr($data['gender'] ?? '', 0, 1));
        $csvTagNo  = trim($data['tagNo'] ?? '');
        $email     = trim(strtolower($data['email'] ?? ''));
        $tmpId     = 'tmp_' . bin2hex(random_bytes(8));

        if ($firstName === '' || $lastName === '' || $dob === null) {
            return [
                'tmp_id' => $tmpId,
                'webscorer_tagNo_conflict' => 'no',
                'tagNo_resolved' => $csvTagNo,
                'member_id' => $tmpId,
                'match_type' => 'invalid',
                'confidence_score' => '0.00',
                'notes' => 'Missing required field: firstName, lastName, or DOB',
            ];
        }

        // Find candidates via exact firstName+lastName match
        $candidates = $this->findCandidates($firstName, $lastName);

        if (empty($candidates)) {
            // No candidate members found → new member
            return [
                'tmp_id' => $tmpId,
                'webscorer_tagNo_conflict' => 'no',
                'tagNo_resolved' => $csvTagNo,
                'member_id' => $tmpId,
                'match_type' => 'new',
                'confidence_score' => '0.00',
                'notes' => 'No member found with matching firstName+lastName',
            ];
        }

        $tagNoHistory = $tagNoData['history'] ?? [];

        // Score each candidate against match tiers.
        // Tie-breaking priority:
        //   1. Higher confidence wins (primary)
        //   2. Higher event_result_count wins (secondary — prefer canonical record with history)
        //   3. Lower member_id wins (tertiary — deterministic fallback)
        $best = null;
        foreach ($candidates as $memberId => $member) {
            $score = $this->scoreCandidate($member, $dob, $csvTagNo, $email, $tagNoHistory, $emailHistory);
            $score['member_id'] = $memberId;
            $score['event_result_count'] = (int)($member['event_result_count'] ?? 0);
            if ($best === null
                || $score['confidence'] > $best['confidence']
                || ($score['confidence'] === $best['confidence'] && $score['event_result_count'] > $best['event_result_count'])
                || ($score['confidence'] === $best['confidence'] && $score['event_result_count'] === $best['event_result_count'] && $memberId < $best['member_id'])) {
                $best = $score;
            }
        }

        // If best score is below minimum threshold, treat as new member
        if ($best === null || $best['confidence'] < 0.50) {
            return [
                'tmp_id' => $tmpId,
                'webscorer_tagNo_conflict' => 'no',
                'tagNo_resolved' => $csvTagNo,
                'member_id' => $tmpId,
                'match_type' => 'new',
                'confidence_score' => '0.00',
                'notes' => 'No candidate met minimum confidence threshold',
            ];
        }

        // Backfill tagNo from history if CSV has none
        $resolvedTagNo = $csvTagNo;
        $lastTagNo = $tagNoData['last'] ?? [];
        if (($csvTagNo === '' || $csvTagNo === '-') && isset($lastTagNo[$best['member_id']])) {
            $resolvedTagNo = $lastTagNo[$best['member_id']];
            $best['notes'] = trim(($best['notes'] ? $best['notes'] . '; ' : '') . 'tagNo backfilled from history: ' . $resolvedTagNo);
        }
        $best['tagNo_resolved'] = $resolvedTagNo;

        return $best;
    }

    /**
     * Find member records matching canonical firstName + lastName.
     * Returns array: memberId => memberData (includes event_result_count for tie-breaking)
     */
    private function findCandidates(string $firstName, string $lastName): array
    {
        $sql = "
            SELECT m.id, m.firstName, m.lastName, m.DOB, m.sex,
                   e.emailAddress, ph.number as phone,
                   tn.tagNo as last_tagNo,
                   COALESCE(erc.event_result_count, 0) AS event_result_count
            FROM member m
            LEFT JOIN email e ON m.email_id = e.id
            LEFT JOIN phone ph ON m.phone_id = ph.id
            LEFT JOIN (
                SELECT member_id, ANY_VALUE(tagNo) as tagNo, MAX(eventDate) as last_used
                FROM eventEntry ee
                JOIN event ev ON ee.event_id = ev.id
                JOIN tagNo tn ON ee.tagNo_id = tn.id
                GROUP BY member_id
            ) tn ON tn.member_id = m.id
            LEFT JOIN (
                SELECT member_id, COUNT(*) AS event_result_count
                FROM eventResult
                GROUP BY member_id
            ) erc ON erc.member_id = m.id
            WHERE LOWER(m.firstName) = :first AND LOWER(m.lastName) = :last
            ORDER BY m.id ASC
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'first' => strtolower($firstName),
            'last'  => strtolower($lastName),
        ]);

        $candidates = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            // Always use the first (lowest-id) member record for a given name.
            // All eventResult history has been merged onto this canonical record.
            // Subsequent duplicates (same name, different DOB) are skipped.
            if (!isset($candidates[$row['id']])) {
                $candidates[$row['id']] = $row;
            }
        }

        // Also try reverse: lastName in firstName field and vice versa
        // (handling swapped CSV columns gracefully)
        if (empty($candidates)) {
            $stmt2 = $this->db->prepare("
                SELECT m.id, m.firstName, m.lastName, m.DOB, m.sex, m.email_id,
                       COALESCE(erc.event_result_count, 0) AS event_result_count
                FROM member m
                LEFT JOIN (
                    SELECT member_id, COUNT(*) AS event_result_count
                    FROM eventResult
                    GROUP BY member_id
                ) erc ON erc.member_id = m.id
                WHERE LOWER(m.firstName) = :last AND LOWER(m.lastName) = :first
                ORDER BY m.id ASC
            ");
            $stmt2->execute([
                'first' => strtolower($lastName),
                'last'  => strtolower($firstName),
            ]);
            while ($row = $stmt2->fetch(PDO::FETCH_ASSOC)) {
                if (!isset($candidates[$row['id']])) {
                    $candidates[$row['id']] = $row;
                }
            }
        }

        return $candidates;
    }

    /**
     * Score a candidate member against a CSV row.
     * Returns array with match_type, confidence_score, notes, etc.
     */
    private function scoreCandidate(
        array $member,
        ?\DateTime $dob,
        string $tagNo,
        string $email,
        array $tagNoHistory,
        array $emailHistory
    ): array {
        $memberId = $member['id'];
        $memberDob = $this->parseDob($member['DOB']);
        $notes = [];

        // Determine best tier
        $tier = null;
        $confidence = 0.0;
        $dobDiff = null;

 $alias = strtolower($member['firstName']);
        $canonical = self::getInverseAliases()[$alias] ?? null;
        $isAliasMatch = $canonical !== null;

        if ($memberDob !== null && $dob !== null) {
            $diff = abs($memberDob->diff($dob)->days);

            if ($diff === 0) {
                $tier = 'direct';
                $confidence = 1.00;
            } elseif ($diff <= $this->config['dob_month_tolerance']) {
                $tier = 'fuzzy.dob_close';
                $confidence = 0.85;
            } elseif ($diff <= $this->config['dob_year_tolerance']) {
                $tier = $isAliasMatch ? 'alias' : 'fuzzy.dob_year';
                $confidence = $isAliasMatch ? 0.65 : 0.70;
            }
        }

        if ($tier === null) {
            // Fuzzy name variant without DOB match
            if ($isAliasMatch) {
                $tier = 'alias';
                $confidence = 0.65;
            } else {
                $tier = 'fuzzy.lastname_only';
                $confidence = 0.50;
            }
        }

        // Support signals
        if ($tagNo !== '') {
            if (isset($tagNoHistory[$memberId]) && in_array($tagNo, $tagNoHistory[$memberId], true)) {
                $confidence = min(1.00, $confidence + 0.15);
                $notes[] = 'tagNo confirmed from eventResult history';
            }
        }

        if ($email !== '' && isset($emailHistory[$memberId])) {
            if (in_array($email, $emailHistory[$memberId], true)) {
                $confidence = min(1.00, $confidence + 0.10);
                $notes[] = 'email confirmed';
            }
        }

        // Round to 2 decimal places
        $confidence = round($confidence, 2);

return [
                'tmp_id' => '',
                'webscorer_tagNo_conflict' => 'no',
                'tagNo_resolved' => $tagNo,
                'member_id' => $memberId,
                'match_type' => $tier,
                'confidence_score' => (string)round($confidence, 2),
                'confidence' => $confidence,
                'notes' => implode('; ', $notes),
            ];
    }

    /**
     * Normalise name: trim, collapse whitespace, ucwords.
     */
    private function normaliseName(string $name): string
    {
        $n = preg_replace('/\s+/', ' ', trim($name));
        return $n !== '' ? ucwords(strtolower($n)) : '';
    }

    /**
     * Parse DOB string into \DateTime.
     * Tries multiple formats.
     */
    private function parseDob(string $dob): ?\DateTime
    {
        $formats = ['Y-m-d', 'd/m/Y', 'm/d/Y', 'Y/m/d'];
        $dob = trim($dob);
        foreach ($formats as $fmt) {
            $dt = \DateTime::createFromFormat($fmt, $dob);
            if ($dt !== false) {
                return $dt;
            }
        }
        return null;
    }

    /**
     * Load tagNo history per member_id from both eventEntry and eventResult.
     * Returns: ['history' => [$memberId => [tagNo, ...]], 'last' => [$memberId => tagNo]]
     *
     * - history: all unique tagNos per member (for confidence boosting)
     * - last: most recently used tagNo per member (for backfill when CSV has none)
     */
    private function loadTagNoHistory(): array
    {
        $sql = "
            SELECT member_id, tagNo, source_date FROM (
                SELECT ee.member_id, tn.tagNo, e.eventDate AS source_date
                FROM eventEntry ee
                JOIN tagNo tn ON ee.tagNo_id = tn.id
                JOIN event e ON ee.event_id = e.id
                WHERE tn.tagNo IS NOT NULL AND tn.tagNo != '' AND tn.tagNo != '-'

                UNION ALL

                SELECT er.member_id, tn.tagNo, e.eventDate AS source_date
                FROM eventResult er
                JOIN tagNo tn ON er.tagNo_id = tn.id
                JOIN event e ON er.event_id = e.id
                WHERE tn.tagNo IS NOT NULL AND tn.tagNo != '' AND tn.tagNo != '-'
            ) combined
            ORDER BY source_date DESC
        ";
        $stmt = $this->db->query($sql);

        $history = [];  // memberId => [tagNo, tagNo, ...]
        $last = [];     // memberId => tagNo (most recent)

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $mid = (int)$row['member_id'];
            $tagNo = trim($row['tagNo']);
            if ($tagNo === '') { continue; }

            if (!isset($history[$mid])) {
                $history[$mid] = [];
            }
            if (!in_array($tagNo, $history[$mid], true)) {
                $history[$mid][] = $tagNo;
            }
            // First occurrence is most recent (ORDER BY source_date DESC)
            if (!isset($last[$mid])) {
                $last[$mid] = $tagNo;
            }
        }

        return ['history' => $history, 'last' => $last];
    }

    /**
     * Load email history per member_id from email table.
     * Returns: [$memberId => [email, email, ...]]
     */
    private function loadEmailHistory(): array
    {
        $sql = "
            SELECT m.id as member_id, e.emailAddress
            FROM member m
            JOIN email e ON m.email_id = e.id
        ";
        $stmt = $this->db->query($sql);
        $map = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $mid = (int)$row['member_id'];
            if (!isset($map[$mid])) {
                $map[$mid] = [];
            }
            $map[$mid][] = strtolower(trim($row['emailAddress']));
        }
        return $map;
    }

    private function getOutputDir(int $eventId): string
    {
        $base = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2);
        return $base . '/storage/app/' . $this->config['storage_path'] . '/' . $eventId . '/identity';
    }
}
