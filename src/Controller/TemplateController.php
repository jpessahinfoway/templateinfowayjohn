<?php
namespace App\Controller;


use Doctrine\Persistence\ObjectManager;
use App\Entity\Main\{Incruste as Main_Incruste, Template as Main_Template, User as Main_User, Zone as Main_Zone,
                     IncrusteElement as Main_IncrusteElement, CSSProperty as Main_CssProperty, IncrusteStyle as Main_IncrusteStyle };

use App\Entity\OldApp\{
    Incruste as OldApp_Incruste,
    TemplateContent as OldApp_TemplateContent,
    Template as OldApp_Template,
    Zone as OldApp_Zone,
    IncrusteElement as OldApp_IncrusteElement,
    CSSProperty as OldApp_CssProperty,
    IncrusteStyle as OldApp_IncrusteStyle,
    Media as OldApp_Media, Image as OldApp_Image, Video as OldApp_Video };

use Doctrine\ORM\EntityManager;

use \InvalidArgumentException;

use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;

use App\Repository\Main\{ CustomerRepository, EnseignesRepository, UserRepository,
                          TemplateRepository as Main_TemplateRepository, IncrusteRepository as Main_IncrusteRepository };

use Symfony\Component\CssSelector\Exception\InternalErrorException;
use Symfony\Component\Yaml\Yaml;
use App\Repository\OldApp\{ TemplateRepository as OldApp_TemplateRepository, IncrusteRepository as OldApp_IncrusteRepository };

use App\Service\{CSSParser as CssParser,
    DatabaseAccessRegister,
    ExternalFileManager,
    IncrusteCSSHandler,
    TemplateContentsHandler,
    IncrusteHandler,
    SessionManager};
use Symfony\Component\HttpKernel\Exception\{ NotFoundHttpException, AccessDeniedHttpException };

use Symfony\Component\HttpFoundation\{ Request, Response, JsonResponse };
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Serializer\SerializerInterface;

use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Encoder\XmlEncoder;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use\Exception;



class TemplateController extends AbstractController
{


    /**
     * @var SessionManager
     */
    private $sessionManager;
    /**
     * @var ExternalFileManager
     */
    private $externalFileManager;

    public function __construct(SessionManager $sessionManager, ExternalFileManager $externalFileManager)
    {

        $this->sessionManager = $sessionManager;
        $this->externalFileManager = $externalFileManager;

        if(!$this->userSessionIsInitialized())
            $this->initializeUserSession();

//        ob_end_clean();
//
    }


    /**
     * Renvoie la page d'accueil du module template
     * @Route("/test", name="template::testimporttemplate" )
     * @throws \Exception
     */
    public function testImportTemplate()
    {
        $templates = $this->getDoctrine()
                          ->getRepository(Template::class)
                          ->getAllTemplateForCustomer(null);
        return new Response('ok');
    }


    /**
     * Renvoie la page d'accueil du module template
     * @Route("/", name="template::dfdsf")
     * @Route("/home/stage/{stage}/{token}", name="template::home", methods="GET",
     *     requirements = {"stage" : "1|2|3", "token": "[a-z0-9]{64}"},
     *     defaults={"token": null})
     *
     *
     * @param Request $request
     * @param string $stage
     * @param CustomerRepository $customerRepository
     * @param EnseignesRepository $enseignesRepository
     * @param UserRepository $userRepository
     * @param ExternalFileManager $externalFileManager
     * @return Response
     * @throws \Exception
     */
    public function home(Request $request, int $stage, CustomerRepository $customerRepository,
                            EnseignesRepository $enseignesRepository, UserRepository $userRepository, ExternalFileManager $externalFileManager): Response
    {

        if(!$this->userSessionIsInitialized())
            throw new AccessDeniedHttpException("Access denied ! Cause : session is not started !");

        elseif ($request->get("token") !== null)
        {

            $user = $userRepository->findOneByToken($request->get('token'));
            if(!$user)
                throw new AccessDeniedHttpException("Access denied ! Cause : Token is not valid ! ");

            $this->updateUserSession($user, $request);
            //$this->createSymLinkToUserMedia();

            $user->setToken(null);

            $this->getDoctrine()->getManager()->flush();
            //$this->getDoctrine()->getManager()->clear();

            unset($user);

            //dd($this->sessionManager->get('user'));

            return $this->redirectToRoute('template::home', [
                'stage' => $stage,
                'token' => null
            ]);
        }


        elseif( is_null($request->get("token")) AND is_null($this->sessionManager->get('user')['QUICKNET']['token']) )
            throw new AccessDeniedHttpException("Access denied ! Cause : Token not found in URL and session ! ");

        if(!in_array($stage, $this->sessionManager->get('user')['permissions']['access']))
            throw new AccessDeniedHttpException(sprintf("Access denied ! Cause : You're not allowed to access this page in this stage('%d') !", $stage));


        switch($stage){
            case 1 : $templatesToLoad = [
                'create' => [] ,
                'load'   => [1]
            ] ;break;
            case 2 : $templatesToLoad = [
                'create' => [1] ,
                'load'   => [2]
            ] ;break;
            case 3 : $templatesToLoad = [
                'create' => [2] ,
                'load'   => [3]
            ] ;break;
        }


        $allLevelsToLoad = array_unique (array_filter(array_merge($templatesToLoad['create'] , $templatesToLoad['load']), function($levelToLoad){
            return is_int($levelToLoad) && ($levelToLoad>=1 && $levelToLoad<=3) ;
        }) );

        $levelsToLoadByEnseigne = ['admin' => [],'enseigne' => []] ;

        foreach($allLevelsToLoad as $levelToLoad){
            if( $levelToLoad < 2 )$levelsToLoadByEnseigne['admin'][] = $levelToLoad;
            else $levelsToLoadByEnseigne['enseigne'][] = $levelToLoad ;
        }
        $templates = [];

        if( count( $levelsToLoadByEnseigne[ 'admin' ] ) > 0 ){
            $templates=array_merge($templates,$this->getDoctrine()
                                                   ->getManager('default')
                                                   ->getRepository(Main_Template::class )
                                                   ->findBy( ['level' => $levelsToLoadByEnseigne[ 'admin' ] ] ));

        }
        if(count( $levelsToLoadByEnseigne[ 'enseigne' ] ) > 0) {

            $templates = array_merge($templates,$this->getDoctrine()
                                                     ->getManager($this->sessionManager->get('user')['QUICKNET']['base'])
                                                     ->getRepository(OldApp_Template::class)
                                                     ->findBy(['level' => $levelsToLoadByEnseigne['enseigne']]));
        }


        $loadableTemplates = [];
        $creatableTemplates = [];
        foreach($templates as $template){
            $currentLevel = $template->getLevel();
            $currentOrientation = $template->getOrientation() ;
            if(in_array($currentLevel,$templatesToLoad['create']))$creatableTemplates[$currentOrientation][]=$template;
            if(in_array($currentLevel,$templatesToLoad['load']))$loadableTemplates[$currentOrientation][]=$template;
        }

        dump( $loadableTemplates, $creatableTemplates, $this->sessionManager->get('user'));

        return $this->render('template/accueil/accueil.html.twig', [
            'user'            => $this->sessionManager->get('user'),
            'controller_name'  => 'TemplateController',
            'loadableTemplates'   => $loadableTemplates,
            'creatableTemplates' => $creatableTemplates,
            'stage'              => $stage
        ]);
    }


