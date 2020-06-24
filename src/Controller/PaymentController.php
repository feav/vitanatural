<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Repository\UserRepository;
use App\Repository\AbonnementRepository;
use App\Repository\CommandeRepository;
use App\Repository\PanierRepository;
use App\Repository\FormuleRepository;
use App\Service\StripeService;
use App\Service\MollieService;
use App\Service\UserService;
use App\Service\GlobalService;
use App\Entity\User;
use App\Entity\Abonnement;
use App\Entity\Panier;
use App\Entity\Commande;
/*
use Stripe\Stripe;
use \Stripe\Charge;*/

use Dompdf\Options;
use Dompdf\Dompdf;

class PaymentController extends AbstractController
{   
    private $params_dir;
    private $mollie_s;
    private $stripe_s;
    private $user_s;
    private $userRepository;
    private $panierRepository;
    private $commandeRepository;
    private $entityManager;
    private $abonnementRepository;
    private $global_s;
    private $formuleRepository;

    public function __construct(ParameterBagInterface $params_dir, UserRepository $userRepository, UserService $user_s, MollieService $mollie_s, AbonnementRepository $abonnementRepository, PanierRepository $panierRepository, CommandeRepository $commandeRepository, GlobalService $global_s, FormuleRepository $formuleRepository, StripeService $stripe_s){
        $this->params_dir = $params_dir;
        $this->mollie_s = $mollie_s;
        $this->stripe_s = $stripe_s;
        $this->user_s = $user_s;
        $this->global_s = $global_s;
        $this->userRepository = $userRepository;
        $this->panierRepository = $panierRepository;
        $this->commandeRepository = $commandeRepository;
        $this->abonnementRepository = $abonnementRepository;
        $this->formuleRepository = $formuleRepository;
    }

    /**
     * @Route("/paiement-cart", name="paiement_cart", methods={"GET"})
     */
    public function paiement(): Response
    {
        return $this->render('home/paiement.html.twig', []);
    }

