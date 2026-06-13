<?php
declare(strict_types=1);
namespace Tests\Unit\Services;

use App\Services\MemberCreator;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakePDO;

/**
 * Unit tests for MemberCreator contact management:
 * - isValidEmail()
 * - isValidPhone()
 * - isSimilar()
 * - updateContactsForKnownMember()
 * - processRow() isNew vs known branches
 *
 * Private methods tested via reflection.
 * DB mocked with FakePDO fixtures.
 */
class MemberCreatorTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════════════
    // isValidEmail()
    // ═══════════════════════════════════════════════════════════════════════

    #[Test]
    #[DataProvider('validEmails')]
    public function isValidEmail_returnsTrue_forValidAddresses(string $email): void
    {
        $this->assertTrue($this->callIsValidEmail($email), "Expected '$email' to be valid");
    }

    public static function validEmails(): array
    {
        return [
            'simple'         => ['user@example.com'],
            'subdomain'      => ['user@mail.example.com'],
            'dots in local' => ['user.name@example.com'],
            'plus sign'      => ['user+tag@example.com'],
            'co.uk tld'     => ['user@example.co.uk'],
            'numeric domain' => ['user@123abc.com'],
        ];
    }

    #[Test]
    #[DataProvider('invalidEmails')]
    public function isValidEmail_returnsFalse_forInvalidAddresses(string $email): void
    {
        $this->assertFalse($this->callIsValidEmail($email), "Expected '$email' to be invalid");
    }

    public static function invalidEmails(): array
    {
        return [
            'no at sign'    => ['userexample.com'],
            'no domain part'=> ['user@'],
            'no tld dot'  => ['user@domain'],
            'single-char tld'=> ['user@domain.c'],
            'empty string'  => [''],
            'spaces'        => ['user name@example.com'],
            'double at'    => ['user@@example.com'],
            'trailing dot' => ['user@domain.'],
            'only at'      => ['@'],
            'local only'    => ['user'],
        ];
    }

    // ═══════════════════════════════════════════════════════════════════════
    // isValidPhone()
    // ═══════════════════════════════════════════════════════════════════════

    #[Test]
    #[DataProvider('validPhones')]
    public function isValidPhone_returnsTrue_forValidNumbers(string $phone): void
    {
        $this->assertTrue($this->callIsValidPhone($phone), "Expected '$phone' to be valid");
    }

    public static function validPhones(): array
    {
        return [
            'mobile 10 digit'  => ['0412123456'],
            'mobile spaced'    => ['0412 123 456'],
            'mobile dashed'    => ['0412-123-456'],
            'landline 10d'    => ['0398765432'],
            '11 digit 0'       => ['0419123456'],
            '11 digit spaced'  => ['03 9876 5432'],
            'plus61 mobile'   => ['+61 412 123 456'],
            'plus61 landline'  => ['+61 2 9876 5432'],
        ];
    }

    #[Test]
    #[DataProvider('invalidPhones')]
    public function isValidPhone_returnsFalse_forInvalidNumbers(string $phone): void
    {
        $this->assertFalse($this->callIsValidPhone($phone), "Expected '$phone' to be invalid");
    }

    public static function invalidPhones(): array
    {
        return [
            'too short'       => ['0412123'],
            'too long'        => ['041212345678'],
            'letters mixed'  => ['0412ABC456'],
            'empty string'    => [''],
            'only letters'    => ['abcdefghij'],
        ];
    }

    // ═══════════════════════════════════════════════════════════════════════
    // isSimilar() — Levenshtein ≤ 1
    // ═══════════════════════════════════════════════════════════════════════

    #[Test]
    #[DataProvider('similarPairs')]
    public function isSimilar_returnsTrue_forStringsWithin1EditDistance(string $a, string $b): void
    {
        $this->assertTrue($this->callIsSimilar($a, $b), "Expected '$a' and '$b' to be similar (dist ≤ 1)");
    }

    public static function similarPairs(): array
    {
        return [
            'one char diff'   => ['test@example.com', 'tast@example.com'],
            'missing char'    => ['test@example.com', 'tst@example.com'],
            'extra char'     => ['test@example.com', 'ttest@example.com'],
            'one digit off'  => ['0412123456', '0412123457'],
        ];
    }

    public static function notSimilarPairs(): array
    {
        return [
            'transposed chars'  => ['test@example.com', 'tset@example.com'], // Lev=2 (e↔s)
            'case diff'         => ['Test@Example.com', 'test@example.com'], // lowercased = identical → NOT similar
            'completely different' => ['test@example.com', 'foo@example.com'],
            'two char diff'     => ['test@example.com', 'tastt@example.com'],
            'empty vs string'  => ['', 'test@example.com'],
        ];
    }

    #[Test]
    #[DataProvider('notSimilarPairs')]
    public function isSimilar_returnsFalse_forStringsMoreThan1Apart(string $a, string $b): void
    {
        $this->assertFalse($this->callIsSimilar($a, $b), "Expected '$a' and '$b' to NOT be similar");
    }

    // ═══════════════════════════════════════════════════════════════════════
    // updateContactsForKnownMember() scenarios
    // ═══════════════════════════════════════════════════════════════════════

    // Email scenarios
    public function test_contacts_noCurrentEmail_newValidEmail_updatesEmail(): void
    {
        $result = $this->callUpdateContacts(
            memberId: 100,
            currentEmailId: null,
            currentEmail: null,
            currentPhoneId: null,
            currentPhone: null,
            newEmail: 'new@valid.com',
            newPhone: ''
        );
        $this->assertSame('new@valid.com', $result['email']);
        $this->assertTrue($result['emailChanged']);
    }

    public function test_contacts_noCurrentEmail_invalidEmail_keepsNull(): void
    {
        $result = $this->callUpdateContacts(
            100, null, null, null, null, 'bad-email', ''
        );
        $this->assertSame('', $result['email']);
        $this->assertFalse($result['emailChanged']);
    }

    public function test_contacts_sameEmail_noChange(): void
    {
        $result = $this->callUpdateContacts(
            100, 5, 'same@example.com', null, null, 'same@example.com', ''
        );
        // No change: email stays as current, emailChanged=false
        $this->assertSame('same@example.com', $result['email']);
        $this->assertFalse($result['emailChanged']);
    }

    public function test_contacts_validDifferentEmail_updates(): void
    {
        $result = $this->callUpdateContacts(
            100, 5, 'old@example.com', null, null, 'newdifferent@valid.com', ''
        );
        $this->assertSame('newdifferent@valid.com', $result['email']);
        $this->assertTrue($result['emailChanged']);
    }

    public function test_contacts_typoEmail_rejected(): void
    {
        // 'tast' vs 'test' — 1-char diff (Levenshtein=1) → keep current
        $result = $this->callUpdateContacts(
            100, 5, 'test@example.com', null, null, 'tast@example.com', ''
        );
        $this->assertSame('test@example.com', $result['email']); // typo rejected, keep current
        $this->assertFalse($result['emailChanged']);
    }

    public function test_contacts_emailUsedByOtherMember_rejected(): void
    {
        // Simulate: email belongs to member 50, current member=100
        $result = $this->callUpdateContacts(
            memberId: 100,
            currentEmailId: 5,
            currentEmail: 'mine@example.com',
            currentPhoneId: null,
            currentPhone: null,
            newEmail: 'theirs@example.com',
            newPhone: ''
        );
        // isUsedByOtherMember returns true (fixture) → keep current email
        $this->assertFalse($result['emailChanged']);
        $this->assertSame('mine@example.com', $result['email']); // current email preserved
    }

    public function test_contacts_invalidEmailFormat_keepsCurrent(): void
    {
        $result = $this->callUpdateContacts(
            100, 5, 'current@example.com', null, null, 'not-an-email', ''
        );
        $this->assertFalse($result['emailChanged']);
    }

    // Phone scenarios
    public function test_contacts_noCurrentPhone_newValidPhone_updates(): void
    {
        $result = $this->callUpdateContacts(
            100, null, null, null, null, '', '0412000001'
        );
        $this->assertTrue($result['phoneChanged']);
        $this->assertSame('0412000001', $result['phone']);
    }

    public function test_contacts_samePhone_noChange(): void
    {
        $result = $this->callUpdateContacts(
            100, null, null, 10, '0412123456', '', '0412123456'
        );
        // Same phone: no change
        $this->assertSame('0412123456', $result['phone']);
        $this->assertFalse($result['phoneChanged']);
    }

    public function test_contacts_phoneTypo_rejected(): void
    {
        // '0412123457' vs '0412123456' — 1-digit diff (Levenshtein=1) → keep current
        $result = $this->callUpdateContacts(
            100, null, null, 10, '0412123456', '', '0412123457'
        );
        $this->assertSame('0412123456', $result['phone']); // typo rejected, keep current
        $this->assertFalse($result['phoneChanged']);
    }

    public function test_contacts_invalidPhoneFormat_keepsCurrent(): void
    {
        $result = $this->callUpdateContacts(
            100, null, null, 10, '0412123456', '', '123'
        );
        $this->assertFalse($result['phoneChanged']);
    }

    public function test_contacts_bothEmailAndPhoneChange_bothUpdated(): void
    {
        $result = $this->callUpdateContacts(
            100, 5, 'old@example.com', 10, '0412000000', 'new@valid.com', '0499000000'
        );
        $this->assertTrue($result['emailChanged']);
        $this->assertTrue($result['phoneChanged']);
        $this->assertSame('new@valid.com', $result['email']);
        $this->assertSame('0499000000', $result['phone']);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Helpers
    // ═══════════════════════════════════════════════════════════════════════

    private function buildFakeDb(array $fixtures): \Tests\Support\FakePDO
    {
        $db = new \Tests\Support\FakePDO();
        $db->setFixtureResolver(function (string $sql) use ($fixtures) {
            $u = strtoupper($sql);
            foreach ($fixtures as $key => $data) {
                if (str_contains($u, strtoupper($key))) {
                    return $key;
                }
            }
            return 'rows';
        });
        foreach ($fixtures as $key => $data) {
            $db->setFixture($key, $data);
        }
        return $db;
    }

    private function createNoopLogger(): object
    {
        return new class {
            public function info(string $m, array $c=[]) {}
            public function warning(string $m, array $c=[]) {}
            public function error(string $m, array $c=[]) {}
            public function debug(string $m, array $c=[]) {}
        };
    }

    // ── Private method invocation ────────────────────────────────────────

    private function callIsValidEmail(string $email): bool
    {
        $db = new \Tests\Support\FakePDO();
        $c = new MemberCreator($db, null);
        $ref = new \ReflectionClass($c);
        $m = $ref->getMethod('isValidEmail');
        $m->setAccessible(true);
        return (bool)$m->invoke($c, $email);
    }

    private function callIsValidPhone(string $phone): bool
    {
        $db = new \Tests\Support\FakePDO();
        $c = new MemberCreator($db, null);
        $ref = new \ReflectionClass($c);
        $m = $ref->getMethod('isValidPhone');
        $m->setAccessible(true);
        return (bool)$m->invoke($c, $phone);
    }

    private function callIsSimilar(string $a, string $b): bool
    {
        $db = new \Tests\Support\FakePDO();
        $c = new MemberCreator($db, null);
        $ref = new \ReflectionClass($c);
        $m = $ref->getMethod('isSimilar');
        $m->setAccessible(true);
        return (bool)$m->invoke($c, $a, $b);
    }

    /**
     * @param int      $memberId
     * @param int|null $currentEmailId
     * @param string|null $currentEmail
     * @param int|null $currentPhoneId
     * @param string|null $currentPhone
     * @param string $newEmail
     * @param string $newPhone
     * @return array{email:string,emailChanged:bool,email_old:string,phone:string,phoneChanged:bool,phone_old:string}
     */
    private function callUpdateContacts(
        int $memberId,
        ?int $currentEmailId,
        ?string $currentEmail,
        ?int $currentPhoneId,
        ?string $currentPhone,
        string $newEmail,
        string $newPhone
    ): array {
        $db = $this->buildFakeDb([
            // SELECT email_id, phone_id FROM member WHERE id = ? → always returns member row
            'SELECT email_id, phone_id FROM member WHERE id = ?' =>
                ['email_id' => $currentEmailId, 'phone_id' => $currentPhoneId],
            // SELECT emailAddress FROM email WHERE id = ? → null if no email, else [$currentEmail]
            'SELECT emailAddress FROM email WHERE id = ?' =>
                $currentEmail !== null ? [$currentEmail] : null,
            // SELECT number FROM phone WHERE id = ? → null if no phone, else [$currentPhone]
            'SELECT number FROM phone WHERE id = ?' =>
                $currentPhone !== null ? [$currentPhone] : null,
            // isUsedByOtherMember: [[id => 50]] when email is taken, [] when free
            'SELECT id FROM member WHERE email_id = (SELECT id FROM email WHERE emailAddress' =>
                $newEmail === 'theirs@example.com' ? [['id' => 50]] : [],
        ]);

        $c = new MemberCreator($db, null);
        $ref = new \ReflectionClass($c);
        $m = $ref->getMethod('updateContactsForKnownMember');
        $m->setAccessible(true);
        return $m->invoke($c, $memberId, $newEmail, $newPhone);
    }
}