    /**
     * Stage 1 (creation, modification, import)
     *
     * @Route(path="/template/stage/{stage}/{action}", name="template::stagesActions", requirements = {
     *     "stage" : "1|2|3",
     *     "action": "create|load"
     *     }, methods="POST")
     *
     *
     * @param string $stage
     * @param string $action
     * @param Request $request
     * @return Response
     * @throws \Exception
     */
    public function stagesActions(string $stage, string $action, Request $request): Response
    {

        if(!$this->userSessionIsInitialized())
            throw new AccessDeniedHttpException("Access denied ! Cause : session is not started !");

        // check if user has 0 permissions
        /*if($this->getDoctrine()->getRepository(User::class)->checkIfUserHasZeroPermission($this->getUser()) === true)
            throw new \Exception("Aucune permissions");


        if(!$this->getDoctrine()->getRepository(User::class)->checkIfUserCanAccedeStage($this->getUser(), 'Stage '.$stage))
            throw new \Exception("Vous n'avez pas le droit d'accéder à cette page !");*/



        if($action !== 'create' and $stage > 1 and !$this->checkIfDataExistInRequest($request, "template"))
            throw new \Exception("Missing 'template' parameter !");

        elseif ($action !== 'create' and $stage > 1 and !intval($request->request->get('template')))
            throw new \Exception("Bad 'template' parameter !");

        elseif(!$this->checkIfDataExistInRequest($request, "orientation"))
            throw new \Exception("Missing 'orientation' parameter !");

        elseif (strtoupper($request->request->get('orientation')) !== "H" AND strtoupper($request->request->get('orientation')) !== "V")
            throw new \Exception("Bad 'orientation' parameter !");

        elseif (!intval($stage))
            throw new \Exception("Impossible to get intval of 'stage' parameter !");

        $stage = intval($stage);

        //dd($action);

        if($action === 'create' and $stage < 2)
            return $this->createTemplate($request, $stage);

        else
            return $this->importTemplate($request, $stage, $action);

        //return $stage === 1 && $action ==='create' ?  $this->createTemplate($request, $stage, $sessionManager) : $this->importTemplate($request, $stage, $action, $sessionManager);

    }


    /**
     * @Route("/get/template/model", name="template::getTemplateModel", methods="POST")
     * @return Response
     */
    public function getTemplateModel(SessionManager $sessionManager): Response
    {

        if(!$sessionManager->get('user'))
            throw new AccessDeniedHttpException("Access denied ! Cause : session is not started !");

        $templates = $this->getDoctrine()->getRepository(Template::class)->findAll();

        $data = [];

        foreach ($templates as $template)
        {

            $zones = $this->getDoctrine()->getRepository(Zone::class)->findBy(['template' => $template]);

            foreach ($zones as $zone)
            {

                $data[$template->getName()]['template']['customer'] = $template->getCustomer();
                $data[$template->getName()]['template']['orientation'] = $template->getOrientation();
                $data[$template->getName()]['template']['background'] = $template->getBackground();
                $data[$template->getName()]['template']['size']['height'] = $template->getHeight();
                $data[$template->getName()]['template']['size']['width'] = $template->getWidth();

                $data[$template->getName()][$zone->getName()]['size']['height'] = $zone->getHeight();
                $data[$template->getName()][$zone->getName()]['size']['width'] = $zone->getWidth();

                $data[$template->getName()][$zone->getName()]['position']['top'] = $zone->getPositionTop();
                $data[$template->getName()][$zone->getName()]['position']['left'] = $zone->getPositionLeft();
                $data[$template->getName()][$zone->getName()]['position']['zIndex'] = $zone->getZIndex();

                $data[$template->getName()][$zone->getName()]['style']['class'] = strtolower($zone->getType()) . "-zone";

                //$data[$template->getName()][$zone->getName()]['contentId'] = $zone->getContentId();
                if($zone->getContent() !== NULL)
                {

                    foreach ($zone->getContent() as $contentId)
                    {

                        $incrustElement = $this->getDoctrine()->getManager('templateressources')->getRepository(IncrusteElement::class)->findOneBy(['id' => $contentId]);
                        $data[$template->getName()][$zone->getName()]['contents'][] = [
                            'id' => $contentId,
                            'incruste' => [
                                'id' => $incrustElement->getIncruste()->getId(),
                                'type' => $incrustElement->getIncruste()->getType(),
                                'name' => $incrustElement->getIncruste()->getName(),
                            ],
                            'parent' => [
                                'id' => ($incrustElement->getParent() !== NULL) ? $incrustElement->getParent()->getId() : null,
                            ],
                            'type' => $incrustElement->getType(),
                            'content' => $incrustElement->getContent(),
                            'class' => $incrustElement->getClass(),
                            'incrusteOrder' => $incrustElement->getIncrustOrder(),
                            'level' => $incrustElement->getLevel()
                        ];

                    }

                }


            }
        }

        //dump($data);die();

        return new JsonResponse($data);
    }


    /**
     * @Route(path="/get/all/stage/{stage}/template/name", name="template::getAllTemplateName", methods="POST",
     *     requirements={"stage": "1|2|3"})
     *
     * @param string $stage
     * @return Response
     */
    public function getAllTemplateName(string $stage): Response
    {

        if(!$this->userSessionIsInitialized())
            throw new AccessDeniedHttpException("Access denied ! Cause : session is not started !");

        if(intval($stage) === 1)
            $em = $this->getDoctrine()->getManager();

        else
            $em = $this->getDoctrine()->getManager($this->sessionManager->get('user')['QUICKNET']['base']);

        $data = [];

        foreach ($em->getRepository((intval($stage) === 1) ? Main_Template::class : OldApp_Template::class)->findAll() as $template)
        {
            $data[] = $template->getName();
        }

        //$em->clear();

        return new JsonResponse($data);

    }