    /**
     * @Route("/checkout", name="checkout_product")
     */
    public function checkout(Request $request, \Swift_Mailer $mailer)
    {   
        $this->entityManager = $this->getDoctrine()->getManager();
        $user = $this->getUser();
        $message = $result = "";
        $response = [];

        $stripeSource = $request->request->get('stripeSource');
        $panier = $this->panierRepository->findOneBy(['user'=>$user->getId(), 'status'=>0]);
        if(!is_null($panier)){
            $amount = $panier->getTotalPrice();
            if(!$amount){
                return new Response(json_encode(array('status'=>500, "checkoutUrl"=>"", "message"=>"le montant de votre commande est null")));
            }
        }
        else
            return new Response(json_encode(array('status'=>500, "checkoutUrl"=>"", "message"=>"Vous n'avez aucun panier en attente de paiement")));
        
        if(is_null($user)){
            $email = $request->request->get('email');
            $emailExist = $this->userRepository->findOneBy(['email'=>$email]);
            if(!is_null($emailExist)){
                return new Response("Un utilisateur existe déjà avec l'email ".$email.". s'il s'agit de vous, veuillez vous connecter avant d'effectuer le paiement. <a href='javascript:void()' class='open-sign-in-modal'>Connectez-vous</a>", 500);
            }
            $user = $this->user_s->register($mailer, $email, $request->request->get('name'));
            $message = "Un compte vous a été crée, des informations de connexion vous ont été envoyées à l'adresse ".$user->getEmail();
            $metadata = ['name'=>$user->getName(), 'email'=>$user->getEmail()];

            if($stripeSource !== null && $amount !== null) {
                 $this->stripe_s->createStripeCustom($request->request->get('stripeSource'), $metadata);
                $preparePaid = $this->preparePaid($panier, $mailer);
                $message = $preparePaid['message'];
                if($preparePaid['paid']){
                    $amount = $preparePaid['amount'];
                    $response = $this->stripe_s->proceedPayment($user, $amount);
                    $this->stripe_s->saveChargeToRefund($panier, $response['charge']);
                    $result = $response['message'];
                }
            }
            $flashBag = $this->get('session')->getFlashBag()->clear();
            $this->addFlash('success', 'Votre commande a été envoyé.');
        }
        else{
            $metadata = ['name'=>$user->getName(), 'email'=>$user->getEmail()];
            
            /* si l'utilisateur a renseigné une carte on lui creer un nouveau custom */
            if($stripeSource !== null && $amount !== null) {
                $this->stripe_s->createStripeCustom($request->request->get('stripeSource'), $metadata);
                $preparePaid = $this->preparePaid($panier, $mailer);
                $message = $preparePaid['message'];
                if($preparePaid['paid']){
                    $amount = $preparePaid['amount'];
                    $response = $this->stripe_s->proceedPayment($user, $amount);
                    $result = $response['message'];
                    $this->stripe_s->saveChargeToRefund($panier, $response['charge']);
                }
            }
            elseif($user->getStripeCustomId() !=""){
                $preparePaid = $this->preparePaid($panier, $mailer);
                $message = $preparePaid['message'];
                if($preparePaid['paid']){
                    $amount = $preparePaid['amount'];
                    $response = $this->stripe_s->proceedPayment($user, $amount);
                    $this->stripe_s->saveChargeToRefund($panier, $response['charge']);
                    $result = $response['message'];
                }
            }
            else
                return new Response(json_encode(array('status'=>500, "checkoutUrl"=>"", "message"=>"Vous n'avez entré aucune carte")));
        }

        if($result == ""){
            //return new Response(json_encode(array('status'=>200, "checkoutUrl"=>$checkoutUrl, "message"=>"Votre paiement a été envoyé, vous recevrez une confirmation d'ici peu.")));

            $panier->setStatus(1);
            $panier->setPaiementDate(new \Datetime());
            if(count($panier->getCommandes())){
                $infosLivraison = ['town'=>$user->getTown(), 'country'=>$user->getCountry(), 'street'=> $user->getStreet(), 'zip_code'=>$user->getZipCode()];
                $infosLivraison = serialize($infosLivraison);
                $panier->setLivraison($infosLivraison);
            }
            if(count($panier->getAbonnements())){
                $abonnement = $panier->getAbonnements()[0];
                if($abonnement->getFormule()->getTryDays() == 0)
                    $abonnement->setState(1);
            }
            $this->entityManager->flush();
            $assetFile = $this->params_dir->get('file_upload_dir');
            if (!file_exists($request->server->get('DOCUMENT_ROOT') .'/'. $assetFile)) {
                mkdir($request->server->get('DOCUMENT_ROOT') .'/'. $assetFile, 0705);
            } 
            $ouput_name = 'facture_'.$panier->getId().'.pdf';
            $save_path = $assetFile.$ouput_name;
            $params = [
                'format'=>['value'=>'A4', 'affichage'=>'portrait'],
                'is_download'=>['value'=>true, 'save_path'=>$save_path],
                'total_price'=>$amount
            ];
            //$dompdf = $this->generatePdf('emails/facture.html.twig', $panier , $params);

            $this->sendMail($mailer, $user, $panier, $save_path, $amount);
            
            return new Response(json_encode(array('status'=>200, "checkoutUrl"=>"", "message"=>$message)));
        }
        else{
            return new Response(json_encode(array('status'=>500, "checkoutUrl"=>"", "message"=>"Une erreur s'est produite")));
        }
        return new Response(json_encode(array('status'=>200, "checkoutUrl"=>"", "message"=>"aucune action Effectuée")));
    }

