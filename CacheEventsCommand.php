<?php

/////////////////////////////////////////////////////////////////////////////
//RUN ME WITH ONE OF THE FOLLOWING COMMANDS:
//  php bin/console app:cache-events
//  C:/wamp64/bin/php/php8.0.10/php.exe bin/console app:cache-events
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
//IO
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;
///////////////////////////
//contorller constants
use App\Controller\Main\AccountController;
///////////////////////////

class CacheEventsCommand extends Command {

    protected static $defaultName = 'app:cache-events';
    private $em;
    private $params;
    private $user;
    private $days_ahead_to_process = 100;
    private $english = 'en-US';
    private $french = 'fr-FR';
    private $projectDir;

    public function __construct(KernelInterface $kernel, EntityManagerInterface $em, ParameterBagInterface $params) {
        parent::__construct();
        $this->projectDir = $kernel->getProjectDir();
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

        $events_to_insert = array();
        $daysEvents = array();
        $t = microtime(true);

        ///////////////////////////////////////////////
        //$conn = $this->container->get('doctrine')->getConnection('default');
        $query = $this->em->getConnection()->prepare("TRUNCATE tmp_events;");
        $query->execute();
        $query = $this->em->getConnection()->prepare("TRUNCATE tmp_events_by_day;");
        $query->execute();
        ///////////////////////////////////////////////
        $sql_insert_events = "INSERT INTO tmp_events (workstation_ak, event_ak, name_en, name_fr)"
                . " VALUES (:workstation_ak, :event_ak, :name_en, :name_fr);";
        $query_insert_evt = $this->em->getConnection()->prepare($sql_insert_events);
        ///////////////////////////////////////////////
        $sql_insert_day_events = "INSERT INTO tmp_events_by_day (workstation_ak, date, event_ak)"
                . " VALUES (:workstation_ak, :date, :event_ak);";
        $query_insert_day_evt = $this->em->getConnection()->prepare($sql_insert_day_events);
        ///////////////////////////////////////////////

        foreach (array("ROM.WKS23", "ROM.WKS42", "ROM.WKS45") as $WKS) {
            $this->user = new UserModel($this->params, $this->em, $WKS); //$this->getWorkstationAk());
            ///////////////////////////////////////////
            ///////////////////////////////////////////
            $AllEvents = $this->user->bos->execute_4("IWsAPIEvent", "FindAllEvents", array(), "");
            if ($AllEvents instanceof \Symfony\Component\DomCrawler\Crawler) {
                $events_to_insert[$WKS] = array_filter($AllEvents->filter('eventlist > event')->each(function (Crawler $node, $i) use (&$WKS, &$query_insert_evt) {
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
                                        $image_hex = $img->filter('IMAGE > VALUE')->text();
                                        $filename = $img->filter('IMAGE > FILENAME')->text();
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
                                        $image_hex = $img->filter('IMAGE > VALUE')->text();
                                        $filename = $img->filter('IMAGE > FILENAME')->text();
                                        $image_bin = hex2bin($image_hex);
                                        //var_dump(array('fn' => $filename, 'size' => strlen($image_bin)));
                                        $filesystem = new Filesystem();
                                        $filesystem->dumpFile($this->projectDir . '/public/image/fr/' . $val['ak'] . '.jpg', $image_bin);
                                    } catch (\Exception $eee) {
                                        ;
                                    }
                                } else {
                                    ;
                                }
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
            }
            ///////////////////////////////////////////
            ///////////////////////////////////////////
            echo "\r\n===============\r\n";
            ///////////////////////////////////////////
            ///////////////////////////////////////////
            for ($i = 0; $i < $this->days_ahead_to_process; $i++) {
                $d = date('Y-m-d', strtotime("Now +$i day"));
                $xml = "
                    <SEARCHEVENTREQ>
                        <DATE>
                            <FROM>".htmlspecialchars($d)."</FROM>
                            <TO>".htmlspecialchars($d)."</TO>
                        </DATE>
                        <NUMBEROFPERSON>1</NUMBEROFPERSON>
                    </SEARCHEVENTREQ>
                    ";
                $dayEvents_dom = $this->user->bos->execute_4("IWsAPIEvent", "SearchEvent", array(), $xml);
                //$daysEvents[$WKS][$d] = array();//(!) need to mark for deletion if no values
                if ($dayEvents_dom instanceof \Symfony\Component\DomCrawler\Crawler) {
                    try {
                        $daysEvents[$WKS][$d] = array_filter($dayEvents_dom->filterXPath('//eventlist/event')->each(function (Crawler $node, $i) use (&$WKS, &$d, &$query_insert_day_evt) {
                                    $event_ak = $node->filter('ak')->text();
                                    ///////////////////////////////////
                                    //INSERT INTO DB: events_by_day
                                    ///////////////////////////////////
                                    $query_insert_day_evt->execute(['workstation_ak' => $WKS
                                        , 'date' => $d
                                        , 'event_ak' => $event_ak]);
                                    ///////////////////////////////////
                                    return $event_ak;
                                }));
                    } catch (\Exception $eeee) {
                        var_dump($eeee->getMessage());
                    }
                } else {
                    ;
                }
            }
            ///////////////////////////////////////////
            ///////////////////////////////////////////
        }

        //var_dump($daysEvents);
        //var_dump($events_to_insert);
        var_dump(array('processing_time' => (microtime(true) - $t)
            , 'daysEvents' => count($daysEvents)
            , 'events_to_insert' => count($events_to_insert)
        ));

        return Command::SUCCESS;
    }

}
