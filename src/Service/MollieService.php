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

/*use Stripe\Stripe;
use \Stripe\Charge;*/

class MollieService{
    
    private $mollieApiKey;
    private $mollieCurrency = "EUR";
    private $userRepository;
    private $commandeRepository;
    private $configRepository;

    public function __construct(EntityManagerInterface $em, UserRepository $userRepository, ConfigRepository $configRepository, CommandeRepository $commandeRepository){
        $this->userRepository = $userRepository;
        $this->commandeRepository = $commandeRepository;
        $this->configRepository = $configRepository;
        $this->em = $em;
        $this->mollieApiKey = !is_null($this->configRepository->findOneBy(['mkey'=>'MOLLIE_TEST_KEY'])) ? $this->configRepository->findOneBy(['mkey'=>'MOLLIE_TEST_KEY'])->getValue() : "";
    }
    public function getValueByKey($key){
        $config = $this->configRepository->findOneBy(['mkey'=>$key]);
        return is_null($config) ? "" : $config->getValue();
    }

    public function createMollieCustom($token, $metadata){
        $mollie = new \Mollie\Api\MollieApiClient();
        $mollie->setApiKey($this->mollieApiKey);

        $customer = $mollie->customers->create([
            'email' => $metadata['email'],
            'name' => $metadata['name'],
        ]);
        $user = $this->userRepository->findOneBy(['email'=>$metadata['email']]);
        $user->setMollieCustomerId($customer->id);
        $this->em->flush();

        return $customer->id;
    }

    public function paidByMollieCustom($mollie_custom_id, $amount){

        $mollie = new \Mollie\Api\MollieApiClient();
        $mollie->setApiKey($this->mollieApiKey);

        $payment_id = "";
        try {
            $payment = $mollie->customers->get($mollie_custom_id)->createPayment([
                "amount" => [
                   "currency" => $this->mollieCurrency,
                   "value" => number_format((float)$amount, 2, '.', '')
                ],
                "description" => "Transaction de la boutique VitaNatural",
                "sequenceType" => "first",
                "redirectUrl" => "https://vitanatural.fr",
                "webhookUrl" => "https://vitanatural.fr",
            ]);
            $payment_id = $payment->id;
        } catch (Exception $e) {
            $result = $e->getMessage();
            echo 'Exception recue : ',  $e->getMessage(), "\n";
        }

        return $payment_id;
    }

    public function proceedPayment($user, $amount){
        $result = "";
        $mollie = new \Mollie\Api\MollieApiClient();
        $mollie->setApiKey($this->mollieApiKey);
        $transactionId = $this->paidByMollieCustom($user->getMollieCustomerId(), $amount);
        
        return ['message'=>$result, 'charge'=> $transactionId];
    }

    public function saveChargeToRefund($panier, $transaction){
        if(count($panier->getCommandes())){
            $panier->setMollieTransactionId($transaction);
            $this->em->flush();
        }
        return $panier->getId();
    }

    public function customerFirstPaid($user, $token, $amount){

        $result = "";
        $mollie = new \Mollie\Api\MollieApiClient();
        $mollie->setApiKey($this->mollieApiKey);

        try {
            $payment = $mollie->customers->get($user->getMollieCustomerId())->createPayment([
                "method" => "creditcard",
                "amount" => [
                    "currency" => $this->mollieCurrency,
                    "value" => number_format((float)$amount, 2, '.', '')
                ],
                "description" => "Transaction de la boutique VitaNatural first payment",
                "redirectUrl" => "https://vitanatural.fr",
                "webhookUrl" => "https://vitanatural.fr",
                "sequenceType" => "first",
                "cardToken" => $token,
            ]);
        } catch (Exception $e) {
            $result = $e->getMessage();
            echo 'Exception recue : ',  $e->getMessage(), "\n";
        }

        return ['message'=>$result, 'charge'=> $payment->id];
    }

    public function proceedPaymentCart($amount, $token){
        $result = "";
        $mollie = new \Mollie\Api\MollieApiClient();
        $mollie->setApiKey($this->mollieApiKey);
        
        $payment = $mollie->payments->create([
              "method" => "creditcard",
              "amount" => [
                    "currency" => $token,
                    "value" => '1.00'
              ],
              "description" => "Transaction de la boutique VitaNatural",
              "redirectUrl" => "https://vitanatural.fr",
              "webhookUrl" => "https://vitanatural.fr",
              "cardToken" => $token,
        ]);
        return ['message'=>$result, 'charge'=> $payment->id];
    }

    public function refund($transaction, $amount = 0){

        $mollie = new \Mollie\Api\MollieApiClient();
        $mollie->setApiKey($this->mollieApiKey);

        $payment = $mollie->payments->get($transaction);
        $refund = $payment->refund([
        "amount" => [
           "currency" => $this->mollieCurrency,
           "value" => number_format((float)$amount, 2, '.', '')
        ]
        ]);

        return 1;
    }
}