    public function sendMail($mailer, $user, $panier, $commande_pdf, $amount){

        /*if(count($panier->getAbonnements())){
            $abonnement = $panier->getAbonnements()[0];
            $tryDays = $abonnement->getFormule()->getTryDays();
            if($tryDays == 0){
                $content = "<p>Bonjour ".$user->getName().", <br> Confirmation de votre abonnement. <p>";
                $url = $this->generateUrl('home');
                try {
                    $mail = (new \Swift_Message('Confirmation Abonnement'))
                        ->setFrom(array('alexngoumo.an@gmail.com' => 'Vitanatural'))
                        ->setTo([$user->getEmail()=>$user->getName()])
                        ->setCc("alexngoumo.an@gmail.com")
                        ->attach(\Swift_Attachment::fromPath($commande_pdf))
                        ->setBody(
                            $this->renderView(
                                'emails/mail_template.html.twig',['content'=>$content, 'url'=>$url]
                            ),
                            'text/html'
                        );
                    $mailer->send($mail);
                } catch (Exception $e) {
                    print_r($e->getMessage());
                } 
            }
        }*/
        if(count($panier->getCommandes())){
            $content = "<p>Bonjour ".$user->getName().", <br> Vous avez fait des achats pour ".$panier->getTotalPrice()."€</p>";
            $url = $this->generateUrl('home');
            try {
                $mail = (new \Swift_Message('Confirmation commande'))
                    ->setFrom(array('alexngoumo.an@gmail.com' => 'Vitanatural'))
                    ->setTo([$user->getEmail()=>$user->getName()])
                    ->setCc("alexngoumo.an@gmail.com")
                    //->attach(\Swift_Attachment::fromPath($commande_pdf))
                    ->setBody(
                        $this->renderView(
                            'emails/mail_template.html.twig',['content'=>$content, 'url'=>$url]
                        ),
                        'text/html'
                    );
                $mailer->send($mail);
            } catch (Exception $e) {
                print_r($e->getMessage());
            }            
        }
        if(count($panier->getAbonnements())){
            $abonnement = $panier->getAbonnements()[0];
            $mois_annee = ($abonnement->getFormule()->getMonth() == 12) ? "ans" : $abonnement->getFormule()->getMonth()."mois";

            $content = "<p>Bien joué ".$user->getName()." Confirmation de votre abonnement</p>";
            $url = $this->generateUrl('home');
            try {
                $mail = (new \Swift_Message('Abonnement réussit'))
                    ->setFrom(array('alexngoumo.an@gmail.com' => 'Vitanatural'))
                    ->setTo([$user->getEmail()=>$user->getName()])
                    ->setCc("alexngoumo.an@gmail.com")
                    ->setBody(
                        $this->renderView(
                            'emails/mail_template.html.twig',['content'=>$content, 'url'=>$url]
                        ),
                        'text/html'
                    );
                $mailer->send($mail);
            } catch (Exception $e) {
                print_r($e->getMessage());
            }            
        }    
        return 1; 
    }

    public function getFullDate($date){
        $day = array("Dimanche","Lundi","Mardi","Mercredi","Jeudi","Vendredi","Samedi"); 
        $month = array("01"=>"janvier", "02"=>"février", "03"=>"mars", "04"=>"avril", "05"=>"mai", "06"=>"juin", "07"=>"juillet", "08"=>"août", "09"=>"septembre", "10"=>"octobre", "11"=>"novembre", "12"=>"décembre"); 
        $fullDate = "";
        $fullDate .= $date->format('d')." ".$month[(string)$date->format('m')]." ".$date->format('Y'). " à ".$date->format('H:i');

        return $fullDate;
    }
    /**
     * @Route("/checkout/direct-paid", name="direct_paid")
     */
    public function directPaid(Request $request, \Swift_Mailer $mailer){
        
        $this->entityManager = $this->getDoctrine()->getManager();
        $result = "";
        $user = $this->getUser();
        if(is_null($user))
            $response = new Response(json_encode("Vous devez etre connecté pour un paiement en un click"), 500);

        $amount = 0;
        $panier = $this->panierRepository->findOneBy(['user'=>$user->getId(), 'status'=>0]);
        if(!is_null($panier)){
            $amount = $panier->getTotalPrice();
            if(!$amount)
                return new Response("le montant de votre commande est null", 500);
        }
        else
            return new Response("Vous n'avez aucun panier en attente de paiement", 500);
        
        $preparePaid = $this->preparePaid($panier, $mailer);
        $message = $preparePaid['message'];
        if($preparePaid['paid']){
            $amount = $preparePaid['amount'];
            $response = $this->stripe_s->proceedPayment($user, $amount);
            $this->stripe_s->saveChargeToRefund($panier, $response['charge']);
            $result = $response['message'];
        }

        $message = $result;
        if($result == ""){
            $panier->setStatus(1);
            $panier->setPaiementDate(new \Datetime());
            if(count($panier->getAbonnements())){
                $abonnement = $panier->getAbonnements()[0];
                if($abonnement->getFormule()->getTryDays() == 0)
                    $abonnement->setState(1);
            }
            $this->entityManager->flush();
            $assetFile = $this->params_dir->get('file_upload_dir');
            if (!file_exists($request->server->get('DOCUMENT_ROOT') .'/'. $assetFile)) {
                mkdir($request->server->get('DOCUMENT_ROOT') .'/'. $assetFile, 0705);
            } 
            $ouput_name = 'facture_'.$panier->getId().'.pdf';
            $save_path = $assetFile.$ouput_name;
            $params = [
                'format'=>['value'=>'A4', 'affichage'=>'portrait'],
                'is_download'=>['value'=>true, 'save_path'=>$save_path],
                'total_price'=>$amount
            ];
            //$dompdf = $this->generatePdf('emails/facture.html.twig', $panier , $params);
            $this->sendMail($mailer, $user, $panier, $save_path, $amount);
            return new Response(json_encode(array('status'=>200, "checkoutUrl"=>"", "message"=>$message)));
        }
        else
            return new Response(json_encode(array('status'=>500, "checkoutUrl"=>"", "message"=>"Une erreur s'est produite")));

        return new Response(json_encode(array('status'=>200, "checkoutUrl"=>"", "message"=>"aucune action Effectuée")));
    }

