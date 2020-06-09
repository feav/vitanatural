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
    private $user_s;
    private $userRepository;
    private $panierRepository;
    private $commandeRepository;
    private $entityManager;
    private $abonnementRepository;
    private $global_s;
    private $formuleRepository;

    public function __construct(ParameterBagInterface $params_dir, UserRepository $userRepository, UserService $user_s, MollieService $mollie_s, AbonnementRepository $abonnementRepository, PanierRepository $panierRepository, CommandeRepository $commandeRepository, GlobalService $global_s, FormuleRepository $formuleRepository){
        $this->params_dir = $params_dir;
        $this->mollie_s = $mollie_s;
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

        $token = $request->request->get('token');
        $panier = $this->panierRepository->findOneBy(['user'=>$user->getId(), 'status'=>0]);
        if(!is_null($panier)){
            $amount = $panier->getTotalPrice();
            if(!$amount)
                return new Response("le montant de votre commande est null", 500);
        }
        else
            return new Response("Vous n'avez aucun panier en attente de paiement", 500);
        
        if(is_null($user)){
            $email = $request->request->get('email');
            $emailExist = $this->userRepository->findOneBy(['email'=>$email]);
            if(!is_null($emailExist)){
                return new Response("Un utilisateur existe déjà avec l'email ".$email.". s'il s'agit de vous, veuillez vous connecter avant d'effectuer le paiement. <a href='javascript:void()' class='open-sign-in-modal'>Connectez-vous</a>", 500);
            }
            $user = $this->user_s->register($mailer, $email, $request->request->get('name'));
            $message = "Un compte vous a été crée, des informations de connexion vous ont été envoyées à l'adresse ".$user->getEmail();
            $metadata = ['name'=>$user->getName(), 'email'=>$user->getEmail()];

            if($token !== null && $amount !== null) {
                $this->mollie_s->createMollieCustom($token, $metadata);
                $preparePaid = $this->preparePaid($panier, $mailer);
                $message = $preparePaid['message'];
                if($preparePaid['paid']){
                    $amount = $preparePaid['amount'];
                    $response = $this->mollie_s->customerFirstPaid($user, $token, $amount);
                    $this->mollie_s->saveChargeToRefund($panier, $response['charge']);
                    $result = $response['message'];
                }
            }
            $flashBag = $this->get('session')->getFlashBag()->clear();
            $this->addFlash('success', 'Paiement effectué avec success');
        }
        else{
            $metadata = ['name'=>$user->getName(), 'email'=>$user->getEmail()];
            
            /* si l'utilisateur a renseigné une carte on lui creer un nouveau custom */
            if($token !== null && $amount !== null) {
                $this->mollie_s->createMollieCustom($token, $metadata);
                $preparePaid = $this->preparePaid($panier, $mailer);
                $message = $preparePaid['message'];
                if($preparePaid['paid']){
                    $amount = $preparePaid['amount'];
                    //$response = $this->mollie_s->customerFirstPaid($user, $token, $amount);
                    $response = $this->mollie_s->proceedPaymentCart($amount, $token);
                    $result = $response['message'];
                    $this->mollie_s->saveChargeToRefund($panier, $response['charge']);
                }
            }
            elseif($user->getMollieCustomerId() !=""){
                $preparePaid = $this->preparePaid($panier, $mailer);
                $message = $preparePaid['message'];
                if($preparePaid['paid']){
                    $amount = $preparePaid['amount'];
                    $response = $this->mollie_s->proceedPayment($user, $amount);
                    //$response = $this->mollie_s->proceedPaymentCart($amount, $token);
                    $this->mollie_s->saveChargeToRefund($panier, $response['charge']);
                    $result = $response['message'];
                }
            }
            else
                return new Response("Vous n'avez entré aucune carte", 500);
        }

        if($result == ""){
            $panier->setStatus(1);
            $panier->setPaiementDate(new \Datetime());

            if(count($panier->getAbonnements())){
                $abonnement = $panier->getAbonnements()[0];
                $tryDays = $abonnement->getFormule()->getTryDays();
                if($tryDays == 0){
                    $abonnement->setState(1);
                }
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
            
            return new Response($message, 200);
        }
        else
            return new Response('Erreur : ' . $errorMessage , 500);
        return new Response(json_encode(['ok'=>true]));
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
        
        /*$amount = $panier->getTotalPrice();
        $response = $this->mollie_s->proceedPayment($user, $amount);
        $this->mollie_s->saveChargeToRefund($panier, $response['charge']);
        $result = $response['message'];*/
        $preparePaid = $this->preparePaid($panier, $mailer);
        $message = $preparePaid['message'];
        if($preparePaid['paid']){
            $amount = $preparePaid['amount'];
            $response = $this->mollie_s->proceedPayment($user, $amount);
            $this->mollie_s->saveChargeToRefund($panier, $response['charge']);
            $result = $response['message'];
        }

        $message = $result;
        if($result == ""){
            $panier->setStatus(1);
            $panier->setPaiementDate(new \Datetime());
            if(count($panier->getAbonnements())){
                $abonnement = $panier->getAbonnements()[0];
                $tryDays = $abonnement->getFormule()->getTryDays();
                if($tryDays == 0){
                    $abonnement->setState(1);
                }
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
            $response = new Response(json_encode($message), 200);
        }
        else
            $response = new Response(json_encode('Erreur : ' . $errorMessage), 500);

        $response->headers->set('Content-Type', 'application/json');
        return $response;
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
            if($value->getActive() && $user->getMollieCustomerId()){
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
        $user = $this->getUser();
        $amount = $panier->getTotalPrice();
        /*$amount = $panier->getTotalPrice() - $panier->getTotalReduction();
        $amount = ( $amount <0 ) ? 0 : $amount;*/
        $message = "Paiement Effectué avec Succèss";
        if(count($panier->getAbonnements())){
            $message = "Votre abonnement sera facturé apres la periode d'essaie";   
        }
        if(!count($panier->getCommandes())){
            $this->entityManager = $this->getDoctrine()->getManager();
            $panier->setPaiementDate(new \Datetime());
            $this->entityManager->flush();
            
            $this->addFlash('success', "Votre abonnement sera facturé apres la periode d'essaie");
            return ['paid'=>false, 'message'=>"Votre abonnement sera facturé apres la periode d'essaie", 'amount'=>0];
        }
        elseif(count($panier->getAbonnements())){
            $message = "Paiement Effectué avec Succèss. Votre abonnement sera facturé apres la periode d'essaie";
            $abonnement = $panier->getAbonnements()[0];
            $abonnementAmount = $abonnement->getFormule()->getPrice();
            //$amount -= $abonnementAmount;
        }
        if(count($panier->getAbonnements())){
            $abonnement = $panier->getAbonnements()[0];
            $tryDays = $abonnement->getFormule()->getTryDays();
            if($tryDays == 0){
                return ['paid'=>true, 'message'=>"Paiement Effectué avec Succèss", 'amount'=>$panier->getTotalPrice()];
            }
        }
        return ['paid'=>true, 'message'=>$message, 'amount'=>$amount];
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

        /*$mollie = new \Mollie\Api\MollieApiClient();
        $mollie->setApiKey("test_VCjK9FN6dJ4fd7mtS8JUHtcm2uy96K");
        $payments = $mollie->payments->page();
        $customers = $mollie->customers->page();
        $datas = ['payments'=> $payments, 'customs'=>$customers];
        dd($datas);*/

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
