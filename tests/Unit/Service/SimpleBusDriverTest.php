<?php
namespace Bref\Symfony\Messenger\Test\Unit\Service;

use Bref\Symfony\Messenger\Service\SimpleBusDriver;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Psr\Log\NullLogger;
use stdClass;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ConsumedByWorkerStamp;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Throwable;

final class SimpleBusDriverTest extends TestCase
{
    use ProphecyTrait;

    private $messengerBus;

    /** @before */
    public function prepare()
    {
        $this->messengerBus = $this->prophesize(MessageBusInterface::class);
    }

    public function test_it_dispatch_message_on_specified_transport_ready_to_be_consumed()
    {
        $sut = new SimpleBusDriver(new NullLogger());
        $envelopeToBeConsumed = new Envelope(new stdClass());
        $this->messengerWillSucceedInConsumingOnTransport($envelopeToBeConsumed, 'my_transport');

        $sut->putEnvelopeOnBus($this->messengerBus->reveal(), $envelopeToBeConsumed, 'my_transport');
    }

    public function test_it_rethrows_handler_failed_exception_and_dispatches_failed_event()
    {
        $events = [];
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(WorkerMessageFailedEvent::class, function (WorkerMessageFailedEvent $event) use (&$events) {
            $events[] = $event;
        });
        $sut = new SimpleBusDriver(new NullLogger(), $dispatcher);
        $envelopeToBeConsumed = new Envelope(new stdClass());
        $this->messengerWillFailToConsumeOnTransport(
            $envelopeToBeConsumed,
            'async',
            new UnrecoverableMessageHandlingException('boum')
        );

        try {
            $sut->putEnvelopeOnBus($this->messengerBus->reveal(), $envelopeToBeConsumed, 'async');
            $this->fail('Expected HandlerFailedException');
        } catch (HandlerFailedException $exception) {
            // The wrapper is rethrown as-is (not unpacked), so failed messages keep being retried / dead-lettered
            $this->assertInstanceOf(UnrecoverableMessageHandlingException::class, $exception->getPrevious());
        }

        $this->assertCount(1, $events);
        $this->assertSame('async', $events[0]->getReceiverName());
        $this->assertInstanceOf(HandlerFailedException::class, $events[0]->getThrowable());
    }

    public function test_it_dispatches_received_and_handled_events()
    {
        $dispatched = [];
        $dispatcher = new EventDispatcher();
        foreach ([WorkerMessageReceivedEvent::class, WorkerMessageHandledEvent::class] as $eventClass) {
            $dispatcher->addListener($eventClass, function (object $event) use (&$dispatched) {
                $dispatched[] = get_class($event);
            });
        }
        $sut = new SimpleBusDriver(new NullLogger(), $dispatcher);
        $envelopeToBeConsumed = new Envelope(new stdClass());
        $this->messengerWillSucceedInConsumingOnTransport($envelopeToBeConsumed, 'my_transport');

        $sut->putEnvelopeOnBus($this->messengerBus->reveal(), $envelopeToBeConsumed, 'my_transport');

        $this->assertSame([WorkerMessageReceivedEvent::class, WorkerMessageHandledEvent::class], $dispatched);
    }

    public function test_it_skips_the_message_when_a_listener_says_not_to_handle_it()
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(WorkerMessageReceivedEvent::class, function (WorkerMessageReceivedEvent $event) {
            $event->shouldHandle(false);
        });
        $sut = new SimpleBusDriver(new NullLogger(), $dispatcher);
        $this->messengerBus->dispatch(Argument::any())->shouldNotBeCalled();

        $this->assertNull($sut->putEnvelopeOnBus($this->messengerBus->reveal(), new Envelope(new stdClass()), 'async'));
    }

    private function messengerWillSucceedInConsumingOnTransport(Envelope $envelope, string $transport)
    {
        $this->messengerBus
            ->dispatch($envelope->with(new ReceivedStamp($transport), new ConsumedByWorkerStamp))
            ->willReturn(
                $envelope->with(new HandledStamp('result', 'my.handler'))
            )
            ->shouldBeCalled()
        ;
    }

    private function messengerWillFailToConsumeOnTransport(Envelope $envelope, string $transport, Throwable $failure)
    {
        $this->messengerBus
            ->dispatch($envelope->with(new ReceivedStamp($transport), new ConsumedByWorkerStamp))
            ->willThrow(
                new HandlerFailedException(
                    $envelope->with(new HandledStamp('result', 'my.handler')),
                    [
                        $failure
                    ]
                )
            )
            ->shouldBeCalled()
        ;
    }
}
