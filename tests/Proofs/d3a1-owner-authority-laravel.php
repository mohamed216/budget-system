<?php

// Loaded only by live proof modes, never by the pure --self-test.
require_once dirname(__DIR__, 2).'/vendor/autoload.php';

use App\Http\Controllers\AuthenticatedSessionController;
use App\Models\User;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;

/** A proof-only adapter: stock Eloquent retrieval/hash checks, explicit protected writes. */
class OaProtectedProvider extends EloquentUserProvider
{
    public function createModel()
    {
        return (new User)->setConnection('oa_auth');
    }

    private function actor(?Authenticatable $user): ?Authenticatable
    {
        // Relations and ordinary model queries remain on restricted accounting.
        return $user?->setConnection('oa_accounting');
    }

    public function retrieveById($identifier)
    {
        return $this->actor(parent::retrieveById($identifier));
    }

    public function retrieveByCredentials(#[SensitiveParameter] array $credentials)
    {
        return $this->actor(parent::retrieveByCredentials($credentials));
    }

    public function retrieveByToken($identifier, #[SensitiveParameter] $token)
    {
        return $this->actor(parent::retrieveByToken($identifier, $token));
    }

    public function updateRememberToken(Authenticatable $user, #[SensitiveParameter] $token)
    {
        $copy = (new User)->setConnection('oa_auth')->newFromBuilder($user->getAttributes(), 'oa_auth');
        parent::updateRememberToken($copy, $token);
        $user->setRememberToken($token);
    }

    public function rehashPasswordIfRequired(Authenticatable $user, #[SensitiveParameter] array $credentials, bool $force = false)
    {
        $copy = (new User)->setConnection('oa_auth')->newFromBuilder($user->getAttributes(), 'oa_auth');
        parent::rehashPasswordIfRequired($copy, $credentials, $force);
        $user->setRawAttributes($copy->getAttributes(), true);
    }
}

class OaHttpKernel extends Illuminate\Foundation\Http\Kernel
{
    protected $bootstrappers = [];

    protected $middlewareGroups = ['web' => [
        Illuminate\Cookie\Middleware\EncryptCookies::class,
        Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
        Illuminate\Session\Middleware\StartSession::class,
        Illuminate\View\Middleware\ShareErrorsFromSession::class,
        Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
        Illuminate\Routing\Middleware\SubstituteBindings::class,
    ]];

    protected $middlewareAliases = [
        'auth' => Illuminate\Auth\Middleware\Authenticate::class,
        'guest' => Illuminate\Auth\Middleware\RedirectIfAuthenticated::class,
        'throttle' => Illuminate\Routing\Middleware\ThrottleRequests::class,
    ];
}

class OaHttpErrors extends Illuminate\Foundation\Exceptions\Handler
{
    public function report(Throwable $e): void
    {
        // No framework log may serialize bindings, cookies, keys, or credentials.
    }

    public function render($request, Throwable $e)
    {
        if ($e instanceof Illuminate\Validation\ValidationException) {
            return response()->json(['rejected' => true], 422);
        }
        if ($e instanceof Illuminate\Auth\AuthenticationException) {
            return response()->json(['unauthenticated' => true], 401);
        }
        if ($e instanceof Illuminate\Session\TokenMismatchException) {
            return response()->json(['csrf_rejected' => true], 419);
        }
        if ($e instanceof OaSigningKeyInactive) {
            return response()->json(['issuer_rejected' => true, 'reason' => OaSigningKeyInactive::REASON], 403);
        }
        if ($e instanceof OaFailure) {
            return response()->json(['issuer_rejected' => true], 403);
        }

        return response()->json(['fixture_error' => get_class($e)], 500);
    }
}

class OaNoopJob implements Illuminate\Contracts\Queue\ShouldQueue
{
    public function handle(): void {}
}

class OaLaravelFixture
{
    public array $cookies = [];

    public array $trace = [];

    public string $activeKey = 'K1';

    public array $signingStates = ['K1' => 'active', 'K2' => 'active'];

    private static ?string $sharedAppKey = null;

    private string $appKey;

    private string $directory;

    private ?Application $application = null;

    public function __construct(private array $keys, private readonly ?Closure $connectionFactory = null)
    {
        self::$sharedAppKey ??= 'base64:'.base64_encode(random_bytes(32));
        $this->appKey = self::$sharedAppKey;
        $this->directory = sys_get_temp_dir().'/d3a1-oa-'.bin2hex(random_bytes(12));
        oaDemand(mkdir($this->directory, 0700), 'OA temporary directory unavailable');
        foreach (['views', 'views/auth', 'compiled', 'storage', 'bootstrap'] as $directory) {
            oaDemand(mkdir($this->directory.'/'.$directory, 0700), 'OA temporary fixture path unavailable');
        }
        // Real controller creates its normal view name, but no Vite/app bootstrap.
        file_put_contents($this->directory.'/views/auth/login.blade.php', '<form method="POST" action="{{ route("login.store") }}">@csrf</form>');
    }

    private function makeApplication(int $target): Application
    {
        Facade::clearResolvedInstances();
        $app = new Application($this->directory);
        $app->useStoragePath($this->directory.'/storage');
        $app->instance('env', 'oa-proof'); // CSRF must NOT use Laravel's testing bypass.
        $connections = [];
        foreach (['AUTH', 'ACCOUNTING'] as $role) {
            $connections['oa_'.strtolower($role)] = [
                'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 3306,
                'database' => OA_SCHEMA, 'username' => getenv('D3A1_OA_'.$role.'_USER'),
                'password' => getenv('D3A1_OA_'.$role.'_PASSWORD'), 'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true,
                'options' => [PDO::ATTR_EMULATE_PREPARES => false],
            ];
        }
        $app->instance('config', new Illuminate\Config\Repository([
            'app' => ['key' => $this->appKey, 'cipher' => 'AES-256-CBC', 'debug' => false, 'url' => 'http://localhost', 'locale' => 'en', 'fallback_locale' => 'en'],
            'database' => ['default' => 'oa_accounting', 'connections' => $connections],
            'auth' => ['defaults' => ['guard' => 'web', 'passwords' => 'users'], 'guards' => ['web' => ['driver' => 'session', 'provider' => 'users']], 'providers' => ['users' => ['driver' => 'oa_protected', 'model' => User::class]]],
            'hashing' => ['driver' => 'bcrypt', 'bcrypt' => ['rounds' => 6, 'verify' => true], 'rehash_on_login' => true],
            'session' => ['driver' => 'database', 'connection' => 'oa_auth', 'table' => 'sessions', 'encrypt' => false, 'serialization' => 'php', 'lifetime' => 120, 'expire_on_close' => false, 'lottery' => [0, 100], 'cookie' => 'oa_session', 'path' => '/', 'domain' => null, 'secure' => false, 'http_only' => true, 'same_site' => 'lax'],
            'cache' => ['default' => 'database', 'prefix' => 'oa_', 'stores' => ['database' => ['driver' => 'database', 'connection' => 'oa_auth', 'table' => 'cache']]],
            'queue' => ['default' => 'database', 'connections' => ['database' => ['driver' => 'database', 'connection' => 'oa_auth', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90, 'after_commit' => false]], 'failed' => ['driver' => 'database-uuids', 'database' => 'oa_auth', 'table' => 'failed_jobs']],
            'view' => ['paths' => [$this->directory.'/views'], 'compiled' => $this->directory.'/compiled'],
            'logging' => ['default' => 'null', 'channels' => ['null' => ['driver' => 'monolog', 'handler' => Monolog\Handler\NullHandler::class]]],
        ]));
        Facade::setFacadeApplication($app);
        foreach ([
            Illuminate\Filesystem\FilesystemServiceProvider::class,
            Illuminate\Database\DatabaseServiceProvider::class,
            Illuminate\Encryption\EncryptionServiceProvider::class,
            Illuminate\Cookie\CookieServiceProvider::class,
            Illuminate\Hashing\HashServiceProvider::class,
            Illuminate\Auth\AuthServiceProvider::class,
            Illuminate\Session\SessionServiceProvider::class,
            Illuminate\Cache\CacheServiceProvider::class,
            Illuminate\Queue\QueueServiceProvider::class,
            Illuminate\Bus\BusServiceProvider::class,
            Illuminate\Translation\TranslationServiceProvider::class,
            Illuminate\Validation\ValidationServiceProvider::class,
            Illuminate\View\ViewServiceProvider::class,
            Illuminate\Foundation\Providers\FoundationServiceProvider::class,
        ] as $provider) {
            $app->register($provider);
        }
        foreach (['AUTH', 'ACCOUNTING'] as $role) {
            $connection = $app['db']->connection('oa_'.strtolower($role));
            $reconnect = function ($connection) use ($role): void {
                $connection->setPdo(fn () => ($this->connectionFactory ?? 'oaConnect')($role));
                $connection->setReadPdo(fn () => $connection->getPdo());
            };
            $connection->setReconnector($reconnect);
            $reconnect($connection);
        }
        $app->singleton(Illuminate\Contracts\Debug\ExceptionHandler::class, OaHttpErrors::class);
        $app['auth']->provider('oa_protected', fn ($app) => new OaProtectedProvider($app['hash'], User::class));
        $app['events']->listen(Illuminate\Database\Events\QueryExecuted::class, function ($event): void {
            // Record only routing facts, never SQL values/bindings.
            $this->trace[] = ['connection' => $event->connectionName, 'users' => (bool) preg_match('/\busers\b/', $event->sql), 'write' => (bool) preg_match('/^(update|insert|delete)/i', $event->sql)];
        });
        $router = $app['router'];
        $router->middleware('web')->group(function () use ($router, $app, $target): void {
            $router->middleware('guest')->group(function () use ($router): void {
                $router->get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
                $router->post('/login', [AuthenticatedSessionController::class, 'store'])->middleware('throttle:5,1')->name('login.store');
            });
            $router->get('/csrf', fn (Request $request) => response()->json(['csrf' => $request->session()->token()]));
            $router->middleware('auth')->group(function () use ($router, $app, $target): void {
                $router->get('/', function (Request $request) use ($app) {
                    oaDemand($request->user()->getConnectionName() === 'oa_accounting', 'OA actor connection escaped accounting');
                    $app['db']->connection()->select('SELECT id FROM users WHERE id=?', [$request->user()->getKey()]);

                    return response()->json(['owner' => (string) $request->user()->getKey()]);
                })->name('dashboard');
                $router->post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
                $router->post('/ticket', function (Request $request) use ($app, $target) {
                    oaDemand(! $request->exists('owner_id'), 'OA caller owner selection denied');
                    if (($this->signingStates[$this->activeKey] ?? null) !== 'active') {
                        throw new OaSigningKeyInactive;
                    }
                    $owner = (string) $request->user()->getKey();
                    $fact = $app['db']->connection()->selectOne('SELECT i.*, a.revision account_revision, a.currency account_currency FROM proof_intents i JOIN proof_accounts a ON a.id=i.account_id AND a.owner_id=i.owner_id WHERE i.id=? AND i.owner_id=?', [$request->input('intent_id'), $owner]);
                    oaDemand($fact !== null && $fact->currency === $fact->account_currency, 'OA issuer owner/fact mismatch');
                    $now = oaClock($app['db']->connection('oa_auth')->getPdo());
                    $ticket = array_combine(OA_FIELDS, ['d3a1-oa/1', OA_SCHEMA, 'apply-neutral-intent', $this->activeKey, (string) $request->input('command_uuid'), $owner, (string) $fact->account_id, (string) $fact->id, (string) $fact->amount, $fact->direction, $fact->accounting_date, $fact->currency, (string) $fact->account_revision, (string) $fact->revision, (string) $now, (string) ($now + 55000000), (string) $target]);
                    $ticket['tag'] = hash_hmac('sha256', oaEncode($ticket), $this->keys[$this->activeKey]);

                    return response()->json($ticket);
                });
            });
        });
        $router->getRoutes()->refreshNameLookups();
        $app->boot();
        $this->application = $app;

        return $app;
    }

    public function request(string $method, string $path, array $parameters = [], int $target = 0): array
    {
        $app = $this->makeApplication($target);
        $kernel = new OaHttpKernel($app, $app['router']);
        $request = Request::create('http://localhost'.$path, $method, $parameters, $this->cookies, [], ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_ACCEPT' => 'application/json']);
        try {
            $response = $kernel->handle($request);
            foreach ($response->headers->getCookies() as $cookie) {
                if ($cookie->getExpiresTime() > 0 && $cookie->getExpiresTime() <= time()) {
                    unset($this->cookies[$cookie->getName()]);
                } else {
                    $this->cookies[$cookie->getName()] = $cookie->getValue();
                }
            }
            $body = $response->getContent();
            $kernel->terminate($request, $response);

            return ['status' => $response->getStatusCode(), 'body' => json_decode($body, true), 'session_id' => $request->hasSession() ? $request->session()->getId() : null];
        } finally {
            $this->disconnect($app);
        }
    }

    public function csrf(): string
    {
        $response = $this->request('GET', '/csrf');
        oaDemand($response['status'] === 200 && isset($response['body']['csrf']), 'OA CSRF retrieval failed');

        return $response['body']['csrf'];
    }

    public function login(int $owner = 1): void
    {
        $response = $this->request('POST', '/login', ['_token' => $this->csrf(), 'email' => 'owner'.$owner.'@example.test', 'password' => oaSecret('D3A1_OA_FIXTURE_PASSWORD').($owner === 2 ? '-owner2' : '')]);
        oaDemand($response['status'] === 302, 'OA real login failed');
    }

    public function ticket(int $target, int $intent = 1, ?string $uuid = null): array
    {
        $response = $this->request('POST', '/ticket', ['_token' => $this->csrf(), 'intent_id' => $intent, 'command_uuid' => $uuid ?? oaUuid()], $target);
        if ($response['status'] === 403 && ($response['body']['issuer_rejected'] ?? null) === true && ($response['body']['reason'] ?? null) === OaSigningKeyInactive::REASON) {
            throw new OaSigningKeyInactive;
        }
        oaDemand($response['status'] === 200 && isset($response['body']['tag']), 'OA authenticated issuer failed');

        return $response['body'];
    }

    public function rotateRememberToken(): void
    {
        $user = $this->application['auth']->guard()->user();
        oaDemand($user !== null, 'OA remembered actor unavailable');
        try {
            $this->application['auth']->createUserProvider('users')->updateRememberToken($user, bin2hex(random_bytes(30)));
        } finally {
            $this->disconnect($this->application);
        }
    }

    public function infrastructure(): void
    {
        $app = $this->makeApplication(0);
        try {
            $app['cache']->put('neutral', 'proof', 60);
            oaDemand($app['cache']->get('neutral') === 'proof', 'OA protected cache roundtrip failed');
            $app['cache']->forget('neutral');
            $app['queue']->push(new OaNoopJob);
            $job = $app['queue']->pop();
            oaDemand($job !== null, 'OA protected queue pop failed');
            $job->fire();
            $job->delete();
            $id = $app['queue.failer']->log('database', 'default', json_encode(['uuid' => oaUuid(), 'displayName' => 'OaNoopJob']), 'neutral fixture failure');
            oaDemand($app['queue.failer']->find($id) !== null && $app['queue.failer']->forget($id), 'OA failed-job lifecycle failed');
        } finally {
            $this->disconnect($app);
        }
    }

    private function disconnect(Application $app): void
    {
        $failed = false;
        foreach (['oa_auth', 'oa_accounting'] as $name) {
            try {
                $pdo = $app['db']->connection($name)->getRawPdo();
                if ($pdo instanceof PDO) {
                    oaRestore($pdo);
                }
            } catch (Throwable $error) {
                $failed = true;
            } finally {
                $app['db']->disconnect($name);
            }
        }
        oaDemand(! $failed, 'OA Laravel session restoration failed; connections discarded');
    }

    public function close(): void
    {
        $this->cookies = [];
        $this->keys = [];
        $this->application = null;
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->directory);
    }
}
