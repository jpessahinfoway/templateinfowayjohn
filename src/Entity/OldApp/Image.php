<?php

namespace App\Entity\OldApp;

use Doctrine\ORM\Mapping as ORM;

/**
* @ORM\Entity(repositoryClass="App\Repository\OldApp\ImageRepository")
*/
class Image
{

/**
 * @ORM\Id()
 * @ORM\GeneratedValue()
 * @ORM\Column(type="integer")
 */
private $id;

/**
* @ORM\Column(type="string", length=3)
*/
private $extension;

/**
* @ORM\Column(type="string", length=50)
*/
private $ratio;

/**
* One Product has One Shipment.
* @ORM\OneToOne(targetEntity="Media")
* @ORM\JoinColumn(name="media", referencedColumnName="id")
*/
private $media;

/**
* @ORM\Column(type="smallint")
*/
private $height;

/**
* @ORM\Column(type="smallint")
*/
private $width;

public function getId(): ?int
{
return $this->id;
}

public function getMedia(): ?Media
{
return $this->media;
}

public function setMedia(Media $media): self
{
$this->media = $media;

return $this;
}

public function getExtension(): ?string
{
return $this->extension;
}

public function setExtension(string $extension): self
{
$this->extension = $extension;

return $this;
}

public function getRatio(): ?string
{
return $this->ratio;
}

public function setRatio(string $ratio): self
{
$this->ratio = $ratio;

return $this;
}

public function getHeight(): ?int
{
return $this->height;
}

public function setHeight(int $height): self
{
$this->height = $height;

return $this;
}

public function getWidth(): ?int
{
return $this->width;
}

public function setWidth(int $width): self
{
$this->width = $width;

return $this;
}

}