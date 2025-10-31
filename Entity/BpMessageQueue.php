<?php

declare(strict_types=1);

namespace MauticPlugin\MauticBpMessageBundle\Entity;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Mautic\CoreBundle\Doctrine\Mapping\ClassMetadataBuilder;

class BpMessageQueue
{
    public const TABLE_NAME = 'bpmessage_queue';

    private ?int $id = null;

    private ?\DateTime $dateAdded = null;

    private ?\DateTimeImmutable $dateModified = null;

    /**
     * @var string|resource|null
     */
    private $payloadCompressed;

    private ?string $configHash = null;

    /**
     * Snapshot da configuração utilizada para envio (url, headers, método, etc.).
     */
    private ?string $configJson = null;

    private int $retries = 0;

    public static function loadMetadata(ORM\ClassMetadata $metadata): void
    {
        $builder = new ClassMetadataBuilder($metadata);
        $builder->setTable(self::TABLE_NAME)
            ->setCustomRepositoryClass(BpMessageQueueRepository::class);

        $builder->addId();

        $builder->createField('dateAdded', Types::DATETIME_MUTABLE)
            ->columnName('date_added')
            ->build();

        $builder->createField('dateModified', Types::DATETIME_IMMUTABLE)
            ->columnName('date_modified')
            ->nullable()
            ->build();

        // Table configuration for MySQL compatibility
        // Note: This section may need adjustment based on actual table configuration needs

        $builder->createField('payloadCompressed', Types::BLOB)
            ->columnName('payload_compressed')
            ->nullable()
            ->build();

        $builder->createField('configHash', Types::STRING)
            ->columnName('config_hash')
            ->length(64)
            ->build();

        $builder->createField('configJson', Types::TEXT)
            ->columnName('config_json')
            ->nullable()
            ->build();

        $builder->createField('retries', Types::INTEGER)
            ->build();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setDateAdded(\DateTime $dateAdded): void
    {
        $this->dateAdded = $dateAdded;
    }

    public function getDateAdded(): ?\DateTime
    {
        return $this->dateAdded;
    }

    public function setDateModified(?\DateTimeImmutable $dateModified): void
    {
        $this->dateModified = $dateModified;
    }

    public function getDateModified(): ?\DateTimeImmutable
    {
        return $this->dateModified;
    }

    /**
     * @param string|resource|null $payload
     */
    public function setPayload($payload): void
    {
        $this->payloadCompressed = $payload;
    }

    /**
     * @return string|resource|null
     */
    public function getPayload()
    {
        return $this->payloadCompressed;
    }

    public function setConfigHash(string $hash): void
    {
        $this->configHash = $hash;
    }

    public function getConfigHash(): ?string
    {
        return $this->configHash;
    }

    public function setConfigJson(?string $configJson): void
    {
        $this->configJson = $configJson;
    }

    public function getConfigJson(): ?string
    {
        return $this->configJson;
    }

    public function setRetries(int $retries): void
    {
        $this->retries = $retries;
    }

    public function getRetries(): int
    {
        return $this->retries;
    }
}