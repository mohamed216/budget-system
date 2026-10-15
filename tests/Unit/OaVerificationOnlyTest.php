<?php

namespace Tests\Unit;

use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use OaFailure;
use OaHttpErrors;
use OaLaravelFixture;
use OaSigningKeyInactive;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class OaVerificationOnlyTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__.'/../Proofs/d3a1-owner-authority.php';
        require_once __DIR__.'/../Proofs/d3a1-owner-authority-laravel.php';
    }

    public function test_verification_only_issuer_emits_the_specific_rejection_signal(): void
    {
        $previousContainer = Container::getInstance();
        $previousFacade = Facade::getFacadeApplication();
        $web = new OaLaravelFixture([], fn () => throw new \LogicException('Database access forbidden in issuer signal test'));
        try {
            $app = (new ReflectionMethod($web, 'makeApplication'))->invoke($web, 42);
            $web->signingStates['K1'] = 'verify_only';
            $request = Request::create('http://localhost/ticket', 'POST');
            $app->instance('request', $request);
            $route = $app['router']->getRoutes()->match($request);
            try {
                // Exercise the actual issuer closure, independently of session/CSRF middleware.
                $route->getAction('uses')($request);
                $this->fail('Verification-only issuer must reject');
            } catch (OaSigningKeyInactive $error) {
                $response = (new OaHttpErrors($app))->render($request, $error);
                $this->assertSame(403, $response->getStatusCode());
                $this->assertSame(['issuer_rejected' => true, 'reason' => OaSigningKeyInactive::REASON], $response->getData(true));
                oaExpectSigningKeyInactive(fn () => $this->ticketFixture([
                    'status' => $response->getStatusCode(), 'body' => $response->getData(true),
                ])->ticket(42));
            }
            $unrelated = (new OaHttpErrors($app))->render($request, new OaFailure('Unrelated fixture failure'));
            $this->assertSame(403, $unrelated->getStatusCode());
            $this->assertArrayNotHasKey('reason', $unrelated->getData(true));
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

    public function test_unrelated_http_rejections_cannot_satisfy_the_proof(): void
    {
        foreach ([
            ['status' => 403, 'body' => ['issuer_rejected' => true]],
            ['status' => 403, 'body' => ['issuer_rejected' => true, 'reason' => 'other']],
            ['status' => 403, 'body' => ['issuer_rejected' => false, 'reason' => OaSigningKeyInactive::REASON]],
            ['status' => 401, 'body' => ['unauthenticated' => true]],
            ['status' => 419, 'body' => ['csrf_rejected' => true]],
            ['status' => 422, 'body' => ['rejected' => true]],
            ['status' => 404, 'body' => null],
            ['status' => 500, 'body' => ['fixture_error' => 'RuntimeException']],
            ['status' => 500, 'body' => ['issuer_rejected' => true, 'reason' => OaSigningKeyInactive::REASON]],
        ] as $response) {
            try {
                oaExpectSigningKeyInactive(fn () => $this->ticketFixture($response)->ticket(42));
                $this->fail('Unrelated rejection satisfied verification-only proof');
            } catch (OaFailure $error) {
                $this->assertNotInstanceOf(OaSigningKeyInactive::class, $error);
            }
        }
    }

    public function test_unrelated_failure_propagates_without_being_accepted(): void
    {
        $unrelated = new OaFailure('Unrelated fixture failure');
        try {
            oaExpectSigningKeyInactive(fn () => throw $unrelated);
            $this->fail('Unrelated failure satisfied verification-only proof');
        } catch (OaFailure $error) {
            $this->assertSame($unrelated, $error);
        }
    }

    public function test_successful_issuance_fails_the_verification_only_proof(): void
    {
        $web = $this->ticketFixture(['status' => 200, 'body' => ['tag' => 'offline-test-tag']]);
        $issued = false;
        try {
            oaExpectSigningKeyInactive(function () use ($web, &$issued) {
                $web->ticket(42);
                $issued = true;
            });
            $this->fail('Successful issuance satisfied verification-only proof');
        } catch (OaFailure $error) {
            $this->assertTrue($issued);
            $this->assertNotInstanceOf(OaSigningKeyInactive::class, $error);
        }
    }

    private function ticketFixture(array $response): OaLaravelFixture
    {
        $web = $this->getMockBuilder(OaLaravelFixture::class)->disableOriginalConstructor()->onlyMethods(['csrf', 'request'])->getMock();
        $web->method('csrf')->willReturn('offline-test-csrf');
        $web->expects($this->once())->method('request')->with('POST', '/ticket', $this->isType('array'), 42)->willReturn($response);

        return $web;
    }
}