    /**
     * @Route("/abonnement/update-abonnement", name="update_abonnement")
     */
    public function updateAbonnements(Request $request, \Swift_Mailer $mailer){
        $this->entityManager = $this->getDoctrine()->getManager();
        $abonnements = $this->abonnementRepository->findBy(['active'=>1]);
        foreach ($abonnements as $key => $value) {
            $result = "";
            $user = $value->getUser();
            if($value->getActive() && $user->getStripeCustomId()){
                $date = $value->getStart();
                $date->add(new \DateInterval('P'.$value->getFormule()->getTryDays().'D'));
                
                if(!$value->getState() && (new \Datetime() >= $date )){
                    $response = $this->mollie_s->proceedPayment($user, $value->getFormule()->getPrice());
                    $result = $response['message'];
                    if($result == ""){
                        $value->setState(1);
                        $content = "<p>Vous etes arrivé à la fin de votre periode d'essaie pour l'abonnement ".$value->getFormule()->getMonth()." mois. vous avez été débité de ".$value->getFormule()->getPrice()."€ sur votre carte</p>";
                        $url = $this->generateUrl('home');
                        try {
                            $mail = (new \Swift_Message('Abonnement Payé'))
                                ->setFrom(array('alexngoumo.an@gmail.com' => 'Vitanatural'))
                                ->setTo([$user->getEmail()=>$user->getName()])
                                ->setCc("alexngoumo.an@gmail.com")
                                //->attach(\Swift_Attachment::fromPath($commande_pdf))
                                ->setBody(
                                    $this->renderView(
                                        'emails/mail_template.html.twig',['content'=>$content, 'url'=>$url]
                                    ),
                                    'text/html'
                                );
                            $mailer->send($mail);
                        } catch (Exception $e) {
                            print_r($e->getMessage());
                        }
                    }
                }
                if( (new \Datetime() >= $value->getEnd()) && !$value->getIsPaid() ){
                    $response = $this->mollie_s->proceedPayment($user, $value->getFormule()->getPrice());
                    $result = $response['message'];

                    if($result == ""){
                        $value->setIsPaid(1);
                        $value->setActive(0);
                        $this->createNewAbonnement($value);

                        $content = "<p>Votre abonnement ".$value->getFormule()->getMonth()." Mois a été renouvellé et vous a couté ".$value->getFormule()->getPrice()."€</p>";
                        $url = $this->generateUrl('home');
                        try {
                            $mail = (new \Swift_Message('Abonnement renouvellé'))
                                ->setFrom(array('alexngoumo.an@gmail.com' => 'Vitanatural'))
                                ->setTo([$user->getEmail()=>$user->getName()])
                                ->setCc("alexngoumo.an@gmail.com")
                                //->attach(\Swift_Attachment::fromPath($commande_pdf))
                                ->setBody(
                                    $this->renderView(
                                        'emails/mail_template.html.twig',['content'=>$content, 'url'=>$url]
                                    ),
                                    'text/html'
                                );
                            $mailer->send($mail);
                        } catch (Exception $e) {
                            print_r($e->getMessage());
                        }            
                    }
                } 
                $this->entityManager->flush();
            }
        }

        return new Response("renouvellé");
    }

