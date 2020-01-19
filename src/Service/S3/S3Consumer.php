<?php declare(strict_types=1);

namespace Bref\Messenger\Service\S3;

use Bref\Messenger\Exception\InvalidEventException;
use Bref\Messenger\Exception\TypeNotSupportedException;
use Bref\Messenger\Message\S3Event;
use Bref\Messenger\Service\BusDriver;
use Bref\Messenger\Service\Consumer;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

class S3Consumer implements Consumer
{
    /** @var MessageBusInterface */
    private $bus;
    /** @var SerializerInterface */
    protected $serializer;
    /** @var string */
    private $transportName;
    /** @var BusDriver */
    private $busDriver;

    public function __construct(
        BusDriver $busDriver,
        MessageBusInterface $bus,
        SerializerInterface $serializer,
        string $transportName
    ) {
        $this->busDriver = $busDriver;
        $this->bus = $bus;
        $this->serializer = $serializer;
        $this->transportName = $transportName;
    }

    public function consume(string $type, $event): void
    {
        if (! in_array($type, self::supportedTypes())) {
            throw TypeNotSupportedException::create($type, self::class, $event);
        }

        if (! is_array($event) || ! isset($event['Records'])) {
            throw InvalidEventException::create($type, self::class, $event);
        }

        foreach ($event['Records'] as $record) {
            $envelope = new Envelope(new S3Event($record));
            $this->busDriver->putEnvelopeOnBus($this->bus, $envelope, $this->transportName);
        }
    }

    public static function supportedTypes(): array
    {
        return ['s3'];
    }
}