    /**
     * @Route(path="/get/{database}/template/{id}/data", name="template::getSpecificTemplateData",
     *     methods="POST", requirements={"database": "[a-z]+", "id": "\d+"})
     *
     * @param Request $request
     * @param string $database
     * @param int $id
     * @return Response
     */
    public function getSpecificTemplateData(Request $request, string $database, int $id): Response
    {

        if(!$this->userSessionIsInitialized())
            throw new AccessDeniedHttpException("Access denied ! Cause : session is not started !");


        $orm = ($database === 'default') ? 'default': $this->sessionManager->get('user')['QUICKNET']['base'];


        $manager = $this->getDoctrine()->getManager($orm);

        $templateToJson = [];

        $template = $manager->getRepository(( $orm === 'default' ) ? Main_Template::class : OldApp_Template::class)->findOneById($id);
        
        if(!$template)
            return new Response("Not found !");

        $templateToJson['id'] = $template->getId();
        $templateToJson['name'] = $template->getName();
        $templateToJson['orientation'] = $template->getOrientation();
        $templateToJson['create_at'] = $template->getCreateAt();
        $templateToJson['modification_date'] = $template->getLastModificationDate();
        $templateToJson['height'] = $template->getHeight();
        $templateToJson['width'] = $template->getWidth();
        $templateToJson['level'] = $template->getLevel();
        $templateToJson['background'] = $template->getBackground();

        $templateToJson['zones'] = [];

        $zones = $template->getZones()->getValues();



        foreach ($zones as $index => $zone)
        {

            $templateToJson['zones'][$index]['id'] = (int) $zone->getId();
            $templateToJson['zones'][$index]['name'] = str_replace($template->getName() . '_', null, $zone->getName());
            $templateToJson['zones'][$index]['width'] = (int) $zone->getWidth();
            $templateToJson['zones'][$index]['height'] = (int) $zone->getHeight();

            $templateToJson['zones'][$index]['isBlocked'] = $zone->getIsBlocked();
            $templateToJson['zones'][$index]['background'] = $zone->getBackground();

            $templateToJson['zones'][$index]['zIndex'] = (int) $zone->getZIndex();

            $templateToJson['zones'][$index]['position'] = (int) $zone->getPosition();
            $templateToJson['zones'][$index]['positionTop'] = (int) $zone->getPositionTop();
            $templateToJson['zones'][$index]['positionLeft'] = (int) $zone->getPositionLeft();

            $templateToJson['zones'][$index]['type'] = $zone->getType();

            $templateToJson['zones'][$index]['parent'] = ($zone->getParent() !== null) ? $zone->getParent()->getId() : null ;

            $templateToJson['zones'][$index]['incrusteElements'] = [];
            foreach ($zone->getContents()->getValues() as $i => $incrustElement)
            {

                //dd($zone->getContent());
                $templateToJson['zones'][$index]['incrusteElements'][$i] = [
                    'id' => (int) $incrustElement->getId(),
                    'incruste' => [
                        'id' => (int) $incrustElement->getIncruste()->getId(),
                        'type' => $incrustElement->getIncruste()->getType(),
                        'name' => $incrustElement->getIncruste()->getName(),
                    ],
                    'parent' => [
                        'id' => (int) ($incrustElement->getParent() !== NULL) ? $incrustElement->getParent()->getId() : null,
                    ],
                    'type' => $incrustElement->getType(),
                    'content' => $incrustElement->getContent(),
                    'class' => $incrustElement->getClass(),
                    'incrusteOrder' => $incrustElement->getIncrustOrder(),
                    'level' => (int) $incrustElement->getLevel(),
                    'style' => []
                ];

                foreach ($incrustElement->getIncrusteStyles()->getValues() as $incrusteStyle) {

                    $templateToJson['zones'][$index]['incrusteElements'][$i]['style'][] = [
                        'id' => (int) $incrusteStyle->getId(),
                        'property' => $incrusteStyle->getProperty()->getName(),
                        'value' => $incrusteStyle->getValue()
                    ];

                }

            }

        }


        //dd($data['template']['zones'][3]);
        //$manager->clear();

        return new JsonResponse($templateToJson);

    }

    /**
     * Renvoie la page d'accueil du module template
     * @Route("template/api/{database}/", name="template::exportTemplate" )
     * @throws \Exception
     */
    public function displayAllTemplate($database, Request $request){

        $response = new JsonResponse();
        $response->headers->set('Content-Type', 'application/json');
        $response->setEncodingOptions(JSON_PRETTY_PRINT);


        $orientation = $request->query->get('orientation') ;
        if( $orientation !== null && $orientation !== 'H' && $orientation !== 'V'){
            return $response->setData('error : invalid parameter for orientation');
        }

        $allManagers = array_keys($this->getDoctrine()->getManagers());

        if(!in_array($database,$allManagers)){
            return $response->setData('{error : Invalid database }') ;
        };

        $circularReferenceHandlingContext = [
            AbstractNormalizer::CIRCULAR_REFERENCE_HANDLER => function ($object, $format, $context) {
                return $object->getId();
            },
        ];
        $encoder =  new JsonEncoder();
        $normalizer = new ObjectNormalizer(null, null, null, null, null, null, $circularReferenceHandlingContext);
        $serializer = new Serializer( [ $normalizer ] , [ $encoder ] );


        $em = $this->getDoctrine()->getManager($database);
        $templateRepo = $em->getRepository( ($database === 'default') ? Main_Template::class : OldApp_Template::class);


        $findBy = ['level' => 1];
        if($orientation!== null )$findBy['orientation'] = $orientation;

        $templates =  $templateRepo->findBy($findBy);
        $templatesToJson = $serializer->serialize($templates, 'json');

        return $response->setData(json_decode($templatesToJson));
    }

    /**
     * Renvoie la page d'accueil du module template
     * @Route("template/api/{database}/{id}", name="template::exportTemplateById", requirements = {"id"="\d+" }))
     * @throws \Exception
     */
    public function exportTemplateById(string $database, string $id){


        $response = new JsonResponse();
        $response->headers->set('Content-Type', 'application/json');
        $response->setEncodingOptions(JSON_PRETTY_PRINT);

        $database = ($database !== 'default') ? $this->sessionManager->get('user')['QUICKNET']['base'] : $database;

        $allManagers = array_keys($this->getDoctrine()->getManagers());

        if(!in_array($database,$allManagers)){
            return $response->setData('{error : Invalid database }') ;
        };

        $circularReferenceHandlingContext = [
            AbstractNormalizer::CIRCULAR_REFERENCE_HANDLER => function ($object, $format, $context) {
                return $object->getId();
            },
        ];

        $encoder =  new JsonEncoder();
        $normalizer = new ObjectNormalizer(null, null, null, null,
                                           null, null, $circularReferenceHandlingContext);

        $serializer = new Serializer( [ $normalizer ] , [ $encoder ] );

        $em = $this->getDoctrine()->getManager($database);
        $templateRepo = $em->getRepository(($database === 'default') ? Main_Template::class : OldApp_Template::class);
        //dd($database);

        $template =  $templateRepo->findOneBy(['id'=>$id]);

        //dd($template);

        $templatesToJson = $serializer->serialize($template, 'json');
        //dd(json_decode($templatesToJson));
        //$em->clear();

        return $response->setData(json_decode($templatesToJson));
    }


