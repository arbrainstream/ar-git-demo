<?php

/////////////////////////////////////////////////////////////////////////////
//RUN ME WITH ONE OF THE FOLLOWING COMMANDS:
//  php bin/console app:cache-bos-pull
//  C:/wamp64/bin/php/php8.0.10/php.exe bin/console app:cache-bos-pull
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
//MAIL
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
///////////////////////////
//IO
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;
///////////////////////////
//contorller constants
use App\Controller\Main\AccountController;
///////////////////////////

class CacheBosPullCommand extends Command {

    protected static $defaultName = 'app:cache-bos-pull';
    private $em;
    private $user;
    private $params;
    private $mailer;
    private $all_workstations = array("ROM.WKS23", "ROM.WKS42", "ROM.WKS45", "ROM.WKS70", "ROM.WKS24");
    private $days_ahead_to_process = 100;
    private $english = 'en-US';
    private $french = 'fr-FR';
    private $projectDir;

    public function __construct(KernelInterface $kernel, EntityManagerInterface $em, MailerInterface $mailer, ParameterBagInterface $params) {
        parent::__construct();
        $this->em = $em;
        $this->params = $params;
//        $this->user = new UserModel($this->params, $em, "ROM.WKS23"); //$this->getWorkstationAk());
        $this->mailer = $mailer;
        $this->projectDir = $kernel->getProjectDir();
    }

    protected function configure() {
        $this
                ->setDescription('Cache BOS.')
        ;
    }

