<?php

/////////////////////////////////////////////////////////////////////////////
//RUN ME WITH ONE OF THE FOLLOWING COMMANDS:
//  php bin/console app:cache-stat-groups
//  C:/wamp64/bin/php/php8.0.10/php.exe bin/console app:cache-stat-groups
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

class CacheStatGroupsCommand extends Command {

    protected static $defaultName = 'app:cache-stat-groups';
    private $em;
    private $params;
    private $user;
    private $english = 'en-US';
    private $french = 'fr-FR';

    public function __construct(EntityManagerInterface $em, ParameterBagInterface $params) {
        parent::__construct();
        $this->em = $em;
        $this->params = $params;
        $this->user = new UserModel($params, $em, "ROM.WKS23"); //$this->getWorkstationAk());
    }

    protected function configure() {
        $this
                ->setDescription('Cache Events.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output) {
        $result = Command::FAILURE;

        $sg_to_insert = array();
        $t = microtime(true);

        ///////////////////////////////////////////////
        //$conn = $this->container->get('doctrine')->getConnection('default');
        $query = $this->em->getConnection()->prepare("TRUNCATE tmp_statgroups;");
        $query->execute();
        $query = $this->em->getConnection()->prepare("TRUNCATE tmp_statgroups_products;");
        $query->execute();
        ///////////////////////////////////////////////
        $sql_insert_statgroups = "INSERT INTO tmp_statgroups (workstation_ak, code, event_ak)"
                . " VALUES (:workstation_ak, :code, :event_ak);";
        $query_insert_statgroups = $this->em->getConnection()->prepare($sql_insert_statgroups);
        ///////////////////////////////////////////////
        $sql_insert_statgroups_products = "INSERT INTO tmp_statgroups_products (workstation_ak, code, product_ak)"
                . " VALUES (:workstation_ak, :code, :product_ak);";
        $query_insert_statgroups_products = $this->em->getConnection()->prepare($sql_insert_statgroups_products);
        ///////////////////////////////////////////////

        foreach (array("ROM.WKS23", "ROM.WKS42", "ROM.WKS45") as $WKS) {
            $this->user = new UserModel($this->params, $this->em, $WKS); //$this->getWorkstationAk());

            $AllSG = $this->user->bos->execute_4("IWsAPIProduct", "FindAllStatisticalGroup", array(), "");
            if ($AllSG instanceof \Symfony\Component\DomCrawler\Crawler) {
                $sg_to_insert[$WKS] = array_filter($AllSG->filterXPath('//statisticalgrouplist/statisticalgroup')->each(function (Crawler $node, $i) use (&$WKS, &$query_insert_statgroups, &$query_insert_statgroups_products) {
                            try {
                                $sg_code = $node->filterXPath('//code')->text();
                                /////////////////////////////////////
                                //products
                                /////////////////////////////////////
                                $xml = "<FINDALLPRODUCTBYSTATGROUPREQ><STATGROUPLIST><STATGROUPITEM>
                              <CODE>".htmlspecialchars($sg_code)."</CODE>
                           </STATGROUPITEM></STATGROUPLIST></FINDALLPRODUCTBYSTATGROUPREQ>";
                                $sg_prods = $this->user->bos->execute_4("IWsAPIProduct", "FindAllProductByStatGroup", array(), $xml);
                                $events = array();
                                if ($sg_prods instanceof \Symfony\Component\DomCrawler\Crawler) {
                                    $events = $sg_prods->filterXPath('//productlist/product/eventlist/eventbase')->each(function (Crawler $node_p, $i_p) {
                                        return $node_p->filterXPath('//ak')->text();
                                    });
                                    $products = $sg_prods->filterXPath('//productlist/product')->each(function (Crawler $node_p, $i_p) {
                                        return $node_p->filterXPath('//ak')->text();
                                    });
                                }
                                /////////////////////////////////////
                                if (count($events) > 0) {
                                    $val['wks'] = $WKS;
                                    $val['code'] = $sg_code;
                                    $val['event_aks'] = array_unique($events); //(!) defines stat group
                                    foreach ($val['event_aks'] as $event_ak) {
                                        ///////////////////////////////////////////////
                                        //INSERT INTO DB: statgroups
                                        ///////////////////////////////////////////////
                                        $query_insert_statgroups->execute(['workstation_ak' => $WKS
                                            , 'code' => $sg_code
                                            , 'event_ak' => $event_ak]);
                                        ///////////////////////////////////////////////
                                    }
                                    $val['product_aks'] = array_unique($products); //(!) statgroup points to
                                    foreach ($val['product_aks'] as $product_ak) {
                                        ///////////////////////////////////////////////
                                        //INSERT INTO DB: statgroups_products
                                        ///////////////////////////////////////////////
                                        $query_insert_statgroups_products->execute(['workstation_ak' => $WKS
                                            , 'code' => $sg_code
                                            , 'product_ak' => $product_ak]);
                                        ///////////////////////////////////////////////
                                    }
                                    return $val;
                                }
                            } catch (\Exception $e) {
                                echo "Invlid stat group: \r\n";
                            }
                        }));
            }
        }

        //var_dump($sg_to_insert);
        var_dump(array('processing_time' => (microtime(true) - $t)
            , 'sg_to_insert' => count($sg_to_insert)
        ));

        return Command::SUCCESS;
    }

}
