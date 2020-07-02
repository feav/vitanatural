<?php

namespace App\Controller;

use App\Entity\Panier;
use App\Entity\Commande;
use App\Entity\Abonnement;
use App\Form\PanierType;
use App\Service\ProductService;
use App\Repository\PanierRepository;
use App\Repository\CommandeRepository;
use App\Repository\FormuleRepository;
use App\Repository\CouponRepository;
use App\Repository\AbonnementRepository;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use App\Service\ConfigService;
/**
 * @Route("/api/panier")
 */
class ApiPanierController extends AbstractController
{
    private $productService;
    private $configService;
    private $panierRepository;
    private $commandeRepository;
    private $couponRepository;
    private $userRepository;
    private $entityManager;
    private $money_unit;
    private $price_shipping = 0;
    public function __construct(UserRepository $userRepository,AbonnementRepository $abonnementRepository,FormuleRepository $formuleRepository,PanierRepository $panierRepository,CouponRepository $couponRepository,CommandeRepository $commandeRepository, ProductService $productService, ConfigService $configService)
    {
        $this->money_unit = "$";
        $this->productService = $productService;
        $this->configService = $configService;
        $this->panierRepository = $panierRepository;
        $this->commandeRepository = $commandeRepository;
        $this->abonnementRepository = $abonnementRepository;
        $this->couponRepository = $couponRepository;
        $this->formuleRepository = $formuleRepository;
        $this->userRepository = $userRepository;
        $this->price_shipping = floatval($this->configService->getField('LIVRAISON_AMOUNT'));
        
    }
    public function userIsConnected():Response
    {
        $this->entityManager = $this->getDoctrine()->getManager();
        $user = $this->getUser();
        if($user){
            return new Response( json_encode(array('status' => 200, 'message' => "Utilisateur  connecte" )) );
        }
        return new Response( json_encode(array('status' => 300, 'message' => "Utilisateur non connecte" )) );


    }
    public function getCurrentCard(): Response
    {
        $this->entityManager = $this->getDoctrine()->getManager();
        $user = $this->getUser();
        if($user){

            $paniers = $this->panierRepository->findBy(array('user' =>  $user->getId(), 'status'=>0 ));
            $produis = array();
            $coupons = array();
            $formules = array();
            $livraison = $this->price_shipping;
            $total = 0;
            $reduction = 0;

            /**
            ** if commande do not exist create one
            **/
            if( count($paniers) ==0){
               
            }else{
                $panier = $paniers[0];
                $commandes = $panier->getCommandes();
                foreach ($commandes as $key => $commande) {
                    if($commande->getQuantity()>0)
                        $produis[] = array(
                            'name' => $commande->getProduct()->getName(),
                            'product_price' => $commande->getPrice(),
                            'price' => $commande->getTotalPrice(),
                            'quantity' => $commande->getQuantity(),
                            'id_product' => $commande->getProduct()->getId(),
                            'oldprice' => $commande->getProduct()->getOldPrice(),
                            'img' => $commande->getProduct()->getImage(),
                        );
                }

                $formules_ = $panier->getAbonnements();
                foreach ($formules_ as $formule) {
                    $formules[] = array(
                        'name' => $formule->getFormule()->getName(),
                        'price' => $formule->getFormule()->getPrice(),
                        'id' => $formule->getFormule()->getId(),
                        'month' => $formule->getFormule()->getMonth(),
                    );
                }
                $coupons_ = $panier->getCoupons();
                foreach ($coupons_ as $key => $coupon) {
                    $coupons[] = array(
                        'name' => $coupon->getNom(),
                        'value' => $coupon->getPriceReduction(),
                        'id' => $coupon->getId(),
                        'code' => $coupon->getCode(),
                        'type' => $coupon->getTypeReduction()
                    );
                }

                $livraison = $panier->getPriceShipping();
                $total = $panier->getTotalPrice();
                $reduction = $panier->getTotalReduction();
            }
            
            return new Response( json_encode(
                array(
                    'status' => 200, 
                    'message' => "reccuperer le panier de l'utilisateur connecte", 
                    'panier'=> array(
                        'products'=>$produis,
                        'coupons' => $coupons,
                        'formules' => $formules,
                        'livraison' => $paniers[0]->getPriceShipping(),
                        'total' => ($total-$reduction),
                        'reduction' => $reduction
                    )
                )
            ) );
        }
        return new Response( json_encode(array('status' => 300, 'message' => "Utilisateur non connecte" )) );
    }
    public function addItemToCard(): Response
    {
       $type = $_GET['type'];
       $quantity = $_GET['quantity'];

       
        $this->entityManager = $this->getDoctrine()->getManager();
        $user = $this->getUser();
        if(!$user){
            if(isset($_GET['user'])){
                $user_id = $_GET['user'];
                $user = $userRepository->findOneById($user_id);
            }
        }
        if($user){

            $paniers = $this->panierRepository->findBy(array('user' =>  $user->getId(), 'status'=>0 ));
            $panier = null;
            /**
            ** if commande do not exist create one
            **/
            if( count($paniers) ==0){
                $panier = new Panier();
                $panier->setUser($user);
                if ($panier) {
                    $this->entityManager->persist($panier);
                    $this->entityManager->flush();
                    $panier->initToken();
                }
            }else{
                $panier = $paniers[0];
            }
            $message = "Auncun produit importe";
            /**
            ** update product into card
            **/
            if($type == 'product'){
                $product = $_GET['product']; 

                $product = $this->productService->findById($product);
                if($product){

                    $commande = $this->commandeRepository->findOneBy(array('product' =>  $product, 'panier'=>$panier, 'gratuit' => 0 ));

                    $commande_offerte = $this->commandeRepository->findOneBy(array('product' =>  $product, 'panier'=>$panier, 'gratuit' => 1 ));

                    // var_dump($commande_offerte);
                    if(!$commande_offerte){
                            $commande_offerte = new Commande();
                            $commande_offerte->setGratuit(1);
                            $commande_offerte->setPanier($panier);
                            $commande_offerte->setProduct($product);
                            $panier->addCommande( $commande_offerte );
                    }

                    if($commande){
                        $message = "La quantite du produit a ete mise a jour, ".$product->getNumberOfGift($quantity).' Offets';
                        if($quantity == $commande->getQuantity() )
                            $message = "La quantite du produit est la meme, ".$product->getNumberOfGift($quantity).' Offets';
                        $commande->setQuantity($quantity);
                        $commande->setPrice($product->getPriceTotal($quantity));

                    }else{
                            $commande = new Commande();
                            $commande->setPanier($panier);
                            $commande->setProduct($product);
                            $commande->setQuantity($quantity);
                            $commande->setPrice($product->getPriceTotal($quantity));
                            $panier->addCommande( $commande );
                            $message = "Le produit a ete a ajoute a votre panier, ".$product->getNumberOfGift($quantity).' Offets' ;

                    }

                    $commande_offerte->setPrice(0);
                    $commande_offerte->setQuantity($product->getNumberOfGift($quantity));

                }else{
                    return new Response( json_encode(array('status' => 300, 'message' => "Ce produit n'existe pas dans notre boutique" )) );
                }

            }
            if($type == 'coupon'){
                $coupon_code = $_GET['product']; 
                $coupon = $this->couponRepository->findOneByCode($coupon_code);
                if($coupon){

                    $exist = $panier->getCoupons()->contains($coupon);
                    if($coupon->getCurrentUsage() < $coupon->getMaxUsage() ){
                        if($exist){
                            if($quantity){
                                $message = "Le coupon est deja dans votre panier";
                            }else{
                                $panier->removeCoupon($coupon);
                                $message = "Le coupon a ete retire de votre panier";
                                $coupon->setCurrentUsage( $coupon->getCurrentUsage() - 1);
                            }
                        }else{
                            if($quantity){
                                $panier->addCoupon($coupon);
                                $message = "Le coupon a ete a ajoute a votre panier";
                                $coupon->setCurrentUsage( $coupon->getCurrentUsage() + 1);
                            }else{
                                $message = "Le coupon n'est pas dans votre panier";
                            }
                        }
                        $this->entityManager->persist($coupon);
                        $this->entityManager->flush();
                    }else{
                        return new Response( json_encode(array('status' => 300, 'message' => "Ce coupon a atteint son nombre maximal d'utilisation" )) );
                    }
                    
                }else{
                    return new Response( json_encode(array('status' => 300, 'message' => "Ce coupon n'existe pas dans notre boutique" )) );
                }

            }
            /**
            ** update formule into card
            **/
            if($type == 'formule'){
                $formule_id = $_GET['product']; 

                $formule = $this->formuleRepository->findOneById($formule_id);
                if($formule){

                    $abonnement = $this->abonnementRepository->findOneBy(array('formule' =>  $formule, 'panier'=>$panier ));

                    if($abonnement){

                        if($quantity){
                            $message = "La formule est deja dans votre panier";
                        }else{
                            $panier->removeAbonnement($abonnement);
                            $message = "La formule a ete retiree de votre panier";
                        }
                    }else{

                        if($quantity){
                            $date = new \DateTime();
                            $date_start = new \DateTime();
                            $month = $formule->getMonth();
                            $trialDay = $formule->getTryDays();
                            $date->add(new \DateInterval('P0Y'.$month.'M'.$trialDay.'DT0H0M0S'));

                            $abonnement = new Abonnement();
                            $abonnement->setPanier($panier);
                            $abonnement->setFormule($formule);
                            $abonnement->setPrice($formule->getPrice());
                            $abonnement->setStart($date_start);
                            $abonnement->setEnd($date);
                            $abonnement->setUser($user);
                            $abonnement->setStripeCustomId($user->getStripeCustomId());
                            $abonnement->setState(0);

                            $panier->addAbonnement($abonnement);
                            
                            $this->entityManager->persist($abonnement);
                            $this->entityManager->flush();
                            $message = "La formule a ete a ajoutee a votre panier";
                        }else{
                            $message = "La formule n'etait pas dans votre panier";
                        }

                    }

                }else{
                    return new Response( json_encode(array('status' => 300, 'message' => "Ce produit n'existe pas dans notre boutique" )) );
                }

            }
            $abonnementExit = $this->abonnementRepository->findBy(['user'=>$user->getId(), 'active'=>1]);
            if(count($abonnementExit))
                $panier->setPriceShipping(0);
            else
                $panier->setPriceShipping( $this->price_shipping );

            $panier->refresh_price();

            $this->entityManager->persist($panier);
            $this->entityManager->flush();

            return new Response( json_encode(array('status' => 200, 'message' => $message )) );

        }
        return new Response( json_encode(array('status' => 300, 'message' => "Utilisateur non connecte" )) );
    }