    public function readableBytes($bytes) {
        $i = floor(log($bytes) / log(1024));
        $sizes = array('B', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB');

        return sprintf('%.02F', $bytes / pow(1024, $i)) * 1 . ' ' . $sizes[$i];
    }

    protected function execute(InputInterface $input, OutputInterface $output) {
        $result = Command::FAILURE;
        try {
            //////////////////////////////////
            //Wipe/truncate tmp tables
            //////////////////////////////////
            $tmp_table_names = array('tmp_events', 'tmp_events_by_day', 'tmp_products', 'tmp_statgroups', 'tmp_statgroups_products');
            foreach ($tmp_table_names as $tbl_name) {
                $query = $this->em->getConnection()->prepare("TRUNCATE $tbl_name;");
                $query->execute();
            }
            var_dump($this->readableBytes(memory_get_usage(true)));

            //////////////////////////////////
            //FYI: Azure App Service timeout is 230 sec (date: 2022-08-02)
            ini_set('default_socket_timeout', 200); //set higher timeout for big api quries like FindAllProducts (in seconds)
            //////////////////////////////////
            
            //////////////////////////////////
            //////////////////////////////////
            //////////////////////////////////
            //cache to temp tables
            //////////////////////////////////
            //products
            $products_inserted = $this->cache_products();
            if (!(isset($products_inserted) && is_array($products_inserted) && count($products_inserted) > 0)) {
                $error_msg = "\r\nError with caching products\r\n";
                var_dump($error_msg);
                $this->email_failure_msg($error_msg);
                return Command::FAILURE;
            }
            var_dump($this->readableBytes(memory_get_usage(true)));
            unset($products_inserted);
            gc_collect_cycles();
            var_dump($this->readableBytes(memory_get_usage(true)));

            //////////////////////////////////
            //statgroup, & statgroup events, & statgroup products)
            $sg_inserted = $this->cache_stat_groups();
            if (!(isset($sg_inserted) && is_array($sg_inserted) && count($sg_inserted) > 0)) {
                $error_msg = "\r\nError with caching stat groups\r\n";
                var_dump($error_msg);
                $this->email_failure_msg($error_msg);
                return Command::FAILURE;
            }
            $sg_inserted_events_count = 0;
            $sg_inserted_products_count = 0;
            foreach ($sg_inserted as $wrk_grp => $stat_groups) {
                foreach ($stat_groups as $sg) {
                    if (isset($sg['event_aks']))
                        $sg_inserted_events_count += count($sg['event_aks']);
                    if (isset($sg['product_aks']))
                        $sg_inserted_products_count += count($sg['product_aks']);
                }
            }
            $error_msg = '';
            if ($sg_inserted_events_count <= 0) {
                $error_msg .= "Error with caching stat group events<br />";
            }
            if ($sg_inserted_products_count <= 0) {
                $error_msg .= "Error with caching stat group products<br />";
            }
            if ($error_msg != '') {
                var_dump($error_msg);
                $this->email_failure_msg($error_msg);
                return Command::FAILURE;
            }
            var_dump($this->readableBytes(memory_get_usage(true)));
            unset($sg_inserted);
            gc_collect_cycles();
            var_dump($this->readableBytes(memory_get_usage(true)));

            //////////////////////////////////
            //events all
            $events_inserted = $this->cache_events_and_img();
            if (!(isset($events_inserted) && is_array($events_inserted) && count($events_inserted) > 0)) {
                $error_msg = "\r\nError with caching events\r\n";
                var_dump($error_msg);
                $this->email_failure_msg($error_msg);
                return Command::FAILURE;
            }
            var_dump($this->readableBytes(memory_get_usage(true)));
            unset($events_inserted);
            gc_collect_cycles();
            var_dump($this->readableBytes(memory_get_usage(true)));

            //////////////////////////////////
            //events by day
            $daysEvents = $this->cache_events_by_day();
            if (!(isset($daysEvents) && is_array($daysEvents) && count($daysEvents) > 0)) {
//(!) comment out for ROM shutdown
/*                $error_msg = "\r\nError with caching events by day\r\n";
                var_dump($error_msg);
                $this->email_failure_msg($error_msg);
                return Command::FAILURE;*/
            }
            var_dump($this->readableBytes(memory_get_usage(true)));
            unset($daysEvents);
            gc_collect_cycles();
            var_dump($this->readableBytes(memory_get_usage(true)));

            //////////////////////////////////
            //////////////////////////////////
            //Validate before import
            //////////////////////////////////
            $error_msg = "";
            foreach ($tmp_table_names as $tn) {
                $sql = "SELECT COUNT(*) cnt FROM $tn;";
                $query = $this->em->getConnection()->prepare($sql);
                $query->execute();
                $res = $query->fetchAll();
                if (count($res) > 0)
                    $sql_tbl_count[$tn] = $res[0]['cnt'];
                else
                    $sql_tbl_count[$tn] = 0;
            }

            $error_msg = "";
            foreach ($sql_tbl_count as $tn => $cnt) {
//(!) comment out for ROM shutdown
/*                if ($cnt <= 0)
                    $error_msg .= "Error with caching events by day for table '$tn'<br />";*/
            }
            if ($error_msg != "") {
                $error_msg .= "<br /><br />" . print_r($sql_tbl_count, true);
                var_dump($error_msg);
                $this->email_failure_msg($error_msg);
                return Command::FAILURE;
            }
            //////////////////////////////////
            //////////////////////////////////
            ////////////////////////////////////////////
            //move from temp tables to cache tables
            // & email report
            ////////////////////////////////////////////
            $results = $this->update_tmp2cache();
            $this->email_report($results);
            //////////////////////////////////
        } catch (\Exception $e) {
            $this->email_failure_msg($e->getMessage());
        }
        return Command::SUCCESS;
    }

    protected function cache_products() {
        $products_inserted = array();
        $t = microtime(true);

        ///////////////////////////////////////////////
        $sql_insert_products = "INSERT INTO tmp_products (workstation_ak, product_ak, code, sheetname, currency, net, tax, gross, name_en, name_fr, desc_en, desc_fr, account_dmg_ak) "
                . " VALUES (:workstation_ak, :product_ak, :code, :sheetname, :currency, :net, :tax, :gross, :name_en, :name_fr, :desc_en, :desc_fr, :account_dmg_ak);";
        $query_insert_products = $this->em->getConnection()->prepare($sql_insert_products);
        ///////////////////////////////////////////////
        
        foreach ($this->all_workstations as $WKS) {
            $this->user = new UserModel($this->params, $this->em, $WKS); //$this->getWorkstationAk());
            
            $AllP = $this->user->bos->execute_4("IWsAPIProduct", "FindAllProducts", array(), "");
            if ($AllP instanceof \Symfony\Component\DomCrawler\Crawler) {
                $products_inserted[$WKS] = array_filter($AllP->filterXPath('//productlist/product')->each(function (Crawler $node, $i) use (&$WKS, &$query_insert_products) {
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
                                    $val['account_dmg_ak'] = $node->filterXPath('//product/warning/account/dmgak')->text();
                                } catch (\Exception $e) {
                                    $val['account_dmg_ak'] = '';
                                }                                
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
                                try {
                                    $val['desc_en'] = $node->filterXPath("//product/i18nlist/i18n[code/text()=\"$this->english\"]/fieldlist/field[objtype/text()=".(AccountController::OBJECT_TYPE_TICKET_DETAILS)."]/value")->text(); //537=Ticket Ticket Details
                                } catch (\Exception $e) {
                                    $val['desc_en'] = '';
                                }
                                try {
                                    $val['desc_fr'] = $node->filterXPath("//product/i18nlist/i18n[code/text()=\"$this->french\"]/fieldlist/field[objtype/text()=".(AccountController::OBJECT_TYPE_TICKET_DETAILS)."]/value")->text(); //537=Ticket Ticket Details
                                } catch (\Exception $e) {
                                    $val['desc_fr'] = '';
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
                $AllP->clear();
            }
            unset($AllP);
        }

        $this->em->flush();
        $this->em->clear();
        //var_dump($products_inserted);
        var_dump(array('processing_time' => (microtime(true) - $t)
            , 'products_inserted' => count($products_inserted)
        ));

        return $products_inserted;
    }

    protected function cache_stat_groups() {
        $sg_inserted = array();
        $t = microtime(true);

        ///////////////////////////////////////////////
        $sql_insert_statgroups = "INSERT INTO tmp_statgroups (workstation_ak, code, event_ak)"
                . " VALUES (:workstation_ak, :code, :event_ak);";
        $query_insert_statgroups = $this->em->getConnection()->prepare($sql_insert_statgroups);
        ///////////////////////////////////////////////
        $sql_insert_statgroups_products = "INSERT INTO tmp_statgroups_products (workstation_ak, code, product_ak, `sort`)"
                . " VALUES (:workstation_ak, :code, :product_ak, :sort);";
        $query_insert_statgroups_products = $this->em->getConnection()->prepare($sql_insert_statgroups_products);
        ///////////////////////////////////////////////

        foreach ($this->all_workstations as $WKS) {
            $this->user = new UserModel($this->params, $this->em, $WKS); //$this->getWorkstationAk());

            $AllSG = $this->user->bos->execute_4("IWsAPIProduct", "FindAllStatisticalGroup", array(), "");
            if ($AllSG instanceof \Symfony\Component\DomCrawler\Crawler) {
                $sg_inserted[$WKS] = array_filter($AllSG->filterXPath('//statisticalgrouplist/statisticalgroup')->each(function (Crawler $node, $i) use (&$WKS, &$query_insert_statgroups, &$query_insert_statgroups_products) {
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
                                        return ['i' => $i_p, 'product_ak' => $node_p->filterXPath('//ak')->text()];
                                    });
                                    $sg_prods->clear();
                                }
                                unset($sg_prods);
                                /////////////////////////////////////
                                if (isset($events) && count($events) > 0) {
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
                                }
                                if (isset($products) && count($products) > 0) {
                                    $val['wks'] = $WKS;
                                    $val['code'] = $sg_code;
                                    $val['product_aks'] = array_column($products, 'i', 'product_ak'); //(!) statgroup points to
                                    foreach ($val['product_aks'] as $product_ak => $i) {
                                        ///////////////////////////////////////////////
                                        //INSERT INTO DB: statgroups_products
                                        ///////////////////////////////////////////////
                                        $query_insert_statgroups_products->execute(['workstation_ak' => $WKS
                                            , 'code' => $sg_code
                                            , 'product_ak' => $product_ak
                                            , 'sort' => $i]);
                                        ///////////////////////////////////////////////
                                    }
                                }
                                if ((isset($events) && count($events) > 0) || (isset($products) && count($products) > 0)) {
                                    return $val;
                                }
                            } catch (\Exception $e) {
                                echo "Invlid stat group: $WKS:$sg_code\r\n";
                                var_dump($e->getMessage());
                            }
                        }));
                $AllSG->clear();
            }
            unset($AllSG);
        }

        $this->em->flush();
        $this->em->clear();
        //var_dump($sg_inserted);
        var_dump(array('processing_time' => (microtime(true) - $t)
            , 'sg_inserted' => count($sg_inserted)
        ));

        return $sg_inserted;
    }

