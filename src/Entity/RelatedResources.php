<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass="App\Repository\RelatedResourcesRepository")
 * @ORM\Table(name="related_resources")
 */
class RelatedResources
{
    /**
     * @ORM\Id()
     * @ORM\Column(type="integer")
     */
    private $id;

    /**
     * @ORM\Column(type="text")
     */
    private $relatedResources;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(int $id): self
    {
        $this->id = $id;

        return $this;
    }

    public function getRelatedResources(): ?string
    {
        return $this->relatedResources;
    }

    public function setRelatedResources(string $relatedResources): self
    {
        $this->relatedResources = $relatedResources;

        return $this;
    }
}
