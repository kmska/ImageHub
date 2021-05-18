<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass="App\Repository\IIIfManifestRepository")
 * @ORM\Table(name="iiif_manifest")
 */
class IIIfManifest
{
    /**
     * @ORM\Id()
     * @ORM\GeneratedValue()
     * @ORM\Column(type="integer")
     */
    private $id;

    /**
     * @ORM\Column(type="string", length=255)
     */
    private $manifestId;

    /**
     * @ORM\Column(type="text")
     */
    private $data;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getManifestId(): ?string
    {
        return $this->manifestId;
    }

    public function setManifestId(string $manifestId): self
    {
        $this->manifestId = $manifestId;

        return $this;
    }

    public function getData(): ?string
    {
        return $this->data;
    }

    public function setData(string $data): self
    {
        $this->data = $data;

        return $this;
    }
}
