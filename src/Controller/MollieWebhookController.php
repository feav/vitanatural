<?php
// ./src/Controller/ListController


namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Response;

use App\Repository\ConfigRepository;

class MollieWebhookController extends AbstractController
{	
	private $configRepository;

	public function __construct(ConfigRepository $configRepository){
        $this->configRepository = $configRepository;
        $this->mollieApiKey = !is_null($this->configRepository->findOneBy(['mkey'=>'MOLLIE_TEST_KEY'])) ? $this->configRepository->findOneBy(['mkey'=>'MOLLIE_TEST_KEY'])->getValue() : "";
    }
    /**
     * @Route("/mollie-webhook", name="mollie_webhook")
     */
    public function webhook(Request $request, \Swift_Mailer $mailer)
    {	
    	$mail = (new \Swift_Message('paiement status'))
                    ->setFrom(array('alexngoumo.an@gmail.com' => 'Vitanatural'))
                    ->setTo('alexngoumo.an@gmail.com')
                    ->setBody( "paiement status OUVERT entree",
                        'text/html'
                    );
                $mailer->send($mail);

        try {
        	$mollie = new \Mollie\Api\MollieApiClient();
        	$mollie->setApiKey($this->mollieApiKey);

		    //$payment = $mollie->payments->get($_POST["id"]);
		    $payment = $mollie->payments->get($request->request->get('id'));
		    $orderId = $payment->metadata->order_id;

		    /*
		     * Update the order in the database.
		     */
		    //database_write($orderId, $payment->status);
		    
		    if ($payment->isPaid()/* && !$payment->hasRefunds() && !$payment->hasChargebacks()*/) {
		        
		    } elseif ($payment->isOpen()) {
		        $mail = (new \Swift_Message('paiement status'))
                    ->setFrom(array('alexngoumo.an@gmail.com' => 'Vitanatural'))
                    ->setTo('alexngoumo.an@gmail.com')
                    ->setBody( "paiement status OUVERT",
                        'text/html'
                    );
                $mailer->send($mail);

		    } elseif ($payment->isPending()) {
		        /*
		         * The payment is pending.
		         */
		    } elseif ($payment->isFailed()) {
		        /*
		         * The payment has failed.
		         */
		    } elseif ($payment->isExpired()) {
		        /*
		         * The payment is expired.
		         */
		    } elseif ($payment->isCanceled()) {
		        /*
		         * The payment has been canceled.
		         */
		    } elseif ($payment->hasRefunds()) {
		        /*
		         * The payment has been (partially) refunded.
		         * The status of the payment is still "paid"
		         */
		    } elseif ($payment->hasChargebacks()) {
		        /*
		         * The payment has been (partially) charged back.
		         * The status of the payment is still "paid"
		         */
		    }
		} catch (\Mollie\Api\Exceptions\ApiException $e) {
		    echo "API call failed: " . htmlspecialchars($e->getMessage());
		}
       	return new Response('fin du hook',200);
    }
}