    /**
     * @Route("/template/stage/{stage}/register", name="template::registerTemplate",
     *     methods="POST", requirements={"stage": "1|2|3"})
     *
     * @param Request $request
     * @param int $stage
     * @return Response
     * @throws Exception
     */
    public function registerTemplate(Request $request, int $stage)
    {

        if(!$this->userSessionIsInitialized())
            throw new AccessDeniedHttpException("Access denied ! Cause : session is not started !");

        if(!in_array($stage, $this->sessionManager->get('user')['permissions']['access']))
            throw new AccessDeniedHttpException(sprintf("Access denied ! Cause : You're not allowed to register in this stage ('%d') !", $stage));

        elseif(!in_array(1, $this->sessionManager->get('user')['permissions']['access'])
            and !in_array(2, $this->sessionManager->get('user')['permissions']['access'])
            and !in_array(3, $this->sessionManager->get('user')['permissions']['access']))
            throw new AccessDeniedHttpException("Access denied ! Cause : user is not allowed to register in any stage !");

        $zonesToRegister = json_decode($request->request->get('zones'));
        $templateToRegister = json_decode($request->request->get('template'));


        $zonesContents = $this->zoneContentFormatting($zonesToRegister);

        //dd(count($zonesToRegister));

        //dd($zonesToRegister, $templateToRegister, $zonesContents);

        $orm = ($stage === 1) ? 'default' : $this->sessionManager->get('user')['QUICKNET']['base'];

        $em = $this->getDoctrine()->getManager($orm);

        $templateObject = ($orm === "default") ? new Main_Template() : new OldApp_Template();

        $templateExist = $em->getRepository( get_class($templateObject))->findOneByName($templateToRegister->name);
        //dd($templateExist);
        if($templateExist)
            return $this->updateTemplate($templateExist, $zonesToRegister, $zonesContents, $em, $orm, $request);


        $template = ($orm === "default") ? new Main_Template() : new OldApp_Template();
        $template->setName($templateToRegister->name)
                 ->setBackground(1)
                 ->setWidth($templateToRegister->attr->size->width)
                 ->setHeight($templateToRegister->attr->size->height)
                 ->setOrientation($templateToRegister->orientation)
                 ->setLastModificationDate(new \DateTime())
                 ->setCreateAt(new \DateTime());

        //$this->getUser()->getCustomer()->addTemplate($template);

        if($stage === 1)
            $template->setLevel(1);

        elseif($stage === 2)
            $template->setLevel(2);

        else
            $template->setLevel(3);

        // insert zone
        foreach ($zonesToRegister as $index => $item)
        {

            $zone = ($orm === "default") ? new Main_Zone() : new OldApp_Zone();
            $zone->setName($template->getName() . "_" . $item->name)
                 ->setType($item->type)
                 ->setTemplate($template)
                 ->setHeight($item->size->height)
                 ->setWidth($item->size->width)
                 ->setPositionTop($item->position->top)
                 ->setPositionLeft($item->position->left)
                 ->setIsBlocked( $item->isBlocked ?? false )
                 ->setBackground(1)
                 ->setPosition("absolute")
                 ->setZIndex($item->zIndex);

            $template->addZones($zone);
            $zone->setTemplate($template);

            if(!is_null($item->zoneParent))
            {

                $zoneParent = $em->getRepository(get_class($zone))->findOneBy(
                    ['name' => $template->getName() . "_" . $item->zoneParent->name]);

                $zone->setParent($zoneParent);
                $zoneParent->addChildren($zone);

            }

            if(array_key_exists($item->name, $zonesContents))
                $this->registerZoneContent($zonesContents, $item->name, $zone, $em, $orm,$request);

            if(!$em->contains($template))
                $em->persist($template);

            $em->persist($zone);
            $em->flush();

        }

        //$em->clear();

        return new JsonResponse([
            'id' => $template->getId(),
            'name' => $template->getName(),
            'orientation' => $template->getOrientation(),
            'modification_date' => $template->getLastModificationDate()
        ]);

    }


    /**
     * @Route("/template/stage/{stage}/cssloader/type/{type}", name="template::cssLoader",methods="GET",
     *     requirements={"stage": "1|2|3"})
     *
     * @param string $type
     * @param string $stage
     * @return Response
     */
    public function loadCss(string $type, int $stage)
    {

        if(!$this->userSessionIsInitialized())
            throw new AccessDeniedHttpException("Access denied ! Cause : session is not started !");

        if($stage === 1)
            $em = $this->getDoctrine()->getManager();

        else
            $em = $this->getDoctrine()->getManager($this->sessionManager->get('user')['QUICKNET']['base']);

        $allIncrustes = $em->getRepository(($stage === 1) ? Main_Incruste::class : OldApp_Incruste::class)->findByType($type);

        //dd($allIncrustes);

        $incrusteCSSHandler = new IncrusteCSSHandler($allIncrustes);
        $response = new Response($incrusteCSSHandler->getGeneratedCSS());
        $response->headers->set('Content-Type', 'text/css');

        //$em->clear();

        return $response;
    }

    /**
     * @Route("/testpage", name="template::testPage",methods="GET")
     */
    public function testPage(SerializerInterface $serialize){//, DatabaseAccessRegister$databaseAccessRegister)
        //$databaseAccessRegister->load();

        return new Response("hello world");

    }


    /**
     * @Route("/template/stage/{stage}/model/register", name="template::registerModel",
     *     methods="GET", requirements={"stage" : "1|2|3"})
     *
     * @param Request $request
     * @param int $stage
     * @return Response
     */
    public function registerModel(Request $request, int $stage){

        if(!$this->userSessionIsInitialized())
            throw new AccessDeniedHttpException("Access denied ! Cause : session is not started !");

        elseif (!$this->sessionManager->get('user')['QUICKNET']['base'])
            throw new AccessDeniedHttpException("Access denied ! Cause : invalid session data !");

        dump(json_decode($request->get('incrusteStyle')));

        /*function registerIncrustElement($stage, $incrustElement, $incrustParent, $em, $parentIncrustElement=false){

            $type =  $incrustElement['_type'] ;
            $className = $type . $incrustParent->getId();


            $newIncrusteElement = ($stage === 1) ? new Main_IncrusteElement() : new OldApp_IncrusteElement();

            $incrusteContentStyle = ($stage === 1) ? new Main_IncrusteStyle() : new OldApp_IncrusteStyle();

            $incrustParent->addIncrusteElement($newIncrusteElement);

            $newIncrusteElement->setType($type);
            $newIncrusteElement->setContent($incrustElement['_content']);
            $newIncrusteElement->setClass($className);
            $newIncrusteElement->setLevel(1);

            $newIncrusteElement->setIncrustOrder($incrustElement['_incrustOrder']);
            if($parentIncrustElement)$parentIncrustElement->addChild($newIncrusteElement);

            foreach($incrustElement['_style'] as $incrusteStyle){


                if(isset($incrusteStyle['name']))
                {
                    //dump( $em->getRepository(CSSProperty::class));
                    $property = $em->getRepository( ($stage === 1) ? Main_CssProperty::class : OldApp_CssProperty::class )->findOneBy([
                        'name' => $incrusteStyle['name']
                    ]);


                    if($property !== NULL && $incrusteStyle['propertyWritting'] !== NULL){

                        $incrusteContentStyle->setProperty($property);
                        $incrusteContentStyle->setValue($incrusteStyle['propertyWritting']);
                        $newIncrusteElement->addIncrusteStyle($incrusteContentStyle);
                        //dump($incrusteContentStyle);

                        $em->persist($incrusteContentStyle);

                    }
                }

            }

            $incrusteStyleClass = ($stage === 1) ? Main_IncrusteStyle::class : OldApp_IncrusteStyle::class;

            if(isset($incrusteContentStyle) && $incrusteContentStyle instanceof $incrusteStyleClass){
                $em->persist($newIncrusteElement);

                foreach($incrustElement['_subContents'] as $subIncrustContent){
                    registerIncrustElement((int) $stage,$subIncrustContent,$incrustParent,$em,$newIncrusteElement);
                }
            }


        }

        function buildPropertiesArray($propertiesList,$object){
            $propertiesValuesArray = [];
            foreach($propertiesList as $property){
                $getter = 'get'.ucfirst($property);
                if(method_exists($object,$getter))$propertiesValuesArray[$property] = $object->$getter() ;
            }
            return $propertiesValuesArray;
        }*/

        $orm = ($stage === 1) ? 'default' : $this->sessionManager->get('user')['QUICKNET']['base'];

        $newIncruste = ($stage === 1) ? new Main_Incruste() : new OldApp_Incruste();

        $entityManager = $this->getDoctrine()->getManager($orm);

        $incrusteStyle = json_decode($request->get('incrusteStyle'),true);
        //dump($incrusteStyle);

        $incrusteResponse = [];

        $incrusteExist = $entityManager->getRepository(($stage === 1) ? Main_Incruste::class : OldApp_Incruste::class)->findOneBy([
            'name' => $incrusteStyle['_name'], 'type' => $incrusteStyle['_type']
        ]);

        if(!$incrusteExist)
        {

            $newIncruste->setName($incrusteStyle['_name'])
                        ->setType($incrusteStyle['_type']);

            $incrusteResponse['name'] = $newIncruste->getName();
            $incrusteResponse['type'] = $newIncruste->getType();
            $entityManager->persist($newIncruste);
            $entityManager->flush();

        }
        else
        {
            $newIncruste = $incrusteExist;
        }



        foreach($incrusteStyle['_incrusteElements'] as $content)
        {
            $this->registerIncrustElement($stage, $content,$newIncruste,$entityManager);
        }


        if(!$incrusteExist)
            $entityManager->persist($newIncruste);


        $entityManager->flush();
        //$entityManager->clear();


        if($newIncruste->getId() !== null)
        {
            $incrusteCreated = $this->buildPropertiesArray(['id', 'name', 'type'], $newIncruste);
            $incrusteCreated['incrusteElements'] = [];

            foreach ($newIncruste->getIncrusteElements() as $incrustElement)
            {
                if ($incrustElement->getId() !== null)
                {
                    $incrusteCreated['incrusteElements'][] = $this->buildPropertiesArray(['id', 'type', 'content', 'class','incrustOrder'], $incrustElement);
                }
            }
        }

        return new JsonResponse($incrusteCreated ?? []);
        //return new Response(json_encode($response));
    }


