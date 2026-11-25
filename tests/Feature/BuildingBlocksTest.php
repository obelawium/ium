<?php

declare(strict_types=1);

namespace Obelaw\Ium\Tests\Feature;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Obelaw\Ium\Abstracts\Domain;
use Obelaw\Ium\Actions\Action;
use Obelaw\Ium\Bases\Dto;
use Obelaw\Ium\Contracts\Actor;
use Obelaw\Ium\Contracts\ActorResolver;
use Obelaw\Ium\Contracts\Authorizer;
use Obelaw\Ium\Contracts\TenantContext;
use Obelaw\Ium\Contracts\TenantIdentity;
use Obelaw\Ium\Exceptions\AuthorizationException;
use Obelaw\Ium\Facades\Ium;
use Obelaw\Ium\Modules\Module;
use Obelaw\Ium\Modules\ModuleScope;
use Obelaw\Ium\Outbox\OutboxMessage;
use Obelaw\Ium\Outbox\OutboxStatus;
use RuntimeException;

final class CoreTenantStubDomain extends Domain {}

final readonly class CreateVoucherInput extends Dto
{
    public function __construct(
        public string $reference,
        public bool $shouldFail = false,
    ) {
        $this->validateSelf();
    }

    protected function rules(): array
    {
        return [
            'reference' => ['required', 'string', 'min:2'],
            'shouldFail' => ['boolean'],
        ];
    }
}

final readonly class VoucherRecordedEvent
{
    public function __construct(
        public string $number,
        public string $actorId,
    ) {}
}

final class RecordVoucherAction extends Action
{
    protected ?string $ability = 'accounting.voucher.record';

    /** @var list<array{actor: string, number: string}> */
    public array $auditLog = [];

    protected function handle(mixed $input, Actor $actor): mixed
    {
        /** @var CreateVoucherInput $input */
        $number = Ium::sequence()->next('voucher:2026', 'JV-2026', padding: 4);

        $this->recordEvent(new VoucherRecordedEvent($number, $actor->id));

        if ($input->shouldFail) {
            throw new RuntimeException('Abort voucher recording');
        }

        return $number;
    }

    protected function audit(Actor $actor, mixed $input, mixed $result): void
    {
        $this->auditLog[] = ['actor' => $actor->identifier(), 'number' => (string) $result];
    }
}

