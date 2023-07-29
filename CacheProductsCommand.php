<?php

/////////////////////////////////////////////////////////////////////////////
//RUN ME WITH ONE OF THE FOLLOWING COMMANDS:
//  php bin/console app:cache-products
//  C:/wamp64/bin/php/php8.0.10/php.exe bin/console app:cache-products
/////////////////////////////////////////////////////////////////////////////
/////////////////////////////////////////////////////////////////////////////

namespace App\Command;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Doctrine\DBAL\Connection;
///////////////////////////
//SOAP
use App\Model\Main\UserModel;
use Symfony\Component\DomCrawler\Crawler;
///////////////////////////
//contorller constants
use App\Controller\Main\AccountController;
///////////////////////////

class CacheProductsCommand extends Command {

    protected static $defaultName = 'app:cache-products';
    private $em;
    private $params;
    private $user;
    private $english = 'en-US';
    private $french = 'fr-FR';

    public function __construct(EntityManagerInterface $em, ParameterBagInterface $params) {
        parent::__construct();
        $this->em = $em;
        $this->params = $params;
        //$this->user = new UserModel($params, $em, "ROM.WKS23");//$this->getWorkstationAk());
        $this->user = new UserModel($params, $em, "ROM.WKS42"); //$this->getWorkstationAk());
        //$this->user = new UserModel($params, $em, "ROM.WKS45");//$this->getWorkstationAk());
    }

    protected function configure() {
        $this
                ->setDescription('Cache Events.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output) {
        $result = Command::FAILURE;

        $products_to_insert = array();
        $t = microtime(true);

        ///////////////////////////////////////////////
        //$conn = $this->container->get('doctrine')->getConnection('default');
        $query = $this->em->getConnection()->prepare("TRUNCATE tmp_products;");
        $query->execute();
        ///////////////////////////////////////////////
        $sql_insert_products = "INSERT INTO tmp_products (workstation_ak, product_ak, code, sheetname, currency, net, tax, gross, name_en, name_fr)"
                . " VALUES (:workstation_ak, :product_ak, :code, :sheetname, :currency, :net, :tax, :gross, :name_en, :name_fr);";
        $query_insert_products = $this->em->getConnection()->prepare($sql_insert_products);
        ///////////////////////////////////////////////

        foreach (array("ROM.WKS23", "ROM.WKS42", "ROM.WKS45") as $WKS) {
            $this->user = new UserModel($this->params, $this->em, $WKS); //$this->getWorkstationAk());

            $AllP = $this->user->bos->execute_4("IWsAPIProduct", "FindAllProducts", array(), "");
            if ($AllP instanceof \Symfony\Component\DomCrawler\Crawler) {
                $products_to_insert[$WKS] = array_filter($AllP->filterXPath('//productlist/product')->each(function (Crawler $node, $i) use (&$WKS, &$query_insert_products) {
                            try {
                                $val['workstation_ak'] = $WKS;
                                $val['product_ak'] = $node->filterXPath('//product/ak')->text();
                                $val['code'] = $node->filterXPath('//product/code')->text();
                                $val['sheetname'] = $node->filterXPath('//product/sheetname')->text();
                                $val['currency'] = $node->filterXPath('//product/price/currency')->text();
                                $val['net'] = $node->filterXPath('//product/price/net')->text();
                                $val['tax'] = $node->filterXPath('//product/price/tax')->text();
                                $val['gross'] = $node->filterXPath('//product/price/gross')->text();
                                try {
                                    $val['name_en'] = $node->filterXPath("//product/i18nlist/i18n[code/text()=\"$this->english\"]/fieldlist/field[objtype/text()=".(AccountController::OBJECT_TYPE_TICKET_NAME)."]/value")->text(); //536=Ticket Name
                                } catch (\Exception $e) {
                                    $val['name_en'] = '';
                                }
                                try {
                                    $val['name_fr'] = $node->filterXPath("//product/i18nlist/i18n[code/text()=\"$this->french\"]/fieldlist/field[objtype/text()=".(AccountController::OBJECT_TYPE_TICKET_NAME)."]/value")->text(); //536=Ticket Name
                                } catch (\Exception $e) {
                                    $val['name_fr'] = '';
                                }
                                ///////////////////////////////////////////////
                                //INSERT INTO DB
                                ///////////////////////////////////////////////
                                $query_insert_products->execute($val);
                                ///////////////////////////////////////////////

                                return $val;
                            } catch (\Exception $e) {
                                echo "Invlid product: \r\n";
                            }
                        }));
            }
        }

        //var_dump($products_to_insert);
        var_dump(array('processing_time' => (microtime(true) - $t)
            , 'products_to_insert' => count($products_to_insert)
        ));

        return Command::SUCCESS;
    }

}