    public function createNewAbonnement($abonnement){
        $this->entityManager = $this->getDoctrine()->getManager();
        $entity = new Abonnement();
        $month = $abonnement->getFormule()->getMonth();
        //$trialDay = $abonnement->getFormule()->getTryDays();

        $curDate = new \Datetime();
        $entity->setStart(new \Datetime());
        $curDate->add(new \DateInterval('P0Y'.$month.'M0DT0H0M0S'));
        $entity->setEnd($curDate);
        $entity->setState(1);
        $entity->setFormule($abonnement->getFormule());
        $entity->setPanier($abonnement->getPanier());
        $entity->setUser($abonnement->getUser());

        $this->entityManager->persist($entity);
        $this->entityManager->flush();
        return 1;
    }


    public function findEvent($eventId)
    {
        return \Stripe\Event::retrieve($eventId);
    }

    /**
     * @Route("/webhook-subscription", name="webhook_subscription")
     */
    public function subscriptionWebhook(Request $request, \Swift_Mailer $mailer){

        \Stripe\Stripe::setApiKey('sk_test_zJN82UbRA4k1a6Mvna4rV3qn');

        $data = json_decode($request->getContent(), true);
        if ($data === null) {
            throw new \Exception('Bad JSON body from Stripe!');
        }
        $eventId = $data['id'];
        $event = $this->findEvent($eventId);

        $message ="";
        // Handle the event
        switch ($event->type) {
            case 'payment_intent.succeeded':
                $paymentIntent = $event->data->object; 
                $message .= " payment_intent_succeeded"
                break;
            case 'payment_intent.failed':
                $paymentIntent = $event->data->object; 
                $message .= " payment_intent_failed"
                break;
            case 'invoice.payment_succeeded':
                $paymentMethod = $event->data->object; 
                $message .= " payment_succeeded";
                break;
            case 'invoice.payment_failed':
                $paymentMethod = $event->data->object; 
                $message = " payment_failed";
                break;
            default:
                return new Response('Evenement inconnu',400);
                /*http_response_code(400);
                exit();*/
        }

         try {
            $mail = (new \Swift_Message("Stripe webhook"))
                ->setFrom(array('alexngoumo.an@gmail.com' => 'webhook'))
                ->setTo("alexngoumo.an@gmail.com")
                ->setBody($message,
                    'text/html'
                );
            $mailer->send($mail);
        } catch (Exception $e) {
            print_r($e->getMessage());
        }        

        //http_response_code(200);
        return new Response('Evenement terminé avec success',200);
    }

    public function generatePdf($template, $data, $params, $type_produit = "product"){
        $options = new Options();
        $dompdf = new Dompdf($options);
        $dompdf -> setPaper ($params['format']['value'], $params['format']['affichage']);
        $html = $this->renderView($template, ['data' => $data, 'total_price'=>$params['total_price'] , 'type_produit'=>$type_produit]);
        $dompdf->loadHtml($html);
        $dompdf->render();
        if($params['is_download']['value']){
            $output = $dompdf->output();
            file_put_contents($params['is_download']['save_path'], $output);
        }
        return $dompdf;
    }

    public function preparePaid($panier, $mailer){
        $amount = $panier->getTotalPrice();
        if(count($panier->getAbonnements())){
            $this->stripe_s->subscription($this->getUser(), $panier->getAbonnements()[0]);
            $this->addFlash('success', "Votre abonnement a été pris en compte");
            $response = ['paid'=>false, 'message'=>"Votre abonnement a été pris en compte", 'amount'=>0];
        }
        if(count($panier->getCommandes())){
            $this->entityManager = $this->getDoctrine()->getManager();
            $panier->setPaiementDate(new \Datetime());
            $this->entityManager->flush();
            
            $this->addFlash('success', "Paiement Effectué avec Succèss");
            $response = ['paid'=>true, 'message'=>"Paiement Effectué avec Succèss", 'amount'=>$amount];
        }
        return $response;
    }

