<?php
namespace App\Service;

use App\Entity\Config;
use Symfony\Component\Config\Definition\Exception\Exception;
use Doctrine\ORM\EntityManagerInterface;

class ConfigService{
    private $doctrine;
    
    public function __construct(EntityManagerInterface $doctrine){
        $this->doctrine = $doctrine;
    }
    public function findAll(){
        $products = $this->doctrine->getRepository(Config::class)->findAll();
        return $products;
    }
    public function findById(int $id){
        $product = $this->doctrine->getRepository(Config::class)->findOneById($id);
        return $product;
    }
    public function getScripts()
    {
        $entityManager = $this->doctrine->getManager();

         $configs = (new Config())->getKeyName();
        $list_key_js = array();

        foreach ($configs as $key => $value) {
            if(   strpos($value['key'], "_JS_")){
                $list_key_js[] = $value['key'];
            }
        }
        $retour = "";
        foreach ($list_key_js as $key => $value) {
            $config = $this->doctrine->getRepository(Config::class)->findOneByMkey($value);
            if($config){
                $retour .= "<script>".$config->getValue()."</script>";
            }
        }
        return ($retour);
    }
    public function getField($name){
        $config = $this->doctrine->getRepository(Config::class)->findOneByMkey($name);
        $retour = $config ? $config->getValue() : "";
        
        return ($retour);
    }
}
