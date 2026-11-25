<?php

declare(strict_types=1);

namespace Obelaw\Ium\Actions;

use Illuminate\Support\Facades\Event;
use Obelaw\Ium\Contracts\Actor;
use Obelaw\Ium\Facades\Ium;

/**
 * Standardized domain write-action wrapper.
 *
 * Guarantees consistent enterprise execution across every IUM module:
 * 1. Domain authorization via `Authorizer`
 * 2. Actor resolution via `ActorResolver`
 * 3. Atomic transaction on the active `TenantContext` connection
 * 4. Optional audit hook inside the transaction
 * 5. Automatic `afterCommit()` domain event dispatching (never leaks rolled-back events)
 *
 * @template TInput
 * @template TResult
 */
abstract class Action
{
    /**
     * Optional domain ability checked against `Authorizer` before execution.
     */
    protected ?string $ability = null;

    /**
     * Whether execution should be wrapped in a database transaction on the active IUM connection.
     */
    protected bool $transactional = true;

    /**
     * Domain events recorded during `handle()`, dispatched strictly after commit.
     *
     * @var list<object>
     */
    private array $recordedEvents = [];

    /**
     * Execute the action with authorization, transaction, audit, and after-commit event dispatch.
     *
     * @param TInput $input
     *
     * @return TResult
     */
    final public function execute(mixed $input = null): mixed
    {
        $this->recordedEvents = [];

        $actor = Ium::actor();

        if ($this->ability !== null && $this->ability !== '') {
            Ium::authorizer()->authorize($this->ability, $this->authorizationSubject($input), $actor);
        }

        if (! $this->transactional) {
            $result = $this->handle($input, $actor);
            $this->audit($actor, $input, $result);
            $this->flushEvents();

            return $result;
        }

        $connection = Ium::connection();

        try {
            return $connection->transaction(function () use ($connection, $input, $actor) {
                $result = $this->handle($input, $actor);
                $this->audit($actor, $input, $result);

                $events = $this->recordedEvents;
                $this->recordedEvents = [];

                if ($events !== []) {
                    $connection->afterCommit(static function () use ($events): void {
                        foreach ($events as $event) {
                            Event::dispatch($event);
                        }
                    });
                }

                return $result;
            });
        } finally {
            $this->recordedEvents = [];
        }
    }

    /**
     * Record a domain event to be dispatched strictly after the transaction commits.
     */
    protected function recordEvent(object $event): void
    {
        $this->recordedEvents[] = $event;
    }

    /**
     * Resolve the optional subject passed to `Authorizer::authorize()`.
     *
     * @param TInput $input
     */
    protected function authorizationSubject(mixed $input): mixed
    {
        return $input;
    }

    /**
     * Optional forensic audit hook invoked inside the transaction after `handle()` succeeds.
     *
     * @param TInput $input
     * @param TResult $result
     */
    protected function audit(Actor $actor, mixed $input, mixed $result): void
    {
        // Subclasses override when audit persistence is required.
    }

    /**
     * Execute the core domain mutation.
     *
     * @param TInput $input
     *
     * @return TResult
     */
    abstract protected function handle(mixed $input, Actor $actor): mixed;

    private function flushEvents(): void
    {
        $events = $this->recordedEvents;
        $this->recordedEvents = [];

        foreach ($events as $event) {
            Event::dispatch($event);
        }
    }
}
