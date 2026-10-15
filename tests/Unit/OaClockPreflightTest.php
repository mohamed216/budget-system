<?php

namespace Tests\Unit;

use OaFailure;
use OaLaravelFixture;
use OaSqlFailure;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class OaClockPreflightTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__.'/../Proofs/d3a1-owner-authority.php';
        require_once __DIR__.'/../Proofs/d3a1-owner-authority-laravel.php';
    }

    public function test_probes_use_in_range_numeric_literals_and_restore_live_time_then_original_zone(): void
    {
        $calls = [];
        oaClockPreflight($this->clockPdo($calls));
        $timestampSql = array_values(array_filter(array_column($calls, 'sql'), fn ($sql) => str_starts_with($sql, 'SET SESSION timestamp')));
        $this->assertSame([
            'SET SESSION timestamp = 946684800',
            'SET SESSION timestamp = 1893456000',
            'SET SESSION timestamp = DEFAULT',
        ], $timestampSql);
        foreach (array_slice($timestampSql, 0, 2) as $sql) {
            $this->assertMatchesRegularExpression('/^SET SESSION timestamp = [0-9]+$/D', $sql);
            $value = (int) substr($sql, strlen('SET SESSION timestamp = '));
            $this->assertGreaterThanOrEqual(1, $value);
            $this->assertLessThanOrEqual(2147483647, $value);
        }
        $this->assertNotContains('SELECT @@session.timestamp', array_column($calls, 'sql'));
        $this->assertSame([
            ['sql' => 'SET SESSION timestamp = DEFAULT', 'parameters' => []],
            ['sql' => 'SET SESSION time_zone = ?', 'parameters' => ['+03:00']],
        ], array_slice($calls, -2));
    }

    public function test_numeric_variable_double_rejects_the_incompatible_timestamp_forms(): void
    {
        foreach (['SET SESSION timestamp = ?', "SET SESSION timestamp = '946684800'", 'SET SESSION timestamp = 2208988800'] as $sql) {
            $calls = [];
            try {
                $this->clockPdo($calls)->query($sql);
                $this->fail('Incompatible timestamp assignment accepted');
            } catch (PDOException $error) {
                $this->assertSame('42000', $error->errorInfo[0]);
            }
        }
    }

    public function test_cleanup_attempts_both_actions_and_reports_every_failure_without_driver_messages(): void
    {
        foreach ([['timestamp_cleanup'], ['zone_cleanup'], ['timestamp_cleanup', 'zone_cleanup']] as $failures) {
            $calls = [];
            try {
                oaClockPreflight($this->clockPdo($calls, $failures));
                $this->fail('Cleanup failure must block');
            } catch (OaFailure $error) {
                $this->assertStringContainsString('BLOCKED', $error->getMessage());
                $this->assertSame(count($failures), substr_count($error->getMessage(), 'CLOCK_CLEANUP'));
                $this->assertStringNotContainsString('private-driver-detail', $error->getMessage());
            }
            $this->assertSame(['SET SESSION timestamp = DEFAULT', 'SET SESSION time_zone = ?'], array_column(array_slice($calls, -2), 'sql'));
        }
    }

    public function test_replay_records_clock_preflight_only_after_successful_checks_and_cleanup(): void
    {
        global $oaObserved;
        $previous = $oaObserved ?? null;
        try {
            foreach ([[], ['setup'], ['independence'], ['range'], ['zone_check'], ['timestamp_cleanup'], ['zone_cleanup']] as $failures) {
                $oaObserved = [];
                $calls = [];
                $root = $this->createMock(PDO::class);
                $root->expects($failures === [] ? $this->once() : $this->never())->method('prepare')->willThrowException($this->driverError());
                // No application or database is booted; execution stops at the first HMAC statement.
                $web = (new ReflectionClass(OaLaravelFixture::class))->newInstanceWithoutConstructor();
                ob_start();
                try {
                    oaReplayProof($root, $this->clockPdo($calls, $failures), $root, $root, $web, []);
                    $this->fail('Offline proof must stop');
                } catch (OaFailure $error) {
                    if ($failures === []) {
                        $this->assertInstanceOf(OaSqlFailure::class, $error);
                        $this->assertSame('CALL oa_hmac(?,?,@oa_tag)', $error->diagnostic['sql']);
                    }
                } finally {
                    ob_end_clean();
                }
                $this->assertSame($failures === [] ? ['clock_preflight' => true] : [], $oaObserved);
                $this->assertSame(['SET SESSION timestamp = DEFAULT', 'SET SESSION time_zone = ?'], array_column(array_slice($calls, -2), 'sql'));
            }
        } finally {
            $oaObserved = $previous;
        }
    }

    private function driverError(): PDOException
    {
        $error = new PDOException('private-driver-detail');
        $error->errorInfo = ['42000', 1232, 'private-driver-detail'];

        return $error;
    }

    private function clockPdo(array &$calls, array $failures = []): PDO
    {
        $zone = '+03:00';
        $reading = 0;
        $pdo = $this->createMock(PDO::class);
        $pdo->method('query')->willReturnCallback(function (string $sql) use (&$calls, &$zone, &$reading, $failures) {
            $calls[] = ['sql' => $sql, 'parameters' => []];
            if (str_starts_with($sql, 'SET SESSION timestamp')) {
                $valid = preg_match('/^SET SESSION timestamp = ([0-9]+|DEFAULT)$/D', $sql, $match);
                if (! $valid || ($match[1] !== 'DEFAULT' && ((int) $match[1] < 1 || (int) $match[1] > 2147483647))) {
                    throw $this->driverError();
                }
            }
            if (($sql === 'SET SESSION timestamp = DEFAULT' && in_array('timestamp_cleanup', $failures, true)) || ($sql === 'SET SESSION timestamp = 946684800' && in_array('setup', $failures, true))) {
                throw $this->driverError();
            }
            $value = match ($sql) {
                'SELECT @@session.time_zone' => in_array('zone_check', $failures, true) && $zone !== '+03:00' ? '+00:00' : $zone,
                'SELECT @oa_clock' => in_array('range', $failures, true) ? 1 : 1800000000000000 + $reading++ * (in_array('independence', $failures, true) ? 10000000 : 1000),
                default => false,
            };
            $statement = $this->createMock(PDOStatement::class);
            $statement->method('fetchColumn')->willReturn($value);

            return $statement;
        });
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use (&$calls, &$zone, $failures) {
            // Timestamp assignments must never use the prepared/bound-string path.
            $this->assertSame('SET SESSION time_zone = ?', $sql);
            $statement = $this->createMock(PDOStatement::class);
            $statement->method('execute')->willReturnCallback(function (array $parameters) use ($sql, &$calls, &$zone, $failures) {
                $calls[] = ['sql' => $sql, 'parameters' => $parameters];
                if ($parameters === ['+03:00'] && in_array('zone_cleanup', $failures, true)) {
                    throw $this->driverError();
                }
                $zone = $parameters[0];

                return true;
            });

            return $statement;
        });

        return $pdo;
    }
}
