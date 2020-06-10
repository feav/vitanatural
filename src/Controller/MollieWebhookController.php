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
    	$status = "";
        try {
        	$mollie = new \Mollie\Api\MollieApiClient();
        	$mollie->setApiKey($this->mollieApiKey);

		    $payment = $mollie->payments->get($request->request->get('id'));
		    $orderId = $payment->metadata->order_id;

		    /*
		     * Update the order in the database.
		     */
		    //database_write($orderId, $payment->status);

		    $status = $payment->status."-".$payment->id."=>";

		    if ($payment->isPaid()/* && !$payment->hasRefunds() && !$payment->hasChargebacks()*/){
		        $status .= " PAID";
		    } elseif ($payment->isOpen()) {
		        $status .= " OPEN";
		    } elseif ($payment->isPending()) {
		        $status .= " PENDING";
		    } elseif ($payment->isFailed()) {
		        $status .= " FAILD";
		    } elseif ($payment->isExpired()) {
		        $status .= "EXPIRED";
		    } elseif ($payment->isCanceled()) {
		        $status .= " CANCELED";
		    } elseif ($payment->hasRefunds()) {
		        $status .= " REFUNDS";
		    } elseif ($payment->hasChargebacks()) {
		       $status .= " CHARGE_BACK";
		    }
		} catch (\Mollie\Api\Exceptions\ApiException $e) {
		    echo "API call failed: " . htmlspecialchars($e->getMessage());
		    $status .= "ERROR API call failed: ".$e->getMessage();
		}

		$mail = (new \Swift_Message('paiement status'))
            ->setFrom(array('alexngoumo.an@gmail.com' => 'Vitanatural'))
            ->setTo('alexngoumo.an@gmail.com')
            ->setBody( $status,
                'text/html'
            );
        $mailer->send($mail);
       	return new Response('fin du hook',200);
    }
}