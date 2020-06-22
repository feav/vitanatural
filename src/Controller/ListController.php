<?php
// ./src/Controller/ListController


namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class ListController extends AbstractController
{
    /**
     * @Route("/list", name="list")
     */
    public function index(Request $request)
    {
    	if($this->isGranted('ROLE_ADMIN'))
        	return $this->redirectToRoute('panier_index');
        else
       		return $this->redirectToRoute('account');
    }
}