    /**
     * @Route("/resiliation-abonnement/{id}", name="abonnement_resilie", methods={"GET"})
     */
    public function resile(Request $request, $id, \Swift_Mailer $mailer)
    {   
        $user = $this->getUser();
        $entityManager = $this->getDoctrine()->getManager();
        $abonnement = $this->abonnementRepository->find($id);
        if($abonnement->getStart() >= new \DateTime()){
            $flashBag = $this->get('session')->getFlashBag()->clear();
            $this->addFlash('warning', "La periode d'essaie de cet abonnement est passée, vous ne pouvez plus le resilier");
            return $this->redirectToRoute('account');
        }
        if(!$abonnement->getActive()){
            $flashBag = $this->get('session')->getFlashBag()->clear();
            $this->addFlash('warning', "cet abonnement n'est pas actif");
            return $this->redirectToRoute('account');
        }
        $abonnement->setResilie(1);
        $abonnement->setActive(0);
        $entityManager->flush();

        $content = "<p>Votre abonnement a bien été resilié</p>";
        $url = $this->generateUrl('home', [], UrlGenerator::ABSOLUTE_URL);
        try {
            $mail = (new \Swift_Message("Résiliation d'abonement"))
                ->setFrom(array('alexngoumo.an@gmail.com' => 'Vitanatural'))
                ->setTo([$user->getEmail()=>$user->getName()])
                ->setCc("alexngoumo.an@gmail.com")
                ->setBody(
                    $this->renderView(
                        'emails/mail_template.html.twig',['content'=>$content, 'url'=>$url]
                    ),
                    'text/html'
                );
            $mailer->send($mail);
        } catch (Exception $e) {
            print_r($e->getMessage());
        }            
        $flashBag = $this->get('session')->getFlashBag()->clear();
        $this->addFlash('success', "Abonnement resilié");
        return $this->redirectToRoute('account');
    }


    /**
     * @Route("/demande-remboursement/{id}", name="demande_remboursement", methods={"GET"})
     */
    public function remboursement(Request $request, $id, \Swift_Mailer $mailer){
        $entityManager = $this->getDoctrine()->getManager();
        $panier = $this->panierRepository->find($id);
        $panier->setRemboursement(1);
        $entityManager->flush();

        $urlPanier = $this->generateUrl('panier_index', [], UrlGenerator::ABSOLUTE_URL);
        $url = $this->generateUrl('home', [], UrlGenerator::ABSOLUTE_URL);
        $content = "<p>l'utilisateur <b>".$this->getUser()->getEmail()."</b> vient de faire une demande de remboursement.<br> connectez-vous à la plateforme afin de valider cette demande.<br><a href='".$urlPanier."'>".$urlPanier."</a></p>";
        try {
            $mail = (new \Swift_Message("Demande de remboursement"))
                ->setFrom([$this->getUser()->getEmail()=>$this->getUser()->getName()])
                ->setTo("bahuguillaume@gmail.com")
                ->setBody(
                    $this->renderView(
                        'emails/mail_template.html.twig',['content'=>$content, 'url'=>$url]
                    ),
                    'text/html'
                );
            $mailer->send($mail);
        } catch (Exception $e) {
            print_r($e->getMessage());
        }     
        $this->addFlash('success', "Votre demande de remboursement a été envoyé");
        return $this->redirectToRoute('account');       
    }

    /**
      * @Route("/success-payment", name="success_payment", methods={"GET"})
     */
    public function payementSuccess(){
        $formule = $this->formuleRepository->findAll();
        return $this->render('home/success_payment.html.twig', [
            'formules' => $formule
        ]);
    }

    /**
      * @Route("/nos-formules/", name="nos_formule", methods={"GET"})
     */
    public function nosFormules(){
        $formule = $this->formuleRepository->findAll();
        return $this->render('home/formule.html.twig', [
            'formules' => $formule,
        ]);
    }
}   
