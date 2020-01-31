<?php

namespace App\Repository\OldApp;

use App\Entity\OldApp\Image;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Common\Persistence\ManagerRegistry;

/**
 * @method Image|null find($id, $lockMode = null, $lockVersion = null)
 * @method Image|null findOneBy(array $criteria, array $orderBy = null)
 * @method Image[]    findAll()
 * @method Image[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class ImageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Image::class);
    }

    // /**
    //  * @return Image[] Returns an array of Image objects
    //  */

    public function findAllTemplateImages()
    {
        return $this->createQueryBuilder('i')
            ->leftJoin('App\Entity\OldApp\TemplateContent', 'cont', 'WITH', 'i.media = cont.id')
            ->leftJoin('App\Entity\OldApp\Media', 'media', 'WITH', 'i.media = media.id')
            ->select('cont.id','i.extension','cont.name','media.type')
            ->andWhere('cont.id IS NOT NULL')
            ->andWhere('media.type = :type')
            ->setParameter("type","elmt")
            ->getQuery()
            ->getArrayResult()
            ;
    }
    public function findImagesByMedias($mediaIds)
    {
        return $this->createQueryBuilder('i')
            ->leftJoin('App\Entity\OldApp\TemplateContent', 'cont', 'WITH', 'i.media = cont.id')
            ->leftJoin('App\Entity\OldApp\Media', 'media', 'WITH', 'i.media = media.id')
            ->select('cont.id','i.extension','cont.name','media.type')
            ->andWhere('i.media IN (:medias)')
            ->setParameter('medias', $mediaIds)
            ->getQuery()
            ->getResult()
        ;
    }


    /*
    public function findOneBySomeField($value): ?Image
    {
        return $this->createQueryBuilder('i')
            ->andWhere('i.exampleField = :val')
            ->setParameter('val', $value)
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }
    */
}