    protected function cache_events_by_day() {
        $daysEvents = array();
        $t = microtime(true);

        ///////////////////////////////////////////////
        $sql_insert_day_events = "INSERT INTO tmp_events_by_day (workstation_ak, date, event_ak, `sort`)"
                . " VALUES (:workstation_ak, :date, :event_ak, :sort);";
        $query_insert_day_evt = $this->em->getConnection()->prepare($sql_insert_day_events);
        ///////////////////////////////////////////////

        foreach ($this->all_workstations as $WKS) {
            $this->user = new UserModel($this->params, $this->em, $WKS); //$this->getWorkstationAk());
            ///////////////////////////////////////////
            ///////////////////////////////////////////
            echo "\r\n";
            for ($i = 0; $i < $this->days_ahead_to_process; $i++) {
                $d = date('Y-m-d', strtotime("Now +$i day"));
                $xml = "
                    <SEARCHEVENTREQ>
                        <DATE>
                            <FROM>".htmlspecialchars($d)."</FROM>
                            <TO>".htmlspecialchars($d)."</TO>
                        </DATE>
						<EVENTCATEGORYLIST>
						   <EVENTCATEGORY>
							  <CODE>TICKET</CODE>
						   </EVENTCATEGORY>
						</EVENTCATEGORYLIST>
                        <NUMBEROFPERSON>1</NUMBEROFPERSON>
                    </SEARCHEVENTREQ>
                    ";
                $dayEvents_dom = $this->user->bos->execute_4("IWsAPIEvent", "SearchEvent", array(), $xml);
                //$daysEvents[$WKS][$d] = array();//(!) need to mark for deletion if no values
                echo "Before foreach: (mem_usage=" . $this->readableBytes(memory_get_usage(true)) . ")\r\n";
                if ($dayEvents_dom instanceof \Symfony\Component\DomCrawler\Crawler) {
                    try {
                        $daysEvents[$WKS][$d] = array_filter($dayEvents_dom->filterXPath('//eventlist/event')->each(function (Crawler $node, $i) use (&$WKS, &$d, &$query_insert_day_evt) {
                                    $node_ak = $node->filterXPath('//ak');
                                    $event_ak = $node_ak->text();
                                    $node_ak->clear();
                                    $sortorder = $node->filterXPath('//sortorder')->text("9".str_pad($i, 6, '0', STR_PAD_LEFT));
                                    ///////////////////////////////////
                                    //INSERT INTO DB: events_by_day
                                    ///////////////////////////////////
                                    $query_insert_day_evt->execute(['workstation_ak' => $WKS
                                        , 'date' => $d
                                        , 'event_ak' => $event_ak
                                        , 'sort' => $sortorder]);
                                    ///////////////////////////////////
                                    return $event_ak;
                                }));
                        $this->em->flush();
                        $this->em->clear();
                        echo "$WKS-$d : " . count($daysEvents[$WKS][$d]) . " Event(s) (mem_usage=" . $this->readableBytes(memory_get_usage(true)) . ")\r\n";
                    } catch (\Exception $eeee) {
                        var_dump($eeee->getMessage());
                    }
                    $dayEvents_dom->clear();
                } else {
                    echo "$WKS-$d : 0 Event(s)\r\n";
                }
                unset($dayEvents_dom);
            }
            ///////////////////////////////////////////
            ///////////////////////////////////////////
        }

        $this->em->flush();
        $this->em->clear();
        //var_dump($daysEvents);
        var_dump(array('processing_time' => (microtime(true) - $t)
            , 'daysEvents' => count($daysEvents)
        ));

        return $daysEvents;
    }

