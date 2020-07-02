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
use App\Repository\ConfigRepository;
use App\Service\StripeService;
use App\Service\MollieService;
use App\Service\UserService;
use App\Service\GlobalService;
use App\Entity\User;
use App\Entity\Abonnement;
use App\Entity\Panier;
use App\Entity\Commande;
use App\Service\ConfigService;

/*
use Stripe\Stripe;
use \Stripe\Charge;*/

use Dompdf\Options;
use Dompdf\Dompdf;

class PaymentController extends AbstractController
{   
    private $params_dir;
    private $configService;
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
    private $price_shipping = 0;
    private $configRepository;

    public function __construct(ParameterBagInterface $params_dir, UserRepository $userRepository, UserService $user_s, MollieService $mollie_s, AbonnementRepository $abonnementRepository, PanierRepository $panierRepository, CommandeRepository $commandeRepository, GlobalService $global_s, FormuleRepository $formuleRepository, StripeService $stripe_s, ConfigService $configService, ConfigRepository $configRepository){
        $this->params_dir = $params_dir;
        $this->mollie_s = $mollie_s;
        $this->stripe_s = $stripe_s;
        $this->user_s = $user_s;
        $this->global_s = $global_s;
        $this->configService = $configService;
        $this->userRepository = $userRepository;
        $this->panierRepository = $panierRepository;
        $this->commandeRepository = $commandeRepository;
        $this->abonnementRepository = $abonnementRepository;
        $this->formuleRepository = $formuleRepository;
        $this->configRepository = $configRepository;
        $this->price_shipping = floatval($this->configService->getField('LIVRAISON_AMOUNT'));
        $this->stripeApiKey = !is_null($this->configRepository->findOneBy(['mkey'=>'STRIPE_PRIVATE_KEY'])) ? $this->configRepository->findOneBy(['mkey'=>'STRIPE_PRIVATE_KEY'])->getValue() : "";
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
            if(count($panier->getCommandes()))
                $this->sendMail($mailer, $user, $panier, $save_path, $amount);
            
            return new Response(json_encode(array('status'=>200, "checkoutUrl"=>"", "message"=>$message)));
        }
        else{
            return new Response(json_encode(array('status'=>500, "checkoutUrl"=>"", "message"=>"Une erreur s'est produite")));
        }
        return new Response(json_encode(array('status'=>200, "checkoutUrl"=>"", "message"=>"aucune action Effectuée")));
    }

    public function totalAmount($panier){
        $amount = $panier->getTotalPrice();
        if(count($panier->getCommandes())){
            $user = $this->getUser();
            $abonnementExit = $this->abonnementRepository->findBy(['user'=>$user->getId(), 'active'=>1]);
            if(!count($abonnementExit) && $panier->getPriceShipping() == 0){
                $amount += $this->price_shipping;
            }
        }
        if($panier->getTotalReduction() > 0)
            $amount -= $panier->getTotalReduction();
        return $amount;
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
            if(count($panier->getCommandes()))
                $this->sendMail($mailer, $user, $panier, $save_path, $amount);
            
            return new Response(json_encode(array('status'=>200, "checkoutUrl"=>"", "message"=>$message)));
        }
        else
            return new Response(json_encode(array('status'=>500, "checkoutUrl"=>"", "message"=>"Une erreur s'est produite")));

        return new Response(json_encode(array('status'=>200, "checkoutUrl"=>"", "message"=>"aucune action Effectuée")));
    }

    /**
     * @Route("/webhook-subscription", name="webhook_subscription")
     */
    public function subscriptionWebhook(Request $request, \Swift_Mailer $mailer){

        \Stripe\Stripe::setApiKey($this->stripeApiKey);

        $data = json_decode($request->getContent(), true);
        if ($data === null) {
            throw new \Exception('Bad JSON body from Stripe!');
        }
        $event = \Stripe\Event::retrieve($data['id']);

        $message ="";
        // Handle the event
        switch ($event->type) {
            case 'customer.subscription.updated':
                $subscription = $event->data->object; 
                $message = "subscription.updated";
                $this->updateSubscription('updated', $subscription, $mailer);
                break;
            case 'customer.subscription.created':
                $subscription = $event->data->object;
                $message = "subscription.created";
                $this->updateSubscription('created', $subscription, $mailer);
                break;
            case 'customer.subscription.deleted':
                $subscription = $event->data->object;
                $message = "subscription.deleted";
                $this->updateSubscription('deleted', $subscription, $mailer);
                break;
            case 'invoice.payment_succeeded':
                $paymentMethod = $event->data->object; 
                $subscription_id = $paymentMethod->lines->data[0]->subscription;
                $abonnementId = $paymentMethod->lines->data[0]->metadata->abonnement_id;

                if(!is_null($paymentMethod->billing_reason) && ($paymentMethod->billing_reason == "subscription_create" || $paymentMethod->billing_reason == "subscription_cycle" ) ){
                    $customer_email = $paymentMethod->customer_email;
                    $invoice_pdf = $paymentMethod->invoice_pdf;
                    $message = '<p>Bonjour, cliquer sur le lien ci-dessous pour telecharger votre facture <br>'.$invoice_pdf.'</p>';
                    $this->factureMail('Facture abonnement', $message, $customer_email, $mailer);
                }
                break;
            case 'payment_intent.payment_failed':
                $paymentIntent = $event->data->object; 
                if( $paymentIntent->status == "requires_payment_method" || $paymentIntent->status == "requires_action" ){

                    $abonnement = $this->abonnementRepository->findOneBy(['stripe_custom_id'=>$paymentIntent->customer]);

                    if(!is_null($abonnement)){
                        $user = $this->userRepository->find($abonnement->getUser());
                        $message = "<p>Bonjour, <br>La carte utilisée neccessite une authentification 3D sécure</p>";

                        if(!is_null($paymentIntent->next_action)){
                            $urlAuth= $paymentIntent->next_action->redirect_to_url->url;
                            $message .= ", cliquez sur le lien sous dessous afin de completer votre paiement.<br>".$urlAuth;
                        }
                        $url = $this->generateUrl('home', [], UrlGenerator::ABSOLUTE_URL);
                        try {
                            $mail = (new \Swift_Message($objet))
                            ->setFrom(array("alexngoumo.an@gmail.com" => 'Vitanatural'))
                            ->setTo([$user->getEmail() => $user->getName()])
                            ->setCc("alexngoumo.an@gmail.com")
                             ->setBody(
                                $this->renderView(
                                    'emails/mail_template.html.twig',['content'=>$message, 'url'=>$url]
                                ),
                                'text/html'
                            );
                            $mailer->send($mail);
                        } catch (Exception $e) {
                            print_r($e->getMessage());
                        }
                    }
                }
                break;
            default:
                return new Response('Evenement inconnu',400);
                /*http_response_code(400);
                exit();*/
        }
        //http_response_code(200);
        return new Response('Evenement terminé avec success',200);
    }

    public function factureMail($objet, $message, $clientEmail, $mailer){
        $url = $this->generateUrl('home', [], UrlGenerator::ABSOLUTE_URL);
        try {
                $mail = (new \Swift_Message($objet))
                ->setFrom(array("alexngoumo.an@gmail.com" => 'Vitanatural'))
                ->setTo([$clientEmail=>$clientEmail])
                ->setCc("alexngoumo.an@gmail.com")
                ->setBody(
                    $this->renderView(
                        'emails/mail_template.html.twig',['content'=>$message, 'url'=>$url]
                    ),
                    'text/html'
                );
                $mailer->send($mail);
            } catch (Exception $e) {
                print_r($e->getMessage());
        }
        return 1;
    }

    public function updateSubscription($status, $subscription, $mailer){

        $this->entityManager = $this->getDoctrine()->getManager();
        $abonnement = $this->abonnementRepository->findOneBy(['subscription'=>$subscription->id]);

        if(!is_null($abonnement)){
            $user = $abonnement->getUser();
            $message = "";

            if($status == "created" || $status == "updated"){
                if($subscription->status == "incomplete_expired"){
                    $abonnement->setActive(0);
                    $abonnement->setStart(new \DateTime(date('Y-m-d H:i:s', $subscription->current_period_start)));
                    $abonnement->setEnd(new \DateTime(date('Y-m-d H:i:s', $subscription->current_period_end)));
                    $message = "<p> Bonjour, <br> aucun paiement de votre abonnement n'a été effectué.</p>";
                }
            }
            if( $status == "created" && ($subscription->status == "active" || $subscription->status == "trialing" )){
                $abonnement->setActive(1);
                $abonnement->setStart(new \DateTime(date('Y-m-d H:i:s', $subscription->current_period_start)));
                $abonnement->setEnd(new \DateTime(date('Y-m-d H:i:s', $subscription->current_period_end)));
                $mois_annee = ($abonnement->getFormule()->getMonth() == 12) ? "ans" : $abonnement->getFormule()->getMonth()."mois";

                $message="<p> Bonjour, <br> nous vous confirmons que votre abonnement a été crée avec succèss. </p>";
            }
            elseif( $status == "updated" && ($subscription->status == "active" || $subscription->status == "trialing" )){
                $abonnement->setActive(1);
                $abonnement->setStart(new \DateTime(date('Y-m-d H:i:s', $subscription->current_period_start)));
                $abonnement->setEnd(new \DateTime(date('Y-m-d H:i:s', $subscription->current_period_end)));
                $message = "<p> Bonjour, <br> nous vous confirmons que votre abonnement a été mis à jour avec succèss. </p>";

            }
            elseif($status == "deleted" && $subscription->status == "canceled"){
                $abonnement->setActive(0);
                $abonnement->setResilie(1);
                $message = "<p>Votre abonnement a bien été resilié</p>";
            }

            $this->entityManager->flush();
            $url = $this->generateUrl('home', [], UrlGenerator::ABSOLUTE_URL);
            try {
                $mail = (new \Swift_Message("Status abonnement"))
                ->setFrom(array($user->getEmail() => 'Vitanatural'))
                ->setCc("alexngoumo.an@gmail.com")
                ->setTo([$user->getEmail()=> $user->getEmail()])
                ->setBody(
                    $this->renderView(
                        'emails/mail_template.html.twig',['content'=>$message, 'url'=>$url]
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
            $this->addFlash('success', "Vous recevrez une confirmation de votre abonnement d'ici peu.");
            $response = ['paid'=>false, 'message'=>"Votre abonnement a été pris en compte", 'amount'=>0];
        }
        if(count($panier->getCommandes())){
            $amount = $this->totalAmount($panier);
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

        $subscription = $this->stripe_s->subscriptionCancel($abonnement->getSubscription());
        if($subscription == $abonnement->getSubscription()){          
            $flashBag = $this->get('session')->getFlashBag()->clear();
            $this->addFlash('success', "Votre demande de resiliation  a été prise en compte");
            return $this->redirectToRoute('account');
        }
        else{
            $flashBag = $this->get('session')->getFlashBag()->clear();
            $this->addFlash('error', "Echec resiliation");
            return $this->redirectToRoute('account');
        }
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