it('generates atomic, tenant-isolated sequences independently per tenant', function () {
    config()->set('database.connections.tenant_a', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    config()->set('database.connections.tenant_b', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);

    $tenantA = new TenantIdentity(id: '1', key: 'acme', connection: 'tenant_a');
    $tenantB = new TenantIdentity(id: '2', key: 'globex', connection: 'tenant_b');
    $context = new InMemoryMultiTenantContext([$tenantA, $tenantB]);
    app()->instance(TenantContext::class, $context);

    Ium::registerModule(Module::make('beta', CoreTenantStubDomain::class, ModuleScope::Tenant));
    $this->artisan('ium:tenants:migrate')->assertExitCode(0);

    $seqA1 = $context->runForTenant($tenantA, fn () => Ium::sequence()->next('je:2026', 'JE-2026', 4));
    $seqA2 = $context->runForTenant($tenantA, fn () => Ium::sequence()->next('je:2026', 'JE-2026', 4));
    $seqB1 = $context->runForTenant($tenantB, fn () => Ium::sequence()->next('je:2026', 'JE-2026', 4));

    expect($seqA1)->toBe('JE-2026-0001')
        ->and($seqA2)->toBe('JE-2026-0002')
        ->and($seqB1)->toBe('JE-2026-0001');
});

it('executes Action with Authorizer, ActorResolver, audit, and strict afterCommit event dispatch', function () {
    $this->artisan('ium:migrate')->assertExitCode(0);

    app()->instance(ActorResolver::class, new class implements ActorResolver
    {
        public function resolve(): Actor
        {
            return Actor::user('42', 'Accountant');
        }

        public function id(): string
        {
            return '42';
        }
    });

    $dispatched = [];
    Event::listen(VoucherRecordedEvent::class, static function (VoucherRecordedEvent $e) use (&$dispatched): void {
        $dispatched[] = $e;
    });

    $action = new RecordVoucherAction();

    // 1. Failing transaction must roll back sequence AND NOT dispatch recorded events!
    expect(fn () => $action->execute(CreateVoucherInput::from(['reference' => 'REF-FAIL', 'shouldFail' => true])))
        ->toThrow(RuntimeException::class);

    expect($dispatched)->toBeEmpty()
        ->and(Ium::sequence()->current('voucher:2026'))->toBe(0)
        ->and($action->auditLog)->toBeEmpty();

    // 2. Successful transaction commits sequence, runs audit, and dispatches event after commit!
    $number = $action->execute(CreateVoucherInput::from(['reference' => 'REF-OK']));

    expect($number)->toBe('JV-2026-0001')
        ->and($dispatched)->toHaveCount(1)
        ->and($dispatched[0]->number)->toBe('JV-2026-0001')
        ->and($dispatched[0]->actorId)->toBe('42')
        ->and($action->auditLog)->toBe([['actor' => 'user:42', 'number' => 'JV-2026-0001']]);

    // 3. Authorizer blocks unauthorized execution before touching DB
    app()->instance(Authorizer::class, new class implements Authorizer
    {
        public function allows(string $ability, mixed $subject = null, ?Actor $actor = null): bool
        {
            return false;
        }

        public function authorize(string $ability, mixed $subject = null, ?Actor $actor = null): void
        {
            throw AuthorizationException::denied($ability);
        }
    });

    expect(fn () => $action->execute(CreateVoucherInput::from(['reference' => 'REF-DENIED'])))
        ->toThrow(AuthorizationException::class);

    expect(Ium::sequence()->current('voucher:2026'))->toBe(1);
});

it('supports immutable Dto helpers (with, only, except)', function () {
    $dto = CreateVoucherInput::from(['reference' => 'REF-01']);
    $cloned = $dto->with(['shouldFail' => true]);

    expect($dto->shouldFail)->toBeFalse()
        ->and($cloned->shouldFail)->toBeTrue()
        ->and($cloned->only(['reference']))->toBe(['reference' => 'REF-01'])
        ->and($cloned->except(['shouldFail']))->toBe(['reference' => 'REF-01']);
});

it('publishes idempotent outbox messages, retries with backoff, marks failed, and replays cleanly', function () {
    $this->artisan('ium:migrate')->assertExitCode(0);

    $outbox = Ium::outbox();

    // Idempotent publish
    $m1 = $outbox->publish('sales.order.confirmed', ['order_id' => 101], idempotencyKey: 'order-101', maxAttempts: 2);
    $m2 = $outbox->publish('sales.order.confirmed', ['order_id' => 101], idempotencyKey: 'order-101', maxAttempts: 2);

    expect($m2->id)->toBe($m1->id)
        ->and(OutboxMessage::query()->count())->toBe(1);

    // First attempt fails -> retried (pending with future available_at)
    $shouldFail = true;
    $received = [];

    $outbox->subscribe('sales.order.confirmed', static function (OutboxMessage $msg) use (&$shouldFail, &$received): void {
        if ($shouldFail) {
            throw new RuntimeException('Accounting downstream timeout');
        }
        $received[] = $msg->payload;
    });

    $r1 = $outbox->processPending(baseBackoffSeconds: 60);
    $m1->refresh();

    expect($r1->processed)->toBe(1)
        ->and($r1->retried)->toBe(1)
        ->and($m1->status)->toBe(OutboxStatus::Pending)
        ->and($m1->attempts)->toBe(1)
        ->and($m1->last_error)->toBe('Accounting downstream timeout');

    // Second attempt after backoff still fails -> exhausted -> Failed
    Carbon::setTestNow(Carbon::now()->addSeconds(120));
    $r2 = $outbox->processPending(baseBackoffSeconds: 60);
    $m1->refresh();

    expect($r2->failed)->toBe(1)
        ->and($m1->status)->toBe(OutboxStatus::Failed)
        ->and($m1->attempts)->toBe(2);

    // Replay failed message once downstream recovers -> Dispatched
    $shouldFail = false;
    $this->artisan('ium:outbox:process --retry-failed')->assertExitCode(0);
    $m1->refresh();

    expect($m1->status)->toBe(OutboxStatus::Dispatched)
        ->and($m1->dispatched_at)->not->toBeNull()
        ->and($received)->toBe([['order_id' => 101]]);

    Carbon::setTestNow();
});
