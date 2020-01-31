<?php

namespace App\Entity\Main;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass="App\Repository\Main\IncrusteRepository")
 * @ORM\Table(name="incrustes")
 */
class Incruste
{
    /**
     * @ORM\Id()
     * @ORM\GeneratedValue()
     * @ORM\Column(type="integer")
     */
    private $id;


    /**
     * One product has many features. This is the inverse side.
     * @ORM\OneToMany(targetEntity="IncrusteElement", mappedBy="incruste", cascade={"remove","persist"})
     */
    private $incrusteElements;


    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private $type;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private $name;

    public function __construct()
    {
        $this->incrusteElements = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(?string $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): self
    {
        $this->name = $name;

        return $this;
    }

    /**
     * @return Collection|IncrusteElement[]
     */
    public function getIncrusteElements(): Collection
    {
        return $this->incrusteElements;
    }

    public function addIncrusteElement(IncrusteElement $incrusteElement): self
    {
        if (!$this->incrusteElements->contains($incrusteElement)) {
            $this->incrusteElements[] = $incrusteElement;
            $incrusteElement->setIncruste($this);
        }

        return $this;
    }

    public function removeIncrusteElement(IncrusteElement $incrusteElement): self
    {
        if ($this->incrusteElements->contains($incrusteElement)) {
            $this->incrusteElements->removeElement($incrusteElement);
            // set the owning side to null (unless already changed)
            if ($incrusteElement->getIncruste() === $this) {
                $incrusteElement->setIncruste(null);
            }
        }

        return $this;
    }


}