    protected function cache_events_and_img() {
        $events_inserted = array();
        $t = microtime(true);

        ///////////////////////////////////////////////
        $sql_insert_events = "INSERT INTO tmp_events (workstation_ak, event_ak, name_en, name_fr)"
                . " VALUES (:workstation_ak, :event_ak, :name_en, :name_fr);";
        $query_insert_evt = $this->em->getConnection()->prepare($sql_insert_events);
        ///////////////////////////////////////////////

        foreach ($this->all_workstations as $WKS) {
            $this->user = new UserModel($this->params, $this->em, $WKS); //$this->getWorkstationAk());
            ///////////////////////////////////////////
            ///////////////////////////////////////////
            $AllEvents = $this->user->bos->execute_4("IWsAPIEvent", "FindAllEvents", array(), "");
            if ($AllEvents instanceof \Symfony\Component\DomCrawler\Crawler) {
                $events_inserted[$WKS] = array_filter($AllEvents->filterXPath('//eventlist/event')->each(function (Crawler $node, $i) use (&$WKS, &$query_insert_evt) {
                            try {
                                $val['workstation_ak'] = $WKS;
                                $val['ak'] = $node->filterXPath('//ak')->text();
                                $val['name_en'] = $node->filterXPath("//i18nlist/i18n[code/text()=\"$this->english\"]/fieldlist/field[objtype/text()=".(AccountController::OBJECT_TYPE_EVENT_NAME)."]/value")->text(); //538=Event Title
                                $val['name_fr'] = $node->filterXPath("//i18nlist/i18n[code/text()=\"$this->french\"]/fieldlist/field[objtype/text()=".(AccountController::OBJECT_TYPE_EVENT_NAME)."]/value")->text(); //538=Event Title
                                //////////////////////////////
                                $img_en_ak = $node->filterXPath("//i18nlist/i18n[code/text()=\"$this->english\"]/fieldlist/field[objtype/text()=".(AccountController::OBJECT_TYPE_EVENT_PHOTO)."]/value")->text(); //539=Event Photo
                                $img = $this->user->bos->execute_4("IWsAPIMask", "ReadImageByAK", $img_en_ak, "");
                                if (isset($img) && $img instanceof \Symfony\Component\DomCrawler\Crawler) {
                                    try {
                                        $image_hex = $img->filterXPath('//image/value')->text();
                                        $filename = $img->filterXPath('//image/filename')->text();
                                        $image_bin = hex2bin($image_hex);
                                        //var_dump(array('fn' => $filename, 'size' => strlen($image_bin)));
                                        $filesystem = new Filesystem();
                                        $filesystem->dumpFile($this->projectDir . '/public/image/en/' . $val['ak'] . '.jpg', $image_bin);
                                    } catch (\Exception $eee) {
                                        ;
                                    }
                                } else {
                                    ;
                                }
                                //////////////////////////////
                                $img_fr_ak = $node->filterXPath("//i18nlist/i18n[code/text()=\"$this->french\"]/fieldlist/field[objtype/text()=".(AccountController::OBJECT_TYPE_EVENT_PHOTO)."]/value")->text(); //539=Event Photo
                                $img = $this->user->bos->execute_4("IWsAPIMask", "ReadImageByAK", $img_fr_ak, "");
                                if (isset($img) && $img instanceof \Symfony\Component\DomCrawler\Crawler) {
                                    try {
                                        $image_hex = $img->filterXPath('//image/value')->text();
                                        $filename = $img->filterXPath('//image/filename')->text();
                                        $image_bin = hex2bin($image_hex);
                                        //var_dump(array('fn' => $filename, 'size' => strlen($image_bin)));
                                        $filesystem = new Filesystem();
                                        $filesystem->dumpFile($this->projectDir . '/public/image/fr/' . $val['ak'] . '.jpg', $image_bin);
                                    } catch (\Exception $eee) {
                                        ;
                                    }
                                    $img->clear();
                                } else {
                                    ;
                                }
                                unset($img);
                                ///////////////////////////////////
                                //INSERT INTO DB: events
                                ///////////////////////////////////
                                $query_insert_evt->execute(['workstation_ak' => $val['workstation_ak']
                                    , 'event_ak' => $val['ak']
                                    , 'name_en' => $val['name_en']
                                    , 'name_fr' => $val['name_fr']]);
                                //$affected += $query->rowCount();
                                ///////////////////////////////////
                                return $val;
                            } catch (\Exception $e) {
                                echo "No english/french values for: \r\n";
                            }
                        }));
                $AllEvents->clear();
            }
            unset($AllEvents);
        }

        $this->em->flush();
        $this->em->clear();
        //var_dump($daysEvents);
        //var_dump($events_inserted);
        var_dump(array('processing_time' => (microtime(true) - $t)
            , 'events_inserted' => count($events_inserted)
        ));

        return $events_inserted;
    }

