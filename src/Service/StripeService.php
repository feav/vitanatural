<?php
namespace App\Service;


use Symfony\Component\Config\Definition\Exception\Exception;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Encoder\UserPasswordEncoderInterface;

use App\Repository\UserRepository;
use App\Repository\CommandeRepository;
use App\Repository\ConfigRepository;
use App\Entity\User;
use App\Entity\Config;
use App\Entity\Commande;

use Stripe\Stripe;
use \Stripe\Charge;

class StripeService{
    
    private $stripeApiKey;
    private $stripeCurrency = "eur";
    private $userRepository;
    private $commandeRepository;
    private $configRepository;

    public function __construct(EntityManagerInterface $em, UserRepository $userRepository, ConfigRepository $configRepository, CommandeRepository $commandeRepository){
        $this->userRepository = $userRepository;
        $this->commandeRepository = $commandeRepository;
        $this->configRepository = $configRepository;
        $this->em = $em;
        $this->stripeApiKey = !is_null($this->configRepository->findOneBy(['mkey'=>'STRIPE_PRIVATE_KEY'])) ? $this->configRepository->findOneBy(['mkey'=>'STRIPE_PRIVATE_KEY'])->getValue() : "";
    }
    public function getValueByKey($key){
        $config = $this->configRepository->findOneBy(['mkey'=>$key]);
        return is_null($config) ? "" : $config->getValue();
    }

    public function createStripeCustom($source, $metadata){
        \Stripe\Stripe::setApiKey($this->stripeApiKey);
        $custom =  \Stripe\Customer::create([
            'source' => $source,
            'email' => $metadata['email'],
            'name' => $metadata['name'],
            'description' => 'Client de la boutique VitaNatural'
        ]);
        $user = $this->userRepository->findOneBy(['email'=>$metadata['email']]);
        $user->setStripeCustomId($custom['id']);
        $this->em->flush();

        return $custom['id'];
    }

    public function paidByStripeCustom($stripe_custom_id, $amount){
        Stripe::setApiKey($this->stripeApiKey);
        $charge = \Stripe\Charge::create([
            'amount' => $amount*100,
            'currency' => $this->stripeCurrency,
            'customer' => $stripe_custom_id, 
        ]);

        return $charge['id'];
    }

    public function proceedPayment($user, $amount){

        $result = $chargeId = "";
        Stripe::setApiKey($this->stripeApiKey);
        try {
            $chargeId = $this->paidByStripeCustom($user->getStripeCustomId(), $amount);

        } catch(\Stripe\Exception\CardException $e) {
            // Since it's a decline, \Stripe\Error\Card will be caught
            $result =  $e->getError()->message;
        } catch (\Stripe\Exception\RateLimitException $e) {
            $result = "Trop de requêtes adressées à l'API trop rapidement";
        } catch (\Stripe\Exception\InvalidRequestException $e) {
            $result = "Des paramètres non valides ont été fournis à l'API de Stripe";
        } catch (\Stripe\Exception\AuthenticationException $e) {
            $result = "L'authentification avec l'API de Stripe a échoué. Peut-être avez-vous changé de clés API récemment";
        } catch (\Stripe\Exception\ApiConnectionException $e) {
            $result = "La communication réseau avec Stripe a échoué";
        } catch (\Stripe\Exception\ApiErrorException $e) {
            $result = "Display a very generic error to the user, and maybe send yourself an email";
        } catch (\Stripe\Exception $e) {
            $result = "Une erreur s'est produite.";
        }

        return ['message'=>$result, 'charge'=> $chargeId];
    }

    public function saveChargeToRefund($panier, $charge){
        if(count($panier->getCommandes())){
            $panier->setStripeChargeId($charge);
            $this->em->flush();
        }
        return $panier->getId();
    }

    public function refund($charge){
        \Stripe\Stripe::setApiKey($this->stripeApiKey);
        $charge = \Stripe\Refund::create([
          'charge' => $charge,
        ]);

        return 1;
    }

    /* abonnement avec definition du prix */
    public function subscription($user, $abonnement){

        if($abonnement->getFormule()->getMonth() == 1){
            $interval = 'month';
        }
        elseif($abonnement->getFormule()->getMonth() == 12)
            $interval = 'year';

        \Stripe\Stripe::setApiKey($this->stripeApiKey);
        $subscription = \Stripe\Subscription::create([
          'customer' => $user->getStripeCustomId(),
          'trial_period_days'=>(int)$abonnement->getFormule()->getTryDays(),
          'items' => [[
            'price_data' => [
              'unit_amount' => 100*$abonnement->getFormule()->getPrice(),
              'currency' => $this->stripeCurrency,
              'product' => $abonnement->getFormule()->getStripeProductId(),
              'recurring' => [
                'interval' => $interval,
              ],
            ],
          ]],
          'metadata' => 
            [
                'abonnement_id' => $abonnement->getId()
            ]
        ]);
        $this->updateAbonnement($subscription['id'], $abonnement);
        return $subscription['id'];
    }

    public function getAllProduct(){
        $stripe = new \Stripe\StripeClient($this->stripeApiKey);
        $products = $stripe->products->all();
        if(!(array)$products)
            return [];
        return $products['data'];
    }

    public function createProduct(){
        \Stripe\Stripe::setApiKey($this->stripeApiKey);
        $product = \Stripe\Product::create([
          'name' => 'abonnement vitanatural',
        ]);
        return $product;
    }

    public function updateAbonnement($subscription, $abonnement){
        $abonnement->setSubscription($subscription);
        $this->em->flush();
    }

    public function subscriptionWebhook(){
        \Stripe\Stripe::setApiKey($this->stripeApiKey);
        $payload = @file_get_contents('php://input');
        $event = null;

        try {
            $event = \Stripe\Event::constructFrom(
                json_decode($payload, true)
            );
        } catch(\UnexpectedValueException $e) {
            // Invalid payload
            http_response_code(400);
            exit();
        }

        // Handle the event
        switch ($event->type) {
            case 'payment_intent.succeeded':
                $paymentIntent = $event->data->object; 
                break;
            case 'payment_method.attached':
                $paymentMethod = $event->data->object; 
                break;
            case 'charge.pending':
                $paymentMethod = $event->data->object; 
                break;
            case 'charge.succeeded':
                $paymentMethod = $event->data->object; 
                break;
            case 'charge.failed':
                $paymentMethod = $event->data->object; 
                break;
            default:
                http_response_code(400);
                exit();
        }

        http_response_code(200);
    }

    public function subscriptionCancel($subscription_id){
        $abonnement = $this->abonnementRepository->findOneBy(['subscription'=>$subscription_id]);
        \Stripe\Stripe::setApiKey($this->stripeApiKey);

        $endTryDay = new \DateTime();
        $trialDay = $abonnement->getFormule()->getTryDays();
        $endTryDay->add(new \DateInterval('P0Y0M'.$trialDay.'DT0H0M0S'));
        if($endTryDay <= new \DateTime()){
            $subscription = \Stripe\Subscription::retrieve($subscription_id);
            $subscription->cancel();//resili imediatement 
        }
        else{
            $subscription = \Stripe\Subscription::update(
              $subscription_id,
              [
                'cancel_at_period_end' => true,
              ]
            );
        }
        
        return $subscription['id'];
    }
}