    public function getCurrentCardNotConnected(): Response
    {
        $this->entityManager = $this->getDoctrine()->getManager();
        
        $products = [];
        if(isset($_GET['products']) && $_GET['products'] !== ''){
            $products = $_GET['products'];
        }
        $produis = array();
        $coupons = array();
        $formules = array();
        $livraison = $this->price_shipping;
        $total = 0;
        $reduction = 0;
        if(count($products)){


            /**
            ** if commande do not exist create one
            **/
            if( count($products) ==0){
               
            }else{
                foreach ($products as $key => $value) {
                    if($value['type'] == 'formule'){
                        $formule = $this->formuleRepository->findOneById($value['id']);
                        $total += $formule->getPrice();
                        $formules[] = array(
                            'name' => $formule->getName(),
                            'price' => $formule->getPrice(),
                            'id' => $formule->getId(),
                            'month' => $formule->getMonth(),
                        );
                    }else if($value['type'] == 'product'){
                        $product = $this->productService->findById($value['id']);
                        $total += floatval($product->getPriceTotal($value['qty'])*( (float) $value['qty'] ) );
                        $produis[] = array(
                            'name' => $product->getName(),
                            'product_price' => floatval($product->getPrice()),
                            'price' => floatval($product->getPriceTotal($value['qty'])*( (float) $value['qty'] ) ),
                            'quantity' => intval($value['qty']),
                            'id_product' => (int)  $product->getId(),
                            'oldprice' => (float) $product->getOldPrice(),
                            'img' => $product->getImage(),
                        );
                        if(intval($value['offert'])){
                            $produis[] = array(
                                'name' => $product->getName(),
                                'product_price' => (float) $product->getPrice(),
                                'price' => 0,
                                'quantity' => (int) $value['offert'],
                                'id_product' => $product->getId()."_",
                                'oldprice' => (float) $product->getOldPrice(),
                                'img' => $product->getImage(),
                            );
                        }
                    }
                }

                foreach ($products as $key => $value) {
                    if($value['type'] == 'coupon'){
                        $coupon = $this->couponRepository->findOneByCode($value['id']);
                        
                        /**
                        ** Gerer la reduction au pourcentage
                        **/
                        $reduction += $coupon->getPriceReduction();
                        $coupons[] = array(
                            'name' => $coupon->getNom(),
                            'value' => $coupon->getPriceReduction(),
                            'id' => $coupon->getId(),
                            'code' => $coupon->getCode(),
                            'type' => $coupon->getTypeReduction()
                        );
                    }
                }

            }
            
        }
        $user = $this->getUser();
        $abonnementExit = [] ;
        if(!is_null($user))
            $abonnementExit = $this->abonnementRepository->findBy(['user'=>$user->getId(), 'active'=>1]);
        if(is_null($user) || (!is_null($user) && !count($abonnementExit))){
            $total += $this->price_shipping;
        }
        return new Response( json_encode(
                array(
                    'status' => 200, 
                    'message' => "reccuperer le panier de l'utilisateur connecte", 
                    'panier'=> array(
                        'products'=>$produis,
                        'coupons' => $coupons,
                        'formules' => $formules,
                        'livraison' => $livraison,
                        'total' => ($total-$reduction),
                        'reduction' => $reduction
                    )
                )
            ) );
    }
    public function addItemToCardNotConnected(): Response
    {

        $type = $_GET['type'];
        $quantity = $_GET['quantity'];
        $products = array();
        
        if(isset($_GET['products']) && $_GET['products'] !== ''){
            $products = $_GET['products'];
        }

       
        $this->entityManager = $this->getDoctrine()->getManager();
        if($this->entityManager){
            $message = "Auncun produit importe";
            /**
            ** update product into card
            **/
            if($type == 'product'){
                $product = $_GET['product']; 

                $product = $this->productService->findById($product);
                if($product){

                    $exist = false;;
                    foreach ($products as $key => $value) {
                        if($type == $value['type'] && $product->getId() == $value['id'] ){
                            $exist = true;
                        }
                    }
                    if($exist){
                                                        
                        $message = "La quantite du produit a ete mise a jour";
                        foreach ($products as $key => $value) {
                            if($type == $value['type'] && $product->getId() == $value['id'] ){
                                if($quantity == $value['qty'] ){
                                    $message = "La quantite du produit est la meme";
                                }else{
                                    $products[$key]['qty'] = $quantity;
                                    $products[$key]['offert'] = $product->getNumberOfGift($quantity);
                                }
                            }
                        }

                        if($quantity==0){
                             $message = "Le produit a ete supprime";
                             $tmp = array();
                            foreach ($products as $key => $value) {
                                if($type == $value['type'] && $product->getId() == $value['id'] ){
                                    
                                }else{
                                    $tmp[]=$value;
                                }
                            }
                            $products = $tmp;
                        }
                    }else{
                            $products[] = array(
                                'id' => $product->getId(),
                                'qty' => intval($quantity),
                                'type' => $type,
                                'offert' => $product->getNumberOfGift($quantity)
                            );
                            $message = "Le produit a ete a ajoute a votre panier";
                    }

                }else{
                    return new Response( json_encode(array('status' => 300, 'message' => "Ce produit n'existe pas dans notre boutique" )) );
                }

            }
            if($type == 'coupon'){
                $coupon_code = $_GET['product']; 
                $coupon = $this->couponRepository->findOneByCode($coupon_code);
                if($coupon){

                    $exist = false;;
                    foreach ($products as $key => $value) {
                        if($type == $value['type'] && $coupon->getCode() == $value['id'] ){
                            $exist = true;
                        }
                    }
                    if($coupon->getCurrentUsage() < $coupon->getMaxUsage() ){
                        if($exist){

                            if($quantity){

                                foreach ($products as $key => $value) {
                                    if($type == $value['type'] && $coupon->getCode() == $value['id'] ){
                                        if($quantity == $value['qty'] ){
                                            $message = "Le coupon est deja dans votre panier";
                                        }else{
                                            $message = "Le coupon a ete retire de votre panier";
                                            $products[$key]['qty'] = $quantity;
                                        }
                                    }
                                }

                            }else{

                                $prod = array();

                                foreach ($products as $key => $value) {

                                    if($type == $value['type'] && $coupon->getId() == $value['id'] ){
                                    }else{
                                        $prod[] = $value;
                                    }

                                }

                                $products = $prod;
                                
                            }
                        }else{
                            if($quantity){
                                $products[] = array(
                                        'id' => $coupon->getCode(),
                                        'qty' => $quantity,
                                        'type' => $type,
                                    );
                                $message = "Le coupon a ete a ajoute a votre panier";
                            }else{
                                $message = "Le coupon n'est pas dans votre panier";
                            }
                        }
                    }else{
                        return new Response( json_encode(array('status' => 300, 'message' => "Ce coupon a atteint son nombre maximal d'utilisation" )) );
                    }
                    
                }else{
                    return new Response( json_encode(array('status' => 300, 'message' => "Ce coupon n'existe pas dans notre boutique" )) );
                }

            }
            /**
            ** update formule into card
            **/
            if($type == 'formule'){
                $formule_id = $_GET['product']; 

                $formule = $this->formuleRepository->findOneById($formule_id);
                if($formule){

                    $exist = false;;
                    foreach ($products as $key => $value) {
                        if($type == $value['type'] && $coupon->getId() == $value['id'] ){
                            $exist = true;
                        }
                    }
                    if($exist){

                        if($quantity){
                            $message = "La formule est deja dans votre panier";
                        }else{
                            $message = "La formule a ete retiree de votre panier";

                            $prod = array();

                            foreach ($products as $key => $value) {

                                if($type == $value['type'] && $formule->getId() == $value['id'] ){}else{
                                    $prod[] = $value;
                                }
                            }
                            $products = $prod;
                        }
                    }else{

                        if($quantity){
                            $products[] = array(
                                        'id' => $formule->getId(),
                                        'qty' => $quantity,
                                        'type' => $type,
                                    );
                            $message = "La formule a ete a ajoutee a votre panier";
                        }else{
                            $message = "La formule n'etait pas dans votre panier";
                        }

                    }

                }else{
                    return new Response( json_encode(array('status' => 300, 'message' => "Ce produit n'existe pas dans notre boutique" )) );
                }

            }
            return new Response( json_encode(array('status' => 200, 'message' => $message, 'panier' => $products )) );

        }
        return new Response( json_encode(array('status' => 300, 'message' => "Utilisateur non connecte" )) );
    }

    /**
     * @Route("/", name="panier_index", methods={"GET"})
     */
    public function index(PanierRepository $panierRepository): Response
    {

        $user = $this->getUser();
        if( false){
            var_dump($user->getId());   
            $paniers = $panierRepository->find(array('user' =>  $user->getId(), 'status'=>0 ));
            if(count($panier)){
                 $panier = new Panier();

                if ($panier) {
                    $entityManager = $this->getDoctrine()->getManager();
                    $entityManager->persist($panier);
                    $entityManager->flush();
                    $panier->initToken();
                    $entityManager->persist($panier);
                    $entityManager->flush();
                }
            }
        }
        die();
        // return $this->render('panier/index.html.twig', [
        //     'paniers' => $panierRepository->findAll(),
        // ]);
    }
}