    private function update_tmp2cache() {
        $results = array();
        $t = microtime(true);

        ///////////////////////////////////////////////
        //$conn = $this->container->get('doctrine')->getConnection('default');
        //events
        $sql = "
                INSERT INTO `cache_events` (`status`,`workstation_ak`,`event_ak`,`name_en`,`name_fr`)
                SELECT 1,`workstation_ak`,`event_ak`,`name_en`,`name_fr`
                FROM tmp_events tmp 
                ON DUPLICATE KEY UPDATE `status`=1,`workstation_ak`=tmp.`workstation_ak`,`name_en`=tmp.`name_en`,`name_fr`=tmp.`name_fr`
                ;
                ";
        $query = $this->em->getConnection()->prepare($sql);
        $query->execute([]);
        $results['cache_events']['insert_update'] = $query->rowCount();
        ///////////////////////////////////////////////
        $sql = "
                UPDATE `cache_events` SET `status`=0 WHERE NOT (`event_ak` IN (SELECT ce.event_ak FROM `tmp_events` ce));
                ";
        $query = $this->em->getConnection()->prepare($sql);
        $query->execute([]);
        $results['cache_events']['deactivated'] = $query->rowCount();
        ///////////////////////////////////////////////
        //events by day
        ///////////////////////////////////////////////
        $sql = "
                INSERT INTO `cache_events_by_day` (`status`,`workstation_ak`,`date`,`event_ak`,`sort`)
                SELECT 1,`workstation_ak`,`date`,`event_ak`,`sort`
                FROM `tmp_events_by_day` tmp 
                ON DUPLICATE KEY UPDATE `status`=1, `sort`=tmp.`sort`
                ;
                ";
        $query = $this->em->getConnection()->prepare($sql);
        $query->execute([]);
        $results['cache_events_by_day']['insert_update'] = $query->rowCount();
        ///////////////////////////////////////////////
        $sql = "
                UPDATE `cache_events_by_day` ce
                 LEFT JOIN `tmp_events_by_day` te ON te.`workstation_ak`=ce.`workstation_ak` AND te.`date`=ce.`date` AND te.`event_ak`=ce.`event_ak` 
                SET `status`=0 
                WHERE te.id IS NULL;
                ";
        $query = $this->em->getConnection()->prepare($sql);
        $query->execute([]);
        $results['cache_events_by_day']['deactivated'] = $query->rowCount();
        ///////////////////////////////////////////////
        //#products
        ///////////////////////////////////////////////
        $sql = "
                INSERT INTO `cache_products` (`status`,`workstation_ak`,`product_ak`,`code`,`sheetname`,`currency`,`net`,`tax`,`gross`,`name_en`,`name_fr`,`desc_en`,`desc_fr`,`account_dmg_ak`)
                SELECT 1,`workstation_ak`,`product_ak`,`code`,`sheetname`,`currency`,`net`,`tax`,`gross`,`name_en`,`name_fr`,`desc_en`,`desc_fr`,`account_dmg_ak`
                FROM tmp_products tmp 
                ON DUPLICATE KEY UPDATE `status`=1,`code`=tmp.`code`,`sheetname`=tmp.`sheetname`,`currency`=tmp.`currency`,`net`=tmp.`net`,`tax`=tmp.`tax`,`gross`=tmp.`gross`,`name_en`=tmp.`name_en`,`name_fr`=tmp.`name_fr`,`desc_en`=tmp.`desc_en`,`desc_fr`=tmp.`desc_fr`,`account_dmg_ak`=tmp.`account_dmg_ak`
                ;
                ";
        $query = $this->em->getConnection()->prepare($sql);
        $query->execute([]);
        $results['cache_products']['insert_update'] = $query->rowCount();
        ///////////////////////////////////////////////
        $sql = "
                UPDATE `cache_products` SET `status`=0 WHERE NOT (`product_ak` IN (SELECT ce.product_ak FROM `tmp_products` ce));
                ";
        $query = $this->em->getConnection()->prepare($sql);
        $query->execute([]);
        $results['cache_products']['deactivated'] = $query->rowCount();
        ///////////////////////////////////////////////
        //#statgroups
        ///////////////////////////////////////////////
        $sql = "
                INSERT INTO `cache_stat_groups` (`status`,`workstation_ak`,`code`,`event_ak`)
                SELECT 1,`workstation_ak`,`code`,`event_ak`
                FROM `tmp_statgroups` tmp 
                ON DUPLICATE KEY UPDATE `status`=1
                ;
                ";
        $query = $this->em->getConnection()->prepare($sql);
        $query->execute([]);
        $results['cache_stat_groups']['insert_update'] = $query->rowCount();
        ///////////////////////////////////////////////
        $sql = "
                UPDATE `cache_stat_groups` ce
                 LEFT JOIN `tmp_statgroups` te ON te.`workstation_ak`=ce.`workstation_ak` AND te.`code`=ce.`code` AND te.`event_ak`=ce.`event_ak` 
                SET `status`=0 
                WHERE te.id IS NULL;
                ";
        $query = $this->em->getConnection()->prepare($sql);
        $query->execute([]);
        $results['cache_stat_groups']['deactivated'] = $query->rowCount();
        ///////////////////////////////////////////////
        //#statgroups products
        ///////////////////////////////////////////////
        $sql = "
                INSERT INTO `cache_stat_groups_products` (`status`,`workstation_ak`,`code`,`product_ak`,`sort`)
                SELECT 1,`workstation_ak`,`code`,`product_ak`,`sort`
                FROM `tmp_statgroups_products` tmp 
                ON DUPLICATE KEY UPDATE `status`=1, `sort`=tmp.`sort`
                ;
                ";
        $query = $this->em->getConnection()->prepare($sql);
        $query->execute([]);
        $results['cache_stat_groups_products']['insert_update'] = $query->rowCount();
        ///////////////////////////////////////////////
        $sql = "
                UPDATE `cache_stat_groups_products` ce
                 LEFT JOIN `tmp_statgroups_products` te ON te.`workstation_ak`=ce.`workstation_ak` AND te.`code`=ce.`code` AND te.`product_ak`=ce.`product_ak` 
                SET `status`=0 
                WHERE te.id IS NULL;
                ";
        $query = $this->em->getConnection()->prepare($sql);
        $query->execute([]);
        $results['cache_stat_groups_products']['deactivated'] = $query->rowCount();
        ///////////////////////////////////////////////
        ///////////////////////////////////////////////

        foreach ($results as $k => $v) {
            $sql = "SELECT COUNT(1) total_count,COUNT(status>0) total_active_count FROM `$k`;";
            $query = $this->em->getConnection()->prepare($sql);
            $query->execute();
            $res = $query->fetchAll();
            try {
                $results[$k]['total_count'] = $res[0]['total_count'];
            } catch (\Exception $e) {
                $results[$k]['total_count'] = 0;
            }
            try {
                $results[$k]['total_active_count'] = $res[0]['total_active_count'];
            } catch (\Exception $e) {
                $results[$k]['total_active_count'] = 0;
            }
        }

        //var_dump($sg_to_insert);
        var_dump(array('processing_time' => (microtime(true) - $t)
            , 'results' => $results
        ));

        return $results;
    }

