<?php

namespace Tests\Unit;

use App\Http\Controllers\AuthenticatedSessionController;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use OaHttpKernel;
use OaLaravelFixture;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class OaLaravelFixtureRoutesTest extends TestCase
{
    public function test_reconnections_preserve_oa_roles_and_restorable_pdo_baselines(): void
    {
        require_once __DIR__.'/../Proofs/d3a1-owner-authority.php';
        require_once __DIR__.'/../Proofs/d3a1-owner-authority-laravel.php';

        $previousContainer = Container::getInstance();
        $previousFacade = Facade::getFacadeApplication();
        $roles = [];
        $restored = [];
        $factory = function (string $role) use (&$roles, &$restored): PDO {
            $roles[] = $role;
            $environment = fn ($key) => match ($key) {
                'D3A1_ISOLATED_TEST' => 'budget-phase23-mysql-test',
                'D3A1_SERVER_UUID' => OA_UUID,
                'D3A1_OA_DSN' => OA_DSN,
                'D3A1_OA_AUTH_USER' => 'd3a1_oa_auth',
                'D3A1_OA_ACCOUNTING_USER' => 'd3a1_oa_accounting',
                default => false,
            };

            // Exercise the actual OA validation/baseline path with an offline PDO.
            return oaOpenConnection($role, 'SCHEMA', 'PROOF', function ($dsn, $username) use ($role, &$restored) {
                $this->assertSame(OA_DSN, $dsn);
                $this->assertSame('d3a1_oa_'.strtolower($role), $username);

                return $this->restorationPdo($role, $username, $restored);
            }, $environment);
        };
        $web = new OaLaravelFixture([], $factory);
        try {
            $app = (new ReflectionMethod($web, 'makeApplication'))->invoke($web, 0);
            foreach (['oa_auth' => 'AUTH', 'oa_accounting' => 'ACCOUNTING'] as $name => $role) {
                $connection = $app['db']->connection($name);
                $original = $connection->getPdo();
                $this->assertSame($original, $connection->getReadPdo());
                oaRestore($original);
                $app['db']->disconnect($name);
                $this->assertNull($connection->getRawPdo());
                $this->assertNull($connection->getRawReadPdo());

                // This is the reconnect path used by Laravel before token UPDATE.
                $connection->reconnectIfMissingConnection();
                $replacement = $connection->getReadPdo();
                $this->assertNotSame($original, $replacement);
                $this->assertSame($replacement, $connection->getPdo());
                oaRestore($replacement);

                // A direct reconnect must also replace an already cached read handle.
                $connection->reconnect();
                $latest = $connection->getReadPdo();
                $this->assertNotSame($replacement, $latest);
                $this->assertSame($latest, $connection->getPdo());
                $this->assertSame($role, end($roles));
            }
            (new ReflectionMethod($web, 'disconnect'))->invoke($web, $app);
            $this->assertSame(['AUTH', 'AUTH', 'AUTH', 'ACCOUNTING', 'ACCOUNTING', 'ACCOUNTING'], $roles);
            $this->assertCount(6, $restored);
            foreach (['oa_auth', 'oa_accounting'] as $name) {
                $this->assertNull($app['db']->connection($name)->getRawPdo());
                $this->assertNull($app['db']->connection($name)->getRawReadPdo());
            }
        } finally {
            try {
                $web->close();
            } finally {
                Facade::clearResolvedInstances();
                Facade::setFacadeApplication($previousFacade);
                Container::setInstance($previousContainer);
            }
        }
    }

    private function restorationPdo(string $role, string $username, array &$restored): PDO
    {
        $pdo = $this->createMock(PDO::class);
        $timezone = $role === 'AUTH' ? '+03:00' : '+00:00';
        $pdo->method('query')->willReturnCallback(function ($sql) use ($username, $timezone) {
            $statement = $this->createMock(PDOStatement::class);
            $statement->method('closeCursor')->willReturn(true);
            if (str_starts_with($sql, 'SELECT @@server_uuid')) {
                $statement->method('fetch')->willReturn([OA_UUID, '8.4.11', $username.'@%', OA_SCHEMA, 'NONE', $timezone, 1, 1, 1]);
            } elseif ($sql === 'SELECT @@foreign_key_checks, @@unique_checks') {
                $statement->method('fetch')->willReturn([1, 1]);
            } else {
                $this->assertContains($sql, ['ROLLBACK', 'SET SESSION autocommit=1', 'SET SESSION foreign_key_checks=1', 'SET SESSION unique_checks=1', 'SET SESSION timestamp=DEFAULT']);
            }

            return $statement;
        });
        $pdo->method('prepare')->willReturnCallback(function ($sql) use ($role, $timezone, &$restored) {
            $this->assertSame('SET SESSION time_zone=?', $sql);
            $statement = $this->createMock(PDOStatement::class);
            $statement->method('execute')->willReturnCallback(function ($bindings) use ($role, $timezone, &$restored) {
                $this->assertSame([$timezone], $bindings);
                $restored[] = $role;

                return true;
            });
            $statement->method('closeCursor')->willReturn(true);

            return $statement;
        });

        return $pdo;
    }

    public function test_login_page_resolves_its_named_form_action_without_database_access(): void
    {
        require_once __DIR__.'/../Proofs/d3a1-owner-authority.php';
        require_once __DIR__.'/../Proofs/d3a1-owner-authority-laravel.php';

        $previousContainer = Container::getInstance();
        $previousFacade = Facade::getFacadeApplication();
        $web = new OaLaravelFixture([]);
        try {
            $app = (new ReflectionMethod($web, 'makeApplication'))->invoke($web, 0);
            // Only this offline test uses an in-memory session; the live fixture is unchanged.
            $app['config']->set('session.driver', 'array');
            foreach (['oa_auth', 'oa_accounting'] as $name) {
                $connection = $app['db']->connection($name);
                $blocked = fn () => throw new \LogicException('Database access is forbidden in the fixture route test.');
                $connection->setPdo($blocked);
                $connection->setReadPdo($blocked);
            }

            $routes = $app['router']->getRoutes();
            $login = $routes->getByName('login');
            $store = $routes->getByName('login.store');
            $this->assertNotNull($login);
            $this->assertNotNull($store);
            $this->assertContains('GET', $login->methods());
            $this->assertSame('login', $store->uri());
            $this->assertSame(['POST'], $store->methods());
            $this->assertSame(AuthenticatedSessionController::class.'@store', $store->getActionName());

            $kernel = new OaHttpKernel($app, $app['router']);
            $request = Request::create('http://localhost/login', 'GET');
            $response = $kernel->handle($request);
            $this->assertSame(200, $response->getStatusCode());
            // Boolean assertions keep generated CSRF/cookie values out of failure output.
            $this->assertTrue(str_contains($response->getContent(), 'action="'.$app['url']->route('login.store').'"'));
            $this->assertTrue(str_contains($response->getContent(), 'name="_token"'));
            $kernel->terminate($request, $response);
        } finally {
            try {
                $web->close();
            } finally {
                Facade::clearResolvedInstances();
                Facade::setFacadeApplication($previousFacade);
                Container::setInstance($previousContainer);
            }
        }
    }
}