    private function buildPropertiesArray($propertiesList,$object)
    {

        $propertiesValuesArray = [];
        foreach($propertiesList as $property)
        {
            $getter = 'get'.ucfirst($property);
            if(method_exists($object,$getter))
                $propertiesValuesArray[$property] = $object->$getter() ;
        }
        return $propertiesValuesArray;
    }


    private function registerIncrustElement($stage, $incrustElement, &$incrustParent, $em, $parentIncrustElement=false)
    {

        $search = [
            'type' => $incrustElement['_type'],
            'class' => $incrustElement['_type'] . $incrustParent->getId(),
            'content' => $incrustElement['_content'],
            'level' => 1,
            'incrustOrder' => $incrustElement['_incrustOrder']
        ];

        $incrustElementExist = $em->getRepository( ($stage === 1) ? Main_IncrusteElement::class : OldApp_IncrusteElement::class )
                                  ->findOneBy($search);

        // don't duplicate media incruste lvl1 if exist
        if($incrustElementExist AND ($incrustElement['_type'] === 'image' OR $incrustElement['_type'] === 'video'))
        {
            return;
        }

        $type =  $incrustElement['_type'];
        $className = $type . $incrustParent->getId();


        $newIncrusteElement = ($stage === 1) ? new Main_IncrusteElement() : new OldApp_IncrusteElement();

        $incrustParent->addIncrusteElement($newIncrusteElement);

        $newIncrusteElement->setType($type);
        $newIncrusteElement->setContent($incrustElement['_content']);
        $newIncrusteElement->setClass($className);
        $newIncrusteElement->setLevel(1);

        $newIncrusteElement->setIncrustOrder($incrustElement['_incrustOrder']);
        if($parentIncrustElement)$parentIncrustElement->addChild($newIncrusteElement);

        foreach($incrustElement['_style'] as $incrusteStyle)
        {


            if(isset($incrusteStyle['name']))
            {
                //dump( $em->getRepository(CSSProperty::class));
                $property = $em->getRepository( ($stage === 1) ? Main_CssProperty::class : OldApp_CssProperty::class )->findOneBy([
                    'name' => $incrusteStyle['name']
                ]);

                //dump($property);

                if($property !== NULL && $incrusteStyle['propertyWritting'] !== '')
                {
                    $incrusteContentStyle = ($stage === 1) ? new Main_IncrusteStyle() : new OldApp_IncrusteStyle();
                    $incrusteContentStyle->setProperty($property);
                    $incrusteContentStyle->setValue($incrusteStyle['propertyWritting']);
                    $newIncrusteElement->addIncrusteStyle($incrusteContentStyle);

                    $property->addIncrusteStyle($incrusteContentStyle);

                    //dump($incrusteContentStyle);

                    $em->persist($incrusteContentStyle);
                    $em->flush();

                    //dump($incrusteContentStyle->getId());

                }
            }

        }

        //die();

        $incrusteStyleClass = ($stage === 1) ? Main_IncrusteStyle::class : OldApp_IncrusteStyle::class;

        if(isset($incrusteContentStyle) && $incrusteContentStyle instanceof $incrusteStyleClass)
        {
            $em->persist($newIncrusteElement);

            foreach($incrustElement['_subContents'] as $subIncrustContent)
            {
                $this->registerIncrustElement((int) $stage,$subIncrustContent,$incrustParent,$em,$newIncrusteElement);
            }
        }

    }



    /**
     * @Route("/template/stage2/getstyles", name="template::stage2getStyles",methods="POST")
     */
    public function stage2Open(SerializerInterface $serialize, ParameterBagInterface $parameterBag, CSSParser $CSSParser)
    {

        if(!$this->userSessionIsInitialized())
            throw new AccessDeniedHttpException("Access denied ! Cause : session is not started !");

        $CSSContent = $CSSParser->parseCSS($parameterBag->get('kernel.project_dir').'/public/css/template/tools/zone_container_editor/text_styles/text-styles.css');

        $serializedCSS = $serialize->serialize($CSSContent,'json');

        return new Response($serializedCSS);

    }


    /**
     * @param Request $request
     * @param int $stageNumber
     * @return Response
     * @throws \Exception
     */
    private function createTemplate(Request $request, int $stageNumber = 1): Response
    {

        $incrusteRepository = $this->getDoctrine()->getManager()->getRepository( Main_Incruste::class );

        $incrusteHandler = new IncrusteHandler($incrusteRepository);
        $incrustData = $incrusteHandler->getIncrusteData();

        dump($this->sessionManager->get('user'));

        //$this->getDoctrine()->getManager()->clear();

        return $this->render('template/stages/index.html.twig', [
            'controller_name' =>    'TemplateController',
            //'template'        =>    null,
            //'id'              =>    null,
            'orientation'     =>    $request->request->get('orientation'),
            'stageNumber'     =>    $stageNumber,
            'action'          =>    'create',
            'classNames'      =>    $incrustData['classNames'],
            'ressources'      =>    $incrustData['ressources']
            //'ressources'      =>    ['background' => []]
        ]);

    }


