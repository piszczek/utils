<?php declare(strict_types=1);

namespace OAS\Utils\Constructor;

use Psr\EventDispatcher\EventDispatcherInterface;

class Dispatcher implements EventDispatcherInterface
{
    private array $listeners = [];

    public function listen(string $event, callable $subscriber): void
    {
        if (!array_key_exists($event, $this->listeners)) {
            $this->listeners[$event] = [];
        }

        $this->listeners[$event][] = $subscriber;
    }

    public function subscribe(SubscriberInterface $subscriber): void
    {
        foreach ($subscriber->getSubscribedEvents() as $event => $listeners) {
            foreach ($listeners as $listener) {
                $this->listen($event, $listener);
            }
        }
    }

    public function dispatch(object $event): void
    {
        $eventName = get_class($event);

        foreach ($this->listeners[$eventName] ?? [] as $listener) {
            call_user_func($listener, $event);
        }
    }
}
