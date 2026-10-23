<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'radar_snapshots')]
#[ORM\Index(name: 'idx_snapshot_ts', columns: ['snapshot_timestamp'])]
class FlightSnapshot
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'snapshot_timestamp', type: 'integer')]
    private int $timestamp;

    #[ORM\Column(name: 'planes_json', type: 'text', length: 16777215)] // MEDIUMTEXT в MySQL
    private string $planesJson;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTimestamp(): int
    {
        return $this->timestamp;
    }

    public function setTimestamp(int $timestamp): self
    {
        $this->timestamp =$timestamp;
        return $this;
    }

    public function getPlanesJson(): string
    {
        return $this->planesJson;
    }

    public function setPlanesJson(string $planesJson): self
    {
        $this->planesJson =$planesJson;
        return $this;
    }
}