    /**
     * @param Request $request
     * @param int $stageNumber
     * @return Response
     * @throws \Exception
     */
    /*private function modifyTemplate(Request $request, int $stageNumber): Response
    {

        $template = $this->getDoctrine()
                         ->getRepository(Template::class)
                         ->findOneBy([
                                         'name' => $request->request->get('template'),
                                         'orientation' => $request->request->get('orientation')
                                     ]);

        if(!$template)
            throw new \Exception("Template '".$request->request->get('template')."' not found !");

        $incrusteHandler = new IncrusteHandler($this->getDoctrine()->getManager('templateressources'));
        $incrustData = $incrusteHandler->getIncrusteData();

        return $this->render('template/stages/index.html.twig', [
            'controller_name' => 'TemplateController',
            'templateName'    => $template->getName(),
            'orientation' => $template->getOrientation(),
            'zones' => $template->getZones()->getValues(),
            'stageNumber' => $stageNumber,
            'classNames'  => $incrustData['classNames'],
            'ressources'  => $incrustData['ressources']
        ]);
    }*/


    /**
     * @param Request $request
     * @param int $stageNumber
     * @param string $action
     * @return Response
     */
    private function importTemplate(Request $request, int $stageNumber, string $action): Response
    {

        $templateId = $request->request->get('template');

        if( ($stageNumber === 2 and $action === "create") or ($action === "load" and $stageNumber === 1) )
            $orm = 'default';

        else
            $orm = $this->sessionManager->get('user')['QUICKNET']['base'];

        $manager = $this->getDoctrine()->getManager($orm);

        $templateFound = $manager->getRepository(($orm === 'default') ? Main_Template::class : OldApp_Template::class)
                                 ->findOneById( $templateId);

       // dd($orm, $action, $request, $sessionManager->get('user'));

        if(!$templateFound)
            throw new NotFoundHttpException(sprintf("Template '%d' not found !", $templateId));

        $manager = $this->getDoctrine()->getManager($this->sessionManager->get('user')['QUICKNET']['base']);

        $incrusteRepository = $manager->getRepository(OldApp_Incruste::class );
        $templateContentRepository = $manager->getRepository(OldApp_TemplateContent::class );


        $templateContentsHandler = new TemplateContentsHandler($manager);

        $allImages = $templateContentsHandler->getAllImages();
        $allVideos = $templateContentsHandler->getAllVideos();

        $incrusteHandler = new IncrusteHandler($incrusteRepository);

        $incrustData = $incrusteHandler->getIncrusteData();

        $incrustData['classNames']['medias'] = $manager->getRepository(OldApp_Media::class)->findAllMediaUsed();

        //$manager->clear();

        dump($incrustData, $this->sessionManager->get('user'), $orm);

        return $this->render('template/stages/index.html.twig', [
            'controller_name' =>    'TemplateController',
            'template'        =>    $templateFound,
            //'id'              =>    (isset($templateFound)) ? (int) $templateId : null,
            'stageNumber'     =>    $stageNumber,
            'action'          =>    $action,
            'classNames'      =>    $incrustData['classNames'],
            'allImages'      =>    $allImages
        ]);
    }