    ///////////////////////////////////////////////////////
    //email report for caching
    ///////////////////////////////////////////////////////
    private function email_failure_msg($error_msg) {
        if ($this->params->get('env') == "prod") {
            $subject = 'FAILURE: Cache Report (ROM Tickets)';
        } else {
            $subject = 'Test FAILURE: Cache Report (ROM Tickets)';
        }

        try {
            $html = "<span style='color:red;'>$error_msg</span>";
            $email = (new Email())
                    ->from('do-not-reply-tickets@rom.on.ca')
//                    ->to('victord@rom.on.ca')
                    ->to('dishane@rom.on.ca')
                    ->cc('victord@rom.on.ca', 'coreyd@rom.on.ca', 'fahda@rom.on.ca')
                    ->subject($subject)
                    ->priority(Email::PRIORITY_HIGH)
                    ->html($html);
            $this->mailer->send($email);
        } catch (\Exception $e) {
            var_dump($e->getMessage());
        }
    }

    ///////////////////////////////////////////////////////
    //email report for caching
    ///////////////////////////////////////////////////////
    private function email_report($results) {
        $html = "";
        $html .= "<table><thead><tr><td>table name</td><td>insert/update</td><td>deactivated</td><td>total_count</td><td>total_active_count</td></tr></thead>";
        $html .= "<tbody>";
        foreach ($results as $k => $r) {
            $html .= "<tr><td>$k</td><td>$r[insert_update]</td><td>$r[deactivated]</td><td>$r[total_count]</td><td>$r[total_active_count]</td></tr>";
        }
        $html .= "</tbody>";
        $html .= "</table>";

        if ($this->params->get('env') == "prod") {
            $subject = 'Cache Report (ROM Tickets)';
        } else {
            $subject = 'Test Cache Report (ROM Tickets)';
        }

        try {
            $email = (new Email())
                    ->from('do-not-reply-tickets@rom.on.ca')
//                    ->to('victord@rom.on.ca')
                    ->to('dishane@rom.on.ca')
                    ->cc('victord@rom.on.ca', 'coreyd@rom.on.ca', 'fahda@rom.on.ca')
                    ->subject($subject)
                    ->html($html);
            $this->mailer->send($email);
        } catch (\Exception $e) {
            var_dump($e->getMessage());
        }
    }

}