    /**
     * @param array $zoneContent
     * @param string $zoneName
     * @param Main_Zone | OldApp_Zone $zone
     * @param ObjectManager $entityManager
     * @param string $orm
     * @param Request $request
     * @throws Exception
     */
    private function registerZoneContent(array $zoneContent, string $zoneName, &$zone, ObjectManager &$entityManager, string $orm, Request $request)
    {

        if( !($zone instanceof Main_Zone) and !($zone instanceof OldApp_Zone) )
            throw new InvalidArgumentException("Error : 'zone' argument is not instance of 'Zone'");

        $orm = ($orm === 'default') ? $orm : $this->sessionManager->get('user')['QUICKNET']['base'];

        //dd($zoneContent, $zoneName);

        if(array_key_exists($zoneName, $zoneContent))
        {

            foreach ($zoneContent[$zoneName]['content'] as $item)
            {

                $search = [
                    'level' => 2,
                    'id' => $item['id'],
                    //'type' => $item['type'],
                    //'class' => $item['class'],
                    //'content' => $item['content'],
                    //'zone' => $zone
                ];

                if($this->isMedia($item))
                {
                    // create if not exist an media_incruste in Incruste entity
                    $mediaIncruste = $this->createMediaIncrusteIfNotExist($request, $item);

                    $incruste = $entityManager->getRepository(($orm === "default") ? Main_Incruste::class : OldApp_Incruste::class)->findOneById($mediaIncruste->id);

                    $incrusteElement = $entityManager->getRepository(($orm === "default") ? Main_IncrusteElement::class : OldApp_IncrusteElement::class)->findOneByIncruste($incruste);

                    $search['id'] = $incrusteElement->getId();
                    //$search['type'] = $item['type'];
                    //$search['class'] = $incrusteElement->getClass();
                    //$search['content'] = $item['content'];
                    //$search['zone'] = $incrusteElement->getZone();

                }

                $incrusteElementLvl2Exist = $entityManager->getRepository( ($orm === "default") ? Main_IncrusteElement::class : OldApp_IncrusteElement::class )->findOneBy($search);
                //dump($incrusteElementLvl2Exist);
                if(!$incrusteElementLvl2Exist)
                {

                    $search['level'] = 1;
                    //$search['zone'] = null;

                    $incrusteElementLvl1Exist = $entityManager->getRepository(($orm === "default") ? Main_IncrusteElement::class : OldApp_IncrusteElement::class)->findOneBy($search);
                    //dump($incrusteElementLvl1Exist);
                    if(!$incrusteElementLvl1Exist)
                    {
                        throw new Exception(sprintf("Internal Error ! Cause : IncrusteElement is not found with lvl 1 or 2 (manager used : '%s') !
                        IncrusteElement searched with : '%d'(id); '%d'(level) ", $orm, $search['id'], $search['level']));

                    }

                    //dd($incrusteElementLvl1Exist, $item);

                    $this->duplicateIncrusteElement($entityManager, $orm, $incrusteElementLvl1Exist, $zone);

                }

                //$entityManager->flush();
                //$entityManager->clear();
            }

        }

    }

    private function isMedia(array $subject)
    {

        if($subject['type'] === 'image' OR $subject['type'] === 'video')
        {
            return true;
        }

        return false;

    }

    private function createMediaIncrusteIfNotExist(Request $request, array $incrusteData)
    {

        $values = [
            '_name' => 'mediaincruste1',
            '_type' => 'media',
            '_incrusteElements' => [
                $incrusteData['type'] => [
                    '_name' => null,
                    '_incrustOrder' => 0,
                    '_class' => null,
                    '_type' => $incrusteData['type'],
                    '_style' => [],
                    '_content' => $incrusteData['content'],
                    '_subContents' => []
                ]
            ]
        ];

        $request->request->set('incrusteStyle', json_encode($values));

        $insertResult = json_decode( ($this->registerModel($request, (int) $request->get('stage')))->getContent() );

        //dd($insertResult);
        return $insertResult;

    }


    /**
     * @param ObjectManager $entityManager
     * @param string $orm
     * @param Main_IncrusteElement | OldApp_IncrusteElement $incrusteElement
     * @param Main_Zone | OldApp_Zone $zone
     * @param int $level
     */
    private function duplicateIncrusteElement(ObjectManager &$entityManager, string $orm, $incrusteElement, &$zone, int $level = 2)
    {

        if( !($incrusteElement instanceof Main_IncrusteElement) and !($incrusteElement instanceof OldApp_IncrusteElement) )
            throw new InvalidArgumentException(sprintf("Internal Error ! Cause : 'incrusteElement' argument is not instance of 'IncrusteElement' !"));

        $search = [
            'level' => 2,
            'type' => $incrusteElement->getType(),
            'class' => $incrusteElement->getClass(),
            'content' => $incrusteElement->getContent(),
            'incrustOrder' => $incrusteElement->getIncrustOrder(),
            'zone' => $zone
        ];

        $incrusteElementExist= $entityManager->getRepository(($orm === "default") ? Main_IncrusteElement::class : OldApp_IncrusteElement::class)
                                             ->findOneBy($search);

        if(!$incrusteElementExist)
        {

            $newIncrusteElement = ($orm === "default") ? new Main_IncrusteElement() : new OldApp_IncrusteElement();
            $newIncrusteElement->setType($incrusteElement->getType())
                               ->setContent($incrusteElement->getContent())
                               ->setClass($incrusteElement->getClass())
                               ->setIncrustOrder($incrusteElement->getIncrustOrder())
                               ->setLevel($level)
                               ->setIncruste($incrusteElement->getIncruste());
                               //->setZone($zone);

            $zone->addIncrusteElement($newIncrusteElement);

            /*if($incrusteElement->getType() === "image" or $incrusteElement->getType() === "video")
                dd($newIncrusteElement);*/

            if($incrusteElement->getParent() !== null)
            {

                if($incrusteElement->getParent()->getLevel() === 1)
                    $this->duplicateIncrusteElementParent( $entityManager,  $orm, $newIncrusteElement, $incrusteElement->getParent(), $zone);

                else
                    $newIncrusteElement->setParent($incrusteElement->getParent());

            }

            else
                $newIncrusteElement->setParent(null);

            if($incrusteElement->getIncrusteStyles() !== null and $incrusteElement->getIncrusteStyles()->getValues() !== [])
            {
                $this->duplicateIncrusteElementStyles($entityManager,  $orm, $incrusteElement->getIncrusteStyles()->getValues(), $newIncrusteElement);
            }

            $entityManager->persist($newIncrusteElement);
            $entityManager->flush();

        }

    }

    /**
     * @param ObjectManager $entityManager
     * @param string $orm
     * @param Main_IncrusteElement | OldApp_IncrusteElement $incrusteElement
     * @param Main_IncrusteElement | OldApp_IncrusteElement $incrusteElementParent
     * @param Main_Zone | OldApp_Zone $zone
     * @param int $level
     */
    private function duplicateIncrusteElementParent(ObjectManager &$entityManager, string $orm, &$incrusteElement, $incrusteElementParent, &$zone, int $level = 2)
    {

        $search = [
            'level' => $level,
            'type' => $incrusteElementParent->getType(),
            'class' => $incrusteElementParent->getClass(),
            'content' => $incrusteElementParent->getContent(),
            'incrustOrder' => $incrusteElementParent->getIncrustOrder(),
            'zone' => $zone,
            'parent' => $incrusteElementParent->getParent(),
            'incruste' => $incrusteElementParent->getIncruste()
        ];

        //dd($search);

        $parentExist = $entityManager->getRepository(($orm === "default") ? Main_IncrusteElement::class : OldApp_IncrusteElement::class)
                                     ->findOneBy($search);

        if($parentExist)
            $incrusteElement->setParent($parentExist);

        else
        {

            $newParent = ($orm === "default") ? new Main_IncrusteElement() : new OldApp_IncrusteElement();
            $newParent->setType($incrusteElementParent->getType())
                      ->setContent($incrusteElementParent->getContent())
                      ->setClass($incrusteElementParent->getClass())
                      ->setParent($incrusteElementParent->getParent())
                      ->setIncrustOrder($incrusteElementParent->getIncrustOrder())
                      ->setLevel($level)
                      ->setIncruste($incrusteElementParent->getIncruste())
                      ->setZone($zone);

            if($incrusteElementParent->getIncrusteStyles() !== null)
            {
                $this->duplicateIncrusteElementStyles($entityManager,  $orm, $incrusteElementParent->getIncrusteStyles()->getValues(), $newParent);
            }

            $incrusteElement->setParent($newParent);
            $newParent->addChild($incrusteElement);

            $zone->addIncrusteElement($newParent);

            $entityManager->persist($newParent);

        }


    }

    /**
     * @param ObjectManager $entityManager
     * @param string $orm
     * @param Main_IncrusteStyle[] | OldApp_IncrusteStyle[] $incrusteStyles
     * @param Main_IncrusteElement | OldApp_IncrusteElement $incrusteElement
     */
    private function duplicateIncrusteElementStyles(ObjectManager &$entityManager, string $orm, $incrusteStyles, &$incrusteElement)
    {

        foreach ($incrusteStyles as $incrusteStyle)
        {

            $incrusteElementStyle = ($orm === "default") ? new Main_IncrusteStyle() : new OldApp_IncrusteStyle();
            $incrusteElementStyle->setIncrusteElement($incrusteElement)
                                ->setProperty($incrusteStyle->getProperty())
                                ->setValue($incrusteStyle->getValue());

            $incrusteElement->addIncrusteStyle($incrusteElementStyle);

            $entityManager->persist($incrusteElementStyle);

        }

    }


    /**
     * @param Main_Template | OldApp_Template $template
     * @param array $zones
     * @param array $zonesContents
     * @param ObjectManager $entityManager
     * @param string $orm
     * @param Request $request
     * @param int $stage
     * @return Response
     * @throws Exception
     */
    private function updateTemplate($template, array $zones, array $zonesContents, ObjectManager $entityManager, string $orm, Request $request)
    {

        if(!($template instanceof Main_Template) and !($template instanceof OldApp_Template))
            throw new InvalidArgumentException("Error : 'template' argument is not instance of 'Template'");

        /** update template **/
        $template->setLastModificationDate(new \DateTime());

        //dd($template->getId(), $orm);

        $zoneObjectClass = ($orm === 'default') ?   Main_Zone::class :  OldApp_Zone::class;

        if(sizeof($template->getZones()->getValues()) !== sizeof($zones))
            $this->removeUnnecessaryTemplateZones($template, $zones, $entityManager, $orm);

        /** update template zones **/
        // will contain zone parent name
        $zoneParentArray = [];
        //dump("all zones: ",$zones);
        // insert zone
        foreach ($zones as $item)
        {

            $zoneExist = $entityManager->getRepository($zoneObjectClass)->findOneByName($template->getName() . "_" . $item->name);

            if($zoneExist)
            {

                if(array_key_exists($item->name, $zonesContents))
                    $this->registerZoneContent($zonesContents, $item->name, $zoneExist, $entityManager, $orm, $request);

            }

            else
            {

                $newZone = ($orm === 'default') ? new  Main_Zone() : new OldApp_Zone();;
                $newZone->setName($template->getName() . "_" . $item->name)
                        ->setBackground(1)
                        ->setType($item->type)
                        ->setIsBlocked($item->isBlocked ?? false)
                        ->setWidth($item->size->width)
                        ->setHeight($item->size->height)
                        ->setPositionTop($item->position->top)
                        ->setPositionLeft($item->position->left)
                        ->setZIndex($item->zIndex)
                        ->setPosition("absolute")
                        ->setTemplate($template);

                $template->addZones($newZone);

                if(!is_null($item->zoneParent))
                {

                    $name = $template->getName() . "_" . $item->zoneParent->name;
                    $zoneParent = $entityManager->getRepository($zoneObjectClass)->findOneByName($name);

                    $newZone->setParent($zoneParent);
                    $zoneParent->addChildren($newZone);

                }

                $entityManager->persist($newZone);

                if(array_key_exists($item->name, $zonesContents))
                {
                    $entityManager->flush();
                    $this->registerZoneContent($zonesContents, $item->name, $newZone, $entityManager, $orm, $request);
                }

            }

            $entityManager->flush();

        }

        //$entityManager->clear();

        return new JsonResponse([
            'id' => $template->getId(),
            'name' => $template->getName(),
            'orientation' => $template->getOrientation(),
            'modification_date' => $template->getLastModificationDate()
        ]);

    }


    private function zoneContentFormatting(array $zones)
    {

        $data = [];
        //dd($zones);
        foreach ($zones as $zone)
        {

            if(property_exists($zone, "content"))
            {

                $zone->content->_type = ($zone->content->_type === "price") ? "prix" : $zone->content->_type;

                if($zone->content->_type === "media")
                {
                    if(property_exists($zone->content->_incrusteElements, 'image'))
                        $zone->content->_type = 'image';

                    else
                        $zone->content->_type = 'video';

                }


                //$zoneExist->setContentId($zone->content->_incrusteElements->{ $zone->content->_type }->_id);
                $data[$zone->name]['content'][] = [
                    'id' => $zone->content->_incrusteElements->{ $zone->content->_type }->_id,
                    'type' => $zone->content->_incrusteElements->{ $zone->content->_type }->_type,
                    'class' => $zone->content->_incrusteElements->{ $zone->content->_type }->_class,
                    'content' => $zone->content->_incrusteElements->{ $zone->content->_type }->_content
                ];

                //$data[$zone->name]['ids'] = [];

                //array_push($data[$zone->name]['ids'], $zone->content->_incrusteElements->{ $zone->content->_type }->_id);

                if(property_exists($zone->content->_incrusteElements->{ $zone->content->_type }, "_subContents")
                    //&& is_object($zone->content->_incrusteElements->{ $zone->content->_type }->_subContents)
                    && count(get_object_vars($zone->content->_incrusteElements->{ $zone->content->_type }->_subContents)) > 0)
                {

                    foreach ($zone->content->_incrusteElements->{ $zone->content->_type }->_subContents as $subContent)
                    {
                        $data[$zone->name]['content'][] = [
                            'id' => $subContent->_id,
                            'type' => $subContent->_type,
                            'class' => $subContent->_class,
                            'content' => $subContent->_content
                        ];

                        //array_push($data[$zone->name]['ids'], $subContent->_id);
                    }

                }

            }

        }

        return $data;

    }

    private function removeUnnecessaryTemplateZones($template, array $zones, ObjectManager $entityManager, string $orm)
    {

        if(!($template instanceof Main_Template) and !($template instanceof OldApp_Template))
            throw new InvalidArgumentException("Error : 'template' argument is not instance of Template");

        foreach ($zones as $index => $zone)
        {
            $zones[$index] = $zone->name;
        }

        $templateObject = ($orm === 'default') ? new Main_Template() : new OldApp_Template();
        $zoneObject = ($orm === 'default') ? new Main_Zone() : new OldApp_Zone();

        $templateZones = $entityManager->getRepository(get_class($templateObject))->getTemplateZonesName($template);

        foreach ($templateZones as $templateZone)
        {

            if(!in_array($templateZone, $zones))
            {
                $zoneToRemove = $entityManager->getRepository(get_class($zoneObject))->findOneByName($template->getName() . "_" . $templateZone);
                $template->removeZones($zoneToRemove);
                $entityManager->remove($zoneToRemove);
                //$entityManager->flush();
            }

        }

        //$entityManager->clear();

    }

    /**
     * @param Request $request
     * @param $data
     * @return bool
     */
    private function checkIfDataExistInRequest(Request $request, $data): bool
    {

        if(!is_null($request->request->get($data)))
            return true;

        elseif (!is_null($request->get($data)))
            return true;

        return false;

    }

    private function userSessionIsInitialized()
    {
        return ( is_null($this->sessionManager->get('user')) ) ? false : true;
    }

    private function initializeUserSession()
    {
        $this->sessionManager->set('user', [
            'QUICKNET' => [
                'base'       =>     null,
                'token'      =>     null,
                'niveau'     =>     null,
                'RES_rep'    =>     null,
                'login'      =>     null,
            ],
            'new_app'       =>      false,
            'customer_id'   =>      null,
            'permissions'   =>      [ ]
        ]);
    }

    /**
     * @param MAIN_User $user
     * @param Request $request
     * @throws \Exception
     */
    private function updateUserSession(Main_User $user, Request $request)
    {

        $conf = Yaml::parse($this->externalFileManager->getFileContent($this->getParameter('project_dir') . "/../admin/config/parameters.yml"));

        // replace this by permissions !!!
        if($request->getHost() !== "127.0.0.1" and $request->getHost() !== "localhost")
            $stages = (explode('_', $user->getRole())[0] === "admin") ? [2,3] : [3];

        // on local server
        // give all access
        else
            $stages = [1, 2, 3];


        $sessionData = [
            'QUICKNET' => [
                'base'       =>     $user->getDatabaseName(),
                'token'      =>     $request->get("token"),
                'niveau'     =>     explode('_', $user->getRole())[0],
                'login'      =>     $user->getLogin(),
            ],
            'new_app'        =>     strtolower(explode('_', $user->getRole())[0] === 'admin' OR strtolower($user->getDatabaseName()) === 'leclerc') ? true : false,
            'customer_id'    =>     $user->getIdLocal(),
            'permissions'    =>     [
                'access'     =>     $stages
            ]
        ];

        if($user->getDatabaseName() === 'quicknet')
            $sessionData['QUICKNET']['RES_rep'] = $conf['sys_path']['datas'] . '/data' . '/PLAYER INFOWAY WEB/';

        else
            $sessionData['QUICKNET']['RES_rep'] = $conf['sys_path']['datas'] . '/data_' . $user->getDatabaseName() . '/PLAYER INFOWAY WEB/';

        $this->sessionManager->replace('user', $sessionData);

    }

    private function createSymLinkToUserMedia()
    {

        $filesystem = new Filesystem();

        $dir = ( $this->sessionManager->get('user')['QUICKNET']['base'] === 'quicknet') ? 'data' : 'data_'. $this->sessionManager->get('user')['QUICKNET']['base'];

        // if directory is not symbolic link
        // e.g : for quick is 'assets/images/medias/data'
        if(!is_link($this->getParameter('project_dir') . "/assets/images/medias/". $dir))
        {

            // creates a symbolic link
            // copy all datas in ORIGIN_DIR INTO TARGET_DIR
            // If the filesystem does not support symbolic links, a third boolean argument is available
            $filesystem->symlink( 'E:/datas/main/PLAYER INFOWAY WEB/medias/' . $dir, $this->getParameter('project_dir') . "/assets/images/medias/" . $dir, true);

        }

    }


}
